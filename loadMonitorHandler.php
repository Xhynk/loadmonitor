<?php
	error_reporting( E_ALL );
	ini_set( 'display_errors', 1 );

	// Start Timer
	$start = microtime( true );
	$count = 0;
	// Require Config File
	require_once dirname( __FILE__ ) . '/inc/config.php';

	spl_autoload_register( function( $class_name ){
		require_once dirname( __FILE__ ) . "/classes/$class_name.class.php";
	});

	// Initialize Load Monitor Class
	$load_monitor = new Load_Monitor();
?>