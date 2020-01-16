<?php
/*
 *
 * OGP - Open Game Panel
 * Copyright (C) 2008 - 2017 The OGP Development Team
 *
 * http://www.opengamepanel.org/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or any later version.
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

chdir(realpath(dirname(__FILE__))); /* Change to the current file path */
chdir("../.."); /* Base path to ogp web files */
// Report all PHP errors
error_reporting(E_ALL);
// Path definitions
define("CONFIG_FILE","includes/config.inc.php");
//Requiere
require_once("includes/functions.php");
require_once("includes/helpers.php");
require_once("includes/html_functions.php");
require_once("modules/config_games/server_config_parser.php");
require_once("includes/lib_remote.php");
require_once CONFIG_FILE;
// Connect to the database server and select database.
$db = createDatabaseConnection($db_type, $db_host, $db_user, $db_pass, $db_name, $table_prefix);

$panel_settings = $db->getSettings();
if( isset($panel_settings['time_zone']) && $panel_settings['time_zone'] != "" )
        date_default_timezone_set($panel_settings['time_zone']);


//set the dates to compare to db end_dates
//end_date we create an invoice
//invoice created = -1
//server suspended (past finish_date) -2
//server removed ( 7 days past finish date) -99
//server renewed (not used in this file) -3
//finish_date we suspend the server
//finish_date + 7 days we delete the server
$today=time();
Echo $today;
$rundate = date('d/M/y G:i',$today);
//did the server expire(finish_date) 7 days ago?
$removal_date = strtotime('- 7 days');

//Server has expired, end_date not -1
//Set the finish date = to end_date
//Set end_date = -1 which enabled invoice
$user_homes = $db->resultQuery( "SELECT *
                                 FROM " . $table_prefix .  "billing_orders
                                 WHERE end_date > 0 AND end_date < ".$today );

if (!is_array($user_homes))
{
}
else
{
        foreach($user_homes as $user_home)
        {

				$user_id = $user_home['user_id'];
                $home_id = $user_home['home_id'];
               
                // Reset the invoice end date -1 so cart.php will create an invoice
				$db->query( "UPDATE " . $table_prefix . "billing_orders
                                         SET end_date=-1
                                         WHERE order_id=".$db->realEscapeSingle($user_home['order_id']));

				// SEND EMAIL
					$settings = $db->getSettings();
					$subject = "Servidor a expirar";
				    $email = $db->resultQuery("   SELECT DISTINCT users_email
									   FROM " . $table_prefix .  "users, " . $table_prefix .  "billing_orders
									   WHERE " . $table_prefix .  "users.user_id = $user_id")[0]["users_email"];
									   
				    $message = "Servidor com o ID ". $home_id . " expira daqui a 7 dias. Faça login no game painel e VEJA as FATURAS para renovar seu servidor.
					 <br>Renove o servidor antes que ele expire e seja removido.
                     <br> Atenção não vai receber mais nenhuma notificação!!<br>
                     <br>~ <br> Obrigado!
                     <br> Atentamente equipa de staff.<br>
                     <br> Esta é uma mensagem automática, por favor, não responda!";
				    $mail = mymail($email, $subject, $message, $settings);
				    if ($mail)
						file_put_contents ( "/var/www/html/modules/billing/BILLING.LOG" ," Fatura criada $home_id, Email sent\n",FILE_APPEND);
					else
						file_put_contents ( "/var/www/html/modules/billing/BILLING.LOG" ," Fatura criada $home_id, Email FAILED\n",FILE_APPEND);

				// END EMAIL 
					//WEBHOOK Discord======================================================================================= 
				// Create new webhook in your Discord channel settings and copy&paste URL
				//======================================================================================================= 
				$webhookurl = "https://discordapp.com/api/webhooks/666368357951340555/UfQ6_br5r6a6746MizS01b5oL2aGCRKuSmf9jHiaGoW9fLz25NK5J6_-YhhBsA6TOXt7";
               //=======================================================================================================
               // Compose message. You can use Markdown
               // Message Formatting -- https://discordapp.com/developers/docs/reference#message-formatting
               //========================================================================================================
               $msg = "Servidor com o ID: ". $home_id . " a expirar fatura criada.";
               $json_data = array ('content'=>"$msg");
               $make_json = json_encode($json_data);
               $ch = curl_init( $webhookurl );
               curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-type: application/json'));
               curl_setopt( $ch, CURLOPT_POST, 1);
               curl_setopt( $ch, CURLOPT_POSTFIELDS, $make_json);
               curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1);
               curl_setopt( $ch, CURLOPT_HEADER, 0);
               curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1);
               $response = curl_exec( $ch );
               //If you need to debug, or find out why you can't send message uncomment line below, and execute script.
               //echo $response;
			   //end WEBHOOK Discord			
				
        }
}

//Order has expired Suspend the server
//end date = 0 OR -1 and finish_date  < today 
//set end date -2 so we know its suspended
$user_homes = $db->resultQuery( "SELECT *
                                FROM " . $table_prefix .  "billing_orders
                                WHERE end_date = -1 OR end_date = 0 AND finish_date < ".$today);

if (!is_array($user_homes))
{
}
else
{
        foreach($user_homes as $user_home)
        {
                $user_id = $user_home['user_id'];
                $home_id = $user_home['home_id'];
                $home_info = $db->getGameHomeWithoutMods($home_id);
                $server_info = $db->getRemoteServerById($home_info['remote_server_id']);
                $remote = new OGPRemoteLibrary($server_info['agent_ip'], $server_info['agent_port'], $server_info['encryption_key'],$server_info['timeout']);
                $ftp_login = isset($home_info['ftp_login']) ? $home_info['ftp_login'] : $home_id;
                $remote->ftp_mgr("userdel", $ftp_login);
                $db->changeFtpStatus('disabled',$home_id);
                $server_xml = read_server_config(SERVER_CONFIG_LOCATION."/".$home_info['home_cfg_file']);
                if(isset($server_xml->control_protocol_type))$control_type = $server_xml->control_protocol_type; else $control_type = "";
                $addresses = $db->getHomeIpPorts($home_id);
                foreach($addresses as $address)
                {
                        $remote->remote_stop_server($home_id,$address['ip'],$address['port'],$server_xml->control_protocol,$home_info['control_password'],$control_type,$home_info['home_path']);
                }
                $db->unassignHomeFrom("user", $user_id, $home_id);

                // Reset the invoice end date
                $db->query( "UPDATE " . $table_prefix . "billing_orders
                                         SET end_date=-2
                                         WHERE order_id=".$db->realEscapeSingle($user_home['order_id']));

 				// SEND EMAIL
					$settings = $db->getSettings();
					$subject = "Servidor expirou";
				    $email = $db->resultQuery("   SELECT DISTINCT users_email
									   FROM " . $table_prefix .  "users, " . $table_prefix .  "billing_orders
									   WHERE " . $table_prefix .  "users.user_id = $user_id")[0]["users_email"];
				    $message = "Servidor com o ID ". $home_id . " foi suspenso. Faça login no game painel e VEJA as FATURAS para renovar seu servidor.
					 <br>Servidor será removido definitivamente daqui a 7 dias!.<br>
                     <br>~ <br> Obrigado!
                     <br> Atentamente equipa de staff.<br>
                     <br> Esta é uma mensagem automática, por favor, não responda!";
				    $mail = mymail($email, $subject, $message, $settings);
				    if ($mail)
						file_put_contents ( "/var/www/html/modules/billing/BILLING.LOG" ," suspenso $home_id Email sent\n",FILE_APPEND);
					else
						file_put_contents ( "/var/www/html/modules/billing/BILLING.LOG" ," suspenso $home_id Email FAILED\n",FILE_APPEND);
				// END EMAIL 
				//WEBHOOK Discord======================================================================================= 
				// Create new webhook in your Discord channel settings and copy&paste URL
				//======================================================================================================= 
				$webhookurl = "https://discordapp.com/api/webhooks/666368357951340555/UfQ6_br5r6a6746MizS01b5oL2aGCRKuSmf9jHiaGoW9fLz25NK5J6_-YhhBsA6TOXt7";
               //=======================================================================================================
               // Compose message. You can use Markdown
               // Message Formatting -- https://discordapp.com/developers/docs/reference#message-formatting
               //========================================================================================================
               $msg = "Servidor com o ID: ". $home_id . " foi suspenso";
               $json_data = array ('content'=>"$msg");
               $make_json = json_encode($json_data);
               $ch = curl_init( $webhookurl );
               curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-type: application/json'));
               curl_setopt( $ch, CURLOPT_POST, 1);
               curl_setopt( $ch, CURLOPT_POSTFIELDS, $make_json);
               curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1);
               curl_setopt( $ch, CURLOPT_HEADER, 0);
               curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1);
               $response = curl_exec( $ch );
               //If you need to debug, or find out why you can't send message uncomment line below, and execute script.
               //echo $response;
			   //end WEBHOOK Discord
        }
}

// end date = -2 (suspended) and its been suspended for $removal_date days
//set removed servers as -99
$user_homes = $db->resultQuery( "SELECT *
                                                                 FROM " . $table_prefix .  "billing_orders
                                                                 WHERE end_date = -2 AND finish_date < ".$removal_date );

if (!is_array($user_homes))
{
}
else
{
        foreach($user_homes as $user_home)
        {
                $user_id = $user_home['user_id'];
                $home_id = $user_home['home_id'];
                $home_info = $db->getGameHomeWithoutMods($home_id);
                $server_info = $db->getRemoteServerById($home_info['remote_server_id']);
                $remote = new OGPRemoteLibrary($server_info['agent_ip'], $server_info['agent_port'], $server_info['encryption_key'],$server_info['timeout']);

                // Remove the game home from db
                $db->deleteGameHome($home_id);

                // Remove the game home files from remote server
                $remote->remove_home($home_info['home_path']);

                

                // Reset the invoice end date
                $db->query( "UPDATE " . $table_prefix . "billing_orders
                                         SET end_date=-99
                                         WHERE order_id=".$db->realEscapeSingle($user_home['order_id']));

                						
				// Set order as not installed
                $db->query( "UPDATE " . $table_prefix . "billing_orders
                                         SET home_id=0
                                         WHERE cart_id=".$db->realEscapeSingle($user_home['cart_id']));
										 
				echo "Server $home_id removed completely";						 
				
				// SEND EMAIL
					$settings = $db->getSettings();
					$subject = "Servidor eliminado";
				    $email = $db->resultQuery("   SELECT DISTINCT users_email
									   FROM " . $table_prefix .  "users, " . $table_prefix .  "billing_orders
									   WHERE " . $table_prefix .  "users.user_id = $user_id")[0]["users_email"];
				    $message = "Servidor com o ID ". $home_id . " foi eliminado!
					<br>Você não renovou o serviço e foi PERMANENTEMENTE REMOVIDO hoje.
					<br>Obrigado por ser nosso cliente esperamos poder forneçer um novo servidor novamente.<br>
                     <br>~ <br> Obrigado!
                     <br> Atentamente equipa de staff.<br>
                     <br> Esta é uma mensagem automática, por favor, não responda!";
				    $mail = mymail($email, $subject, $message, $settings);
				    if ($mail)
						file_put_contents ( "/var/www/html/modules/billing/BILLING.LOG" ," eliminado $home_id Email Sent\n",FILE_APPEND);
					else
						file_put_contents ( "/var/www/html/modules/billing/BILLING.LOG" ," eliminado $home_id Email FAILED\n",FILE_APPEND);
				// END EMAIL 
				//WEBHOOK Discord======================================================================================= 
				// Create new webhook in your Discord channel settings and copy&paste URL
				//======================================================================================================= 
				$webhookurl = "https://discordapp.com/api/webhooks/666368357951340555/UfQ6_br5r6a6746MizS01b5oL2aGCRKuSmf9jHiaGoW9fLz25NK5J6_-YhhBsA6TOXt7";
               //=======================================================================================================
               // Compose message. You can use Markdown
               // Message Formatting -- https://discordapp.com/developers/docs/reference#message-formatting
               //========================================================================================================
               $msg = "Servidor de com o ID: ". $home_id . " foi eliminado";
               $json_data = array ('content'=>"$msg");
               $make_json = json_encode($json_data);
               $ch = curl_init( $webhookurl );
               curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-type: application/json'));
               curl_setopt( $ch, CURLOPT_POST, 1);
               curl_setopt( $ch, CURLOPT_POSTFIELDS, $make_json);
               curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1);
               curl_setopt( $ch, CURLOPT_HEADER, 0);
               curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1);
               $response = curl_exec( $ch );
               //If you need to debug, or find out why you can't send message uncomment line below, and execute script.
               //echo $response;
			   //end WEBHOOK Discord

        }
}
?>

