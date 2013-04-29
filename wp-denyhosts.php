<?php
/*
Plugin Name: WP-DenyHosts
Plugin URI: http://pross.org.uk
Description: Block bad login attempts.
Version: 0.9.1
Author: Simon Prosser
*/
class DenyHosts {

	function __construct() {

		add_action( 'init', array( &$this, 'init' ) );

		add_action( 'login_head', array( &$this, 'check_bans' ) );
		add_action('wp_login_failed', array( &$this, 'failed_attempt' ) );

		// do api stuffs...
		add_action('denyhost_cron', array( &$this, 'cron_funcs' ) );
		if( ! wp_next_scheduled( 'denyhost_cron' ) )
			wp_schedule_event( time(), 'daily', 'denyhost_cron' );

		add_action( 'init', array( &$this, 'admin' ) );
	}

	function admin() {

		if( ! is_admin() || ! is_super_admin() )
			return;
		add_action( 'admin_menu', array( &$this, 'denyhosts_menu' ) );
		add_action( 'admin_enqueue_scripts', array( &$this, 'admin_scripts' ) );
		add_action( 'admin_footer', array( &$this, 'placeholder' ) );
		if( is_admin() && isset( $_REQUEST['deny-submit'] ) && check_admin_referer( 'deny-submit', 'deny-options' ) )
			$this->update_options();
	}

	function placeholder() {
		echo '<script>jQuery("textarea[placeholder]").placeholder();</script>';
	}

	function admin_scripts() {
		wp_enqueue_script( 'denyhosts-placeholder', plugins_url( 'js/jquery.placeholder.min.js', __FILE__  ), array( 'jquery' ) );
	}
	function defaults() {
		return array(
			'block_init'		=> 0,
			'block_site'		=> 0,
			'login_limit'		=> 3,
			'email_admin'		=> 0,
			'local_whitelist'	=> '',
			'local_banlist'		=> ''
			);
	}

	function init() {

		$this->options = get_option( 'denyhosts_options', $this->defaults() );

		if( is_admin() )
			return;

		if( $this->options['block_init'] && '/wp-login.php' == $_SERVER['REQUEST_URI'] )
			$this->check_bans();
		if( $this->options['block_site'] )
			$this->check_bans();
	}

	function denyhosts_menu() {
		$hook = add_options_page( 'WP DenyHosts', 'WP DenyHosts', 'manage_options', 'wp-denyhosts', array( &$this, 'admin_options' ) );
	}

	function update_options() {

		$defaults = $this->defaults();

		$opts = wp_parse_args( $_REQUEST, $defaults );

		foreach( $defaults as $key => $option )
			$this->options[$key] = $opts[$key];

		update_option( 'denyhosts_options', $this->options );
	}

	function admin_options() {
		if (!current_user_can('manage_options'))  {
			wp_die( __('You do not have sufficient permissions to access this page.') );
		}
		echo '<div class="wrap">';
		echo '<h1>WP-DenyHosts</h1>';
		echo '<p><form name="denyhosts" method="post" action="">';

		foreach( $this->display_options() as $felement )
			printf( '<p>%s</p>', $felement );

		wp_nonce_field( 'deny-submit','deny-options' );
		echo '</form>';

		echo $this->latest_douchebags();

		echo '</div>';
	}

	function latest_douchebags() {

		$local = get_option( 'denyhosts_bans' );

		$slist = get_option( 'denyhosts_slist' );

		$text = sprintf( _n( '<h3>%d local ban since last update.</h3>', '<h3><strong>%d</strong> Total local bans since last update.</h3>', count( $local ), 'wp-deny-hosts' ), count( $local ) );

		$text .= sprintf( '<h3>%s temporary bans on the network.</h3>', count( $slist['daily'] ) );

		$text .= sprintf( '<h3>%s full bans on the network.</h3>', count( $slist['bans'] ) );

		return $text;
	}


	// return array of displayed options.
	function display_options() {

		return array(

			sprintf( '<input type="checkbox" name="block_init" value="1" %s/> Show Access Denied and pass a 403 header, otherwise show polite blocked message.',
				checked( $this->options['block_init'], 1, false )
			),
			sprintf( '<input type="checkbox" name="block_site" value="1" %s/> Block the offending IP sitewide, not just wp-login.php',
				checked( $this->options['block_site'], 1, false )
			),
			sprintf( '<input type="checkbox" name="email_admin" value="1" %s/> Email admin user when IP is blocked.',
				checked( $this->options['email_admin'], 1, false )
			),

			sprintf( '<select name="login_limit">
				<option value="1" %s>1</option>
				<option value="3" %s>3</option>
				<option value="5" %s>5</option>
				</select> %s',
			selected( $this->options['login_limit'], '1', false ),
			selected( $this->options['login_limit'], '3', false ),
			selected( $this->options['login_limit'], '5', false ),
			__( 'Attempts before IP is blocked.', 'wp-deny-hosts' )
			),

			sprintf( "Add IP to local white list. One IP per line.<br /><textarea cols='25' rows='4' name='local_whitelist' placeholder='100.100.100.98\n98.45.81.10\n192.168.1.1'>%s</textarea>", $this->options['local_whitelist'] ),

			sprintf( "Add IP to local ban list. One IP per line. These IPs will not be uploaded.<br /><textarea cols='25' rows='4' name='local_banlist' placeholder='100.100.100.98\n98.45.81.10\n127.0.0.1'>%s</textarea>", $this->options['local_banlist'] ),

			sprintf( '<input type="submit" name="deny-submit" class="button-primary" value="%s" />',
				esc_attr('Save Changes')
			)
		);
	}

	function cron_funcs() {
		$response = wp_remote_post( 'http://wp-denyhosts.com/api.php', array(
			'method' => 'POST',
			'timeout' => 45,
			'redirection' => 5,
			'httpversion' => '1.0',
			'blocking' => true,
			'headers' => array(),
			'body' => array( 'ips' => get_option( 'denyhosts_bans', array() ), 'reporter' => $_SERVER['REMOTE_ADDR'] ),
			'cookies' => array()
    		)
		);

		$data = json_decode( $response['body'] );
		if( is_object( $data )  && ! empty( $data ) ) {
			update_option( 'denyhosts_slist', array( 'daily' => $data->daily, 'bad' => $data->bad ) );
			update_option( 'denyhosts_bans', array() );
		}
	}

	function check_bans() {

		$data = get_option( 'denyhosts_bans', array() );
		$ip = $_SERVER['REMOTE_ADDR'];
		$whitelist = explode( "\n", $this->options['local_whitelist'] );
		$banlist = explode( "\n", $this->options['local_banlist'] );

		foreach( $whitelist as $l => $white ) {
			if( $white == $ip )
				return;
		}

		foreach( $banlist as $l => $ban ) {
			if( $ban == $ip )
				$this->block();
		}

		if( isset( $data[ $ip ] ) )
			$this->block();

		// shitlist?
		$data = get_option( 'denyhosts_slist', array( 'daily' => array(), 'bad' => array() ) );
		$data = array_merge( $data['daily'], $data['bad'] );

		if( array_search( $ip, $data ) )
			$this->block();
	}

	function failed_attempt( $args ) {

		$data = get_option( 'denyhosts_temp', array() );
		$limit = $this->options['login_limit'];
		$ip = $_SERVER['REMOTE_ADDR'];

		if( isset( $data[ $ip ] ) && count( $data[ $ip ] ) > $limit )
			$this->add_ban( $ip );

		$data[ $ip ][] = $args;

		update_option( 'denyhosts_temp', $data );
	}

	function add_ban( $ip ) {
		$data = get_option( 'denyhosts_bans', array() );
		$temp = get_option( 'denyhosts_temp' );
		$data[ $ip ] = $temp[ $ip ];
		unset( $temp[ $ip ] );

		update_option( 'denyhosts_bans', $data );
		update_option( 'denyhosts_temp', $temp );
		if( $this->options['email_admin'] )
			wp_mail( get_option( 'admin_email' ), 'IP BLOCKED', sprintf( 'IP: %s has just been blocked on %s. Total IPs blocked: %s', $ip, get_option( 'blogname' ), count( $data ) ) );
		$this->block();
	}

	function block() {

		if( $this->options['block_init'] ) {
			header("Status: 403 Forbidden");
			die( '<h1>Access Denied.</h1>');
		}
		?>
		<style type="text/css">html{background:#f9f9f9;}body{background:#fff;color:#333;font-family:sans-serif;-webkit-border-radius:3px;border-radius:3px;border:1px solid #dfdfdf;max-width:700px;height:auto;margin:2em auto;padding:1em 2em;}h1{border-bottom:1px solid #dadada;clear:both;color:#666;font:24px Georgia, "Times New Roman", Times, serif;margin:30px 0 0;padding:0 0 7px;}#error-page{margin-top:50px;}#error-page p{font-size:14px;line-height:1.5;margin:25px 0 20px;}#error-page code{font-family:Consolas, Monaco, monospace;}</style></head>
		<body id='error-page'>
		<?php printf( '<h1>Access Denied!</h1><p>Your IP <strong>%s</strong> has been blocked and logged.</p></body></html>', $_SERVER['REMOTE_ADDR'] );
		exit();
	}
}
new DenyHosts;