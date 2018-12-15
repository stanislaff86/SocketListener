<?php
error_reporting(E_ALL & ~E_NOTICE);
set_time_limit(0);

$address = "172.30.21.200";
$port = 3779;
$max_clients = 2000;
 
//MYSQL CONNECTION
$MYSQLhost		= 'p:localhost';
$MYSQLlogin 	= 'login';
$MYSQLpassword	= 'password';
$MYSQLdatabase	= 'BeepCall';

//MySQL connection
//////////////////
$link = mysqli_connect($MYSQLhost, $MYSQLlogin, $MYSQLpassword, $MYSQLdatabase);
if (!$link) { syslog(LOG_ERR,'SocketListener: MySQL Database Connect Error  '. mysqli_connect_errno() . ') '. mysqli_connect_error()); }

if(!($sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)))
{
    $errorcode = socket_last_error();
    $errormsg = socket_strerror($errorcode);
    syslog(LOG_ERR, "SocketListener: Couldn't create socket: [".$errorcode."] ".$errormsg);
    die("Couldn't create socket: [$errorcode] $errormsg \n");
}
 
socket_set_nonblock($sock);
syslog(LOG_INFO, "SocketListener: Socket created");
 
// Bind the source address
if( !socket_bind($sock, $address , $port) )
{
    $errorcode = socket_last_error();
    $errormsg = socket_strerror($errorcode);
    syslog(LOG_ERR, "Could not bind socket : [".$errorcode."] ".$errormsg); 
    die("Could not bind socket : [$errorcode] $errormsg \n");
}
 
syslog(LOG_INFO, "SocketListener: Socket bind OK");

if(!socket_listen ($sock , 10))
{
    $errorcode = socket_last_error();
    $errormsg = socket_strerror($errorcode);
	syslog(LOG_ERR, "Could not listen on socket : [".$errorcode."] ".$errormsg); 
    die("Could not listen on socket : [$errorcode] $errormsg \n");
}

syslog(LOG_INFO, "SocketListener: Socket listen OK. Waiting for incoming connections...");
 
if(!socket_set_option($sock, SOL_SOCKET, SO_SNDTIMEO, array('sec' => 1, 'usec' => 0)))
{
	echo 'Не могу установить опцию на сокете: . socket_strerror(socket_last_error())' . PHP_EOL;
}
 
//array of client sockets
$client_socks = array();
 
//array of sockets to read
$read = array();
 
//start loop to listen for incoming connections and process existing connections
while(true) 
{
    //prepare array of readable client sockets
    $read = array();
     
    //first socket is the master socket
    $read[0] = $sock;
     
    //now add the existing client sockets
    for ($i = 0; $i < $max_clients; $i++)
    {
        if($client_socks[$i] != null)
        {
            $read[$i+1] = $client_socks[$i];
        }
    }
     
    //now call select - blocking call
    if(socket_select($read , $write , $except , null) === false)
    {
        $errorcode = socket_last_error();
        $errormsg = socket_strerror($errorcode);
		syslog(LOG_ERR, "Could not listen on socket : [".$errorcode."] ".$errormsg);
        die("Could not listen on socket : [$errorcode] $errormsg \n");
    }
     
    //if ready contains the master socket, then a new connection has come in
    if (in_array($sock, $read)) 
    {
        for ($i = 0; $i < $max_clients; $i++)
        {
            if ($client_socks[$i] == null) 
            {
                $client_socks[$i] = socket_accept($sock);
                if(!socket_set_option($client_socks[$i], SOL_SOCKET, SO_SNDTIMEO, array('sec' => 1, 'usec' => 0)))
				{
					syslog(LOG_INFO, "Не могу установить опцию на сокете: ". socket_strerror(socket_last_error()));
				}
                //display information about the client who is connected
                if(socket_getpeername($client_socks[$i], $address, $port))
                {
                    syslog(LOG_INFO, "SocketListener: Client ".$address." : ".$port." is now connected to us.");
				}
                 
               	break;
			}
        }
		 
	}
	
    //check each client if they send any data
    for ($i = 0; $i < $max_clients; $i++)
    {
        if (in_array($client_socks[$i] , $read))
        {
			$input = '';
			if (false !== ($bytes = socket_recv($client_socks[$i], $input, 16384, 0))) 
			{
				echo "Прочитано $bytes байта из функции socket_recv()....".PHP_EOL;
			} 
			else 
			{
			//	echo "Не получилось выполнить socket_recv(); причина: " . socket_strerror(socket_last_error($client_socks[$i])).PHP_EOL;
				syslog(LOG_WARNING, "SocketListener: Не получилось выполнить socket_recv(); причина: " . socket_strerror(socket_last_error($client_socks[$i])));
				socket_close($client_socks[$i]);
				unset($client_socks[$i]);
				continue;
			}
			
			if ($input == null) 
            {
                //zero length string meaning disconnected, remove and close the socket
                socket_close($client_socks[$i]);
				unset($client_socks[$i]);
				continue;
            }
			if($input != "")
			{
				if(socket_getpeername($client_socks[$i], $address, $port))
                {
                    echo "Client $address:$port\n";
                }
			//	$input = trim($input);
				
				if($notif_end[$i] != '')
				{
					$input = 'T.#'.$notif_end[$i].$input;
				//	$notif_end = array();
					$notif_end[$i] = '';
				}
				
				$notif = preg_split('/T.#/', $input);
				
				$Ack = '';
				
				for($c = 1; $c < count($notif); $c++)
				{
					if(preg_match("/(.*?)##;.*?;State=(.*?);.*?;FREE_TEXT=(\d{12}) (.*?)\\x00/si", $notif[$c], $array))
					{
						$AckHeader = "";
						$AckHeader .= chr(0x00);
						$AckHeader .= chr(0x4b);
						$AckHeader .= chr(0x10);
						
						$MessageId = $array[1];
						$Ack .= $AckHeader."#".$MessageId."#";
						$State = $array[2];
						$Msisdns[0] = $array[3];
						$Msisdns[1] = $array[4];
						
						if(is_numeric($Msisdns[1]))
						{
							$CallingNumber = "00".$Msisdns[0];
							$CalledNumber = "00".$Msisdns[1];
							
							db_reconnect();		
							if (mysqli_query($link, "INSERT into messageid_logs(MessageId,IP,type,CallingNumber,CalledNumber) values ('$MessageId','$address','0','$CallingNumber','$CalledNumber')"))
							{
								mysqli_query($link, "CALL BeepRequest('".$MessageId."','".$CallingNumber."','".$CalledNumber."','".$State."');");
							}
							else
							{
								if(mysqli_errno($link) == 1062) //Duplicate entry on insert
								{
									syslog(LOG_INFO,'SocketListener: Duplicate '. $MessageId);
								}
							}
						}
						else
						{
							syslog(LOG_INFO,'SocketListener: Incorrect msisdns: '. $MessageId. ' '. $Msisdns[0]. ' ' .$Msisdns[1]);
						}
						
					}
					else
					{
					//	$notif_end = array();
						$notif_end[$i] = $notif[$c];
					}
				}
			//	socket_send($client_socks[$i], $Ack, strlen($Ack), 0);
				if (false !== ($length = socket_send($client_socks[$i], $Ack, strlen($Ack), 0))) 
				{
					echo "Отправлено $length из ".strlen($Ack)." байт из функции socket_send()....".PHP_EOL;
				}
				else
				{
					syslog(LOG_WARNING, "SocketListener: Не получилось выполнить socket_send(); причина: " . socket_strerror(socket_last_error($client_socks[$i])));
				}
			}	
        }
	}
}

// MySQL restoring connection to  database function
///////////////////////////////////////////////////
function db_reconnect() 
{
	global $link, $MYSQLhost, $MYSQLlogin, $MYSQLpassword, $MYSQLdatabase;
	if(!mysqli_ping($link))	{ $link = mysqli_connect($MYSQLhost, $MYSQLlogin, $MYSQLpassword, $MYSQLdatabase); }
}

?>
