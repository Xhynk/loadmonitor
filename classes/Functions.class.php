<?php
	class Functions extends Load_Monitor {
		public function __construct(){
			global $mysqli;
			$this->mysqli = $mysqli;
		}

		public function sys_getnumcores(){
			$cmd = "uname";
			$OS  = strtolower(trim(shell_exec($cmd)));
		 
			switch( $OS ){
			   case( 'linux' ):
					$cmd = "cat /proc/cpuinfo | grep processor | wc -l";
					break;
			   case( 'freebsd' ):
					$cmd = "sysctl -a | grep 'hw.ncpu' | cut -d ':' -f2";
					break;
			   default:
					$cmd = null;
			}
		 
			if( $cmd ){
			   $num_cores = intval(trim(shell_exec($cmd)));
			}
			
			return empty( $num_cores ) ? 1 : $num_cores;
		}

		/**
		 * Determine Current Alert Level
		 *
		 * @param float $load - Current Load (utilized cores)
		 * @param int $cores - Number of Cores Available (max load)
		 * @return int - 0 (optimal) through 4 (critical)
		 */
		public function alert_level( $load, $cores ){
			$percent = ( $load / $cores ) * 100;

			switch( $percent ){
				case( $percent > 300 ):   return 4;
				case( $percent > 100 ):   return 3;
				case( $percent > 66.66 ): return 2;
				case( $percent > 33.33 ): return 1;
				default:                  return 0;
			}
		}

		/**
		 * Determine Ver Alert Level
		 *
		 * @param int $alert_level - Current Alert Level
		 * @return string - 0 (optimal) through 4 (critical)
		 */
		public function verbalize_alert_level( $alert_level ){
			switch( $alert_level ){
				case 4:  return 'Critical';
				case 3:  return 'High';
				case 2:  return 'Nominal';
				case 1:  return 'Low';
				case 0:  return 'Optimal';
				default: return 'Nominal';
			}
		}

		/**
		 * Determine Which Slack Emoji to send
		 *
		 * @return string - Slack Emoji :emoji:
		 */
		public function slackmoji( $status ){
			switch( $status ){
				case "Critical": return ':fire:';
				case "High":     return ':warning:';
				case "Nominal":  return ':white_check_mark:';
				case "Low":      return ':heavy_check_mark:';
				case "Optimal":  return ':star2:';
				default:         return ':warning:';
			}
		}

		/**
		 * Retrieve Past Scans
		 *
		 * @param string $num_results - Number of previous scans to get
		 * @return mixed - Array containing results or false on DB failure
		 */
		public function get_scan( $connection, $num_results = 1 ){
			if( $query = $connection->prepare( "SELECT cpu_usage, sent_slack, sent_email FROM ". DB_TABLE ." ORDER BY scan_id DESC LIMIT ?" ) ){
				$query->bind_param('i', $num_results);
				$query->execute();
				$query->bind_result( $cpu_usage, $sent_slack, $sent_email );

				$results = array();
				
				while( $query->fetch() ){
					$results[] = array(
						'cpu_usage'  => $cpu_usage,
						'sent_slack' => $sent_slack,
						'sent_email' => $sent_email
					);
				}

				$query->close();
			} else {
				$results = false;
			}
			
			return $results;
		}

		/**
		 * Get the Time of this scan
		 *
		 * @param string $timezone - Valid PHP Timezone
		 * @param double $one_min_avg - Date Format
		 * @return string - Formatted DateTime String
		 */
		public function scan_time( $timezone = 'America/Los_Angeles', $format = 'Y-m-d H:i:s' ){
			$datetime = new DateTime( 'now', new DateTimeZone( $timezone ) );
			$datetime->setTimestamp( time() );
			
			return $datetime->format( $format );
		}

		/**
		 * Save Current Scan Results
		 *
		 * @param double $cpu_usage - Current CPU Usage as a percent
		 * @param double $one_min_avg - One Minute Load Average
		 * @param double $five_min_avg - Five Minute Load Averge
		 * @param double $fifteen_min_avg - Fifteen Minute Load Average
		 * @param bool $sent_slack - Was a Slack Message sent this scan?
		 * @param bool $sent_email - Was an Email sent this scan?
		 * @return bool - true on success, false on failure
		 */
		public function save_scan( $connection, $cpu_usage = 0, $one_min_avg = 0, $five_min_avg = 0, $fifteen_min_avg = 0, $sent_slack = false, $sent_email = false ){
			/* Considuer "ALTER TABLE ". DB_TABLE ." AUTO_INCREMENT = 0;"
			 * to prevent AUTO_INCREMENT from becoming needlessly large
			 */

			#$scan_time = scan_time(); // Not working? -8 Hrs manually
			$scan_timestamp = time();
			$scan_timestamp = $scan_timestamp - 28800;
			$scan_time      = date( 'Y-m-d H:i:s', $scan_timestamp );

			if( $query = $connection->prepare( "INSERT INTO ". DB_TABLE ." (cpu_usage, one_min_avg, five_min_avg, fifteen_min_avg, scan_time, sent_slack, sent_email) VALUES (?, ?, ?, ?, ?, ?, ?)" ) ){
				$query->bind_param( "ddddsii", $cpu_usage, $one_min_avg, $five_min_avg, $fifteen_min_avg, $scan_time, $sent_slack, $sent_email );
				$query->execute();

				$results = $query->get_result();
				$query->close();
				
				return true;
			} else {
				return false;
			}
		}

		/**
		 * Delete Past Scans
		 *
		 * @return bool - True on Delete, False on fail/not needed
		 */
		public function delete_scans( $connection ){
			// Find Current Amount of Rows in Database
			if( $query = $connection->query( "SELECT COUNT(*) FROM ". DB_TABLE ."" ) ){
				$rows = mysqli_fetch_row( $query );
				$total_rows = $rows[0];
			}

			if( $total_rows > DB_ROW_LIMIT ){
				$limit = $total_rows - DB_ROW_LIMIT;
				
				// Delete Superfluous Row(s)
				if( $query = $connection->prepare( "DELETE FROM ". DB_TABLE ." ORDER BY scan_id ASC LIMIT ?" ) ){
					$query->bind_param( 'i', $limit );
					$query->execute();

					$query->close();
					return true; // Delete Succeeded
				} else {
					return false; // Delete Failed
				}
			} else {
				return false; // Delete Unneccessary
			}
		}

		/**
		 * Send a Slack Notification
		 *
		 * @param string $channel - A Channel ID to override the webhook
		 * @return bool - true if sent, false if not
		 */
		public function slack_handler( $channel = false, $status, $hostname, $cpu_usage, $cores, $load ){
			$payload = array();
			global $start;

			/* Currently used memory */
			$mem_usage = memory_get_usage();
			
			/* Currently used memory including inactive pages */
			$mem_full_usage = memory_get_usage(TRUE);
			
			/* Peak memory consumption */
			$mem_peak = memory_get_peak_usage();
			$payload['text']  = $this->slackmoji( $status )." *$status Server Load*\r\n";
			$payload['text'] .= "The One Minute Load Average for *$hostname* is *$status*, running at `$cpu_usage%` capacity.\r\n";
			$payload['text'] .= "The maximimum stable load is `$cores`, and is currently: `$load`.\r\n";
			$payload['text'] .= "<https://$hostname/whm/|WHM Login>	|	<https://manage.liquidweb.com/|LW Manage>	|	<mailto:support@liquidweb.com|Email Support>\r\n\r\n";
			$payload['text'] .= '```Runtime : '. round( (microtime(true) - $start), 5 ) ."Sec\r\nMem Usage: ". round($mem_usage / 1024) . 'KB | Real Usage: '. round($mem_full_usage / 1024) .'KB | Peak Usage: '. round($mem_peak / 1024) .'KB```';

			if( $channel ){
				$payload['channel'] = $channel;
			}

			$json = json_encode( $payload );

			$ch = curl_init( SLACK_WEBHOOK_URL );
			curl_setopt( $ch, CURLOPT_POST, 1 );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $json );
			curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1 );
			curl_setopt( $ch, CURLOPT_HEADER, 0 );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );

			if( $response = curl_exec( $ch ) ){
				return true;
			} else {
				return false;
			}
		}

		/**
		 * Send a Slack Notification
		 *
		 * @param array $recipients - List of Email Recipients
		 * @return mixed - value of PHP's mail() function
		 */
		public function email_handler( $recipients = array( 'demchak.alex@gmail.com' ), $status, $hostname, $cpu_usage, $cores, $load ){
			global $start;

			// Validate Email Addresses
			foreach( $recipients as $recipient ){
				if( ! filter_var( $recipient, FILTER_VALIDATE_EMAIL ) ){
					return "$recipient is not a valid email address. Aborting email_handler().";
				}
			}

			$header = "From: Load Monitor <loadMonitorPHP@{$hostname}>\r\n"; 
			$header.= "MIME-Version: 1.0\r\n"; 
			$header.= "Content-Type: text/html; charset=ISO-8859-1\r\n"; 
			$header.= "X-Priority: 1\r\n"; 

			$body = '<!doctype html>
	<html>
		<head>
			<meta name="viewport" content="width=device-width" />
			<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
			<title>Third River Marketing</title>
			<style>img{border:none;-ms-interpolation-mode:bicubic;max-width:100%}body{background-color:#f6f6f6;font-family:sans-serif;-webkit-font-smoothing:antialiased;font-size:14px;line-height:1.4;margin:0;padding:0;-ms-text-size-adjust:100%;-webkit-text-size-adjust:100%}table{border-collapse:separate;mso-table-lspace:0pt;mso-table-rspace:0pt;width:100%}table td{font-family:sans-serif;font-size:14px;vertical-align:top}.body{background-color:#f6f6f6;width:100%}.container{display:block;Margin:0 auto!important;max-width:580px;padding:10px;width:580px}.content{box-sizing:border-box;display:block;Margin:0 auto;max-width:580px;padding:10px}.main{background:#fff;border-radius:3px;width:100%}.wrapper{box-sizing:border-box;padding:20px}.content-block{padding-bottom:10px;padding-top:10px}.footer{clear:both;Margin-top:10px;text-align:center;width:100%}.footer td,.footer p,.footer span,.footer a{color:#999;font-size:12px;text-align:center}h1,h2,h3,h4{color:#000;font-family:sans-serif;font-weight:400;line-height:1.4;margin:0;Margin-bottom:30px}h1{font-size:35px;font-weight:300;text-align:center;text-transform:capitalize}p,ul,ol{font-family:sans-serif;font-size:14px;font-weight:400;margin:0;Margin-bottom:15px}p li,ul li,ol li{list-style-position:inside;margin-left:5px}a{color:#3498db;text-decoration:underline}.btn{box-sizing:border-box;width:100%}.btn>tbody>tr>td{padding-bottom:15px}.btn table{width:auto}.btn table td{background-color:#fff;border-radius:5px;text-align:center}.btn a{background-color:#fff;border:solid 1px #3498db;border-radius:5px;box-sizing:border-box;color:#3498db;cursor:pointer;display:inline-block;font-size:14px;font-weight:700;margin:0;padding:12px 25px;text-decoration:none}.btn-primary table td{background-color:#3498db}.btn-primary a{background-color:#3498db;border-color:#3498db;color:#fff}.last{margin-bottom:0}.first{margin-top:0}.align-center{text-align:center}.align-right{text-align:right}.align-left{text-align:left}.clear{clear:both}.mt0{margin-top:0}.mb0{margin-bottom:0}.preheader{color:transparent;display:none;height:0;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;visibility:hidden;width:0}.powered-by a{text-decoration:none}hr{border:0;border-bottom:1px solid #f6f6f6;Margin:20px 0}@media only screen and (max-width:620px){table[class=body] h1{font-size:28px!important;margin-bottom:10px!important}table[class=body] p,table[class=body] ul,table[class=body] ol,table[class=body] td,table[class=body] span,table[class=body] a{font-size:16px!important}table[class=body] .wrapper,table[class=body] .article{padding:10px!important}table[class=body] .content{padding:0!important}table[class=body] .container{padding:0!important;width:100%!important}table[class=body] .main{border-left-width:0!important;border-radius:0!important;border-right-width:0!important}table[class=body] .btn table{width:100%!important}table[class=body] .btn a{width:100%!important}table[class=body] .img-responsive{height:auto!important;max-width:100%!important;width:auto!important}}@media all{.ExternalClass{width:100%}.ExternalClass,.ExternalClass p,.ExternalClass span,.ExternalClass font,.ExternalClass td,.ExternalClass div{line-height:100%}.apple-link a{color:inherit!important;font-family:inherit!important;font-size:inherit!important;font-weight:inherit!important;line-height:inherit!important;text-decoration:none!important}.btn-primary table td:hover{background-color:#34495e!important}.btn-primary a:hover{background-color:#34495e!important;border-color:#34495e!important}}pre{background:#eee;padding:10px 20px;display:inline-block;font-weight:900;letter-spacing:1px;font-size:18px;border:1px solid #e0e0e0}</style>
		</head>
		<body class="">
			<table border="0" cellpadding="0" cellspacing="0" class="body">
				<tr>
					<td>&nbsp;</td>
					<td class="container">
						<div class="content">
							<div style="text-align:center;"><a href="https://thirdrivermarketing.com/" target="_blank"><img src="https://thirdrivermarketing.com/keygen/400-wide.png"></a></div>
							<table class="main">
								<tr>
									<td class="wrapper">
										<table border="0" cellpadding="0" cellspacing="0">
											<tr>
												<td>
													<p>The One Minute Load Average for <strong>'. $hostname .'</strong> is <strong>'. $status .'</strong>, running at <strong>'. $cpu_usage .'%</strong> capacity.</p>
													<p>The maximimum stable load for this server is <strong>'. $cores .'</strong>, and it is currently:<br /><pre>'. $load .'</pre></p>
													<p>Please keep an eye on the server load, and alert your Hosting Provider if necessary.
													<table border="0" cellpadding="0" cellspacing="0" class="">
														<tbody>
															<tr>
																<td align="left">
																	<table border="0" cellpadding="0" cellspacing="0">
																		<tbody>
																			<tr>
																				<td class="btn btn-primary"><a href="https://'. $hostname .'/whm/" rel="nofollow noopener" target="_blank">Login to WHM Panel</a></td>
																			</tr>
																			<tr><br></tr>
																			<tr>
																				<td class="btn btn-primary"><a href="https://manage.liquidweb.com/" rel="nofollow noopener" target="_blank">Login to Manage</a></td>
																			</tr>
																			<tr><br></tr>
																			<tr>
																				<td class="btn btn-primary"><a href="mailto:support@liquidweb.com" rel="nofollow noopener" target="_blank">Contact Support: support@liquidweb.com</a></td>
																			</tr>
																			<tr><td></td></tr>
																		</tbody>
																	</table>
																</td>
															</tr>
														</tbody>
													</table>
													<p style="padding-top:16px;">This script was run by a cron job for /home/thirdmkt/loadMonitorPHP on thirdrivermarketing.com</p>
													<p style="padding-top:16px;">This script was completed in '. round( (microtime(true) - $start), 5 ).' seconds</p>
												</td>
											</tr>
										</table>
									</td>
								</tr>
							</table>
							<div class="footer">
								<table border="0" cellpadding="0" cellspacing="0">
									<tr>
										<td class="content-block">
											<span class="apple-link">Third River Marketing</span>
											<br> 1436 Commercial St. NE, Salem OR 97301
											<br> (503) 581-4554
										</td>
									</tr>
								</table>
							</div>
						</div>
					</td>
					<td>&nbsp;</td>
				</tr>
			</table>
		</body>
	</html>';
			
			if( count( $recipients ) > 0 ){
				if( $mail = mail( implode(',', $recipients), "⚠ {$status} Load on {$hostname} - [{$load}]", $body, $header ) ){
					return true;
				} else {
					return false;
				}
			}
		}
	}
?>