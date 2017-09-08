<?php
/**
 * pronote.php - une classe php permettant de gérer une connexion à l'espace elève de pronote.
 */
/**
 * Dépendances : 
 * 	- openssl
 * 	- curl
 * 	- PHP 7.0+ ou random_compat (MIT)
 */
/**
 * Notes :
 *  Toutes les méthodes ne sont pas encore codées, mais les principales le sont.
 * Le script effectue les mêmes appels que l'interface normale, ce qui signifie que tout reste crypté.
 * ### ATTENTION: ###
 * Dans le cas où vous voudriez utiliser ce script sur un serveur web (i.e pour faire un interface pronote alternative), il faut faire TRÈS attention à la sécurité au niveau de la transmission serveur <-> client.
 * Pronote, avec son interface entièrement côté client, a l'immense avantage d'assurer une sécurité parfaite contre toutes les attaques de MITM: cryptage AES/RSA, système de numero de requête etc...
 * ##################
 * Ce script est utilisé pour plusieurs de mes applications liées à pronote depuis plus d'un an (reférence: date de premier commit de ce script, ie 03/2017) et son fonctionnement est (a priori) avéré.

 * ### TODO: ###
 * 	- Gestion de la deconnexion pour inactivié
 * 	- Ajouter les autres pages
 * 	- Vérifier que les autres configurations de cryptage sont bien gérées
 * 	- ???
 * #############
 */
/**
 * LICENSE :
 *	MIT License
 *	
 *	Copyright (c) Frazew 2015-2017
 *	
 *	Permission is hereby granted, free of charge, to any person obtaining a copy
 *	of this software and associated documentation files (the "Software"), to deal
 *	in the Software without restriction, including without limitation the rights
 *	to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *	copies of the Software, and to permit persons to whom the Software is
 *	furnished to do so, subject to the following conditions:
 *	
 *	The above copyright notice and this permission notice shall be included in all
 *	copies or substantial portions of the Software.
 *	
 *	THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *	IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *	FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 *	AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *	LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *	OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 *	SOFTWARE.
 */

set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__FILE__));
include_once dirname(__FILE__)."/random_compat.phar";
require_once dirname(__FILE__)."/Crypt/RSA.php";

/**
 * Classe principale
 */
Class Pronote {
	// Version
	private $pronoteVersion = "2016";
	public $version = "3.14";
	public $userAgent = "";

	// L'url du serveur pronote
	private $pronoteURL = "";

	// Cryptographie
	private $AESIV = "";
	private $AES_key = "";
	private $RSA_mod = "";
	private $RSA_exp = "";

	// Paramètres liés à la session courante
	private $pronoteSession = "";
	private $requestCount = 1;

	// Un array qui garde les paramètres liés à l'utilisateur comme son nom, sa classe etc
	private $userData = array();
	public $customPeriode = false;
	

	/**
	 * Exporte l'état de configuration actuel.
	 * @return Array Ensemble des paramètres
	 */
	public function export() {
		return array(
			"pronoteURL" => $this->pronoteURL,
			"userAgent" => $this->userAgent,
			"AESIV" => $this->AESIV,
			"AES_key" => $this->AES_key,
			"RSA_mod" => $this->RSA_mod,
			"RSA_exp" => $this->RSA_exp,
			"pronoteSession" => $this->pronoteSession,
			"requestCount" => $this->requestCount,
			"userData" => $this->userData,
			"customPeriode" => $this->customPeriode
		);
	}

	/**
	 * Importe un état de configuration à partir d'un état sauvé.
	 * @param  Array $arr L'état de configuration, supposé sans problème
	 */
	public function import($arr) {
		$this->pronoteURL = $arr["pronoteURL"];
		$this->userAgent = $arr["userAgent"];
		$this->AESIV = $arr["AESIV"];
		$this->AES_key = $arr["AES_key"];
		$this->RSA_mod = $arr["RSA_mod"];
		$this->RSA_exp = $arr["RSA_exp"];
		$this->pronoteSession = $arr["pronoteSession"];
		$this->requestCount = $arr["requestCount"];
		$this->userData = $arr["userData"];
		$this->customPeriode = $arr["customPeriode"];
	}

	/**
	 * Connecte un utilisateur au serveur pronote et initie tous les paramètres. Entrypoint
	 * @param  $user, le nom d'utilisateur
	 * @param  $password, le mot de passe
	 * @param  $autologin, si true, le mot de passe est un token de connexion automatique @TODO Pas encore implémenté
	 * @param  $url, l'url du serveur auquel se connecter
	 * @return Un array, [status, message, userData]. Si status = 0, il y a eu une erreur et message est défini, sinon status = 1 et userData est défini
	 */
	public function login($user, $password, $autologin = false, $url) {
		global $DEBUG;

		if ($url == "") {
			return array(
				"status" => 0,
				"message" => "Aucune URL fournie, impossible de continuer"
			);
		}
		$this->pronoteURL = $url;
		// On donne un User-Agent connu et à jour histoire de pas se prendre la page signalant que le navigateur n'est pas compatible
		$this->userAgent = "Mozilla/5.0 (X11; Linux) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/60.0.3112.113 Safari/537.36 pronote.php/" . $this->version . "";

		// Requête initiale de la page pour récupérer les paramètres.
		if (isset($DEBUG) && $DEBUG == true) echo "Premiere requete\n";
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
		curl_setopt($ch, CURLOPT_URL, $this->pronoteURL . "/eleve.html");
		curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
		$pronotePage = curl_exec($ch);
		curl_close($ch);
		if (isset($DEBUG) && $DEBUG == true) echo "Premiere requete terminée\n";
		if (isset($DEBUG) && $DEBUG == true) file_put_contents("pronote_page", $pronotePage);
		$params = array();
		$doc = new DOMDocument();
		$doc->loadHTML($pronotePage);
		$body = $doc->getElementsByTagName("body");
		if ($body && $body->length > 0) {
			$onload = $body->item(0)->getAttribute("onload");
			if (preg_match("/({h:.*?})/", $onload, $matches)) {
				Util::parse_jsobj($matches[0], $params);
			} else {
				return array(
					"status" => 0,
					"message" => "Impossible d'identifier les paramètres lors de la requête initiale (0x1)"
				);
			}
		} else {
			return array(
				"status" => 0,
				"message" => "Impossible d'identifier les paramètres lors de la requête initiale (0x2)"
			);
		}

		if ($params == null) {
			if (strpos($pronotePage, "Impossible d'afficher la page")) {
				preg_match_all('/<div class="texte" style="font-size:12px;">(.*?)<\/div>/', $pronotePage, $matches);
				return array(
					"status" => 0,
					"message" => $matches[1]
				);
			} else {
				return array(
					"status" => 0,
					"message" => "Impossible d'établir la connexion initiale à Pronote"
				);
			}
		}

		$this->pronoteSession = (int)$params["h"];
		$this->RSA_mod = $params["MR"];
		$this->RSA_exp = $params["ER"];
		$this->requestCount = 1;
		$this->userData = array();
		$this->AESIV = Util::randomStr();

		if ($autologin) $this->AES_key = $password;
		else $this->AES_key = utf8_encode(strtolower($user) . $password);
		

		/*
		Première requête : On donne au serveur notre IV AES
		 */
		$post = array(
			"donnees" => array(
				"Uuid" => Crypto::encryptRSA($this->AESIV, $this->RSA_mod, $this->RSA_exp)
			)
		);		
		$json = $this->makeRequest("FonctionParametres", $post, array("", ""), array("", ""), array("", $this->AESIV));

		if ($json != null) {
			if (isset($json["erreur"])) {
				return array(
					"status" => 0,
					"message" => "La requête des paramètres a échoué : " . $json["erreur"]
				);
			}
			else {
				$this->userData["premierePeriode"] = $json["donneesSec"]["donnees"]["General"]["dateDebutPremierCycle"]["V"];
				$this->pronoteVersion = $json["donneesSec"]["donnees"]["General"]["millesime"];
			}
		} else {
			return array(
				"status" => 0,
				"message" => "Erreur d'identification en accédant à Pronote"
			);
		}



		/*
		Deuxième requête, l'identification où on fournit le nom d'utilisateur au serveur
		 */
		$post = array(
			"donnees" => array(
				"Identifiant" => $user,
				"PourENT" => false,
				"demandeConnexionAuto" => true
			)
		);
		$json = $this->makeRequest("Identification", $post, array("", $this->AESIV), array("", $this->AESIV), array("", $this->AESIV));
		
		if ($json != null) {
			if (isset($json["erreur"])) {
				return array(
					"status" => 0,
					"message" => "La requête d'identification a échoué : " . $json["erreur"]
				);
			}
			else if (isset($json["donneesSec"]["donnees"]["Challenge"]) && $json["donneesSec"]["donnees"]["Challenge"] != null) {
				$decrypted = Crypto::decryptAES($json["donneesSec"]["donnees"]["Challenge"], $this->AES_key, $this->AESIV, true, true);

				/*
				Troisième requête, on "prouve" qu'on a le bon mot de passe en décryptant la chaîne envoyée avec et en la recryptant.
				 */
				$post = array(
					"xml" => ("<Authentification><Connexion G=\"0\"/><Challenge T=\"3\">" . Crypto::generateNumeroOrdre($decrypted, $this->AES_key, $this->AESIV, true) . "</Challenge></Authentification>")
				);
				$json = $this->makeRequest("Authentification", $post, array("", $this->AESIV), array("", $this->AESIV), array("", $this->AESIV));
				if ($json != null) {
					if (isset($json["erreur"])) {
						return array(
							"status" => 0,
							"message" => "La requête d'authentification a échoué : " . $json["erreur"]
						);
					}

					if (!preg_match("/<Cle T=\"3\">(.*?)<\\/Cle>/", $json["donneesSec"]["xml"], $cle)) {
						return array(
							"status" => 0,
							"message" => "Erreur d'identification : les identifiants sont-ils valides ? (0x1)"
						);
					}

					// La nouvelle clé AES est décryptée
					$this->AES_key = Crypto::decryptAES(trim($cle[1]), $this->AES_key, $this->AESIV, false, false, true);
					$this->userData["nom"] = $json["donneesSec"]["donnees"]["ressource"]["L"];
					$this->userData["code"] = $json["donneesSec"]["donnees"]["ressource"]["N"];
					$this->userData["classe"] = $json["donneesSec"]["donnees"]["ressource"]["classeDEleve"]["L"];				

					if (preg_match("/<Onglet G=\"99\">.+?<\\/Onglet>/s", $json["donneesSec"]["xml"], $onglet)) {
						if (preg_match_all("/<Periode G=\"2\" N=\"([A-Z0-9]+)\" L=\"(.*?)\"(?:|.*?)>/", $onglet[0], $periodes)) {
							preg_match("/<PeriodeParDefaut N=\"(.*?)\" L=\".*?\"\\/>/", $json["donneesSec"]["xml"], $periode);
							$this->userData["periode"] = $periode[1];						
							foreach ($periodes[1] as $key => $periode) {
								$this->userData["periodes"][$key] = array(
									"code" => $periodes[1][$key],
									"nom" => $periodes[2][$key]
								);
							}
						}
					}

					/*
					Tout s'est bien passé, on retourne les infos de connexion.
					 */
					return array(
						"status" => 1,
						"userData" => $this->userData
					);
				} else {
					return array(
						"status" => 0,
						"message" => "Erreur d'identification : les identifiants sont-ils valides ? (0x2)"
					);
				}

			} else {
				return array(
					"status" => 0,
					"message" => "Erreur d'identification : le nom d'utilisateur est-il valide ?"
				);
			}
		} else {
			return array(
				"status" => 0,
				"message" => "Erreur d'identification en accédant à Pronote"
			);
		}
	}

	/**
	 * Envoit la requête de navigation vers un onglet ainsi que la requête des données liées à la page.
	 * @param  String $nom    Nom interne de la requête
	 * @param  int $onglet Numéro d'onglet
	 * @param  Array $args   Arguments pour la requête
	 * @return Array         array(status, message, data), si status = 0, message est défini avec l'erreur, si status = 1, data contient les données
	 */
	private function navigateNCall($nom, $onglet, $args) {
			// Apparemment, il faut prévenir de notre action, histoire d'éviter les accès non autorisés probablement.
			$post = array(
				"_Signature_" => array(
					"onglet" => $onglet
				),
				"donnees" => array(
					"onglet" => $onglet
				)
			);
			$this->makeRequest("Navigation", $post);
			$json = $this->makeRequest($nom, $args);

			if ($json != null) {
				if (isset($json["erreur"])) {
					return array(
						"status" => 0,
						"message" => "La requête a été refusée : " . $json["erreur"]
					);
				}
				return array(
					"status" => 1,
					"data" => $json
				);
			} else {
				return array(
					"status" => 0,
					"message" => "La requête a échoué."
				);
			}
	}

	/**
	 * Formatte et envoit une requête donnée vers le serveur pronote.
	 * @param  $nom, nom de la fonction appelée
	 * @param  $args, arguments à lui passer, sous la forme d'un array()
	 * @param  $AESNumeroOrdre, array(cle, iv) utilisé pour la génération du numéro d'ordre
	 * @param  $AESArgs, array(cle, iv) utilisé pour le cryptage des arguments
	 * @param  $AESDonnees, array(cle, iv) utilisé pour le décryptage des données
	 * @return Le résultat de la requête en JSON, 1 en cas d'échec.
	 */
	private function makeRequest($nom, $args, $AESNumeroOrdre = null, $AESArgs = null, $AESDonnees = null) {
		global $DEBUG;
		if ($AESNumeroOrdre == null) $AESNumeroOrdre = array($this->AES_key, $this->AESIV);
		if ($AESArgs == null) $AESArgs = array($this->AES_key, $this->AESIV);
		if ($AESDonnees == null) $AESDonnees = array($this->AES_key, $this->AESIV);

		$numeroOrdre = Crypto::generateNumeroOrdre($this->requestCount, $AESNumeroOrdre[0], $AESNumeroOrdre[1]);

		$espace = "3";
		$url = $this->pronoteURL . "appelfonction/" . $espace . "/" . $this->pronoteSession . "/" . $numeroOrdre;
		
		$post = array();
		$post["session"] = $this->pronoteSession;
		$post["numeroOrdre"] = $numeroOrdre;
		$post["nom"] = $nom;
		if (isset($DEBUG) && $DEBUG == true) echo json_encode($args, JSON_UNESCAPED_SLASHES) . "\n";
		$args = Crypto::encryptAESWithMD5WithGzip(json_encode($args, JSON_UNESCAPED_SLASHES), $AESArgs[0], $AESArgs[1]);
		$post["donneesSec"] = $args;

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST"); 
		curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
		curl_setopt($ch, CURLOPT_POSTFIELDS, str_replace("\\/", "/", json_encode($post))); 
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			"Origin: " . $this->pronoteURL,
			'Referer: ' . $this->pronoteURL . 'mobile.eleve.html'
		));
		$response = curl_exec($ch);
		if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) $this->requestCount += 2;
		curl_close($ch);

		$json = json_decode($response, true);
		if ($json != null) {
			if (isset($json["donneesSec"]) && $json["donneesSec"] != null) {
				$json["donneesSec"] = json_decode(Crypto::decryptAESWithMD5WithGzip($json["donneesSec"], $AESDonnees[0], $AESDonnees[1]), true);		
			}

		}
		
		if (isset($DEBUG) && $DEBUG == true) print_r($json);

		if ($json != null && isset($json["Erreur"])) {
			return array("erreur" => var_export($json["Erreur"], true));
		}
		return $json;
	}

	/**
	 * Crée le lien vers une ressource externe.
	 * @param  $nomfichier, le nom du fichier
	 * @param  $args, les arguments nécessaires à l'accès à la ressource
	 * @return Le lien complet vers la ressource externe
	 */
	public function generateURLFichierExterne($nomFichier, $args) {
		$string = $this->pronoteURL . "FichiersExternes/";
		$aesParam = Crypto::generateNumeroOrdre(json_encode($args), $this->AES_key, $this->AESIV);
		$string .= $aesParam . "/" . urlencode($nomFichier) . "?Session=" . $this->pronoteSession;
		return $string;
	}

	public function getUserData() {
		return $this->userData;
	}

	/**
	 * Récupère les données de la page d'accueil.
	 * @return Array Voir la documentation complète pour avoir le détail du format
	 */
	public function getHomePageData() {
		$date = new DateTime();
		$date = $date->createFromFormat("j/m/Y", $this->userData["premierePeriode"]);
		$weeks = $date->diff(new DateTime());

		$post = array(
			"_Signature_" => array(
				"onglet" => 7
			),
			"xml" => "<PageAccueil><NumeroSemaine T=\"1\">" . (int)(($weeks->days) / 7 + 1) . "</NumeroSemaine></PageAccueil>"
		);
		$request = $this->navigateNCall("PageAccueil", 7, $post);

		if ($request["status"] == 0) {
			return array(
				"status" => 0,
				"message" => $request["message"]
			);
		}

		$json = $request["data"];
		if ($json != null) {
			$data = array();
			foreach ($json["donneesSec"]["donnees"]["notes"]["listeDevoirs"] as $devoir) {
				$corrige = null;			

				if (isset($devoir["libelleCorrige"])) {
					$corrige = $this->generateURLFichierExterne($devoir["libelleCorrige"], array("N" => $devoir["N"], "G" => 60 /* 60 = EGenreRessource.Devoir*/));
				}
				$data["notes"][] = array(
					"matiere" => $devoir["service"]["V"]["L"],
					"periode" => $devoir["periode"]["V"]["L"],
					"date" => $devoir["date"]["V"],
					"code" => $devoir["N"],
					"note" => array(
						"note" => ($devoir["note"]["V"] == "|3" ? "N.Noté" : ($devoir["note"]["V"] == "|1" ? "Abs." : $devoir["note"]["V"])),
						"bareme" => $devoir["bareme"]["V"],
						"moyenne" => $devoir["moyenne"]["V"]
					),
					"corrige" => $corrige
				);

			}

			foreach ($json["donneesSec"]["donnees"]["travailAFaire"]["listeTAF"]["V"] as $travail) {
				$data["devoirs"][] = array(
					"matiere" => $travail["matiere"]["V"]["L"],
					"pour" => $travail["pourLe"]["V"],
					"donne" => $travail["donneLe"]["V"],
					"descriptif" => $travail["descriptif"]["V"]
				);
			}

			return array(
				"status" => 1,
				"data" => $data
			); 
		} else {
			return array(
				"status" => 0,
				"message" => "Une erreur est survenue lors de l'accès aux informations de base"
			); 
		}
	}

	/**
	 * Récupère le relevé de notes et effectue quelques mesures statistiques supplémentaires.
	 * @return Array Voir la documentation complète pour avoir le détail du format
	 */
	public function getReleveNoteData() {
		$post = array(
			"_Signature_" => array(
				"onglet" => 12
			),
			"xml" => '<PageReleve><Eleve N="' . $this->userData["code"] . '"/><Periode G="2" N="' . $this->userData["periode"] . '"/></PageReleve>'
		);
		$request = $this->navigateNCall("PageReleve", 12, $post);

		if ($request["status"] == 0) {
			return array(
				"status" => 0,
				"message" => $request["message"]
			);
		}
		$json = $request["data"];

		$xml = simplexml_load_string($json["donneesSec"]["xml"]);	    
		$json_string = json_encode(@$xml, JSON_PRETTY_PRINT);

		$json = json_decode(@$json_string, true);
		if ($json != null) {
			$notes = array();
			$notes_coeffs = [];
			$total = 0;

			foreach ($json["ListeServices"]["Service"] as $matiere) {
				if (isset($notes[$matiere["@attributes"]["L"]])) {
					$notes[$matiere["@attributes"]["L"]]["prof"] .= " & " . $matiere["Professeur"]["@attributes"]["L"];
				} else {
					$notes[$matiere["@attributes"]["L"]] = array(
						"prof" => $matiere["Professeur"]["@attributes"]["L"],
						"coefficient" => Util::tofloat($matiere["Coefficient"]),
						"devoirs" => array(),
						"hasMean" => 0
					);			
				}

				if (is_object($matiere["Devoir"][0]) || is_array($matiere["Devoir"][0])) {				
					$count = 0;
					$totalCoeff = [];
					foreach ($matiere["Devoir"] as $devoir) {
						$note = "0";
						if ($devoir["Note"] == "|1") $note = "Abs.";
						else if ($devoir["Note"] == "|3") $note = "N.Noté";
						else {
							$note = Util::tofloat($devoir["Note"]);
							$count += Util::tofloat($devoir["Coefficient"]);
							$notes_coeffs[] = array(
								"note" => ($note * 20 / Util::tofloat($devoir["Bareme"])),
								"coeff" => Util::tofloat($devoir["Coefficient"])
							);
							$total += Util::tofloat($devoir["Coefficient"]);
							$totalCoeff[] = Util::tofloat($devoir["Coefficient"]) * ($note * 20 / Util::tofloat($devoir["Bareme"]));
						}

						$corrige = null;			

						if (isset($devoir["LibelleCorrige"])) {
							$corrige = generateURLFichierExterne($devoir["LibelleCorrige"], array("N" => $devoir["@attributes"]["N"], "G" => 60 /* 60 = EGenreRessource.Devoir*/));
						}

						array_push($notes[$matiere["@attributes"]["L"]]["devoirs"], array(
							"date" => $devoir["Date"],
							"bareme" => Util::tofloat($devoir["Bareme"]),
							"code" => $devoir["@attributes"]["N"],
							"note" => $note,
							"commentaire" => (is_string($devoir["Commentaire"]) ? $devoir["Commentaire"] : ""),
							"coeff" => Util::tofloat($devoir["Coefficient"]),
							"corrige" => $corrige
						));
					}

					$totalSum = 0;
					foreach ($totalCoeff as $note) {
						$totalSum += $note;
					}
					if ($count == 0) {
						$notes[$matiere["@attributes"]["L"]]["hasMean"] = false;
						$notes[$matiere["@attributes"]["L"]]["moyenne"] = "-";
					} else {
						$notes[$matiere["@attributes"]["L"]]["hasMean"] = true;
						$notes[$matiere["@attributes"]["L"]]["moyenne"] = $totalSum / (float)$count;
					}
				} else if (isset($matiere["Devoir"])) {
					$count = 0;
					$totalCoeff = [];
					$devoir = $matiere["Devoir"];

					$note = "0";
					if ($devoir["Note"] == "|1") $note = "Abs.";
					else if ($devoir["Note"] == "|3") $note = "N.Noté";
					else {
						$note = Util::tofloat($devoir["Note"]);
						$count += Util::tofloat($devoir["Coefficient"]);
						$notes_coeffs[] = array(
							"note" => ($note * 20 / Util::tofloat($devoir["Bareme"])),
							"coeff" => Util::tofloat($devoir["Coefficient"])
						);
						$total += Util::tofloat($devoir["Coefficient"]);
						$totalCoeff[] = Util::tofloat($devoir["Coefficient"]) * ($note * 20 / Util::tofloat($devoir["Bareme"]));
					}

					$corrige = null;			

					if (isset($devoir["LibelleCorrige"])) {
						$corrige = $this->generateURLFichierExterne($devoir["LibelleCorrige"], array("N" => $devoir["@attributes"]["N"], "G" => 60 /* 60 = EGenreRessource.Devoir*/));
					}

					array_push($notes[$matiere["@attributes"]["L"]]["devoirs"], array(
						"date" => $devoir["Date"],
						"bareme" => Util::tofloat($devoir["Bareme"]),
						"code" => $devoir["@attributes"]["N"],
						"note" => $note,
						"commentaire" => (is_string($devoir["Commentaire"]) ? $devoir["Commentaire"] : ""),
						"coeff" => Util::tofloat($devoir["Coefficient"]),
						"corrige" => $corrige
					));

					$totalSum = 0;
					foreach ($totalCoeff as $note) {
						$totalSum += $note;
					}
					if ($count == 0) {
						$notes[$matiere["@attributes"]["L"]]["hasMean"] = false;
						$notes[$matiere["@attributes"]["L"]]["moyenne"] = "-";
					} else {
						$notes[$matiere["@attributes"]["L"]]["hasMean"] = true;
						$notes[$matiere["@attributes"]["L"]]["moyenne"] = $totalSum / (float)$count;
					}
				}
			}

			$espe = 0;
			$variance = 0;
			foreach ($notes_coeffs as $note_coeff) {
				$espe += $note_coeff["note"] * ($note_coeff["coeff"]/$total);
				$variance += ($note_coeff["note"]**2) * ($note_coeff["coeff"]/$total);
			}
			$variance -= $espe**2;
			$ecart_type = sqrt($variance);

			$sum = 0;
			$sum_coeff = 0;
			foreach ($notes as $matiere => $data) {
				if ($data["hasMean"]) {
					$sum += $data["moyenne"]*$data["coefficient"];
					$sum_coeff += $data["coefficient"];
				}
			}

			sort($allNotes);
			$notes["meta"] = array(
				"moyenne" => $espe,
				"ecartType" => $ecart_type
			);

			if (empty($notes) && !$this->customPeriode) {
				$key = array_search($this->userData["periode"], array_column($this->userData["periodes"], 'code'));
				if ($key > 1) $key--;
				$this->userData["periode"] = $this->userData["periodes"][$key]["code"];
				return getReleveNoteData();
			} else {
				return array(
					"status" => 1,
					"data" => $notes
				);
			}
		} else {
			return array(
				"status" => 0,
				"message" => "Une erreur est survenue lors de l'accès aux informations de base"
			); 
		}
	}

	/**
	 * Récupère l'emploi du temps de la semaine, encore très incomplet.
	 * @return Array Voir la documentation complète pour avoir le détail du format
	 */
	public function getEDTData() {
		$date = new DateTime();
		$date = $date->createFromFormat("j/m/Y", $_SESSION["userData"]["premierePeriode"]);
		$weeks = $date->diff(new DateTime());
		$post = array(
			"_Signature_" => array(
				"onglet" => 16
			),
			"xml" => '<PageEmploiDuTemps><Ressource G="4" N="' . $this->userData["code"] . '" L="' . $this->userData["nom"] . '" E="0"/><NumeroSemaine T="1">' . (int)(($weeks->days) / 7 + 1) . '</NumeroSemaine></PageEmploiDuTemps>'
		);
		$request = $this->navigateNCall("PageEmploiDuTemps", 16, $post);

		if ($request["status"] == 0) {
			return array(
				"status" => 0,
				"message" => $request["message"]
			);
		}
		$json = $request["data"];

		if ($json != null) {
			$data = array();
			foreach ($json["donneesSec"]["donnees"]["ListeCours"] as $cours) {
				$date = new DateTime();
				$date = $date->createFromFormat("j/m/Y H:i:s", $cours["DateDuCours"]["V"]);
				$profs = array();
				$salles = array();
				foreach ($cours["Contenus"] as $contenu) {
					if ($contenu["G"] == 3) array_push($profs, $contenu["L"]);
					if ($contenu["G"] == 17) array_push($salles, $contenu["L"]);
				}
				array_push($data, array(
					"matiere" => $cours["Contenus"][0]["L"],
					"profs" => $profs,
					"salles" => $salles,
					"date" => $date,
				));
			}

			usort($data, function($a, $b) {
			    return $b['date'] < $a['date'];
			});
			setlocale(LC_ALL, array('fr_FR.UTF-8','fr_FR@euro','fr_FR','french'));

			$planning = array();
			$currentDate = -1;
			foreach ($data as $cours) {
				if ($currentDate == -1) $currentDate = date('w', $cours["date"]->getTimestamp());
				else if ($currentDate != date('w', $cours["date"]->getTimestamp())) $currentDate = date('w', $cours["date"]->getTimestamp());
				
				if (!is_array($planning[ucfirst(strftime('%A %e %B', $cours["date"]->getTimestamp()))])) $planning[ucfirst(strftime('%A %e %B', $cours["date"]->getTimestamp()))] = array();
				array_push($planning[ucfirst(strftime('%A %e %B', $cours["date"]->getTimestamp()))], $cours);
			}

			return array(
				"status" => 1,
				"data" => $planning
			);
		} else {
			return array(
				"status" => 0,
				"message" => "Une erreur est survenue lors de l'accès aux informations de base"
			); 
		}
	}

	/**
	 * Récupère le graphique du profil.
	 * @return Array Voir la documentation complète pour avoir le détail du format
	 */
	public function getProfileGraphData() {
		$key = array_search($this->userData["periode"], array_column($this->userData["periodes"], 'code'));
		$post = array(
			"_Signature_" => array(
				"onglet" => 111
			),
			"donnees" => array(
				"accepteBase64" => true,
				"eleve" => array(
					"G" => 4,
					"L" => $this->userData["nom"],
					"N" => $this->userData["code"]
				),
				"periode" => array(
					"G" => 2,
					"L" => $this->userData["periodes"][$key]["nom"],
					"N" => $this->userData["periodes"][$key]["code"]
				),
				"hauteur" => 847,
				"largeur" => 1080
			)
		);
		$request = $this->navigateNCall("Graphique", 111, $post);

		if ($request["status"] == 0) {
			return array(
				"status" => 0,
				"message" => $request["message"]
			);
		}
		$json = $request["data"];
		
		if ($json != null) {
			$fichiers = (array)(@$json["donneesNonSec"]["fichiers"]);
			if ($fichiers == null || empty($fichiers)) {
				$key = array_search($this->userData["periode"], array_column($this->userData["periodes"], 'code'));
				if ($key > 1 && !$this->customPeriode) {
					$key--;
					$this->userData["periode"] = $this->userData["periodes"][$key]["code"];
					return getProfileGraphData();
				} else {
					return array(
						"status" => 0,
						"message" => "Une erreur est survenue lors de l'accès aux informations de base"
					); 
				}
			} else {
				return array(
					"status" => 1,
					"data" => $fichiers[0]
				);
			}
		} else {
			return array(
				"status" => 0,
				"message" => "Une erreur est survenue lors de l'accès aux informations de base"
			); 
		}
	}

	/**
	 * Récupère le graphique d'évolution du profil.
	 * @return Array Voir la documentation complète pour avoir le détail du format
	 */
	public function getProfileEvolData() {
		$post = array(
			"_Signature_" => array(
				"onglet" => 112
			),
			"donnees" => array(
				"accepteBase64" => true,
				"eleve" => array(
					"G" => 4,
					"L" => $this->userData["nom"],
					"N" => $this->userData["code"]
				),
				"periode" => null,
				"hauteur" => 813,
				"largeur" => 1800
			)
		);
		$request = $this->navigateNCall("Graphique", 112, $post);

		if ($request["status"] == 0) {
			return array(
				"status" => 0,
				"message" => $request["message"]
			);
		}
		$json = $request["data"];

		if ($json != null) {
			$fichiers = (array)(@$json["donneesNonSec"]["fichiers"]);
			if ($fichiers == null || empty($fichiers)) {
				return array(
					"status" => 0,
					"message" => "Une erreur est survenue lors de l'accès aux informations de base"
				); 
			} else {
				return array(
					"status" => 1,
					"data" => $fichiers[0]
				);
			}
		} else {
			return array(
				"status" => 0,
				"message" => "Une erreur est survenue lors de l'accès aux informations de base"
			); 
		}
	}

	/**
	 * Récupère les ressources pédagogiques déposée.
	 * @return Array Voir la documentation complète pour avoir le détail du format
	 */
	public function getPedaRes() {
		$post = array(
			"_Signature_" => array(
				"onglet" => 99
			),
			"donnees" => array(
				"avecRessourcesEditeur" => true,
				"avecRessourcesPronote" => true
			)
		);
		$request = $this->navigateNCall("RessourcePedagogique", 99, $post);

		if ($request["status"] == 0) {
			return array(
				"status" => 0,
				"message" => $request["message"]
			);
		}
		$json = $request["data"];

		if ($json != null) {
			$idToMatiereMapping = array();
			foreach ($json["donneesSec"]["donnees"]["listeMatieres"]["V"] as $matiere) {
				$idToMatiereMapping[$matiere["N"]] = $matiere["L"];
			}

			$documentToMatiereMapping = array();
			foreach ($json["donneesSec"]["donnees"]["listeRessources"]["V"] as $ressource) {
				$matiereName = $idToMatiereMapping[$ressource["matiere"]["V"]["N"]];
				$documentToMatiereMapping[$matiereName][] = array(
					"date" => $ressource["date"]["V"],
					"type" => ($ressource["G"] == 1 ? "lien" : "doc"),
					"nom" => $ressource["ressource"]["V"]["L"],
					"id" => $ressource["ressource"]["V"]["N"],
					"url" => $this->generateURLFichierExterne($ressource["ressource"]["V"]["L"], array("N" => $ressource["ressource"]["V"]["N"], "G" => $ressource["ressource"]["V"]["G"]))
				);
			}
		
			return array(
				"status" => 1,
				"data" => $documentToMatiereMapping
			);
		} else {
			return array(
				"status" => 0,
				"message" => "Une erreur est survenue lors de l'accès aux informations de base"
			); 
		}
	}

	/**
	 * Récupère l'actualité postée.
	 * @return Array Voir la documentation complète pour avoir le détail du format
	 */
	public function getInfoNews() {
		$post = array(
			"_Signature_" => array(
				"onglet" => 8
			),
			"donnees" => array(
				"estAuteur" => false
			)
		);
		$request = $this->navigateNCall("PageActualites", 8, $post);

		if ($request["status"] == 0) {
			return array(
				"status" => 0,
				"message" => $request["message"]
			);
		}
		$json = $request["data"];
		
		if ($json != null) {
			$actualites = array();
			foreach ($json["donneesSec"]["donnees"]["listeActualites"]["V"] as $news) {
				$pj = [];
				if (!empty($news["listeDocumentsJoints"]["V"])) {
					foreach ($news["listeDocumentsJoints"]["V"] as $doc) {
						$pj[] = array(
							"nom" => $doc["L"],
							"lien" => $this->generateURLFichierExterne($doc["L"], array("N" => $doc["N"], "G" => 50))
						);
					}
				}

				$actualites[] = array(
					"titre" => ($news["L"] == "" ? "Aucun titre" : $news["L"]),
					"date" => $news["dateDebut"]["V"],
					"categorie" => $news["categorie"]["V"]["L"],
					"message" => $news["message"]["V"],
					"pj" => $pj
				);
			}
		
			$actualites = array_reverse($actualites);
			return array(
				"status" => 1,
				"data" => $actualites
			);
		} else {
			return array(
				"status" => 0,
				"message" => "Une erreur est survenue lors de l'accès aux informations de base"
			); 
		}
	}
}









/**
 * L'ensemble des fonctions liées à la cryptographie
 */
Class Crypto {
	/**
	 * Décrypte les données fournies, sachant qu'elles sont sous la forme MD5+data et que les données résultantes sont compressées avec GZip.
	 * @param  $string, les données d'entrée
	 * @param  $cle, la clé de cryptage
	 * @param  $iv, l'IV AES
	 * @return Le chaîne décryptée
	 */
	public static function decryptAESWithMD5WithGzip($string, $cle, $iv) {
		$decrypted = Crypto::decryptAES($string, $cle, $iv, false);
		return gzinflate($decrypted);
	}

	/**
	 * Crypte la chaîne fournie en entrée après l'avoir compressée avec GZIP.
	 * @param  $string, la chaîne à crypter
	 * @param  $cle, la clé de cryptage
	 * @param  $iv, l'IV AES
	 * @return La chaîne compressée avec GZip et cryptée selon les paramètres fournis, sous la forme MD5+data. N.B: ici, MD5 est le MD5 de $string+$cle
	 */
	public static function encryptAESWithMD5WithGzip($string, $cle, $iv) {
		$encrypted = Crypto::encryptAESRaw(gzdeflate(bin2hex($string)), $cle, $iv);
		return strtoupper(md5($string . $cle)) . $encrypted;
	}

	/**
	 * Crypte la chaîne fournie selon un simple crypage AES
	 * @param  $string, la chaîne à crypter
	 * @param  $cle, la clé de cryptage
	 * @param  $iv, l'IV AES
	 * @return La chaîne cryptée selon les paramètres fournis, sous la forme MD5+data
	 */
	public static function encryptAESWithMD5($string, $cle, $iv) {
		$encrypted = Crypto::encryptAES($string, $cle, $iv);
		return strtoupper(md5($string)) . $encrypted;
	}

	/**
	 * Le numéro d'ordre apparaît dans de nombreuses parties des appels pronote. Il est par exemple essentiel pour les requêtes "AJAX". Il s'agit en fait d'un cryptage AES normal.
	 * @param  $string, la chaîne à crypter
	 * @param  $cle, la clé de cryptage
	 * @param  $iv, l'IV AES
	 * @param  $utf8, voir la doc de encryptAES
	 * @param  $utf8cle, si true, la clé est encodée en utf8 avant le calcul du MD5
	 * @return Le numéro d'ordre correspondant au paramètres fournis.
	 */
	public static function generateNumeroOrdre($string, $cle, $iv, $utf8 = false, $utf8cle = false) {
		$encrypted = Crypto::encryptAES($string, $cle, $iv, $utf8);
		if ($utf8cle) $cle = utf8_encode($utf8cle);
		return strtoupper(md5($string . $cle)) . $encrypted;
	}


	/**
	 * @param  $string, la chaîne à crypter
	 * @param  $cle, la clé à utiliser pour crypter
	 * @param  $iv, l'IV à utiliser pour crypter
	 * @param  $utf8, si true, la chaîne ne sera pas encodée en utf8 avant cryptage
	 * @return La chaîne cryptée selon les paramètres fournis, sous la forme d'une chaîne hexadécimale
	 */
	public static function encryptAES($string, $cle, $iv, $utf8 = false) {
		$cle = pack("H*", md5($cle));
		$iv = $iv == "" ? "" : pack("H*", md5(utf8_encode($iv)));
		if (!$utf8) $string = utf8_encode($string);
		return bin2hex(@openssl_encrypt($string, "aes-128-cbc", $cle, OPENSSL_RAW_DATA, $iv)); // Le @ pour ignorer les avertissement permet d'éviter les problème face à l'utilisation d'un IV vide
	}

	/*
		Simple wrapper pour le cryptage de données brutes
	 */
	public static function encryptAESRaw($string, $cle, $iv) {
		return Crypto::encryptAES($string, $cle, $iv, true);
	}

	/**
	 * @param  $string, la chaîne à décrypter
	 * @param  $cle, la clé à utiliser
	 * @param  $iv, l'IV à utiliser
	 * @param  $alea, si true, la chaîne a été cryptée avec ajout d'aléatoire et celui-ci sera retiré, si false, on ne modifie rien
	 * @param  $utf8, si true et qu'on a de l'aléatoire, permet de gérer les caractères multibyte pour enlever les caractères aléatoires
	 * @param  $bytes, si true, la chaîne cryptée était une suite de bytes, elle est reconvertie en chaîne normale
	 * @return La chaîne décryptée selon les paramètres fournis
	 */
	public static function decryptAES($string, $cle, $iv, $alea, $utf8 = false, $bytes = false) {
		$encrypted = substr($string, 32);
		$md5 = substr($string, 0, 32);

		$cle = pack("H*", md5($cle));
		$iv = $iv == "" ? "" : pack("H*", md5(utf8_encode($iv)));
		$decrypted = (string)openssl_decrypt(hex2bin($encrypted), "aes-128-cbc", $cle, OPENSSL_RAW_DATA, $iv);

		$returnValue = "";
		if ($alea) {
			$newDecrypted = array();
			if ($utf8) {
				$arr = preg_split('//u', $decrypted, -1, PREG_SPLIT_NO_EMPTY);
				$i = 0;
				foreach ($arr as $letter) {
					if ($i % 2 == 0) array_push($newDecrypted, $letter);
					$i++;
				}
			} else {
				$length = strlen($decrypted);
				for ($i = 0; $i < $length; $i++) {
					if ($i %2 == 0) array_push($newDecrypted, $decrypted{$i});
				}
			}

			$returnValue =  implode('', $newDecrypted);
		} else $returnValue = $decrypted;

		if ($bytes) {
			$input = explode(",", $decrypted);
			$output = '';
			for ($i = 0, $j = count($input); $i < $j; ++$i) {
				$output .= chr($input[$i]);
			}
			$returnValue = $output;
		}

		return $returnValue;
	}

	/**
	 * @param  $string, chaîne à crypter
	 * @param  $mod, le mod du cryptage RSA
	 * @param  $exp, l'exposant du cryptage RSA
	 * @return La chaîne cryptée selon les paramètres fournis, encodée en base64 et coupée tous les 64 bytes par deux caractères \r\n
	 */
	public static function encryptRSA($string, $mod, $exp) {
		$rsa = new Crypt_RSA(); 
		$rsa->loadKey(
			array(
				'e' => new Math_BigInteger($exp, 16),
				'n' => new Math_BigInteger($mod, 16)
			)
		);
		$rsa->setEncryptionMode(CRYPT_RSA_ENCRYPTION_PKCS1);
		return trim(chunk_split(base64_encode($rsa->encrypt($string)), 64, "\r\n"));
	}
}


/**
 * Fonctions utiles
 */
class JsParserException extends Exception {}
Class Util {
	/**
	 * Génère une nombre donné de bytes pseudo-alétoirement. La doc php spécifie que cette fonction est sûre pour une utilisation cryptographique.
	 * @param  $len, le nombre de bytes à générer
	 * @return $len bytes générés pseudo-aléatoirement
	 */
	public static function randomStr($len = 16) {
		$bytes = bin2hex(random_bytes($len));
		if (isset($DEBUG) && $DEBUG == true) echo $bytes . "\n";
		return $bytes;
	}

	/**
	 * Convertit un nombre en flottant depuis une représentation en chaîne.
	 * @param  $num, le nombre sous la forme d'une chaîne
	 * @return Le nombre passé en chaîne converti en flottant
	 */
	public static function tofloat($num) {
		$dotPos = strrpos($num, '.');
		$commaPos = strrpos($num, ',');
		$sep = (($dotPos > $commaPos) && $dotPos) ? $dotPos : 
			((($commaPos > $dotPos) && $commaPos) ? $commaPos : false);
	   
		if (!$sep) {
			return floatval(preg_replace("/[^0-9]/", "", $num));
		} 

		return floatval(
			preg_replace("/[^0-9]/", "", substr($num, 0, $sep)) . '.' .
			preg_replace("/[^0-9]/", "", substr($num, $sep+1, strlen($num)))
		);
	}

	/*
	 * Merci StackOverflow. Un simple coup de regex aurait sans doute suffit pour parser les paramètres, mais au moins avec ça on est sûr.
	 */
	public static function parse_jsobj($str, &$data) {
		$str = trim($str);
		if(strlen($str) < 1) return;

		if($str{0} != '{') {
			throw new JsParserException('The given string is not a JS object');
		}
		$str = substr($str, 1);

		/* While we have data, and it's not the end of this dict (the comma is needed for nested dicts) */
		while(strlen($str) && $str{0} != '}' && $str{0} != ',') { 
			/* find the key */
			if($str{0} == "'" || $str{0} == '"') {
				/* quoted key */
				list($str, $key) = Util::parse_jsdata($str, ':');
			} else {
				$match = null;
				/* unquoted key */
				if(!preg_match('/^\s*[a-zA-z_][a-zA-Z_\d]*\s*:/', $str, $match)) {
				throw new JsParserException('Invalid key ("'.$str.'")');
				}	
				$key = $match[0];
				$str = substr($str, strlen($key));
				$key = trim(substr($key, 0, -1)); /* discard the ':' */
			}

			list($str, $data[$key]) = Util::parse_jsdata($str, '}');
		}
		"Finshed dict. Str: '$str'\n";
		return substr($str, 1);
	}

	private static function comma_or_term_pos($str, $term) {
		$cpos = strpos($str, ',');
		$tpos = strpos($str, $term);
		if($cpos === false && $tpos === false) {
			throw new JsParserException('unterminated dict or array');
		} else if($cpos === false) {
			return $tpos;
		} else if($tpos === false) {
			return $cpos;
		}
		return min($tpos, $cpos);
	}

	private static function parse_jsdata($str, $term="}") {
		$str = trim($str);


		if(is_numeric($str{0}."0")) {
			/* a number (int or float) */
			$newpos = Util::comma_or_term_pos($str, $term);
			$num = trim(substr($str, 0, $newpos));
			$str = substr($str, $newpos+1); /* discard num and comma */
			if(!is_numeric($num)) {
				throw new JsParserException('OOPSIE while parsing number: "'.$num.'"');
			}
			return array(trim($str), $num+0);
		} else if($str{0} == '"' || $str{0} == "'") {
			/* string */
			$q = $str{0};
			$offset = 1;
			do {
				$pos = strpos($str, $q, $offset);
				$offset = $pos;
			} while($str{$pos-1} == '\\'); /* find un-escaped quote */
			$data = substr($str, 1, $pos-1);
			$str = substr($str, $pos);
			$pos = Util::comma_or_term_pos($str, $term);
			$str = substr($str, $pos+1);		
			return array(trim($str), $data);
		} else if($str{0} == '{') {
			/* dict */
			$data = array();
			$str = Util::parse_jsobj($str, $data);
			return array($str, $data);
		} else if($str{0} == '[') {
			/* array */
			$arr = array();
			$str = substr($str, 1);
			while(strlen($str) && $str{0} != $term && $str{0} != ',') {
				$val = null;
				list($str, $val) = Util::parse_jsdata($str, ']');
				$arr[] = $val;
				$str = trim($str);
			}
			$str = trim(substr($str, 1));
			return array($str, $arr);
		} else if(stripos($str, 'true') === 0) {
			/* true */
			$pos = Util::comma_or_term_pos($str, $term);
			$str = substr($str, $pos+1); /* discard terminator */
			return array(trim($str), true);
		} else if(stripos($str, 'false') === 0) {
			/* false */
			$pos = Util::comma_or_term_pos($str, $term);
			$str = substr($str, $pos+1); /* discard terminator */
			return array(trim($str), false);
		} else if(stripos($str, 'null') === 0) {
			/* null */
			$pos = Util::comma_or_term_pos($str, $term);
			$str = substr($str, $pos+1); /* discard terminator */
			return array(trim($str), null);
		} else if(strpos($str, 'undefined') === 0) {
			/* null */
			$pos = Util::comma_or_term_pos($str, $term);
			$str = substr($str, $pos+1); /* discard terminator */
			return array(trim($str), null);
		} else {
			throw new JsParserException('Cannot figure out how to parse "'.$str.'" (term is '.$term.')');
		}
	}
}

?>
