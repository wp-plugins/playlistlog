<?php
/*
 Plugin Name: Playlist Log
 Plugin URI: https://github.com/petermolnar/wordpress-playlistlog
 Description: Keep track of what you've been listening to
 Author: cadeyrn
 Author URI: https://petermolnar.eu
 Version: 0.3
*/

// check if class already exists
if (!class_exists("WPPlaylistLog")) :

class WPPlaylistLog {
	// endpoint query var
	const query_var = 'playlistlog';

	const term_artist = 'playlistlog_artist';
	const term_album = 'playlistlog_album';

	const option_sessions = 'playlistlog_sessions';
	const option_userpassword = 'playlistlog_password';

	const session_valid = 120000;

	private $sessions = array();
	private $user = null;
	private $importfile = null;
	private $tmpdir = null;

	public function __construct () {

		register_activation_hook( __FILE__ , array( &$this, 'plugin_activate' ) );
		register_deactivation_hook( __FILE__ , array( &$this, 'plugin_deactivate' ) );

		add_action('init', array(&$this, 'init'));

		// take care of urls
		add_action('init', array(&$this, 'url_rewrite'));

		// password field
		add_action( 'show_user_profile', array(&$this, 'password_field') );
		add_action( 'edit_user_profile', array(&$this, 'password_field') );
		add_action( 'personal_options_update', array(&$this, 'save_password_field') );
		add_action( 'edit_user_profile_update', array(&$this, 'save_password_field') );
	}

	public function init () {
		//self::debug('initializing');

		add_filter('query_vars', array(&$this, 'query_var'));
		add_action('parse_query', array(&$this, 'parse_query'));

		//self::debug('registering post type ' . static::query_var );
		register_post_type( static::query_var, array(
			'label' => 'PlaylistLog',
			'public' => true,
			'exclude_from_search' => true,
			'show_ui' => true,
			'menu_position' => 20,
			'menu_icon' => 'dashicons-media-audio',
			'hierarchical' => false,
			'supports' => array ( 'title', 'custom-fields' ),
			'can_export' => true,
		));

		// artist
		//self::debug('registering taxonomy ' . static::term_artist );
		register_taxonomy( static::term_artist, static::query_var , array (
			'label' => __('Artist'),
			'public' => true,
			'show_ui' => true,
			'hierarchical' => false,
			'show_admin_column' => true,
			//'rewrite' => array( 'slug' => 'foto' ),
		));

		// album
		//self::debug('registering taxonomy ' . static::term_album );
		register_taxonomy( static::term_album, static::query_var , array (
			'label' => __('Album'),
			'public' => true,
			'show_ui' => true,
			'hierarchical' => false,
			'show_admin_column' => true,
			//'rewrite' => array( 'slug' => 'foto' ),
		));

	}

	/**
	 * plugin activation hook
	 */
	public function plugin_activate() {
		self::debug('activating');
		flush_rewrite_rules();
	}

	/**
	 * plugin deactivation hook; destroy valid sessions
	 */
	public function plugin_deactivate () {
		self::debug('deactivating');
		delete_option( static::option_sessions );
		flush_rewrite_rules();
	}

	/**
	 * additional query variables for WP query to look for
	 *
	 * @param array $vars - current vars
	 *
	 * @return array $vars - return with additional vars
	 */
	public static function query_var($vars) {
		//self::debug('adding query var ' . static::query_var);
		$vars[] = static::query_var;
		return $vars;
	}

	/**
	 *
	 */
	public function url_rewrite () {
		$match = static::query_var . '/?(.*)$';
		$replace = 'index.php?'. static::query_var .'&$matches[1]';
		//self::debug('adding rewrite rule:' . $match . ' => ' . $replace);
		add_rewrite_rule( $match, $replace, 'top');
	}

	/**
	 * get stored session ids from database
	 * also removes all the expired requests
	 *
	 */
	private function get_sessions() {
		$valid_sessions = array();
		$sessions = get_option( static::option_sessions );

		if (!empty($sessions) && is_array($sessions)) {
			foreach ($sessions as $hash => $session ) {
				$time = time();
				if ( isset($session['timestamp']) && $session['timestamp'] > ( $time - static::session_valid) ) {
					self::debug("\tsession valid");
					$valid_sessions [ $hash ] = $session;
				}
				else {
					self::debug('session expired: ' . json_encode($session));
				}
			}
		}

		if ( count($sessions) != count($valid_sessions)) {
			update_option ( static::option_sessions, $valid_sessions );
		}

		self::debug('valid sessions: ' . json_encode($valid_sessions));
		return $valid_sessions;
	}

	/**
	 * generate playlistlog password field for Profile page
	 *
	 * @param mixed $user - WP User object
	 */
	public function password_field( $user ) {
		self::debug('displaying password field');
		$pass = get_user_meta( $user->ID, static::option_userpassword, true );

		?>
		<table class="form-table">
			<tbody>
				<tr valign="top">
					<th scope="row"><label for="<?php echo static::option_userpassword ?>"><?php _e('Audioscrobbler password') ?></label></th>
					<td>
						<input type="password" value="<?php echo esc_attr( $pass ); ?>" name="<?php echo static::option_userpassword ?>" id="<?php echo static::option_userpassword ?>" />
						<p class="description"><strong><?php _e('WARNING: this password is stored unencrypted and is not safe.');?></strong><br /><?php _e('It should be different from you login password. This is due to technical limitations of the playlistlog protocol.'); ?></p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Save playlistlog password field
	 *
	 * @param int $user_id - user ID
	 *
	 */
	public function save_password_field( $user_id ) {
		self::debug('processing password field');
		if ( !current_user_can( 'edit_user', $user_id ) )
			return false;

		if (isset($_POST[ static::option_userpassword ]) && !empty($_POST[ static::option_userpassword ])) {

			$new = sanitize_text_field($_POST[ static::option_userpassword ]);

			$cur = get_user_meta( $user_id, static::option_userpassword, true );

			update_usermeta( $user_id, static::option_userpassword, $new, $cur);
		}
	}

	/**
	 * send HTTP response
	 *
	 * @param array $response - array with text and code fields containing HTTP status code and text to return with
	 *
	 *
	 */
	private function response ( $response ) {
		self::debug ( 'Sending response ' . $response['code'] . ' ' . $response['text'] );
		header('Content-Type: text/plain; charset=' . get_option('blog_charset'));
		status_header( $response['code'] );
		echo $response['text'];
		exit;
	}

	/**
	 * @param mixed $wp - WP Query object
	 *
	 */
	private function validate_session ( &$wp ) {
		if (!isset($_POST['s']) || empty($_POST['s'])) {
			return false;
		}

		$current_sessions = $this->get_sessions();
		$sessionid = sanitize_text_field( $_POST['s'] );
		self::debug('Session ID posted: ' . $sessionid );
		self::debug('Current sessions: ' . json_encode($current_sessions) );

		if (array_key_exists( $sessionid, $current_sessions)) {
			return $current_sessions[$sessionid];
		}

		return false;
	}

	/**
	 * Parse the WebMention request and render the document
	 *
	 * @param WP $wp WordPress request context
	 *
	 * @uses do_action() Calls 'webmention_request' on the default request
	 */
	public function parse_query($wp) {
		// check if it is an playlistlog request or not
		if (!array_key_exists(static::query_var, $wp->query_vars)) {
			return;
		}

		self::debug('Potential Audioscrobbler request caught');

		if (!empty($_POST)) {
			$this->scrobbler( $wp );
		}
		else {
			$this->handshake($wp);
		}
	}

	/**
	 * creates & stores session id for valid handshake request
	 *
	 * @param string $user
	 * @param epoch $timestamp
	 *
	 * @return string session id
	 *
	 */
	private function add_session ( $userid, $timestamp ) {

		// successfull handshake authenticated, generate session id
		$sessionid = hash( 'sha256', $user . $timestamp );
		$current_sessions = $this->get_sessions();

		if ( !array_key_exists($sessionid, $current_sessions)) {
			$session = array ( 'userid' => $userid, 'timestamp' => $timestamp );
			self::debug('adding new session: ' . json_encode($session));
			$current_sessions[ $sessionid ] = $session;

			update_option ( static::option_sessions, $current_sessions );
		}

		return $sessionid;
	}

	/**
	 *
	 * @param string $username -
	 * @param string $pass - hashed password sent in the request
	 * @param epoch timestamp - request timestamp
	 *
	 * @return bool false on invalid, true on valid authentication
	 *
	 */
	private function auth ( $username, $pass, $timestamp ) {
		$user = get_user_by( 'login', $username );
		if ( !$user ) {
			self::debug('Authentication failed: user not found: ' . $username);
			return false;
		}

		$pass_stored = get_user_meta( $user->ID, static::option_userpassword, true );
		$pass_stored = md5( md5($pass_stored) . $timestamp );
		self::debug('Authenticating ' . $username . ' with ' . $pass_stored . ' against ' . $pass);

		if ( $pass == $pass_stored ) {
			self::debug('Authentication for ' . $username . ' was successfull');
			return $user->ID;
		}

		return false;
	}

	/**
	 * scrobbler endpoint parsing
	 *
	 * @param mixed $wp - request params
	 */
	private function scrobbler ( &$wp ) {

		self::debug('Scrobbler initiated from ' . $_SERVER['REMOTE_ADDR']);

		$session = $this->validate_session($wp);
		if ( $session == false || empty($session) ) {
			$response = array (
				'text' => 'BADSESSION',
				'code' => 401,
			);
			$this->response ($response);
		}

		self::debug('Scrobbler started for user ' . $session['userid']);

		$data = array (
			'artist' => 'a',
			'track'  => 't',
			'album' => 'b',
			'n' => 'n',
			'm' => 'm',
			'played_at' => 'i', // unix epoch
			'o' => 'o',
			'l' => 'l',
		);

		$identifier = 't';
		if ( isset($_POST[ $identifier ]) && !empty($_POST[ $identifier ]) && !is_array($_POST[ $identifier ])) {
			// Now playing request, not implemented yet
			self::debug('NOW PLAYING request; not implemented');
			$response = array (
				'text' => 'OK',
				'code' => 200,
			);
			$this->response ($response);
		}

		foreach ( $data as $name => $identifier ) {
			if ( isset($_POST[ $identifier ]) && !empty($_POST[ $identifier ])) {

				if (!is_array($_POST[ $identifier ])) {
					$response = array (
						'text' => 'BADREQUEST',
						'code' => 500,
					);
					$this->response ($response);
				}

				foreach ( $_POST[ $identifier ] as $id => $data ) {
					$field = $_POST[ $identifier ][ $id ];
					$tracks[$id][ $name ] = sanitize_text_field($field);
				}
			}
		}

		foreach ($tracks as $id => $track ) {

			self::debug('Processing track: ' . json_encode($track));
			$title = $track['track'];
			$trackmeta = array();
			$metas = array('n', 'm', 'o', 'l' );
			foreach ( $metas as $meta ) {
				if (isset($track[$meta]) && !empty($track[$meta])) {
					$trackmeta[$meta] = $track[$meta];
				}
			}
			$played_at = (isset($track['played_at']) && !empty($track['played_at'])) ? $track['played_at'] : time();

			$this->add_track ( $session['userid'], $title, $played_at, $trackmeta, $track['artist'], null, $track['album'], null );


			//$terms = array (
				//'artist' => static::term_artist,
				//'album' => static::term_album,
			//);

			//// insert the post


			//$post = array(
			  //'post_title' => $track['track'],
			  //'post_status' => 'private',
			  //'post_type' => static::query_var,
			  //'post_author' => $session['userid'],
			  //'ping_status' => 'closed',
			  //'post_date'      => date('Y-m-d H:i:s', $played_at ),
			  //'post_date_gmt'  => date('Y-m-d H:i:s', $played_at ),
			  //'comment_status' => 'closed',
			//);

			//$post_id = wp_insert_post( $post, true );

			//if (is_wp_error($post_id)) {
				//self::debug('ERROR: ' . $post_id->get_error_message());
				//$response = array (
					//'text' => 'ERROR',
					//'code' => 500,
				//);
				//$this->response ($response);
			//}

			//self::debug('Track inserted with ID: ' . $post_id);

			//// meta to store
			//$metas = array('tracknumber', 'length', 'musicbrainzid', 'source' );
			//foreach ( $metas as $meta ) {
				//if (isset($track[$meta]) && !empty($track[$meta])) {
					//self::debug('Adding meta ' . $meta . ' for track ' . $post_id);
					//add_post_meta($post_id, $meta, $track[$meta], true);
				//}
			//}

			//foreach ( $terms as $key => $taxonomy ) {
				//// check for artist taxonomy
				//if ( ! term_exists( $track[ $key ], $taxonomy )) {
					//self::debug('Inserting term ' . $track[$key] . ' to ' . $taxonomy );
					//wp_insert_term( $track[ $key ], $taxonomy );
				//}

				//self::debug('Adding ' . $taxonomy . ': ' . $track[$key] . ' for ' . $post_id );
				//wp_set_post_terms( $post_id, $track[$key], $taxonomy );
			//}

		}

		$response = array (
			'text' => 'OK',
			'code' => 200,
		);
		$this->response ($response);
	}


	/**
	 * handshake request parse
	 *
	 * @param mixed $wp - request params
	 *
	 *
	 */
	private function handshake ( &$wp ) {

		// TODO Banned check

		// check for 'hs' key - if not present, this is not a handshake request
		if (!isset($_GET['hs'])) {
			$response = array (
				'text' => 'Welcome to WordPress AudioScrobbler',
				'code' => 200,
			);
			$this->response ($response);
		}

		self::debug('Handshake initiated from ' . $_SERVER['REMOTE_ADDR']);

		$required_vars  = array (
			'user',
			'timestamp',
			'auth',
			'client-id'
		);

		$failed = false;


		foreach ( $required_vars as $var ) {
			$parsed_vars[ $var ] = false;
			// only the first letter is needed
			// I used the full name for readability
			$var_ = $var[0];

			if ( isset($_GET[ $var_ ]) && !empty($_GET[ $var_ ]) ) {
				$parsed_vars[$var] = $_GET[ $var_ ];
			}
			else {
				$failed = array (
					'code' => 400,
					'text' => "FAILED missing or empty ". $var,
				);
			}
		}

		// check timestamp
		$currtime = time();
		$timestamp = (int) $parsed_vars['timestamp'];

		if ( ($currtime - $timestamp) > static::session_valid ) {
			$failed = array (
				'code' => 400,
				'text' => "BADTIME",
			);
		}

		// authenticate
		$pass = sanitize_text_field( $parsed_vars['auth'] );
		$user = sanitize_user( $parsed_vars['user'] );

		$userid = $this->auth( $user, $pass, $timestamp);
		if ( $userid == false || empty($userid) ) {
			self::debug('Authentication failed for user ' . $user );
			$failed = array (
				'code' => 401,
				'text' => "BADAUTH",
			);
		}

		// failed request, kthxbye
		if ( !empty($failed) && is_array($failed)) {
			$this->response( $failed );
		}

		// good request, generate session id
		$sessionid = $this->add_session($userid, $timestamp);

		$nowplaying_endpoint = site_url("/". static::query_var);

		$scrobbler_endpoint = site_url("/". static::query_var);

		$response = array (
			'code' => 200,
			'text' => "OK\n" . $sessionid . "\n" . $nowplaying_endpoint . "\n" . $scrobbler_endpoint,
		);
		$this->response($response);
	}

	private function add_track ( $userid, $track, $time = false, $trackmeta = array(), $artist = false, $artistmeta = array(), $album = false,  $albummeta = array() ) {


		$post = array(
			'post_title' => $track,
			'post_status' => 'private',
			'post_type' => static::query_var,
			'post_author' => $userid,
			'ping_status' => 'closed',
			'post_date' => date('Y-m-d H:i:s', $time ),
			'post_date_gmt'  => date('Y-m-d H:i:s', $time ),
			'comment_status' => 'closed',
		);

		$post_id = wp_insert_post( $post, true );

		if (is_wp_error($post_id)) {
			self::debug('ERROR: ' . $post_id->get_error_message());
			$response = array (
				'text' => 'ERROR',
				'code' => 500,
			);
			$this->response ($response);
		}

		self::debug('Track inserted with ID: ' . $post_id);

		// meta to store
		//$metas = array('tracknumber', 'length', 'musicbrainzid', 'source' );
		foreach ( $trackmeta as $meta => $value ) {
			self::debug('Adding meta ' . $meta. ': ' . $value . ' for track ' . $post_id);
			add_post_meta($post_id, $meta, $value, true);
		}

		if (!empty( $artist )) {

			if ( ! term_exists( $artist, static::term_artist )) {
				self::debug('Inserting term ' . $artist . ' to ' . static::term_artist );
				$args = array ();
				if ( !empty($artistmeta) ) {
					$args = array (
						'description' => json_encode($artistmeta),
					);
				}
				wp_insert_term( $artist, static::term_artist, $args );
			}

			self::debug('Adding ' . static::term_artist . ': ' . $artist . ' for ' . $post_id );
			wp_set_post_terms( $post_id, $artist, static::term_artist );
		}


		if (!empty( $album )) {

			if ( ! term_exists( $album, static::term_album )) {
				self::debug('Inserting term ' . $album . ' to ' . static::term_album );
				$args = array ();
				if ( !empty($albummeta) ) {
					$args = array (
						'description' => json_encode($albummeta),
					);
				}
				wp_insert_term( $album, static::term_album, $args );
			}

			self::debug('Adding ' . static::term_album . ': ' . $album . ' for ' . $post_id );
			wp_set_post_terms( $post_id, $album, static::term_album );
		}

	}



	private function lastfmimport_header() {
		echo '<div class="wrap">';
		echo '<h2>'.__('Import Last.fm').'</h2>';
	}

	private function lastfmimport_footer() {
		echo '</div>';
	}

	private function lastfmimport_greet() {
		echo '<div class="narrow">';
		echo '<p>'.__('Upload your Last.fm zip export file and import your Scrobbles to the PlaylistLog').'</p>';
		wp_import_upload_form("admin.php?import=lastfm&amp;step=1");
		echo '</div>';
	}

	public function lastfmimport_dispatch() {
		if (empty ($_GET['step']))
			$step = 0;
		else
			$step = (int) $_GET['step'];

		$this->lastfmimport_header();

		switch ($step) {
			case 0 :
				$this->lastfmimport_greet();
				break;
			case 1 :
				check_admin_referer('import-upload');
				$result = $this->lastfmimport_upload();
				if ( is_wp_error( $result ) )
					echo $result->get_error_message();
				break;
		}

		$this->lastfmimport_footer();
	}

	private function lastfmimport_upload() {
		$file = wp_import_handle_upload();
		if ( isset($file['error']) ) {
			echo $file['error'];
			return;
		}

		$this->importfile = $file['file'];
		$this->extractzip();
		$this->lastfmimport_import();
		//if ( is_wp_error( $result ) )
		//	return $result;

		wp_import_cleanup($file['id']);
		$this->rm_r($this->tmpdir);
		$this->tmpdir = null;

		echo '<h3>';
		printf(__('All done. <a href="%s">Have fun!</a>'), get_option('home'));
		echo '</h3>';
	}

	private function extractzip () {
		require_once ( dirname (__FILE__) . '/lib/pclzip-2-8-2/pclzip.lib.php');

		$tmpd = sys_get_temp_dir() . '/pclzip-' . time();

		self::debug('temp dir for zip: ' . $tmpd );
		if (!is_dir($tmpd)) {
			mkdir ($tmpd);
		}

		$archive = new PclZip( $this->importfile );
		if ($archive->extract(PCLZIP_OPT_PATH, $tmpd) == 0) {
			self::debug("Error : ".$archive->errorInfo(true));
			die("Error : ".$archive->errorInfo(true));
		}

		$this->tmpdir = $tmpd;
	}

	private function lastfmimport_import() {
		if (!is_dir($this->tmpdir))
			return false;

		do_action( 'import_start' );
		self::debug('import starting');
		set_time_limit( 0 );

		$user = wp_get_current_user();
		if(!isset($user->ID) || empty($user->ID))
			return false;

		$jsons = glob( $this->tmpdir . '/json/scrobbles/*.{json}', GLOB_BRACE);
		foreach($jsons as $jsonfile) {
			self::debug('import parsing file: ' . $jsonfile );
			$json = json_decode(file_get_contents($jsonfile));

			if (is_array($json) && !empty($json)) {
				foreach ($json as $track ) {

					$trackmeta = $artistmeta = $albummeta = array();

					if ( !isset($track->track->name) || empty($track->track->name))
						continue;

					if ( !isset($track->timestamp->iso) || empty($track->timestamp->iso))
						continue;

					$title = $track->track->name;
					$time = strtotime($track->timestamp->iso);

					if ( isset($track->track->mbid) && !empty($track->track->mbid)) {
						$trackmeta['m'] = $track->track->mbid;
					}

					$artist = null;
					if ( isset($track->track->artist->name) && !empty($track->track->artist->name)) {
						$artist = $track->track->artist->name;
						if (isset($track->track->artist->mbid) && !empty($track->track->artist->mbid)) {
							$artistmeta['m'] = $track->track->artist->mbid;
						}
					}
					elseif (isset($track->album->artist->name) && !empty($track->album->artist->name)) {
						$artist = $track->track->artist->name;
						if (isset($track->album->artist->mbid) && !empty($track->album->artist->mbid)) {
							$artistmeta['m'] = $track->album->artist->mbid;
						}
					}

					$album = null;
					if ( isset($track->album->name) && !empty($track->album->name)) {
						$album = $track->album->name;
						if (isset($track->album->mbid) && !empty($track->album->mbid)) {
							$albummeta['m'] = $track->album->mbid;
						}
					}

					$this->add_track( $user->ID, $title, $time, $trackmeta, $artist, $artistmeta, $album, $albummeta );

				}
			}

		}

		self::debug('import end');
		do_action( 'import_end' );
		return true;
	}

	public static function rm_r( $path ) {
		if ( is_dir( $path ) and $dh = opendir( $path ) ) {
			while ( ($entry = readdir( $dh )) !== false ) {
				if ( !preg_match( '/\A\.\.?\z/', $entry ) ) {
					$p = $path.DIRECTORY_SEPARATOR.$entry;
					if ( is_dir( $p ) ) {
						rm_r( $p );
					}
					else {
						unlink( $p );
					}
				}
			}
			closedir( $dh );
			rmdir( $path );
			return true;
		}
		else {
			return false;
		}
	}

	/**
	 *
	 * debug messages; will only work if WP_DEBUG is on
	 * or if the level is LOG_ERR, but that will kill the process
	 *
	 * @param string $message
	 * @param int $level
	 */
	public static function debug( $message, $level = LOG_NOTICE ) {
		if ( @is_array( $message ) || @is_object ( $message ) )
			$message = json_encode($message);

		switch ( $level ) {
			case LOG_ERR :
				wp_die( '<h1>Error:</h1>' . '<p>' . $message . '</p>' );
				exit;
			default:
				if ( !defined( 'WP_DEBUG' ) || WP_DEBUG != true )
					return;
				break;
		}

		error_log(  __CLASS__ . ": " . $message );
	}

}

$WPPlaylistLog = new WPPlaylistLog();

register_importer('lastfm', __('Last.fm'), __('Import scrobbler data from Last.fm zip export file'), array ($WPPlaylistLog, 'lastfmimport_dispatch'));

endif;

