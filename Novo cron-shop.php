<?php
/*
 *
 * Bebiano
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

$today=date('YmdHi');
$user_homes = $db->resultQuery( "SELECT * 
								 FROM " . $table_prefix .  "billing_orders  
								 WHERE end_date>0 AND end_date<".$today );
								 
if (!is_array($user_homes))
{
	echo "Nothing to do.\n";
}
else
{
	foreach($user_homes as $user_home)
	{
		$user_id = $user_home['user_id'];
		$home_id = $user_home['home_id'];
		/*
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
		*/
		
		// Reset the invoice end date
		$db->query( "UPDATE " . $table_prefix . "billing_orders
					SET end_date=-1
					WHERE order_id=".$db->realEscapeSingle($user_home['order_id']));
					 
		//echo "Home ID $home_id unassigned succesfull.";
		echo "Invoice enabled for $home_id\n";

		// Enviar e-mail de notificação
		if( empty( $panel_settings['panel_name'] ) )
			$subject = "Open Game Panel :: Servidor a expirar";
		else
			$subject = $panel_settings['panel_name'] . " :: Servidor a expirar";

		$email = $db->resultQuery( "SELECT DISTINCT users_email 
									FROM " . $table_prefix . "users 
									WHERE user_id = " . $user_id)[0]["users_email"];

		$message = "Um ou mais dos seus servidores estão prestes a expirar. Faça o login <a href='https://hlc.ovh/'>aqui</a> e vá até a loja e, em seguida, ao carrinho de compras para estender o(s) seu(s) servidor(es).
					<br>
					<br> Para pagamento sem taxa, fale com o staff <a href='https://discord.me/sfservidores'>aqui.</a>, antes de acabar o prazo!
					<br>
					<br> Os seus servidores serão <span style='color:red;'>desativados</span> e ficam <span style='color:red;'>inutilizáveis</span> daqui a <span style='color:red;'>5 dias</span> se não forem <span style='color:red;'>renovados</span>.<br>
					<br> <span style='color:red;'>Atenção não vai receber mais nenhuma notificação!!</span><br>
					<br>
					<br>~ <br> Obrigado!
					<br>
					<br> Atentamente,
					<br> Equipa de staff
					<br>
					<br> Esta é uma mensagem automática, por favor, não responda!";

		$mail = mymail($email, $subject, $message, $panel_settings);

		if ($mail) {
			echo "Expired server email send successful.";
			mymail($panel_settings['smtp_login'], $subject, $message, $panel_settings); // Envia cópia para admin
		} else {
			echo "Expired server email send UNSUCCESSFUL.";
		}
	}
}

$user_homes = $db->resultQuery( "SELECT * 
								 FROM " . $table_prefix .  "billing_orders  
								 WHERE end_date=-1 AND finish_date>0 AND finish_date<".$today );
								 
if (!is_array($user_homes))
{
	echo "Any server finishes now.";
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
		
		// Set order as not installed
		$db->query( "UPDATE " . $table_prefix . "billing_orders
					 SET home_id=0
					 WHERE cart_id=".$db->realEscapeSingle($ipn['item_number'])); 
					 
		// Reset the invoice end date
		$db->query( "UPDATE " . $table_prefix . "billing_orders
					 SET end_date=-2
					 WHERE order_id=".$db->realEscapeSingle($user_home['order_id']));
					 
		$db->query( "UPDATE " . $table_prefix . "billing_orders
					 SET finish_date=-2
					 WHERE order_id=".$db->realEscapeSingle($user_home['order_id']));
					 
		echo "Home ID $home_id finished completely.";
		
				// Enviar e-mail de notificação
		if( empty( $panel_settings['panel_name'] ) )
			$subject = "Open Game Panel :: Servidor eliminado";
		else
			$subject = $panel_settings['panel_name'] . " :: Servidor eliminado";

		$email = $db->resultQuery( "SELECT DISTINCT users_email 
									FROM " . $table_prefix . "users 
									WHERE user_id = " . $user_id)[0]["users_email"];

		$message = "O seu servidor foi <span style='color:red;'>eliminado</span> por falta de pagamento!
					<br>
					<br>~ <br> Obrigado!
					<br>
					<br> Atentamente,
					<br> Equipa de staff
					<br>
					<br> Esta é uma mensagem automática, por favor, não responda!";

		$mail = mymail($email, $subject, $message, $panel_settings);

		if ($mail) {
			echo "Expired server email send successful.";
			mymail($panel_settings['smtp_login'], $subject, $message, $panel_settings); // Envia cópia para admin
		} else {
			echo "Expired server email send UNSUCCESSFUL.";
		}
	}
}
?>
