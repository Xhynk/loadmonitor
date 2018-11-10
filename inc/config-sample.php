<?php
	define( 'DB_PREFIX', 'myprefix_' );
	define( 'DB_NAME', DB_PREFIX . 'databasename' );
	define( 'DB_USER', DB_PREFIX . 'usrname' );
	define( 'DB_TABLE', 'table_name' );
	define( 'DB_PASS', 'password' );
	define( 'DB_HOST', 'localhost' );

	define( 'SLACK_WEBHOOK_URL', 'https://hooks.slack.com/services/ABC/123/098ZYX' );

	define( 'DB_ROW_LIMIT', 1440 ); // Max Number of Rows to store (1 per min)
	define( 'NOTIFICATIONS_LIMIT', 60 ); // Minumum Number of Minutes between Non-Critical Notifications
	define( 'CRITICAL_THRESHOLD', 10 ); // Minumum Number of Minutes between Critical Notifications
	define( 'NOTIFICATIONS_THRESHOLD', 3 ) // Minimum Alert Level to send notifications
?>