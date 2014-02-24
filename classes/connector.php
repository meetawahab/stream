<?php
abstract class WP_Stream_Connector {

	/**
	* Name/slug of the context
	* @var string
	*/
	public static $name = null;

	/**
	* Actions this context is hooked to
	* @var array
	*/
	public static $actions = array();

	/**
	* Previous Stream entry in same request
	* @var int
	*/
	public static $prev_stream = null;

	/**
	 * Register all context hooks
	 *
	 * @return void
	 */
	public static function register() {
		$class = get_called_class();

		// Check if logging action is enable for user or provide a hook for plugin to override on specific cases
		if ( ! self::is_logging_enabled_for_user() ) {
			return;
		}
		if ( ! self::is_logging_enabled_for_ip() ) {
			return;
		}

		foreach ( $class::$actions as $action ) {
			add_action( $action, array( $class, 'callback' ), null, 5 );
		}

		add_filter( 'wp_stream_action_links_' . $class::$name, array( $class, 'action_links' ), 10, 2 );
	}

	/**
	 * Callback for all registered hooks throughout Stream
	 * Looks for a class method with the convention: "callback_{action name}"
	 *
	 * @return void
	 */
	public static function callback() {
		$action   = current_filter();
		$class    = get_called_class();
		$callback = array( $class, 'callback_' . str_replace( '-', '_', $action ) );

		//For the sake of testing, trigger an action with the name of the callback
		if ( defined( 'STREAM_TESTS' ) ) {
			/**
			 * Action fires during testing to test the current callback
			 *
			 * @param  array  $callback  Callback name
			 */
			do_action( 'stream_test_' . $callback[1] );
		}

		//Call the real function
		if ( is_callable( $callback ) ) {
			return call_user_func_array( $callback, func_get_args() );
		}
	}

	/**
	 * Add action links to Stream drop row in admin list screen
	 *
	 * @filter wp_stream_action_links_{connector}
	 * @param  array $links      Previous links registered
	 * @param  int   $record     Stream record
	 * @return array             Action links
	 */
	public static function action_links( $links, $record ) {
		return $links;
	}


	/**
	 * Check if we need to record action for specific users
	 *
	 * @param null $user
	 *
	 * @return mixed|void
	 */
	public static function is_logging_enabled_for_user( $user = null ) {
		if ( is_null( $user ) ){
			$user = wp_get_current_user();
		}

		// If the user is not a valid user then we log action
		if ( ! ( $user instanceof WP_User ) || $user->ID === 0 ) {
			$bool = true;
		} else {
			// If a user is part of a role that we don't want to log, we disable it
			$user_roles   = array_values( $user->roles );
			$roles_logged = WP_Stream_Settings::$options['exclude_authors_and_roles'];
			$bool         = ( count( array_intersect( $user_roles, $roles_logged ) ) === 0 );
			//Check user id in exclude array
			if ( $bool ){
				$bool = ! ( in_array( $user->ID , $roles_logged ) );
			}
		}

		/**
		 * Filter sets boolean result value for this method
		 *
		 * @param  bool
		 * @param  obj    $user  Current user object
		 * @param  string        Current class name
		 * @return bool
		 */
		return apply_filters( 'wp_stream_record_log', $bool, $user, get_called_class() );
	}

	/**
	 * Check if we need to record action for IP
	 *
	 * @param null $ip
	 *
	 * @return mixed|void
	 */
	public static function is_logging_enabled_for_ip( $ip = null ) {
		if ( is_null( $ip ) ){
			$ip = filter_input( INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP );
		} else {
			$ip = filter_var( $ip, FILTER_VALIDATE_IP );
		}

		// If ip is not valid the we will log the action
		if ( $ip === false ) {
			$bool = true;
		} else {
			$bool = self::is_logging_enabled( 'ip_addresses', $ip );
		}

		/**
		 * Filter sets boolean result value for this method
		 *
		 * @param  bool
		 * @param  obj    $user  Current user object
		 * @param  string        Current class name
		 * @return bool
		 */

		$user = wp_get_current_user();
		return apply_filters( 'wp_stream_record_log', $bool, $user, get_called_class() );
	}

	/**
	 * @param $action string action slug to check whether logging is enable or not for that action
	 *
	 * @return bool
	 */
	public static function is_logging_enabled_for_action( $action ) {
		return self::is_logging_enabled( 'actions', $action );
	}

	/**
	 * @param $context string context slug to check whether logging is enable or not for that context
	 *
	 * @return bool
	 */
	public static function is_logging_enabled_for_context( $context ) {
		return self::is_logging_enabled( 'contexts', $context );
	}

	/**
	 * This function is use to check whether logging is enabled
	 *
	 * @param $column string name of the setting key (actions|ip_addresses|contexts|connectors)
	 * @param $value string to check in excluded array
	 * @return array
	 */
	public static function is_logging_enabled( $column, $value ){

		$excluded_values = WP_Stream_Settings::get_excluded_by_key( $column );
		$bool = ( ! in_array( $value, $excluded_values ) ) ;

		return $bool;
	}
	/**
	 * Log handler
	 *
	 * @param  string $message   sprintf-ready error message string
	 * @param  array  $args      sprintf (and extra) arguments to use
	 * @param  int    $object_id Target object id
	 * @param  array  $contexts  Contexts of the action
	 * @param  int    $user_id   User responsible for the action
	 *
	 * @internal param string $action Action performed (stream_action)
	 * @return void
	 */
	public static function log( $message, $args, $object_id, $contexts, $user_id = null ) {
		//Prevent inserting Excluded Context & Actions
		foreach ( $contexts as $context => $action ){
			if ( ! self::is_logging_enabled_for_context( $context ) ){
				unset( $contexts[$context] );
			} else if ( ! self::is_logging_enabled_for_action( $action ) ){
				unset( $contexts[$context] );
			}
		}
		if ( count( $contexts ) == 0 ){
			return ;
		}
		$class = get_called_class();

		return WP_Stream_Log::get_instance()->log(
			$class::$name,
			$message,
			$args,
			$object_id,
			$contexts,
			$user_id
			);
	}

	/**
	 * Save log data till shutdown, so other callbacks would be able to override
	 *
	 * @param  string $handle Special slug to be shared with other actions
	 *
	 * @internal param mixed $arg1 Extra arguments to sent to log()
	 * @internal param mixed $arg2 , etc..
	 * @return void
	 */
	public static function delayed_log( $handle ) {
		$args = func_get_args();
		array_shift( $args );

		self::$delayed[$handle] = $args;
		add_action( 'shutdown', array( __CLASS__, 'delayed_log_commit' ) );
	}

	/**
	 * Commit delayed logs saved by @delayed_log
	 * @return void
	 */
	public static function delayed_log_commit() {
		foreach ( self::$delayed as $handle => $args ) {
			call_user_func_array( array( __CLASS__, 'log' ) , $args );
		}
	}

}
