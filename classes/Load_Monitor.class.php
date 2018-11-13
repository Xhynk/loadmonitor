<?php
	class Load_Monitor {
		static $instance;

		public static function get_instance(){
			if( ! self::$instance )
				self::$instance = new self();

			return self::$instance;
		}

		public function __construct(){
			// Initialize
			$this->init();

			// Run
			$this->run();

			// Shutdown
			$this->shutdown();
		}

		/**
		 * Initialize
		 *
		 * @return void
		 */
		public function init(){
			global $mysqli;
			$this->mysqli = $mysqli;
			$this->functions = new Functions();

			// Set Hostname for Dynamic Reponse
			$this->hostname = gethostname();

			// Get Current Load
			$load = sys_getloadavg();

			// Define Load Averages
			$this->one_min_avg     = $load[0];
			$this->five_min_avg    = $load[1];
			$this->fifteen_min_avg = $load[2];

			// Define Current Number of Cores
			$this->cores = $this->functions->sys_getnumcores();

			// Define Current CPU Usage as Percentage
			$this->cpu_usage = ( $this->one_min_avg / $this->cores ) * 100;

			// Define Alert Level and Verbalize as Status
			$this->alert_level = $this->functions->alert_level( $this->one_min_avg, $this->cores ); // 0 - 4
			$this->status      = $this->functions->verbalize_alert_level( $this->alert_level ); // Optimial - Critical
		}

		public function run(){
			// Initialize Variables
			$slack_counter = $email_counter = 0;
			$slacked_this_scan = $emailed_this_scan = false;
			
			// Get Results of last hour's worth of scans
			$results = $this->functions->get_scan( $this->mysqli, NOTIFICATIONS_LIMIT );

			// Critical Threshold in minutes
			$threshold = CRITICAL_THRESHOLD;

			// Loop Through Scan Results
			$counter = 0;
			foreach( $results as $result ){
				if( $result['sent_slack'] ) $slack_counter++;
				if( $result['sent_email'] ) $email_counter++;
		
				// If server load is critical		
				if( $this->status == 'Critical' ){
					// Break foreach at the threshold.
					if( $counter == $threshold ){
						break;
					}
				}
				$counter++;
			}

			if( $this->alert_level >= NOTIFICATIONS_THRESHOLD ){
				if( $slack_counter < 1 ){
					$slacked_this_scan = $this->functions->slack_handler( 'D19LY2ZFX', $this->status, $this->hostname, $this->cpu_usage, $this->cores, $this->one_min_avg );
				}

				if( $email_counter < 1 ){
					$emailed_this_scan = $this->functions->email_handler( array( 'demchak.alex@gmail.com' ), $this->status, $this->hostname, $this->cpu_usage, $this->cores, $this->one_min_avg );
				}
			}

			// Save this Scan
			$save = $this->functions->save_scan( $this->mysqli, $this->cpu_usage, $this->one_min_avg, $this->five_min_avg, $this->fifteen_min_avg, $slacked_this_scan, $emailed_this_scan );

			// Have last scans fall off
			$this->functions->delete_scans( $this->mysqli );
		}

		public function shutdown(){
			mysqli_close( $this->mysqli );
		}
	}
?>