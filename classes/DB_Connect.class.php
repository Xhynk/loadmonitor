<?php
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
?>