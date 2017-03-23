<?php
/**
 * Script de test de la classe pronote.php. Usage: php test.php <lien>
 */
include_once "pronote.php";
//$DEBUG = true;

$stdin = fopen ("php://stdin","r");

echo "Pseudo: ";
$user = trim(fgets($stdin));
echo "Mot de passe: ";
$passwd = trim(fgets($stdin));

$pronote = new Pronote();
$login = $pronote->login($user, $passwd, false, $argv[1]);
print_r($login);
if ($login["status"] == 1) {
	print_r($pronote->getHomePageData());
	print_r($pronote->getReleveNoteData());
}
?>