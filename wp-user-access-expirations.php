<?php

/**
 *	Plugin Name: WP User Access Expirations
 *	Plugin URI: https://github.com/jonalvarezz/wp-user-access-expirations
 *	Description: Expires a user's access to a site after a specified number of days based upon the registration date. The administrator can restore a user's access from the user's profile page.
 *	Version: 0.5
 *	License: GPL V2
 *	Author: Jonathan ALvarez <@jonalvarezz> - Original by Nate Jacobs <nate@natejacobs.org>
 *	Author URI: https://github.com/jonalvarezz
 */
 
class UserAccessExpiration
{	
	// the plugin options meta key	
	CONST option_name = "user_access_expire_options";
	// the custom user meta key
	CONST user_meta = 'uae_user_access_expired';
	// custom user meta key to handle operations with expired dates
	CONST user_meta_expire_date = 'uae_user_access_expired_date';
	// custom user meta key to control the number of notifications sent
	CONST user_meta_expire_count = 'uae_user_notification_count';

	// hook into user registration and authentication
	public function __construct()
	{
		// since 0.1
		add_action( 'user_register', array( __CLASS__, 'set_expiration_timer' ) );
		add_filter( 'authenticate', array( __CLASS__, 'check_user_access_status' ), 10, 3 );
		add_action( 'admin_menu', array( __CLASS__, 'add_user_expire_submenu' ) );
		add_action('admin_init', array( __CLASS__, 'options_init' ));
		register_activation_hook( __FILE__, array( __CLASS__, 'activation' ) );
		add_action('notify_expire_users_cron', 'do_cron');

		// since 0.2
		add_action( 'show_user_profile', array( __CLASS__, 'add_user_profile_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'add_user_profile_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_user_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_user_profile_fields' ) );		
	}
	
	/** 
	 *	Activation
	 *
	 *	Upon plugin activation create a custom user meta key of user_access_expired
	 *	for all users and set the value to false (access is allowed). Also adds
	 *	default settings for the log in error message and number of days of access.
	 *
	 *	@author		Nate Jacobs
	 *	@since		0.1
	 *	@update		0.2 (add_option)
	 */
	public function activation()
	{
		// get settings
		$options = get_option( self::option_name );
		// limit user data returned to just the id
		$args = array( 'fields' => array( 'ID', 'user_registered' ) );
		$users = get_users( $args );
		// loop through each user
		foreach ( $users as $user )
		{
			// add the custom user meta to the wp_usermeta table
			add_user_meta( $user->ID, self::user_meta, 'false' );

			// add expire date to get easily from a WP_User_Query
			$reg_date = strtotime( $user->user_registered );
			$expire_date = date( 'Y-m-d H:i:s', strtotime( '+'.$options['number_days'].'days', $reg_date ) );
			add_user_meta( $user->ID, self::user_meta_expire_date, ''.$expire_date );

			// initialice notification count
			add_user_meta( $user->ID, self::user_meta_expire_count, '0' );
		}
		
		// add option with base information
		add_option( 
			self::option_name, 
			array( 
				'error_message' => 'To gain access please contact us.',
				'number_days' => '30',
				'notify_days' => '15',
				'notify_text' => 'Your suscription will expire in less than 15 days. Please Contact us',
				'notify_subject' => 'Your suscription is going to expire!'
			),
			'',
			'yes'
		);

		// Cron daily notify messages
		wp_schedule_event( time(), 'daily', 'notify_expire_users_cron');
	}
	
	/** 
	 *	do_cron
	 *
	 *	Send notification message to users.
	 *
	 *	@author		jonalvarezz
	 *	@since		0.2
	 */
	public function do_cron()
	{
		$options = get_option( self::option_name );
		$subject = $options['notify_subject'];
		$headers = 'From: Comunicaciones Invertir Mejor <comunicaciones@invertirmejor.com>' . "\r\n";
		$message = $options['notify_text'];
		$range1 = date( 'Y-m-d H:i:s', date('U') );
		$range2 = date( 'Y-m-d H:i:s', strtotime( '+15 days' ) );

		$args = array(
			'orderby' => 'registered',
			'order' => 'ASC',
			'fields' => array( 'ID', 'user_registered', 'user_email'),
			'meta_query' => array(
				'relation' => 'AND',
				array(
					'key' => self::user_meta_expire_date,
					'value' => array($range1,$range2),
					'type' => 'DATETIME',
					'compare' => 'BETWEEN'
				),
				array(
					'key' => self::user_meta_expire_count,
					'value' => '1',
					'type' => 'numeric',
					'compare' => '<'
				)
			)
		);
		$users = get_users( $args );
		
		foreach ( $users as $user ) {
			wp_mail( array($users->user_email,'jonathan@invertirmejor.com'), $subject, $message, $headers );
		}
	}

	/** 
	 *	Set Expiration Timer
	 *
	 *	Adds a custom user meta key of user_access_expired when a new user is registered.
	 *	It sets the initial value to false. This indicates the user still has access.
	 *
	 *	@author		Nate Jacobs
	 *	@since		0.1
	 *
	 *	@param	int	$user_id
	 */
	public function set_expiration_timer( $user_id )
	{
		add_user_meta( $user_id, self::user_meta, 'false' );
	}
	
	/** 
	 *	Check User Access Status
	 *
	 *	Takes the credentials entered by the user on the login form and grabs the user_id
	 *	from the login name. Gets the value of the user meta field set up by the 
	 *	set_expiration_timer method. Also gets the user registered date/time. If the specified 
	 *	time frame has elapsed then the user is denied access.
	 *
	 *	@author		Nate Jacobs
	 *	@since		0.1
	 *	@updated	0.4
	 *
	 *	@param	string	$user
	 *	@param	string	$user_login
	 *	@param	string	$password
	 *	@return	mixed	$user ( either an error or valid user )	
	 */
	public function check_user_access_status( $user, $user_login, $password )
	{
		// get user data by login
		$user_info = get_user_by( 'login', $user_login );
		$access_expiration = '';
		$expire_time = '';
		$new_time = '';
		$expired = '';
		
		// if the user has entered something in the user name box
		if ( $user_info )
		{
			// get the plugin options
			$options = get_option( self::option_name );
			// get the custom user meta defined earlier
			$access_expiration = get_user_meta( $user_info->ID, self::user_meta, true );
			// get the user registered time
			$register_time = strtotime( $user_info->user_registered );
			// get the date in unix time that is the specified number of elapsed days from the registered date
			$expire_time = strtotime( '+'.$options['number_days'].'days', $register_time );
			
			if( $expire_time < date( 'U' ) )
			{
				if( user_can($user_info->ID, 'manage_options') )
				{
					$expired = false;
				}
				else
				{
					$expired = true;
				}
			}
		}
		
		if ( empty( $user_login ) || empty( $password ) )
		{
			if ( empty( $username ) )
				$user = new WP_Error('empty_username', __('<strong>ERROR</strong>: The username field is empty.'));
	
			if ( empty( $password ) )
				$user = new WP_Error('empty_password', __('<strong>ERROR</strong>: The password field is empty.'));
		}
		else
		{
			// if the custom user meta field is true ( access is expired ) or the current date is more than
			// the specified number of days past the registered date, deny access
			if ( $access_expiration == 'true' || $expired )
			{
				// change the custom user meta to show access is now denied
				update_user_meta( $user_info->ID, self::user_meta, 'true' );
				// register a new error with the error message set above
				$user = new WP_Error( 'access_denied', __( '<strong>Su acceso al sitio ha expirado</strong><br>'.$options['error_message'] ) );
				// deny access to login and send back to login page
				remove_action( 'authenticate', 'wp_authenticate_username_password', 20 );
			}
		}	
		return $user;
	}
	
	/** 
	 *	Add Submenu Page
	 *
	 *	Adds a submenu page to settings page for the user entered settings.
	 *
	 *	@author		Nate Jacobs
	 *	@since		0.2
	 */
	public function add_user_expire_submenu()
	{
		add_submenu_page(
			'options-general.php',
			__( 'User Access Expiration' ),
			__( 'User Expiration' ),
			'manage_options',
			'user-access-expiration',
			array( __CLASS__, 'user_access_expire_settings' )
		);
	}
	
	/** 
	 *	Initiate Options
	 *
	 *	Create the options needed for the settings API.
	 *
	 *	@author		Nate Jacobs
	 *	@since		0.2
	 */
	public function options_init()
	{
		register_setting( 
			'user_access_expire_options',
			'user_access_expire_options',
			array( __CLASS__, 'user_access_expire_options_validate' )
		);
		add_settings_section(
			'primary_section',
			'', //section title
			array( __CLASS__, 'primary_section_text' ),
			__FILE__
		);
		
		$settings_fields = array(
			array(
				'id' => 'number_of_days',
				'title' => 'Number of Days',
				'function' => 'setting_number_days',
				'section' => 'primary_section'
			),
			array(
				'id' => 'error_message',
				'title' => 'Error Message',
				'function' => 'setting_error_message',
				'section' => 'primary_section'
			),
			array(
				'id' => 'notify_days',
				'title' => 'Notify message number of days',
				'function' => 'setting_notify_days',
				'section' => 'primary_section'
			),
			array(
				'id' => 'notify_subject',
				'title' => 'Email Subject of notify Message',
				'function' => 'setting_notify_subject',
				'section' => 'primary_section'
			),
			array(
				'id' => 'notify_text',
				'title' => 'Notify Message',
				'function' => 'setting_notify_text',
				'section' => 'primary_section'
			),
		);
		
		foreach( $settings_fields as $settings )
		{
			add_settings_field(
				$settings['id'],
				$settings['title'],
				array( __CLASS__, $settings['function'] ),
				__FILE__,
				$settings['section']
			);
		}
	}
	
	/** 
	 *	Primary Section Text
	 *
	 *	Not used at this point, but method provided for potential future use.
	 *
	 *	@author		Nate Jacobs
	 *	@since		0.2
	 */
	public function primary_section_text()
	{
		
	}
	
	/** 
	 *	Number of Days to Expire
	 *
	 *	Provides field to allow administrators to set how many days a user's access
	 *	should be expired after.
	 *
	 *	@author		Nate Jacobs
	 *	@since		0.2
	 */
	public function setting_number_days()
	{
		$options = get_option( self::option_name );
		//{$this->get_settings( 'user-access-expiration' )}
		echo "<input id='number_of_days' name='user_access_expire_options[number_days]' size='10' type='text' value='{$options['number_days']}' />";
		echo "<br>How many days after registration should a user have access for?";
	}
	
	/** 
	 *	Error Message 
	 *
	 *	Provides field to allow administrators to set the error message a user sees
	 *	once their access has expired.
	 *
	 *	@author		Nate Jacobs
	 *	@since		0.2
	 */
	public function setting_error_message()
	{
		$options = get_option( self::option_name );
		echo "<input id='error_message' name='user_access_expire_options[error_message]' size='75' type='text' value='{$options['error_message']}' />";
		echo "<br>This message is displayed to a user once their access is denied.";
		echo "<br><b>Example:</b> To gain access please contact us at myemail@myexample.com.";	
	}

	/** 
	 *	Expire Notify
	 *
	 *	Provides fields to allow administrators to set a the amount of days and the text within a expire
	 *  notification message should be sent via email to the user.
	 *
	 *	@author		jonalvarezz
	 *	@since		0.2
	 */
	public function setting_notify_days()
	{
		$options = get_option( self::option_name );
		echo "<input id='notify_days' name='user_access_expire_options[notify_days]' type='number' size='10' value='{$options['notify_days']}' />";
		echo "<span> days left.</span><br>";	
		echo "<br>How many days left a notification message should be sent to the user";
	}
	public function setting_notify_subject()
	{
		$options = get_option( self::option_name );
		echo "<input id='notify_subject' name='user_access_expire_options[notify_subject]' type='text' size='75' value='{$options['notify_subject']}' />";
		echo "<br>Notify message email header";
	}
	public function setting_notify_text()
	{
		$options = get_option( self::option_name );
		echo "<textarea id='notify_text' name='user_access_expire_options[notify_text]' rows='6' cols='75'>{$options['notify_text']}</textarea>";
		echo "<br>This is the message the user will receive";	
	}
	
	/** 
	 *	Validate and Clean Options
	 *
	 *	Takes the values entered by the user and validates and cleans the input
	 *	to prevent xss or other mean things.
	 *	Checks the number of days entered value to make sure it is a number.
	 *
	 *	@author		Nate Jacobs
	 *	@since		0.2
	 */
	public function user_access_expire_options_validate( $input )
	{
		$valid_input['error_message'] =  wp_filter_nohtml_kses( $input['error_message'] );
		$input['number_days'] =  trim( $input['number_days'] );
		$valid_input['number_days'] = ( is_numeric( $input['number_days'] ) ) ? $input['number_days'] : '';

		$valid_input['notify_text'] = $input['notify_text'];
		$valid_input['notify_days'] = $input['notify_days'];
		$valid_input['notify_subject'] =  wp_filter_nohtml_kses( $input['notify_subject'] );
		
		if ( is_numeric( $input['number_days'] ) == FALSE )
		{
			add_settings_error(
				$input['number_days'],
				'_txt_numeric_error',
				__( 'Sorry that is not a number. Please enter a number.	' ),
				'error'
			);
		}
		return $valid_input;
	}
	
	/** 
	 *	Add Content for Settings Page
	 *
	 *	Create the settings page for the plugin.
	 *
	 *	@author		Nate Jacobs
	 *	@since		0.2
	 */
	public function user_access_expire_settings()
	{
		?>
		<div class="wrap">
			<?php settings_errors(); ?>
			<div class="icon32" id="icon-options-general"><br></div>
			<h2><?php _e( 'User Access Expiration Settings' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'user_access_expire_options' ); ?>
				<?php do_settings_sections( __FILE__ ); ?>
				<p class="submit">
				<input type="submit" class="button-primary" value="<?php _e( 'Save Changes' ) ?>" />
				</p>
			</form>
		</div>
		<?php
	}
	
	/** 
	 *	Add User Profile Field
	 *
	 *	Adds an extra field to the user profile page. Allows an administrator
	 *	to change a specific user's access. 
	 *
	 *	@author		Nate Jacobs
	 *	@since		0.2
	 *
	 *	@param	object	$user
	 */
	 public function add_user_profile_fields( $user )
	 {
	 	if ( current_user_can( 'manage_options', $user->ID ) )
		{
		?>
		<h3>User Access Expiration</h3>
		<table class="form-table">			
		<tr>
			<th>Registered date: </th>
			<td><?php echo get_the_author_meta( 'user_registered', $user->ID ); ?></td>
		</tr>
		<tr>			
			<th><label for="user-access">Does this person have access to the site?</label></th>
			<td>
				<?php $access = get_the_author_meta( self::user_meta, $user->ID ); ?>
				<select id="user-access" name="user-access" class="regular-text">
					<option value="false" <?php if ( $access == 'false' ) echo "selected"; ?>>Yes</option>
					<option value="true" <?php if ( $access == 'true' ) echo "selected"; ?>>No</option>
				</select>
			</td>
		</tr>
		</table>
		<?php
		}
	 }
	
	/** 
	  *	Save User Profile Fields
	  *
	  *	Saves the access value for the user.
	  *
	  *	@author		Nate Jacobs
	  *	@since		0.2
	  *
	  *	@param	int	$user_id
	  */ 
	public function save_user_profile_fields( $user_id )
	{
		if( !current_user_can( 'manage_options', $user_id ) )
			return false;
		
		update_user_meta( $user_id, self::user_meta, $_POST['user-access'] );
	}
}
new UserAccessExpiration();