<?php
	error_reporting( E_ALL );
	ini_set( 'display_errors', 1 );

	$start        = microtime( true );
	$load_monitor = new stdClass();

	class DB_Connect {
		function __construct() {
			$this->connect = mysqli_connect(
				DB_HOST,
				DB_USER,
				DB_PASS,
				DB_NAME
			);

			if( ! $this->connect ){
				echo "Error: Unable to connect to MySQL." . PHP_EOL;
				//echo "Debugging errno: " . mysqli_connect_errno() . PHP_EOL;
				//echo "Debugging error: " . mysqli_connect_error() . PHP_EOL;
				exit;
			}
		}
	}

	class Load_Monitor {
		public function __construct(){
			require_once dirname( __FILE__ ) . '/inc/config.php';
			require_once dirname( __FILE__ ) . '/inc/functions.php';

			$this->init();
			$this->run();
		}

		/**
		 * Initialize
		 *
		 * @return void
		 */
		public function init(){
			global $load_monitor, $mysqli;

			// Initialize Load Monitor Object
			foreach( $_GET as $key => $value ){
				$load_monitor->$key = $value;
			}

			// Current CPU Usage as a Percentage
			$load_monitor->cpu_usage = ( $load_monitor->one_min_avg / $load_monitor->cores ) * 100;

			// Verbalize Alert Levels
			$load_monitor->alert_level = alert_level( $load_monitor->one_min_avg, $load_monitor->cores ); // 0 - 4
			$load_monitor->status      = verbalize_alert_level( $load_monitor->alert_level ); // Optimial - Critical

			// Initialize Database Connection
			$database = new DB_Connect();
			$mysqli	  = $database->connect;
		}

		public function run(){
			// Initialize Variables
			global $load_monitor;
			$slack_counter = $email_counter = 0;
			$slacked_this_scan = $emailed_this_scan = false;
			
			// Get Results of last hour's worth of scans
			$results = get_scan( NOTIFICATIONS_LIMIT );

			// Critical Threshold in minutes
			$threshold = CRITICAL_THRESHOLD;

			// Loop Through Scan Results
			$counter = 0;
			foreach( $results as $result ){
				if( $result['sent_slack'] ) $slack_counter++;
				if( $result['sent_email'] ) $email_counter++;
		
				// If server load is critical		
				if( $load_monitor->status == 'Critical' ){
					// Break foreach at the threshold.
					if( $counter == $threshold ){
						break;
					}
				}
				$counter++;
			}

			if( $load_monitor->alert_level >= NOTIFICATIONS_THRESHOLD ){
				if( $slack_counter < 1 ){
					$slacked_this_scan = slack_handler( 'D19LY2ZFX' );
				}

				if( $email_counter < 1 ){
					$emailed_this_scan = email_handler( array( 'demchak.alex@gmail.com' ) );
				}
			}

			// Save this Scan
			$save = save_scan( $load_monitor->cpu_usage, $load_monitor->one_min_avg, $load_monitor->five_min_avg, $load_monitor->fifteen_min_avg, $slacked_this_scan, $emailed_this_scan );

			// Have last scans fall off
			delete_scans();
		}
	}