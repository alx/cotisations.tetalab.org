<?php
/* self_adherent.php
 * - Saisie d'un adh�rent par lui-m�me
 * Copyright (c) 2004 Fr�d�ric Jaqcuot, Georges Khaznadar
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 */

include("includes/config.inc.php");
include(WEB_ROOT."includes/database.inc.php"); 
include(WEB_ROOT."includes/functions.inc.php"); 
include(WEB_ROOT."includes/session.inc.php"); 
include_once(WEB_ROOT."includes/i18n.inc.php");
include_once(WEB_ROOT."includes/smarty.inc.php");
include(WEB_ROOT."includes/dynamic_fields.inc.php");

// initialize warnings
$error_detected = array();
$warning_detected = array();
//$confirm_detected = array();

// flagging required fields
	$required = array(
			'titre_adh' => 1,
			'nom_adh' => 1,
			'login_adh' => 1,
			'mdp_adh' => 1,
			'adresse_adh' => 1,
			'cp_adh' => 1,
			'ville_adh' => 1);
/**
* TODO
* - export to a class so users can dynamicaly modify this
*/
$disabled = array(
	//'titre_adh' => 'disabled',
	'id_adh' => 'disabled',
	//'nom_adh' => 'disabled',
	//'prenom_adh' => 'disabled',
	'date_crea_adh' => 'disabled',
	'id_statut' => 'disabled',
	'activite_adh' => 'disabled',
	'bool_exempt_adh' => 'disabled',
	'bool_admin_adh' => 'disabled',
	'date_echeance' => 'disabled',
	'info_adh' => 'disabled'
);

// DEBUT parametrage des champs
// On recupere de la base la longueur et les flags des champs
// et on initialise des valeurs par defaut

$update_string = '';
$insert_string_fields = '';
$insert_string_values = '';

$adherent['dyn'] = extract_posted_dynamic_fields($DB, $_POST, $disabled);

// checking posted values for 'regular' fields
if ( isset($_POST["valid"]) ) {
	//check fields goodness
	$fields = &$DB->MetaColumns(PREFIX_DB."adherents");
	while (list($key, $properties) = each($fields)) {
		$key = strtolower($key);
		if (isset($_POST[$key]))
			$value = trim($_POST[$key]);
		else
			$value = '';
		// if the field is enabled, check it
		if (!isset($disabled[$key]))
		{
			// fill up the adherent structure
			$adherent[$key] = htmlentities(stripslashes($value),ENT_QUOTES);

			// now, check validity
			if ($value != ""){
				switch ($key)
				{
					// dates
					case 'ddn_adh':
						if (preg_match("@^([0-9]{2})/([0-9]{2})/([0-9]{4})$@", $value, $array_jours))
						{
							if (checkdate($array_jours[2],$array_jours[1],$array_jours[3]))
								$value = $DB->DBDate($array_jours[3].'-'.$array_jours[2].'-'.$array_jours[1]);
							else
								$error_detected[] = _T("- Non valid date!");
						}
						else
							$error_detected[] = _T("- Wrong date format (dd/mm/yyyy)!");
						break;
					case 'email_adh':
					case 'msn_adh':
						if (!is_valid_email($value))
							$error_detected[] = _T("- Non-valid E-Mail address!")." (".$key.")";
						break;
					case 'url_adh':
						if (!is_valid_web_url($value))
							$error_detected[] = _T("- Non-valid Website address! Maybe you've skipped the http:// ?");
						elseif ($value=='http://')
							$value = '';
						break;
					case 'login_adh':
						if (strlen($value)<4)
							$error_detected[] = _T("- The username must be composed of at least 4 characters!");
						else
						{
							//check if login is already taken
							$requete = "SELECT id_adh FROM ".PREFIX_DB."adherents WHERE login_adh=". $DB->qstr($value, get_magic_quotes_gpc());
							if (isset($adherent['id_adh']) && $adherent['id_adh'] != '')
								$requete .= " AND id_adh!=" . $DB->qstr($adherent['id_adh'], get_magic_quotes_gpc());
							$result = &$DB->Execute($requete);
							if (!$result->EOF || $value==PREF_ADMIN_LOGIN)
								$error_detected[] = _T("- This username is already used by another member !");
						}
						break;
					case 'mdp_adh':
						if( !PasswordCheck($_POST["mdp_adh"],$_POST["mdp_crypt"]) ) {
							$error_detected[] = _T("Password misrepeated: ");
						} elseif (strlen($value)<4) {
							$error_detected[] = _T("- The password must be of at least 4 characters!");
						} else {
							// md5sum du mot de passe
							// On garde le mot en clair pour le mail et le template
							$adherent['mdp_adh_plain'] = $adherent['mdp_adh'];
							$adherent['mdp_adh'] = md5($adherent['mdp_adh']);
							$value = $adherent["mdp_adh"];
							break;
						}

						// dates already quoted
						if ($key=='date_crea_adh' || $key=='ddn_adh')
						{
							if ($value=='')
							$value='null';
						}
						else
							$value = $DB->qstr($value, get_magic_quotes_gpc());
				}

				$update_string .= ", ".$key."=".$value;
				$insert_string_fields .= ", ".$key;
				$insert_string_values .= ($key=='ddn_adh')?", ".$value:", ".$DB->qstr($value, get_magic_quotes_gpc());

			}
		}
	}

	// missing required fields?
	while (list($key,$val) = each($required)){
		if (!isset($disabled[$key]) && (!isset($adherent[$key]) || trim($adherent[$key])==''))
			$error_detected[] = _T("- Mandatory field empty.")." ($key)";
	}

	if (count($error_detected)==0) {
		$date_crea_adh = date("Y-m-d");
		$insert_string_fields .= ",date_crea_adh";
		$insert_string_values .= ",'".$date_crea_adh."'";
		$requete = "INSERT INTO ".PREFIX_DB."adherents (" . substr($insert_string_fields,1) . ") VALUES (" . substr($insert_string_values,1) . ")";
		/** For debug **/
		//echo "query will be :<br />".$requete."<br />";
		if (!$DB->Execute($requete))
			print substr($insert_string_values,1).": ".$DB->ErrorMsg();
		dblog("Self_subscription as a member:"." ".strtoupper($adherent["nom_adh"])." ".$adherent["prenom_adh"], $requete);
		//$adherent['id_adh'] = get_last_auto_increment($DB, PREFIX_DB."adherents", "id_adh");

		// il est temps d'envoyer un mail
		if ($adherent['email_adh']!="") {
				//$mail_headers = "From: ".PREF_EMAIL_NOM." <".PREF_EMAIL.">\nContent-Type: text/plain; charset=iso-8859-15\n";
			$mail_subject = _T("Your Galette identifiers");
			$mail_text =  _T("Hello,")."\n";
			$mail_text .= "\n";
			$mail_text .= _T("You've just been subscribed on the members management system of the association.")."\n";
			$mail_text .= _T("It is now possible to follow in real time the state of your subscription")."\n";
			$mail_text .= _T("and to update your preferences from the web interface.")."\n";
			$mail_text .= "\n";
			$mail_text .= _T("Please login at this address:")."\n";
			$mail_text .= "http://".$_SERVER["SERVER_NAME"].dirname($_SERVER["REQUEST_URI"])."\n";
			$mail_text .= "\n";
			$mail_text .= _T("Username:")." ".custom_html_entity_decode($adherent['login_adh'])."\n";
			$mail_text .= _T("Password:")." ".custom_html_entity_decode($adherent['mdp_adh_plain'])."\n";
			$mail_text .= "\n";
			$mail_text .= _T("See you soon!")."\n";
			$mail_text .= "\n";
			$mail_text .= _T("(this mail was sent automatically)")."\n";
			/** For debug **/
			//echo "mail content (send to ".$_POST['email_adh']." with subject '".$mail_subject."') will be :<br />".$mail_text."<br />";
			$mail_result = custom_mail ($_POST['email_adh'],$mail_subject,$mail_text);

			/** TODO
			* Send email to admin(s?) to inform a new account has been created.
			* Btw, emailing admin(s?) should be added in the preferences
			*/

			if( $mail_result == 1) { //check if mail was successfully send
				dblog("Self subscribe - Send subscription mail to:".$adherent["email_adh"], $requete);
				$warning_detected[] = _T("Password sent. Login:")." \"" . $adherent["login_adh"] . "\"";
			}else{ //warn user if not
				switch ($mail_result) {
					case 2 :
						dblog("Self subscribe - Email sent is disabled in the preferences. Ask galette admin.");
						$warning_detected[] = _T("Email sent is disabled in the preferences. Ask galette admin.");
						break;
					case 3 :
						dblog("Self subscribe - A problem happened while sending password for account:"." \"" . $adherent["email_adh"] . "\"");
						$warning_detected[] = _T("A problem happened while sending password for account:"." \"" . $adherent["email_adh"] . "\".");
						break;
					case 4 :
						dblog("Self subscribe - The mail server filled in the preferences cannot be reached. Ask Galette admin");
						$warning_detected[] = _T("The mail server filled in the preferences cannot be reached. Ask Galette admin.");
						break;
					default :
						dblog("A problem happened while sending password for account:"." \"" . $adherent["email_adh"] . "\"");
						$warning_detected[] = _T("A problem happened while sending password for account:"." \"" . $adherent["email_adh"] . "\"");
					break;
				}
			}
		}

		$head_redirect = "<meta http-equiv=\"refresh\" content=\"10;url=index.php\" />";

		// dynamic fields
		/*set_all_dynamic_fields($DB, 'adh', $adherent['id_adh'], $adherent['dyn']);

		// deadline
		$date_fin = get_echeance($DB, $adherent['id_adh']);
		if ($date_fin!="")
			$date_fin_update = $DB->DBDate($date_fin[2].'-'.$date_fin[1].'-'.$date_fin[0]);
		else
			$date_fin_update = "NULL";
		$requete = "UPDATE ".PREFIX_DB."adherents SET date_echeance=".$date_fin_update." WHERE id_adh=" . $adherent['id_adh'];
		if ( $DB->Execute($requete) ){
			$warning_detected[] = _T("Inscription sent to the administrator for approval");
			$head_redirect = "<meta http-equiv=\"refresh\" content=\"10;url=index.php\" />";
		}*/
	}
}elseif($_POST["update_lang"] == 1){
	while (list($key, $properties) = each($fields)) {
		$key = strtolower($key);
		if (isset($_POST[$key]))
			$adherent[$key] = trim($_POST[$key]);
		else
			$adherent[$key] = '';
	}
} else {
	// initialiser la structure adh�ent �vide (nouvelle fiche)
	$adherent["id_statut"] = "4";
	$adherent["titre_adh"] = "1";
	$adherent["date_crea_adh"] =date("d/m/Y");
	#annoying
	#$adherent["url_adh"] = "http://";
	$adherent["url_adh"] = "";
	$adherent["pref_lang"] = PREF_LANG;
}


// - declare dynamic fields for display
$disabled['dyn'] = array();
if (!isset($adherent['dyn']))
	$adherent['dyn'] = array();

//image to defeat mass filling forms
$spam_pass=PasswordImage();
$s=PasswordImageName($spam_pass);
$spam_img = print_img($s);

$dynamic_fields = prepare_dynamic_fields_for_display($DB, 'adh', $_SESSION["admin_status"], $adherent['dyn'], $disabled['dyn'], 1);

// template variable declaration
$tpl->assign("spam_pass",$spam_pass);
$tpl->assign("spam_img",$spam_img);
$tpl->assign("required",$required);
$tpl->assign("disabled",$disabled);
$tpl->assign("data",$adherent);
$tpl->assign("time",time());
$tpl->assign("dynamic_fields",$dynamic_fields);
$tpl->assign("error_detected",$error_detected);
$tpl->assign("warning_detected",$warning_detected);
$tpl->assign("languages",drapeaux());
$tpl->assign("head_redirect", $head_redirect);


// pseudo random int
$tpl->assign("time",time());

// genre
$tpl->assign('radio_titres', array(
		3 => _T("Miss"),
		2 => _T("Mrs"),
		1 => _T("Mister"),
		4 => _T("Society")));

// display page
$tpl->display("self_adherent.tpl");
?>
