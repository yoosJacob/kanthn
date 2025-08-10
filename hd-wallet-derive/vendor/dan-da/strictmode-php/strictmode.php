<?php

namespace strictmode;

/**
 * This file makes three things happen:
 *
 *   1) a global error handler for php errors that causes an exception to be
 *      thrown instead of standard php error handling.
 *
 *   2) a global exception handler for any exceptions that are somehow not
 *      caught by the application code.
 *
 *   3) error_reporting is set to E_STRICT, so that even notices cause an
 *      exception to be thrown.  This way we are forced to deal with even
 *      the minor issues during development, and hopefully fewer issues
  make it out into the world.
 */

// strict mode will be enabled simply by including this file.
init_strict_mode();

// or application can call strictmode\initializer::init()
// this enables autoloading eg via composer.
class initializer {
    static function init() {
        // does nothing.  already init'd when file is first required.
    }
    
    // call this if you need to re-initialize.  typically after your app
    // overrides error or exception handling.
    static function reinit() {
        init_strict_mode();
    }
}
 
/**
 * This will initialize strict mode.  It is safe to be called multiple times per process
 * eg in the event that a 3rd party lib overrides an error or exception handler.
 *
 * It is called in this file; the parent php file(s) should use require_once and do
 * not need to make any call.
 */
function init_strict_mode() {

    // these are safe to call multiple times per process without dups.
    error_reporting( E_ALL | E_STRICT );
    restore_strict_error_handler();
    restore_strict_exception_handler();
   
    // register_shutdown_function should only be called once per process to avoid dups.
    static $called = false;
    if( !$called ) {
    
        register_shutdown_function( "strictmode\shutdown_handler" );
        $called = true;
    }
}

/**
 * This function restores the error handler if it should get overridden
 * eg by a 3rd party lib.  Any error handlers that were registered after
 * ours are removed.
 */
function restore_strict_error_handler() {
    
    $e_handler_name = function() {
        $name = set_error_handler('strictmode\restore_strict_error_handler');  // will never be used.
        restore_error_handler();
        return $name;
    };
    
    while( !in_array( $e_handler_name(), array( 'strictmode\_global_error_handler', null ) ) ) {
        restore_error_handler();
    }
    if( !$e_handler_name() ) {
        set_error_handler( 'strictmode\_global_error_handler' );
    }
}

/**
 * This function restores the exception handler if it should get overridden
 * eg by a 3rd party lib.  Any error handlers that were registered after
 * ours are removed.
 */
function restore_strict_exception_handler() {

    $exc_handler_name = function() {
        $name = set_exception_handler('strictmode\restore_strict_exception_handler'); // will never be used.
        restore_exception_handler();
        return $name;
    };
    
    while( !in_array( $exc_handler_name(), array( 'strictmode\_global_exception_handler', null ) ) ) {
        restore_exception_handler();
    }
    if( !$exc_handler_name() ) {
        set_exception_handler( 'strictmode\_global_exception_handler' );
    }
}

/***
 * This error handler callback will be called for every type of PHP notice/warning/error.
 * 
 * We aspire to write solid code. everything is an exception, even minor warnings.
 *
 * However, we allow the @operator in the code to override.
 */
function _global_error_handler($errno, $errstr, $errfile, $errline ) {
    
    /* from php.net
     *  error_reporting() settings will have no effect and your error handler will
     *  be called regardless - however you are still able to read the current value of
     *  error_reporting and act appropriately. Of particular note is that this value will
     *  be 0 if the statement that caused the error was prepended by the @ error-control operator.
     */
    if( !error_reporting() ) {
        return;
    }

    throw new \ErrorException($errstr, $errno, 0, $errfile, $errline);
}


/***
 * This exception handler callback will be called for any exceptions that the application code does not catch.
 */
function _global_exception_handler( $e ) {
    $msg = sprintf( "\nUncaught Exception. code: %s, message: %s\n%s : %s\n\nStack Trace:\n%s\n", $e->getCode(), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString() );
    while( ( $e = $e->getPrevious() ) ) {
        $msg .= sprintf( "\nPrevious Exception. code: %s, message: %s\n%s : %s\n\nStack Trace:\n%s\n", $e->getCode(), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString() );
    }
    echo $msg;
    // error_log( $msg );
    strict_mode_mail_admin( 'Uncaught exception!', $msg );
    echo "\n\nNow exiting.  Please report this problem to the software author\n\n";
    exit(1);
}

/**
 * This shutdown handler callback prints a message and sends email on any PHP fatal error
 */
function shutdown_handler() {

  $error = error_get_last();

  $ignore = E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_STRICT | E_DEPRECATED | E_USER_DEPRECATED;
  if ( $error && ($error['type'] & $ignore) == 0) {

    // error keys: type, file, line, message
    $msg = "Ouch! Encountered PHP Fatal Error.  Shutting down.\n" . print_r( $error, true );
    echo $msg;
    strict_mode_mail_admin( 'PHP Fatal Error!', $msg );
  }
}

/**
 * email admin if defined
 */
function strict_mode_mail_admin( $subject, $msg ) {
    if( defined('strictmode\ALERTS_DISABLE') ) {
        return;
    }
    
    $subject = sprintf( '[%s] [%s] %s [pid: %s]', gethostname(), basename($_SERVER['PHP_SELF']), $subject, getmypid() );
    if( defined('strictmode\ALERTS_MAILTO') ) {
       $rc = mail( ALERTS_MAILTO, $subject, $msg );
       if( $rc ) {
           echo "\nINFO: alert sent to " . ALERTS_MAILTO . "\n";
       }
       else {
           echo "\nWARNING: alert could not be sent to " . ALERTS_MAILTO . ".  mail() returned false $rc\n";
       }
    }
    else {
        echo "\nWARNING: strictmode\ALERTS_MAILTO not defined in config.  To disable this warning, define 'strictmode\ALERTS_DISABLE'.  alert not sent with subject: $subject\n";
    }
}
