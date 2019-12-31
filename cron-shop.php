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
//error_reporting(E_ALL);
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
$today=date('YmdHi');
//7 days from today if server expires (end_date)
$invoice_date = date('YmdHi',strtotime('+ 7 days'));
//suspend the server on the end_date by comparing to finish_date which is +5
$suspend_date = date('YmdHi',strtotime('+ 5 days'));
//5 days ago server expired(finish_date)
$removal_date = date('YmdHi',strtotime(' -5 days'));
 
//Create invoice 7 days before server expires.
$user_homes = $db->resultQuery( "SELECT *
                                 FROM " . $table_prefix .  "billing_orders  
                                 WHERE end_date>0 AND end_date<".$invoice_date);
                                 
if (!is_array($user_homes))
{
    echo "Nothing to do\n";
}
else
{
    foreach($user_homes as $user_home)
    {
       
        // Reset the invoice end date -1 so cart.php will create an invoice
        $db->query( "UPDATE " . $table_prefix . "billing_orders
                     SET end_date=-1
                     WHERE order_id=".$db->realEscapeSingle($user_home['order_id']));
                     
        echo "Invoice enabled for ". $user_home['home_id']." \n";

        // Enviar e-mail de notificação
        if( empty( $panel_settings['panel_name'] ) )
            $subject = "Open Game Panel :: Servidor quase a expirar";
        else
            $subject = $panel_settings['panel_name'] . " :: Servidor quase a expirar";

        $email = $db->resultQuery( "SELECT DISTINCT users_email 
                                    FROM " . $table_prefix . "users 
                                    WHERE user_id = " . $user_home['user_id'])[0]["users_email"];

        $message = "Um ou mais dos seus servidores expiraram. Faça o login <a href='https://hlc.ovh/'>aqui</a> e vá até a loja e, em seguida, ao carrinho de compras para estender seu (s) servidor (es). <br> <br> Os seus servidores serão desativados e inutilizáveis  daqui a 7 dias se não forem renovados. <br> <br> Atenção não vai receber mais nenhuma notificação!!<br> <br> Para pagamento sem taxa fale com a staff <a href='https://discord.me/sfservidores'>aqui</a><br> <br>~ <br> Obrigado! <br> Atentamente equipa de staff ";

        $mail = mymail($email, $subject, $message, $panel_settings);

        if ($mail)
            echo "Quase Expired server email send successful.";
        else
            echo "Quase Expired server email send UNSUCCESSFUL.";
    }
}


//Order has expired. Suspend the server
$user_homes = $db->resultQuery( "SELECT *
                                 FROM " . $table_prefix .  "billing_orders  
                                 WHERE end_date=-1 AND finish_date<".$suspend_date);
                                 
if (!is_array($user_homes))
{
    echo "Nothing to do\n";
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
                     SET end_date=-1
                     WHERE order_id=".$db->realEscapeSingle($user_home['order_id']));
                     
        echo "Server Expired $home_id unassigned succesfully\n";
    }
}
 
//5 days after expiration we remove the server
$user_homes = $db->resultQuery( "SELECT *
                                 FROM " . $table_prefix .  "billing_orders  
                                 WHERE end_date=-1 AND finish_date>0 AND finish_date<".$removal_date );
                                 
if (!is_array($user_homes))
{
    echo "No servers finished\n";
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
                     WHERE cart_id=".$db->realEscapeSingle($user_home['cart_id']));
                     
        // Reset the invoice end date
        $db->query( "UPDATE " . $table_prefix . "billing_orders
                     SET end_date=-2
                     WHERE order_id=".$db->realEscapeSingle($user_home['order_id']));
                     
        $db->query( "UPDATE " . $table_prefix . "billing_orders
                     SET finish_date=-2
                     WHERE order_id=".$db->realEscapeSingle($user_home['order_id']));
                     
        echo "Server $home_id removed completely";
    }
}

?>