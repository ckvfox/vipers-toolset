<?php
/**
 * Plugin Name: Vipers Toolset
 * Plugin URI: #
 * Description: Asset Governance Toolkit with scanner, conditional rules, safety net, performance compare, and recommendations.
 * Version: 2.0.1
 * Author: Vipers
 * License: GPL-2.0+
 * Text Domain: vipers-toolset
 */

defined( 'ABSPATH' ) || exit;

class Vipers_Toolset_2 {

	const OPTION_SETTINGS = 'vipers_toolset2_settings';
	const OPTION_RULES = 'vipers_toolset2_rules';
	const OPTION_SCAN_LOG = 'vipers_toolset2_scan_log';
	const OPTION_COMPARE_LOG = 'vipers_toolset2_compare_log';
	const OPTION_SNAPSHOTS = 'vipers_toolset2_snapshots';
	const OPTION_EVENTS = 'vipers_toolset2_events';
	const TRANSIENT_SCAN_TOKEN = 'vipers_toolset2_scan_token';

	private static $instance = null;
	private $decision_log = array();
	private $request_bypass = false;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
		add_action( 'wp_ajax_vipers_run_scan', array( $this, 'ajax_run_scan' ) );

		add_action( 'init', array( $this, 'maybe_enable_recovery_bypass' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_local_google_fonts' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'apply_rules' ), 9999 );
		add_action( 'wp_print_styles', array( $this, 'capture_assets' ), PHP_INT_MAX );
		add_action( 'wp_print_scripts', array( $this, 'capture_assets' ), PHP_INT_MAX );

		add_filter( 'script_loader_src', array( $this, 'track_script_src' ), 10, 2 );
		add_filter( 'style_loader_src', array( $this, 'track_style_src' ), 10, 2 );
	}

	public function add_admin_menu() {
		add_management_page(
			'Vipers Toolset 2.0',
			'Vipers Toolset 2.0',
			'manage_options',
			'vipers-toolset',
			array( $this, 'render_dashboard' )
		);
	}

	private function defaults() {
		return array(
			'dry_run' => 1,
			'scanner_enabled' => 1,
			'max_log_rows' => 400,
			'recovery_key' => wp_generate_password( 18, false, false ),
		);
	}

	private function get_settings() {
		$defaults = $this->defaults();
		$saved = get_option( self::OPTION_SETTINGS, array() );
		$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
		if ( empty( $saved['recovery_key'] ) ) {
			update_option( self::OPTION_SETTINGS, $settings );
		}
		return $settings;
	}

	private function get_rules() {
		$rules = get_option( self::OPTION_RULES, array() );
		if ( ! is_array( $rules ) ) {
			return array();
		}
		return array_values( $rules );
	}

	private function get_scan_log() {
		$log = get_option( self::OPTION_SCAN_LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	private function get_compare_log() {
		$log = get_option( self::OPTION_COMPARE_LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	private function add_event( $message, $type = 'info' ) {
		$events = get_option( self::OPTION_EVENTS, array() );
		if ( ! is_array( $events ) ) {
			$events = array();
		}
		$events[] = array(
			'time' => current_time( 'mysql' ),
			'type' => $type,
			'message' => sanitize_text_field( $message ),
		);
		$events = array_slice( $events, -60 );
		update_option( self::OPTION_EVENTS, $events, false );
	}

	public function maybe_enable_recovery_bypass() {
		$settings = $this->get_settings();
		if ( isset( $_GET['vipers_recover'] ) && hash_equals( (string) $settings['recovery_key'], (string) wp_unslash( $_GET['vipers_recover'] ) ) ) {
			$this->request_bypass = true;
			set_transient( 'vipers_toolset2_bypass', 1, 30 * MINUTE_IN_SECONDS );
		}

		if ( get_transient( 'vipers_toolset2_bypass' ) ) {
			$this->request_bypass = true;
		}
	}

	public function apply_rules() {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		$settings = $this->get_settings();
		if ( $this->request_bypass ) {
			$this->decision_log[] = array( 'type' => 'bypass', 'message' => 'Recovery bypass active; rules skipped.' );
			return;
		}

		$rules = $this->get_rules();
		$context = $this->build_context();

		foreach ( $rules as $rule ) {
			if ( empty( $rule['enabled'] ) || empty( $rule['handle'] ) || empty( $rule['asset_type'] ) ) {
				continue;
			}

			if ( ! $this->rule_matches( $rule, $context ) ) {
				continue;
			}

			$action = isset( $rule['action'] ) ? $rule['action'] : 'disable';
			if ( 'disable' !== $action ) {
				continue;
			}

			$handle = $rule['handle'];
			if ( ! empty( $settings['dry_run'] ) ) {
				$this->decision_log[] = array(
					'type' => 'dry-run',
					'handle' => $handle,
					'asset_type' => $rule['asset_type'],
					'rule_name' => $rule['name'],
				);
				continue;
			}

			if ( 'style' === $rule['asset_type'] ) {
				wp_dequeue_style( $handle );
				wp_deregister_style( $handle );
			} else {
				wp_dequeue_script( $handle );
				wp_deregister_script( $handle );
			}

			$this->decision_log[] = array(
				'type' => 'applied',
				'handle' => $handle,
				'asset_type' => $rule['asset_type'],
				'rule_name' => $rule['name'],
			);
		}
	}

	private function build_context() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return array(
			'is_front_page' => is_front_page(),
			'is_home' => is_home(),
			'is_singular' => is_singular(),
			'is_page' => is_page(),
			'is_single' => is_single(),
			'is_archive' => is_archive(),
			'is_search' => is_search(),
			'is_404' => is_404(),
			'is_user_logged_in' => is_user_logged_in(),
			'uri' => $uri,
		);
	}

	private function rule_matches( $rule, $context ) {
		$type = isset( $rule['url_type'] ) ? $rule['url_type'] : 'all';
		if ( 'all' !== $type ) {
			$map = array(
				'front_page' => ! empty( $context['is_front_page'] ),
				'home' => ! empty( $context['is_home'] ),
				'singular' => ! empty( $context['is_singular'] ),
				'page' => ! empty( $context['is_page'] ),
				'single' => ! empty( $context['is_single'] ),
				'archive' => ! empty( $context['is_archive'] ),
				'search' => ! empty( $context['is_search'] ),
				'404' => ! empty( $context['is_404'] ),
			);
			if ( empty( $map[ $type ] ) ) {
				return false;
			}
		}

		if ( isset( $rule['logged_in'] ) && '' !== $rule['logged_in'] ) {
			$must_be_logged_in = ( '1' === (string) $rule['logged_in'] );
			if ( $must_be_logged_in !== (bool) $context['is_user_logged_in'] ) {
				return false;
			}
		}

		if ( ! empty( $rule['path_contains'] ) ) {
			if ( false === strpos( $context['uri'], $rule['path_contains'] ) ) {
				return false;
			}
		}

		return true;
	}

	public function track_script_src( $src, $handle ) {
		$GLOBALS['vipers_toolset2_seen_scripts'][ $handle ] = $src;
		return $src;
	}

	public function track_style_src( $src, $handle ) {
		$GLOBALS['vipers_toolset2_seen_styles'][ $handle ] = $src;
		return $src;
	}

	public function capture_assets() {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		$settings = $this->get_settings();
		if ( ! $this->is_capture_request_allowed( $settings ) ) {
			return;
		}

		$scripts = isset( $GLOBALS['vipers_toolset2_seen_scripts'] ) && is_array( $GLOBALS['vipers_toolset2_seen_scripts'] ) ? $GLOBALS['vipers_toolset2_seen_scripts'] : array();
		$styles = isset( $GLOBALS['vipers_toolset2_seen_styles'] ) && is_array( $GLOBALS['vipers_toolset2_seen_styles'] ) ? $GLOBALS['vipers_toolset2_seen_styles'] : array();

		$rows = array();
		$total_bytes = 0;
		foreach ( $styles as $handle => $src ) {
			$size = $this->estimate_asset_size( $src );
			$total_bytes += $size;
			$rows[] = $this->build_row( 'style', $handle, $src, $size );
		}
		foreach ( $scripts as $handle => $src ) {
			$size = $this->estimate_asset_size( $src );
			$total_bytes += $size;
			$rows[] = $this->build_row( 'script', $handle, $src, $size );
		}

		if ( empty( $rows ) ) {
			return;
		}

		$url_type = $this->detect_url_type();
		$path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		$scan_log = $this->get_scan_log();
		$scan_log[] = array(
			'time' => current_time( 'mysql' ),
			'url_type' => $url_type,
			'path' => $path,
			'assets' => $rows,
			'total_count' => count( $rows ),
			'total_bytes' => $total_bytes,
		);
		$scan_log = array_slice( $scan_log, -absint( $settings['max_log_rows'] ) );
		update_option( self::OPTION_SCAN_LOG, $scan_log, false );

		$removed_count = 0;
		foreach ( $this->decision_log as $item ) {
			if ( isset( $item['type'] ) && ( 'applied' === $item['type'] || 'dry-run' === $item['type'] ) ) {
				$removed_count++;
			}
		}

		$compare_log = $this->get_compare_log();
		$compare_log[] = array(
			'time' => current_time( 'mysql' ),
			'path' => $path,
			'url_type' => $url_type,
			'total_count' => count( $rows ),
			'total_bytes' => $total_bytes,
			'decision_count' => $removed_count,
			'dry_run' => ! empty( $settings['dry_run'] ) ? 1 : 0,
		);
		$compare_log = array_slice( $compare_log, -absint( $settings['max_log_rows'] ) );
		update_option( self::OPTION_COMPARE_LOG, $compare_log, false );
	}

	private function is_capture_request_allowed( $settings ) {
		if ( empty( $settings['scanner_enabled'] ) ) {
			return false;
		}

		$request_token = isset( $_GET['vipers_toolset_scan'] )
			? sanitize_text_field( wp_unslash( $_GET['vipers_toolset_scan'] ) )
			: '';
		if ( empty( $request_token ) ) {
			return false;
		}

		$active_token = get_transient( self::TRANSIENT_SCAN_TOKEN );
		if ( ! is_string( $active_token ) || '' === $active_token ) {
			return false;
		}

		return hash_equals( $active_token, $request_token );
	}

	private function build_row( $type, $handle, $src, $size ) {
		$plugin_guess = $this->guess_plugin_slug_from_src( $src );
		return array(
			'type' => $type,
			'handle' => sanitize_key( $handle ),
			'src' => esc_url_raw( $src ),
			'size' => absint( $size ),
			'plugin' => $plugin_guess,
		);
	}

	private function guess_plugin_slug_from_src( $src ) {
		$needle = '/wp-content/plugins/';
		$pos = strpos( $src, $needle );
		if ( false === $pos ) {
			return '';
		}
		$sub = substr( $src, $pos + strlen( $needle ) );
		$parts = explode( '/', $sub );
		return ! empty( $parts[0] ) ? sanitize_key( $parts[0] ) : '';
	}

	private function estimate_asset_size( $src ) {
		if ( empty( $src ) ) {
			return 0;
		}

		$clean_src = strtok( $src, '?' );
		$site = home_url();
		if ( 0 !== strpos( $clean_src, $site ) && 0 !== strpos( $clean_src, '/' ) ) {
			return 0;
		}

		$relative = str_replace( home_url(), '', $clean_src );
		$relative = ltrim( $relative, '/' );
		if ( empty( $relative ) ) {
			return 0;
		}

		$absolute = ABSPATH . $relative;
		if ( file_exists( $absolute ) ) {
			$size = filesize( $absolute );
			return false === $size ? 0 : (int) $size;
		}

		return 0;
	}

	private function detect_url_type() {
		if ( is_front_page() ) {
			return 'front_page';
		}
		if ( is_home() ) {
			return 'home';
		}
		if ( is_page() ) {
			return 'page';
		}
		if ( is_single() ) {
			return 'single';
		}
		if ( is_singular() ) {
			return 'singular';
		}
		if ( is_archive() ) {
			return 'archive';
		}
		if ( is_search() ) {
			return 'search';
		}
		if ( is_404() ) {
			return '404';
		}
		return 'other';
	}

	public function enqueue_local_google_fonts() {
		if ( is_admin() ) {
			return;
		}

		$css = $this->build_local_google_fonts_css();
		if ( '' === $css ) {
			return;
		}

		wp_register_style( 'vipers-local-google-fonts', false, array(), self::OPTION_SETTINGS );
		wp_enqueue_style( 'vipers-local-google-fonts' );
		wp_add_inline_style( 'vipers-local-google-fonts', $css );
	}

	private function build_local_google_fonts_css() {
		$fonts_dir = WP_CONTENT_DIR . '/fonts/google-fonts';
		if ( ! is_dir( $fonts_dir ) ) {
			return '';
		}

		$allowlist = $this->get_local_google_font_allowlist();

		$allowed_extensions = array(
			'ttf'   => 'truetype',
			'otf'   => 'opentype',
			'woff'  => 'woff',
			'woff2' => 'woff2',
		);

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $fonts_dir, FilesystemIterator::SKIP_DOTS )
		);

		$fonts = array();

		foreach ( $iterator as $file_info ) {
			if ( ! $file_info->isFile() ) {
				continue;
			}

			$extension = strtolower( $file_info->getExtension() );
			if ( ! isset( $allowed_extensions[ $extension ] ) ) {
				continue;
			}

			$path = $file_info->getPathname();
			$relative_path = ltrim( str_replace( '\\', '/', substr( $path, strlen( $fonts_dir ) ) ), '/' );
			if ( '' === $relative_path ) {
				continue;
			}

			$segments = explode( '/', $relative_path );
			$family = $this->normalize_font_family_name( $segments[0] );
			$variant = $this->detect_font_variant( pathinfo( $path, PATHINFO_FILENAME ) );

			if ( ! $this->is_allowed_local_google_font_variant( $family, $variant, $allowlist ) ) {
				continue;
			}

			$key = strtolower( $family ) . '|' . $variant['weight'] . '|' . $variant['style'];

			if ( ! isset( $fonts[ $key ] ) ) {
				$fonts[ $key ] = array(
					'family'  => $family,
					'weight'  => $variant['weight'],
					'style'   => $variant['style'],
					'sources' => array(),
				);
			}

			$fonts[ $key ]['sources'][ $extension ] = content_url( 'fonts/google-fonts/' . $relative_path );
		}

		if ( empty( $fonts ) ) {
			return '';
		}

		ksort( $fonts );

		$css = '';
		foreach ( $fonts as $font ) {
			$src_parts = array();
			foreach ( array( 'woff2', 'woff', 'ttf', 'otf' ) as $extension ) {
				if ( empty( $font['sources'][ $extension ] ) ) {
					continue;
				}

				$src_parts[] = sprintf(
					"url('%s') format('%s')",
					esc_url_raw( $font['sources'][ $extension ] ),
					$allowed_extensions[ $extension ]
				);
			}

			if ( empty( $src_parts ) ) {
				continue;
			}

			$css .= sprintf(
				"@font-face{font-family:'%s';src:%s;font-weight:%d;font-style:%s;font-display:swap;}\n",
				str_replace( "'", "\\'", $font['family'] ),
				implode( ',', $src_parts ),
				(int) $font['weight'],
				$font['style']
			);
		}

		return $css;
	}

	private function get_local_google_font_allowlist() {
		$allowlist = array(
			'Graduate' => array(
				'weights' => array( 400 ),
				'styles'  => array( 'normal' ),
			),
			'Montserrat' => array(
				'weights' => array( 400, 500, 600, 700 ),
				'styles'  => array( 'normal', 'italic' ),
			),
			'Permanent Marker' => array(
				'weights' => array( 400 ),
				'styles'  => array( 'normal' ),
			),
			'Saira Semi Condensed' => array(
				'weights' => array( 400, 500, 600, 700, 800, 900 ),
				'styles'  => array( 'normal' ),
			),
			'Source Sans 3' => array(
				'weights' => array( 300, 400, 500, 600, 700 ),
				'styles'  => array( 'normal', 'italic' ),
			),
		);

		return apply_filters( 'vipers_toolset2_local_font_allowlist', $allowlist );
	}

	private function is_allowed_local_google_font_variant( $family, $variant, $allowlist ) {
		if ( empty( $allowlist[ $family ] ) || ! is_array( $allowlist[ $family ] ) ) {
			return false;
		}

		$rules = $allowlist[ $family ];
		$allowed_weights = isset( $rules['weights'] ) && is_array( $rules['weights'] ) ? $rules['weights'] : array();
		$allowed_styles = isset( $rules['styles'] ) && is_array( $rules['styles'] ) ? $rules['styles'] : array();

		if ( ! in_array( (int) $variant['weight'], $allowed_weights, true ) ) {
			return false;
		}

		if ( ! in_array( $variant['style'], $allowed_styles, true ) ) {
			return false;
		}

		return true;
	}

	private function normalize_font_family_name( $directory_name ) {
		return trim( str_replace( '_', ' ', sanitize_text_field( $directory_name ) ) );
	}

	private function detect_font_variant( $filename ) {
		$normalized = strtolower( preg_replace( '/[^a-z]/', '', $filename ) );
		$style = false !== strpos( $normalized, 'italic' ) ? 'italic' : 'normal';
		$weight = 400;

		$weight_map = array(
			'extrablack' => 950,
			'ultrabold'  => 800,
			'extrabold'  => 800,
			'semibold'   => 600,
			'demibold'   => 600,
			'extralight' => 200,
			'ultralight' => 200,
			'thin'       => 100,
			'light'      => 300,
			'medium'     => 500,
			'regular'    => 400,
			'normal'     => 400,
			'black'      => 900,
			'heavy'      => 900,
			'bold'       => 700,
		);

		foreach ( $weight_map as $needle => $mapped_weight ) {
			if ( false !== strpos( $normalized, $needle ) ) {
				$weight = $mapped_weight;
				break;
			}
		}

		return array(
			'weight' => $weight,
			'style'  => $style,
		);
	}

	public function handle_admin_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! isset( $_POST['vipers_action'] ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['vipers_action'] ) );

		if ( 'save_settings' === $action ) {
			check_admin_referer( 'vipers_toolset2_settings' );
			$settings = $this->get_settings();
			$settings['dry_run'] = isset( $_POST['dry_run'] ) ? 1 : 0;
			$settings['scanner_enabled'] = isset( $_POST['scanner_enabled'] ) ? 1 : 0;
			$settings['max_log_rows'] = max( 50, min( 2000, absint( $_POST['max_log_rows'] ?? 400 ) ) );
			if ( isset( $_POST['reset_recovery_key'] ) ) {
				$settings['recovery_key'] = wp_generate_password( 18, false, false );
			}
			update_option( self::OPTION_SETTINGS, $settings );
			$this->add_event( 'Settings updated.', 'success' );
		}

		if ( 'add_rule' === $action ) {
			check_admin_referer( 'vipers_toolset2_rules' );
			$rules = $this->get_rules();
			$rules[] = array(
				'id' => uniqid( 'rule_', true ),
				'name' => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
				'asset_type' => in_array( $_POST['asset_type'] ?? '', array( 'style', 'script' ), true ) ? $_POST['asset_type'] : 'style',
				'handle' => sanitize_key( wp_unslash( $_POST['handle'] ?? '' ) ),
				'url_type' => sanitize_key( wp_unslash( $_POST['url_type'] ?? 'all' ) ),
				'path_contains' => sanitize_text_field( wp_unslash( $_POST['path_contains'] ?? '' ) ),
				'logged_in' => isset( $_POST['logged_in'] ) ? sanitize_text_field( wp_unslash( $_POST['logged_in'] ) ) : '',
				'action' => 'disable',
				'enabled' => 1,
			);
			update_option( self::OPTION_RULES, $rules, false );
			$this->add_event( 'Rule added.', 'success' );
		}

		if ( 'toggle_rule' === $action ) {
			check_admin_referer( 'vipers_toolset2_rules' );
			$rule_id = sanitize_text_field( wp_unslash( $_POST['rule_id'] ?? '' ) );
			$rules = $this->get_rules();
			foreach ( $rules as &$rule ) {
				if ( isset( $rule['id'] ) && $rule['id'] === $rule_id ) {
					$rule['enabled'] = empty( $rule['enabled'] ) ? 1 : 0;
					break;
				}
			}
			update_option( self::OPTION_RULES, $rules, false );
			$this->add_event( 'Rule status changed.', 'success' );
		}

		if ( 'delete_rule' === $action ) {
			check_admin_referer( 'vipers_toolset2_rules' );
			$rule_id = sanitize_text_field( wp_unslash( $_POST['rule_id'] ?? '' ) );
			$rules = array_filter( $this->get_rules(), function( $rule ) use ( $rule_id ) {
				return ! isset( $rule['id'] ) || $rule['id'] !== $rule_id;
			} );
			update_option( self::OPTION_RULES, array_values( $rules ), false );
			$this->add_event( 'Rule deleted.', 'success' );
		}

		if ( 'create_snapshot' === $action ) {
			check_admin_referer( 'vipers_toolset2_safety' );
			$snapshots = get_option( self::OPTION_SNAPSHOTS, array() );
			if ( ! is_array( $snapshots ) ) {
				$snapshots = array();
			}
			$snapshots[] = array(
				'id' => uniqid( 'snap_', true ),
				'time' => current_time( 'mysql' ),
				'settings' => $this->get_settings(),
				'rules' => $this->get_rules(),
			);
			$snapshots = array_slice( $snapshots, -20 );
			update_option( self::OPTION_SNAPSHOTS, $snapshots, false );
			$this->add_event( 'Snapshot created.', 'success' );
		}

		if ( 'restore_snapshot' === $action ) {
			check_admin_referer( 'vipers_toolset2_safety' );
			$snapshot_id = sanitize_text_field( wp_unslash( $_POST['snapshot_id'] ?? '' ) );
			$snapshots = get_option( self::OPTION_SNAPSHOTS, array() );
			if ( is_array( $snapshots ) ) {
				foreach ( $snapshots as $snapshot ) {
					if ( isset( $snapshot['id'] ) && $snapshot['id'] === $snapshot_id ) {
						update_option( self::OPTION_SETTINGS, $snapshot['settings'] );
						update_option( self::OPTION_RULES, $snapshot['rules'] );
						$this->add_event( 'Snapshot restored.', 'success' );
						break;
					}
				}
			}
		}

		if ( 'clear_logs' === $action ) {
			check_admin_referer( 'vipers_toolset2_safety' );
			update_option( self::OPTION_SCAN_LOG, array(), false );
			update_option( self::OPTION_COMPARE_LOG, array(), false );
			$this->add_event( 'Scanner and compare logs cleared.', 'success' );
		}
	}

	public function ajax_run_scan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
		check_ajax_referer( 'vipers_scan_nonce' );

		$targets = array( home_url( '/' ) );
		$pages = get_pages( array( 'number' => 6 ) );
		foreach ( $pages as $page ) {
			$targets[] = get_permalink( $page->ID );
		}

		$targets = array_unique( $targets );
		$results = array();
		$scan_token = wp_generate_password( 24, false, false );
		set_transient( self::TRANSIENT_SCAN_TOKEN, $scan_token, 10 * MINUTE_IN_SECONDS );

		foreach ( $targets as $url ) {
			$scan_url = add_query_arg( 'vipers_toolset_scan', rawurlencode( $scan_token ), $url );
			wp_remote_get( $scan_url, array( 'timeout' => 10, 'redirection' => 3 ) );
			$results[] = $scan_url;
		}
		delete_transient( self::TRANSIENT_SCAN_TOKEN );

		$this->add_event( 'Manual scan requested for ' . count( $results ) . ' URLs.', 'success' );
		wp_send_json_success( array( 'count' => count( $results ) ) );
	}

	private function build_aggregate() {
		$scan_log = $this->get_scan_log();
		$total_requests = count( $scan_log );
		$aggregate = array();

		foreach ( $scan_log as $request ) {
			$seen_this_request = array();
			$url_type = $request['url_type'] ?? 'other';
			foreach ( $request['assets'] as $asset ) {
				$key = $asset['type'] . '::' . $asset['handle'];
				if ( ! isset( $aggregate[ $key ] ) ) {
					$aggregate[ $key ] = array(
						'type' => $asset['type'],
						'handle' => $asset['handle'],
						'plugin' => $asset['plugin'],
						'request_count' => 0,
						'total_size' => 0,
						'url_types' => array(),
					);
				}

				if ( ! isset( $seen_this_request[ $key ] ) ) {
					$aggregate[ $key ]['request_count']++;
					$seen_this_request[ $key ] = true;
				}
				$aggregate[ $key ]['total_size'] += absint( $asset['size'] );
				$aggregate[ $key ]['url_types'][ $url_type ] = 1;
			}
		}

		foreach ( $aggregate as &$row ) {
			$row['coverage_pct'] = $total_requests > 0 ? round( ( $row['request_count'] / $total_requests ) * 100, 1 ) : 0;
			$row['avg_size'] = $row['request_count'] > 0 ? (int) round( $row['total_size'] / $row['request_count'] ) : 0;
			$row['url_type_count'] = count( $row['url_types'] );
		}

		return array_values( $aggregate );
	}

	private function build_recommendations() {
		$aggregate = $this->build_aggregate();
		$recommendations = array();

		foreach ( $aggregate as $row ) {
			if ( $row['coverage_pct'] >= 85 && $row['avg_size'] >= 30000 && $row['url_type_count'] <= 2 ) {
				$recommendations[] = array(
					'level' => 'high',
					'text' => sprintf(
						'%s (%s) appears on %s%% of requests and averages %s KB. It is only seen on %d URL type(s); consider conditional loading.',
						$row['handle'],
						$row['type'],
						$row['coverage_pct'],
						round( $row['avg_size'] / 1024, 1 ),
						$row['url_type_count']
					),
				);
			}
		}

		if ( empty( $recommendations ) ) {
			$recommendations[] = array(
				'level' => 'info',
				'text' => 'No high-confidence recommendations yet. Run scanner on more URL types for better suggestions.',
			);
		}

		return $recommendations;
	}

	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Access denied.' );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		$tabs = array(
			'overview'        => 'Übersicht',
			'scanner'         => 'Asset Map',
			'rules'           => 'Regelwerk',
			'safety'          => 'Sicherheitsnetz',
			'compare'         => 'Performance-Vergleich',
			'recommendations' => 'Empfehlungen',
			'help'            => '📖 Anleitung',
		);

		?>
		<div class="wrap">
			<h1>Vipers Toolset 2.0</h1>
			<p>Focused complement to WP-Optimize and OMGF: asset governance, rule control, safety, and measurable deltas.</p>
			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $id => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'tools.php?page=vipers-toolset&tab=' . $id ) ); ?>" class="nav-tab <?php echo $tab === $id ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>
			<div style="background:#fff;border:1px solid #dcdcde;padding:20px;margin-top:12px;">
				<?php
				switch ( $tab ) {
					case 'scanner':
						$this->render_scanner_tab();
						break;
					case 'rules':
						$this->render_rules_tab();
						break;
					case 'safety':
						$this->render_safety_tab();
						break;
					case 'compare':
						$this->render_compare_tab();
						break;
					case 'recommendations':
						$this->render_recommendations_tab();
						break;				case 'help':
					$this->render_help_tab();
					break;					default:
						$this->render_overview_tab();
				}
				?>
			</div>
		</div>
		<?php
	}

	private function render_overview_tab() {
		$settings = $this->get_settings();
		$rules = $this->get_rules();
		$scan_log = $this->get_scan_log();
		$compare_log = $this->get_compare_log();
		$events = get_option( self::OPTION_EVENTS, array() );
		$events = is_array( $events ) ? array_reverse( $events ) : array();

		echo '<h2>System Status</h2>';
		echo '<p><strong>Dry-run:</strong> ' . ( ! empty( $settings['dry_run'] ) ? 'Enabled' : 'Disabled' ) . '</p>';
		echo '<p><strong>Scanner:</strong> ' . ( ! empty( $settings['scanner_enabled'] ) ? 'Enabled' : 'Disabled' ) . '</p>';
		echo '<p><strong>Rules:</strong> ' . count( $rules ) . ' total</p>';
		echo '<p><strong>Scan requests stored:</strong> ' . count( $scan_log ) . '</p>';
		echo '<p><strong>Compare rows stored:</strong> ' . count( $compare_log ) . '</p>';

		echo '<h3>Quick Settings</h3>';
		echo '<form method="post">';
		wp_nonce_field( 'vipers_toolset2_settings' );
		echo '<input type="hidden" name="vipers_action" value="save_settings">';
		echo '<p><label><input type="checkbox" name="dry_run" value="1" ' . checked( 1, (int) $settings['dry_run'], false ) . '> Enable dry-run (log only, no dequeue)</label></p>';
		echo '<p><label><input type="checkbox" name="scanner_enabled" value="1" ' . checked( 1, (int) $settings['scanner_enabled'], false ) . '> Enable scanner on frontend requests</label></p>';
		echo '<p><label>Max log rows <input type="number" min="50" max="2000" name="max_log_rows" value="' . esc_attr( $settings['max_log_rows'] ) . '"></label></p>';
		echo '<p><button class="button button-primary" type="submit">Save settings</button> ';
		echo '<button class="button" type="submit" name="reset_recovery_key" value="1">Regenerate recovery key</button></p>';
		echo '</form>';

		echo '<h3>Latest Events</h3>';
		if ( empty( $events ) ) {
			echo '<p>No events yet.</p>';
			return;
		}

		echo '<ul>';
		foreach ( array_slice( $events, 0, 10 ) as $event ) {
			echo '<li><strong>' . esc_html( $event['time'] ) . '</strong> [' . esc_html( $event['type'] ) . '] ' . esc_html( $event['message'] ) . '</li>';
		}
		echo '</ul>';
	}

	private function render_scanner_tab() {
		$scan_log = $this->get_scan_log();
		echo '<h2>Asset Map Scanner</h2>';
		echo '<p>Captures enqueued CSS/JS per request and URL type for governance decisions.</p>';
		echo '<p><button id="vipers-run-scan" class="button button-primary">Run quick crawl now</button> <span id="vipers-run-scan-result"></span></p>';

		if ( empty( $scan_log ) ) {
			echo '<p>No scan data yet. Visit frontend pages or run quick crawl.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Time</th><th>URL Type</th><th>Path</th><th>Assets</th><th>Approx Size</th></tr></thead><tbody>';
			foreach ( array_reverse( array_slice( $scan_log, -25 ) ) as $row ) {
				echo '<tr>';
				echo '<td>' . esc_html( $row['time'] ) . '</td>';
				echo '<td>' . esc_html( $row['url_type'] ) . '</td>';
				echo '<td>' . esc_html( $row['path'] ) . '</td>';
				echo '<td>' . esc_html( $row['total_count'] ) . '</td>';
				echo '<td>' . esc_html( round( $row['total_bytes'] / 1024, 1 ) ) . ' KB</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		$nonce = wp_create_nonce( 'vipers_scan_nonce' );
		echo '<script>
			document.getElementById("vipers-run-scan").addEventListener("click", function () {
				var result = document.getElementById("vipers-run-scan-result");
				result.textContent = "Running...";
				fetch(ajaxurl, {
					method: "POST",
					headers: {"Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"},
					body: new URLSearchParams({ action: "vipers_run_scan", _ajax_nonce: "' . esc_js( $nonce ) . '" })
				}).then(function (r) { return r.json(); }).then(function (data) {
					if (data.success) {
						result.textContent = "Done. Crawled " + data.data.count + " URLs. Reload this page.";
					} else {
						result.textContent = "Failed.";
					}
				}).catch(function () {
					result.textContent = "Failed.";
				});
			});
		</script>';
	}

	private function render_rules_tab() {
		$rules = $this->get_rules();
		echo '<h2>Rule Engine</h2>';
		echo '<p>Disable specific CSS/JS handles under defined conditions.</p>';

		echo '<h3>Add Rule</h3>';
		echo '<form method="post">';
		wp_nonce_field( 'vipers_toolset2_rules' );
		echo '<input type="hidden" name="vipers_action" value="add_rule">';
		echo '<p><label>Name <input required type="text" name="name"></label></p>';
		echo '<p><label>Asset Type <select name="asset_type"><option value="style">Style</option><option value="script">Script</option></select></label></p>';
		echo '<p><label>Handle <input required type="text" name="handle"></label></p>';
		echo '<p><label>URL Type <select name="url_type">';
		foreach ( array( 'all', 'front_page', 'home', 'singular', 'page', 'single', 'archive', 'search', '404' ) as $t ) {
			echo '<option value="' . esc_attr( $t ) . '">' . esc_html( $t ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p><label>Path contains (optional) <input type="text" name="path_contains" placeholder="/kontakt"></label></p>';
		echo '<p><label>Login state <select name="logged_in"><option value="">Any</option><option value="1">Logged in only</option><option value="0">Logged out only</option></select></label></p>';
		echo '<p><button class="button button-primary" type="submit">Add rule</button></p>';
		echo '</form>';

		echo '<h3>Current Rules</h3>';
		if ( empty( $rules ) ) {
			echo '<p>No rules yet.</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Type</th><th>Handle</th><th>Condition</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $rules as $rule ) {
			$condition = ( $rule['url_type'] ?? 'all' ) . ( ! empty( $rule['path_contains'] ) ? ' + path:' . $rule['path_contains'] : '' );
			echo '<tr>';
			echo '<td>' . esc_html( $rule['name'] ?? '' ) . '</td>';
			echo '<td>' . esc_html( $rule['asset_type'] ?? '' ) . '</td>';
			echo '<td><code>' . esc_html( $rule['handle'] ?? '' ) . '</code></td>';
			echo '<td>' . esc_html( $condition ) . '</td>';
			echo '<td>' . ( ! empty( $rule['enabled'] ) ? 'Enabled' : 'Disabled' ) . '</td>';
			echo '<td>';
			echo '<form method="post" style="display:inline-block;margin-right:6px;">';
			wp_nonce_field( 'vipers_toolset2_rules' );
			echo '<input type="hidden" name="vipers_action" value="toggle_rule">';
			echo '<input type="hidden" name="rule_id" value="' . esc_attr( $rule['id'] ) . '">';
			echo '<button class="button" type="submit">Toggle</button>';
			echo '</form>';
			echo '<form method="post" style="display:inline-block;">';
			wp_nonce_field( 'vipers_toolset2_rules' );
			echo '<input type="hidden" name="vipers_action" value="delete_rule">';
			echo '<input type="hidden" name="rule_id" value="' . esc_attr( $rule['id'] ) . '">';
			echo '<button class="button button-link-delete" type="submit">Delete</button>';
			echo '</form>';
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function render_safety_tab() {
		$settings = $this->get_settings();
		$snapshots = get_option( self::OPTION_SNAPSHOTS, array() );
		$snapshots = is_array( $snapshots ) ? array_reverse( $snapshots ) : array();

		echo '<h2>Safety Net</h2>';
		echo '<p><strong>Dry-run:</strong> ' . ( ! empty( $settings['dry_run'] ) ? 'Enabled' : 'Disabled' ) . '</p>';
		echo '<p><strong>Recovery URL parameter:</strong> <code>?vipers_recover=' . esc_html( $settings['recovery_key'] ) . '</code></p>';
		echo '<p>Use recovery parameter to bypass rules for 30 minutes if frontend breaks.</p>';

		echo '<form method="post" style="display:inline-block;margin-right:8px;">';
		wp_nonce_field( 'vipers_toolset2_safety' );
		echo '<input type="hidden" name="vipers_action" value="create_snapshot">';
		echo '<button class="button button-primary" type="submit">Create snapshot</button>';
		echo '</form>';
		echo '<form method="post" style="display:inline-block;">';
		wp_nonce_field( 'vipers_toolset2_safety' );
		echo '<input type="hidden" name="vipers_action" value="clear_logs">';
		echo '<button class="button" type="submit">Clear scan/compare logs</button>';
		echo '</form>';

		echo '<h3 style="margin-top:20px;">Snapshots</h3>';
		if ( empty( $snapshots ) ) {
			echo '<p>No snapshots yet.</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Rules</th><th>Action</th></tr></thead><tbody>';
		foreach ( $snapshots as $snapshot ) {
			echo '<tr>';
			echo '<td>' . esc_html( $snapshot['time'] ) . '</td>';
			echo '<td>' . esc_html( count( $snapshot['rules'] ) ) . '</td>';
			echo '<td>';
			echo '<form method="post">';
			wp_nonce_field( 'vipers_toolset2_safety' );
			echo '<input type="hidden" name="vipers_action" value="restore_snapshot">';
			echo '<input type="hidden" name="snapshot_id" value="' . esc_attr( $snapshot['id'] ) . '">';
			echo '<button class="button" type="submit">Restore</button>';
			echo '</form>';
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function render_compare_tab() {
		$compare = $this->get_compare_log();
		echo '<h2>Performance Compare</h2>';
		echo '<p>Request-level baseline for assets and rule impacts.</p>';

		if ( empty( $compare ) ) {
			echo '<p>No compare data yet.</p>';
			return;
		}

		$avg_count = array_sum( wp_list_pluck( $compare, 'total_count' ) ) / count( $compare );
		$avg_size = array_sum( wp_list_pluck( $compare, 'total_bytes' ) ) / count( $compare );
		$avg_decisions = array_sum( wp_list_pluck( $compare, 'decision_count' ) ) / count( $compare );

		echo '<p><strong>Average assets/request:</strong> ' . esc_html( round( $avg_count, 1 ) ) . '</p>';
		echo '<p><strong>Average size/request:</strong> ' . esc_html( round( $avg_size / 1024, 1 ) ) . ' KB</p>';
		echo '<p><strong>Average rule decisions/request:</strong> ' . esc_html( round( $avg_decisions, 2 ) ) . '</p>';

		echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Path</th><th>URL Type</th><th>Assets</th><th>Size</th><th>Rule Decisions</th><th>Mode</th></tr></thead><tbody>';
		foreach ( array_reverse( array_slice( $compare, -30 ) ) as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['time'] ) . '</td>';
			echo '<td>' . esc_html( $row['path'] ) . '</td>';
			echo '<td>' . esc_html( $row['url_type'] ) . '</td>';
			echo '<td>' . esc_html( $row['total_count'] ) . '</td>';
			echo '<td>' . esc_html( round( $row['total_bytes'] / 1024, 1 ) ) . ' KB</td>';
			echo '<td>' . esc_html( $row['decision_count'] ) . '</td>';
			echo '<td>' . ( ! empty( $row['dry_run'] ) ? 'Dry-run' : 'Live' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function render_recommendations_tab() {
		$recommendations = $this->build_recommendations();
		$aggregate = $this->build_aggregate();

		echo '<h2>Recommendation Engine</h2>';
		echo '<p>Heuristic suggestions based on request coverage and weight.</p>';
		echo '<ul>';
		foreach ( $recommendations as $item ) {
			echo '<li><strong>' . esc_html( strtoupper( $item['level'] ) ) . ':</strong> ' . esc_html( $item['text'] ) . '</li>';
		}
		echo '</ul>';

		if ( empty( $aggregate ) ) {
			echo '<p>No aggregate data yet.</p>';
			return;
		}

		usort( $aggregate, function( $a, $b ) {
			return $b['avg_size'] <=> $a['avg_size'];
		} );

		echo '<h3>Top Heavy Assets</h3>';
		echo '<table class="widefat striped"><thead><tr><th>Handle</th><th>Type</th><th>Plugin</th><th>Coverage</th><th>Avg Size</th><th>URL Types</th></tr></thead><tbody>';
		foreach ( array_slice( $aggregate, 0, 25 ) as $row ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( $row['handle'] ) . '</code></td>';
			echo '<td>' . esc_html( $row['type'] ) . '</td>';
			echo '<td>' . esc_html( $row['plugin'] ? $row['plugin'] : '-' ) . '</td>';
			echo '<td>' . esc_html( $row['coverage_pct'] ) . '%</td>';
			echo '<td>' . esc_html( round( $row['avg_size'] / 1024, 1 ) ) . ' KB</td>';
			echo '<td>' . esc_html( $row['url_type_count'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
	private function render_help_tab() {
		$settings     = $this->get_settings();
		$recovery_url = esc_url( home_url( '/?vipers_recover=' . $settings['recovery_key'] ) );
		?>
		<style>
			.vt-help h2 { border-bottom: 2px solid #2271b1; padding-bottom: 6px; margin-top: 30px; }
			.vt-help h3 { color: #2271b1; margin-top: 22px; }
			.vt-help h4 { margin-top: 16px; margin-bottom: 4px; }
			.vt-help .vt-notice  { background: #fcf8e3; border-left: 4px solid #e6a817; padding: 10px 14px; margin: 12px 0; border-radius: 2px; }
			.vt-help .vt-tip     { background: #eaf6fb; border-left: 4px solid #2271b1; padding: 10px 14px; margin: 12px 0; border-radius: 2px; }
			.vt-help .vt-danger  { background: #fbeaea; border-left: 4px solid #d63638; padding: 10px 14px; margin: 12px 0; border-radius: 2px; }
			.vt-help .vt-success { background: #edfaef; border-left: 4px solid #00a32a; padding: 10px 14px; margin: 12px 0; border-radius: 2px; }
			.vt-help table { border-collapse: collapse; width: 100%; margin: 12px 0; }
			.vt-help table th { background: #f0f0f1; text-align: left; padding: 7px 10px; }
			.vt-help table td { padding: 7px 10px; border-bottom: 1px solid #e2e4e7; vertical-align: top; }
			.vt-help ol li, .vt-help ul li { margin-bottom: 5px; }
			.vt-help .vt-step { display: flex; gap: 14px; align-items: flex-start; margin: 10px 0; }
			.vt-help .vt-step-num { background: #2271b1; color: #fff; border-radius: 50%; min-width: 26px; height: 26px; display: flex; align-items: center; justify-content: center; font-weight: bold; flex-shrink: 0; }
			.vt-help .vt-toc { background: #f6f7f7; border: 1px solid #dcdcde; padding: 14px 18px; display: inline-block; min-width: 260px; border-radius: 2px; }
			.vt-help .vt-toc li { margin-bottom: 3px; }
			.vt-help .vt-toc a { text-decoration: none; }
			.vt-help .vt-toc a:hover { text-decoration: underline; }
		</style>
		<div class="vt-help">

		<h1 style="margin-top:0">Vipers Toolset 2.0 – Bedienungsanleitung</h1>
		<p><em>Version 2.0 &bull; Ergänzungs-Tool zu WP-Optimize Premium und OMGF</em></p>

		<div class="vt-toc">
			<strong>Inhalt</strong>
			<ol>
				<li><a href="#vt-help-philosophy">Konzept &amp; Abgrenzung</a></li>
				<li><a href="#vt-help-quickstart">Schnellstart (Empfohlener Workflow)</a></li>
				<li><a href="#vt-help-overview">Tab: Übersicht</a></li>
				<li><a href="#vt-help-scanner">Tab: Asset Map</a></li>
				<li><a href="#vt-help-rules">Tab: Regelwerk</a></li>
				<li><a href="#vt-help-safety">Tab: Sicherheitsnetz</a></li>
				<li><a href="#vt-help-compare">Tab: Performance-Vergleich</a></li>
				<li><a href="#vt-help-recommendations">Tab: Empfehlungen</a></li>
				<li><a href="#vt-help-recovery">Notfall-Wiederherstellung</a></li>
				<li><a href="#vt-help-faq">Häufige Fragen (FAQ)</a></li>
			</ol>
		</div>

		<!-- 1 -->
		<h2 id="vt-help-philosophy">1. Konzept &amp; Abgrenzung</h2>
		<p>Das Vipers Toolset 2.0 ist <strong>kein</strong> Caching-, Image- oder Font-Plugin.
		Es wurde speziell als Ergänzung zu <strong>WP-Optimize Premium</strong> und <strong>OMGF</strong> entwickelt
		und übernimmt Aufgaben, die diese Tools nicht abdecken:</p>

		<table>
			<thead><tr><th>Funktion</th><th>WP-Optimize</th><th>OMGF</th><th>Vipers Toolset</th></tr></thead>
			<tbody>
				<tr><td>Seiten-Cache</td><td>✅</td><td>–</td><td>–</td></tr>
				<tr><td>CSS/JS Minify &amp; Kombinieren</td><td>✅</td><td>–</td><td>–</td></tr>
				<tr><td>Google Fonts lokal hosten</td><td>⚠️ (deaktivieren!)</td><td>✅</td><td>–</td></tr>
				<tr><td>Bilder optimieren</td><td>✅</td><td>–</td><td>–</td></tr>
				<tr><td>Datenbankbereinigung</td><td>✅</td><td>–</td><td>–</td></tr>
				<tr><td><strong>Asset-Inventar pro Seite/URL-Typ</strong></td><td>–</td><td>–</td><td>✅</td></tr>
				<tr><td><strong>Bedingte CSS/JS-Regeln</strong></td><td>–</td><td>–</td><td>✅</td></tr>
				<tr><td><strong>Snapshots + Rollback</strong></td><td>–</td><td>–</td><td>✅</td></tr>
				<tr><td><strong>Messbarer Regel-Impact</strong></td><td>–</td><td>–</td><td>✅</td></tr>
				<tr><td><strong>Empfehlungsengine</strong></td><td>–</td><td>–</td><td>✅</td></tr>
			</tbody>
		</table>

		<div class="vt-notice">
			<strong>Wichtig:</strong> Wenn WP-Optimize Premium aktiv ist, muss dort die Option
			<em>„Google Fonts-Verarbeitung deaktivieren"</em> aktiviert sein, damit OMGF keine Konflikte hat.
		</div>

		<!-- 2 -->
		<h2 id="vt-help-quickstart">2. Schnellstart – Empfohlener Workflow</h2>

		<div class="vt-step"><div class="vt-step-num">1</div><div>
			<strong>Plugin aktivieren, Dry-Run lassen</strong><br>
			Nach der Aktivierung ist <em>Dry-Run</em> standardmäßig <strong>aktiv</strong>.
			Das bedeutet: Regeln werden ausgewertet und geloggt, aber <strong>kein Asset wird entfernt</strong>.
			Dies ist der sichere Einstiegsmodus.
		</div></div>

		<div class="vt-step"><div class="vt-step-num">2</div><div>
			<strong>Asset Map befüllen</strong><br>
			Wechsle zum Tab <em>Asset Map</em> und klicke auf <strong>„Jetzt Quick-Crawl starten"</strong>.
			Das Plugin besucht Startseite und bis zu 6 Unterseiten im Hintergrund.
			Zusätzlich werden beim normalen Surfen auf deiner Website automatisch Assets erfasst.<br>
			<em>Tipp: Besuche selbst einige Seiten als Besucher (inkognito), um alle URL-Typen abzudecken.</em>
		</div></div>

		<div class="vt-step"><div class="vt-step-num">3</div><div>
			<strong>Empfehlungen prüfen</strong><br>
			Wechsle zum Tab <em>Empfehlungen</em>. Nach ausreichend Scan-Daten erscheinen dort
			Handles, die auf bestimmten URL-Typen unnötig geladen werden und gute Kandidaten
			für bedingte Regeln sind.
		</div></div>

		<div class="vt-step"><div class="vt-step-num">4</div><div>
			<strong>Snapshot erstellen</strong><br>
			Vor dem Anlegen von Regeln: Im Tab <em>Sicherheitsnetz</em> einen Snapshot speichern.
			Damit kannst du jederzeit auf den aktuellen Stand zurückrollen.
		</div></div>

		<div class="vt-step"><div class="vt-step-num">5</div><div>
			<strong>Erste Regeln anlegen (noch im Dry-Run)</strong><br>
			Wechsle zum Tab <em>Regelwerk</em> und lege eine Regel an.
			Im Dry-Run kannst du im Tab <em>Performance-Vergleich</em> sehen,
			wie oft die Regel ausgelöst worden wäre – ohne Risiko.
		</div></div>

		<div class="vt-step"><div class="vt-step-num">6</div><div>
			<strong>Dry-Run deaktivieren</strong><br>
			Wenn die Regeln im Dry-Run-Log korrekt aussehen:
			Im Tab <em>Übersicht</em> den Haken bei <em>Dry-Run</em> entfernen und speichern.
			<strong>Recovery-URL vorher notieren</strong> (Tab: Sicherheitsnetz) – für den Notfall!
		</div></div>

		<div class="vt-step"><div class="vt-step-num">7</div><div>
			<strong>Frontend prüfen</strong><br>
			Öffne die betroffenen Seiten im Browser (inkognito). Sind alle Funktionen intakt?<br>
			Wenn nicht: Notfall-URL aufrufen (siehe Abschnitt 9) oder Snapshot wiederherstellen.
		</div></div>

		<!-- 3 -->
		<h2 id="vt-help-overview">3. Tab: Übersicht</h2>
		<p>Der erste Tab zeigt den aktuellen Systemstatus auf einen Blick und erlaubt das schnelle Anpassen der Grundeinstellungen.</p>

		<h3>Systemstatus</h3>
		<ul>
			<li><strong>Dry-Run:</strong> Zeigt, ob Regeln nur geloggt (sicher) oder wirklich angewendet werden.</li>
			<li><strong>Scanner:</strong> Zeigt, ob Asset-Erfassung auf Frontend-Seiten aktiv ist.</li>
			<li><strong>Regeln:</strong> Anzahl aller angelegten Regeln (aktive + deaktivierte).</li>
			<li><strong>Scan-Anfragen gespeichert:</strong> Wie viele Frontend-Anfragen bisher analysiert wurden.</li>
			<li><strong>Vergleichs-Zeilen gespeichert:</strong> Datenbasis für den Performance-Vergleich.</li>
		</ul>

		<h3>Schnelleinstellungen</h3>
		<table>
			<thead><tr><th>Einstellung</th><th>Beschreibung</th></tr></thead>
			<tbody>
				<tr><td>Dry-Run aktivieren</td><td>Wenn aktiv: Regeln werden geloggt, aber kein Asset wird entfernt. <strong>Immer zuerst testen!</strong></td></tr>
				<tr><td>Scanner aktivieren</td><td>Wenn aktiv: Jede Frontend-Anfrage erfasst geladene CSS/JS-Assets automatisch.</td></tr>
				<tr><td>Max. Log-Zeilen</td><td>Maximale Anzahl gespeicherter Scan-Einträge (50–2000). Ältere Einträge werden automatisch gelöscht.</td></tr>
				<tr><td>Recovery-Key zurücksetzen</td><td>Generiert einen neuen Notfall-Schlüssel. <strong>Danach unbedingt die neue Recovery-URL im Tab Sicherheitsnetz notieren!</strong></td></tr>
			</tbody>
		</table>

		<h3>Ereignisprotokoll</h3>
		<p>Zeigt die letzten 10 Plugin-Ereignisse (Einstellungen gespeichert, Regeln hinzugefügt, Snapshots erstellt usw.).
		Nützlich zur Nachvollziehbarkeit von Änderungen.</p>

		<!-- 4 -->
		<h2 id="vt-help-scanner">4. Tab: Asset Map</h2>
		<p>Die Asset Map zeigt, welche CSS- und JavaScript-Dateien auf welchen Seiten deiner Website geladen werden.
		Dies ist die Grundlage für alle Regel- und Optimierungsentscheidungen.</p>

		<h3>Wie die Erfassung funktioniert</h3>
		<p>Der Scanner hängt sich in den WordPress-Enqueue-Prozess ein und protokolliert jeden
		CSS- und JS-Handle, der auf einer Frontend-Seite tatsächlich ausgegeben wird.
		Pro Anfrage werden gespeichert: Zeitstempel, URL-Typ, Pfad, Anzahl Assets, geschätzte Gesamtgröße.</p>

		<h3>Quick-Crawl</h3>
		<p>Der Button <strong>„Jetzt Quick-Crawl starten"</strong> lässt das Plugin im Hintergrund
		die Startseite sowie bis zu 6 WordPress-Seiten (Pages) aufrufen, um Scan-Daten zu befüllen.
		Diese Anfragen laufen serverseitig – browserseitig geladene Ressourcen (externe Fonts, CDN usw.) werden dabei <em>nicht</em> erfasst.</p>

		<div class="vt-tip">
			<strong>Tipp:</strong> Für realistische Daten eignet sich der normale Seitenbesuch als ausgeloggter Besucher.
			Öffne inkognito mehrere verschiedene Seiten (Startseite, Kategorie, einzelner Beitrag, Kontakt-Seite),
			um alle URL-Typen abzudecken.
		</div>

		<h3>Tabellenspalten</h3>
		<table>
			<thead><tr><th>Spalte</th><th>Bedeutung</th></tr></thead>
			<tbody>
				<tr><td>Zeit</td><td>Wann die Anfrage erfasst wurde.</td></tr>
				<tr><td>URL-Typ</td><td>WordPress-Seitentyp: front_page, home, page, single, archive, search, 404, other.</td></tr>
				<tr><td>Pfad</td><td>Relativer URL-Pfad der Seite.</td></tr>
				<tr><td>Assets</td><td>Anzahl erfasster CSS + JS Handles auf dieser Seite.</td></tr>
				<tr><td>Größe (ca.)</td><td>Geschätzte Gesamtgröße aller lokalen Assets anhand der Dateigrößen. Externe Quellen = 0 KB.</td></tr>
			</tbody>
		</table>

		<h3>Handle-Namen herausfinden</h3>
		<p>Den <em>Handle</em> (technischen Namen) eines Scripts oder Stylesheets findest du so:</p>
		<ol>
			<li>Browser-Entwicklertools öffnen (F12) → Tab „Netzwerk" → Filter: CSS oder JS.</li>
			<li>Im Quellcode der Seite nach <code>id="handle-name-css"</code> oder <code>id="handle-name-js"</code> suchen.
			WordPress fügt bei jedem Asset ein <code>id</code>-Attribut mit dem Handle hinzu (minus das <code>-css</code>/<code>-js</code>-Suffix).</li>
			<li>Im Tab <em>Empfehlungen</em> → Tabelle „Schwergewichtige Assets": Dort sind alle erfassten Handles mit Klarnamen sichtbar.</li>
		</ol>

		<!-- 5 -->
		<h2 id="vt-help-rules">5. Tab: Regelwerk</h2>
		<p>Das Regelwerk erlaubt das gezielte Deaktivieren (Dequeue) einzelner CSS- oder JS-Handles
		unter präzisen Bedingungen – ohne Code-Änderungen am Theme oder Plugin.</p>

		<h3>Regel anlegen – Feldübersicht</h3>
		<table>
			<thead><tr><th>Feld</th><th>Beschreibung</th><th>Beispiel</th></tr></thead>
			<tbody>
				<tr><td>Name</td><td>Frei wählbarer Beschreibungsname für die Regel.</td><td><em>Slider-JS nur auf Startseite</em></td></tr>
				<tr><td>Asset-Typ</td><td><strong>Style</strong> = CSS-Datei &bull; <strong>Script</strong> = JavaScript-Datei.</td><td>Script</td></tr>
				<tr><td>Handle</td><td>Exakter technischer Name des Assets (Kleinbuchstaben, case-sensitive).</td><td><code>smart-slider3</code></td></tr>
				<tr><td>URL-Typ</td><td>Auf welchem Seitentyp soll die Regel wirken?</td><td>page (WordPress-Seiten)</td></tr>
				<tr><td>Pfad enthält</td><td>Optionaler Text, der im URL-Pfad vorkommen muss (AND-Verknüpfung mit URL-Typ).</td><td><code>/kontakt</code></td></tr>
				<tr><td>Login-Status</td><td>Regel nur für eingeloggte / ausgeloggte Besucher oder für alle.</td><td>Nur ausgeloggt</td></tr>
			</tbody>
		</table>

		<h3>URL-Typen erklärt</h3>
		<table>
			<thead><tr><th>URL-Typ</th><th>Entspricht</th></tr></thead>
			<tbody>
				<tr><td>all</td><td>Alle Seiten ohne Einschränkung.</td></tr>
				<tr><td>front_page</td><td>Die als Startseite festgelegte Seite (<code>is_front_page()</code>).</td></tr>
				<tr><td>home</td><td>Blog-Übersichtsseite (<code>is_home()</code>).</td></tr>
				<tr><td>singular</td><td>Jede einzelne Inhaltsseite (Beitrag + Seite + Custom Post Type).</td></tr>
				<tr><td>page</td><td>Nur WordPress-Seiten (<code>is_page()</code>).</td></tr>
				<tr><td>single</td><td>Nur Blogbeiträge (<code>is_single()</code>).</td></tr>
				<tr><td>archive</td><td>Kategorie-, Tag-, Datums-, Autoren-Archive.</td></tr>
				<tr><td>search</td><td>Suchergebnisseite.</td></tr>
				<tr><td>404</td><td>Fehlerseite „Nicht gefunden".</td></tr>
			</tbody>
		</table>

		<h3>Regellogik</h3>
		<p>Alle Bedingungen einer Regel werden mit <strong>UND</strong> verknüpft:
		URL-Typ <em>UND</em> Pfad enthält <em>UND</em> Login-Status müssen gleichzeitig zutreffen.</p>

		<h3>Typische Anwendungsfälle</h3>
		<table>
			<thead><tr><th>Ziel</th><th>Einstellung</th></tr></thead>
			<tbody>
				<tr><td>Kontaktformular-CSS nur auf Kontaktseite laden</td><td>URL-Typ: page &bull; Pfad enthält: /kontakt &bull; Handle des CF7-Styles</td></tr>
				<tr><td>Slider-Script nur auf Startseite laden</td><td>URL-Typ: front_page &bull; Handle des Slider-Scripts</td></tr>
				<tr><td>WooCommerce-CSS auf Blog deaktivieren</td><td>URL-Typ: single &bull; Handle: woocommerce-layout</td></tr>
				<tr><td>Plugin-Script nur für ausgeloggte Nutzer deaktivieren</td><td>Login-Status: Nur ausgeloggt &bull; URL-Typ: all</td></tr>
			</tbody>
		</table>

		<div class="vt-notice">
			<strong>Wichtig:</strong> Verwende immer zuerst den <strong>Dry-Run-Modus</strong> und prüfe im Tab
			<em>Performance-Vergleich</em>, ob die Regel wie erwartet ausgelöst wird,
			bevor du den Dry-Run deaktivierst.
		</div>

		<h3>Regeln aktivieren / deaktivieren / löschen</h3>
		<ul>
			<li><strong>Toggle:</strong> Schaltet die Regel ein oder aus, ohne sie zu löschen. Nützlich für Tests.</li>
			<li><strong>Löschen:</strong> Entfernt die Regel dauerhaft. Vorher einen Snapshot erstellen!</li>
		</ul>

		<!-- 6 -->
		<h2 id="vt-help-safety">6. Tab: Sicherheitsnetz</h2>
		<p>Das Sicherheitsnetz schützt vor unerwünschten Auswirkungen aktiver Regeln
		durch zwei Mechanismen: <strong>Snapshots</strong> und den <strong>Recovery-Key</strong>.</p>

		<h3>Snapshots</h3>
		<p>Ein Snapshot speichert den kompletten aktuellen Zustand:
		alle Einstellungen <em>und</em> alle Regeln als unveränderlichen Zeitstempel-Eintrag.</p>
		<ul>
			<li>Es werden maximal <strong>20 Snapshots</strong> gespeichert (älteste werden automatisch gelöscht).</li>
			<li>Snapshot wiederherstellen: Klick auf <em>„Wiederherstellen"</em> setzt Einstellungen und Regeln
			auf den Stand zum Zeitpunkt des Snapshots zurück. Scan- und Vergleichs-Logs bleiben erhalten.</li>
		</ul>

		<div class="vt-success">
			<strong>Empfehlung:</strong> Snapshot erstellen…
			<ul style="margin-top:6px;">
				<li>… bevor du neue Regeln anlegst.</li>
				<li>… bevor du Dry-Run deaktivierst.</li>
				<li>… nach einem erfolgreichen Test, den du festhalten möchtest.</li>
			</ul>
		</div>

		<h3>Recovery-URL (Notfall-Bypass)</h3>
		<p>Falls nach dem Deaktivieren von Dry-Run die Website nicht mehr korrekt lädt
		(z. B. fehlendes CSS bricht das Layout), rufe folgende URL auf:</p>
		<p><code style="word-break:break-all"><?php echo esc_html( $recovery_url ); ?></code></p>
		<ul>
			<li>Diese URL deaktiviert alle Regeln für <strong>30 Minuten</strong>.</li>
			<li>In dieser Zeit kannst du ruhig den Dry-Run wieder aktivieren oder Regeln korrigieren.</li>
			<li>Der Bypass erlischt automatisch nach 30 Minuten oder nach einem Transient-Flush.</li>
			<li>Den Key kannst du in der Übersicht zurücksetzen. <strong>Danach hier die neue URL notieren!</strong></li>
		</ul>

		<div class="vt-danger">
			<strong>Sicherheitshinweis:</strong> Die Recovery-URL enthält einen geheimen Schlüssel.
			Teile sie nicht öffentlich. Speichere sie in deinem Passwort-Manager oder einer privaten Notiz.
		</div>

		<h3>Logs löschen</h3>
		<p>Der Button <em>„Scan/Vergleichs-Logs löschen"</em> entfernt alle gespeicherten Asset-Scan-Daten
		und Performance-Vergleichs-Daten. Regeln und Einstellungen bleiben unberührt.
		Nützlich, wenn du nach einer größeren Regeländerung frisch beginnen möchtest.</p>

		<!-- 7 -->
		<h2 id="vt-help-compare">7. Tab: Performance-Vergleich</h2>
		<p>Der Performance-Vergleich zeigt den messbaren Effekt deiner Regeln über alle erfassten Anfragen hinweg.</p>

		<h3>Durchschnittliche Kennzahlen (oben)</h3>
		<table>
			<thead><tr><th>Kennzahl</th><th>Bedeutung</th></tr></thead>
			<tbody>
				<tr><td>Ø Assets/Anfrage</td><td>Durchschnittliche Anzahl geladener CSS+JS-Handles pro Seitenaufruf.</td></tr>
				<tr><td>Ø Größe/Anfrage</td><td>Durchschnittliches Gesamt-Volumen aller lokalen Assets pro Seitenaufruf.</td></tr>
				<tr><td>Ø Regelentscheidungen/Anfrage</td><td>Wie oft pro Aufruf eine Regel ausgelöst wurde (Dry-Run oder Live).</td></tr>
			</tbody>
		</table>

		<h3>Tabellenspalten</h3>
		<table>
			<thead><tr><th>Spalte</th><th>Bedeutung</th></tr></thead>
			<tbody>
				<tr><td>Zeit</td><td>Zeitstempel des Seitenaufrufs.</td></tr>
				<tr><td>Pfad</td><td>Aufgerufener URL-Pfad.</td></tr>
				<tr><td>URL-Typ</td><td>Erkannter WordPress-Seitentyp.</td></tr>
				<tr><td>Assets</td><td>Anzahl tatsächlich geladener Handles auf dieser Seite.</td></tr>
				<tr><td>Größe</td><td>Geschätzte Gesamtgröße der lokalen Assets.</td></tr>
				<tr><td>Regelentscheidungen</td><td>Anzahl der Regeln, die auf dieser Seite ausgelöst wurden.</td></tr>
				<tr><td>Modus</td><td><em>Dry-run</em>: nur geloggt &bull; <em>Live</em>: wirklich entfernt.</td></tr>
			</tbody>
		</table>

		<div class="vt-tip">
			<strong>Wie interpretieren?</strong> Wenn du Dry-Run deaktivierst und danach neue Daten sammelst,
			kannst du die Assets- und Größen-Werte vor und nach dem Aktivieren von Regeln vergleichen.
			Ein klarer Rückgang zeigt, dass deine Regeln wirken.
		</div>

		<!-- 8 -->
		<h2 id="vt-help-recommendations">8. Tab: Empfehlungen</h2>
		<p>Die Empfehlungsengine analysiert die gesammelten Scan-Daten und hebt Assets hervor,
		die besonders gute Kandidaten für bedingte Regeln sind.</p>

		<h3>Empfehlungskriterien (HIGH)</h3>
		<p>Eine Empfehlung der Stufe <strong>HIGH</strong> wird ausgelöst, wenn ein Asset gleichzeitig:</p>
		<ul>
			<li>auf <strong>≥ 85 %</strong> aller erfassten Anfragen geladen wird,</li>
			<li>durchschnittlich <strong>≥ 30 KB</strong> groß ist,</li>
			<li>nur auf <strong>≤ 2 verschiedenen URL-Typen</strong> gesehen wurde.</li>
		</ul>
		<p>Das bedeutet: Das Asset ist groß, allgegenwärtig, aber eigentlich nur für wenige Seitentypen relevant –
		ein klassischer Fall für eine bedingte Regel.</p>

		<h3>Tabelle „Schwergewichtige Assets"</h3>
		<p>Zeigt die 25 größten Assets (nach Durchschnittsgröße) – unabhängig von der Empfehlungsschwelle.
		Nützlich für einen schnellen Überblick, welche Plugins die meisten Ressourcen laden.</p>

		<h3>Was tun mit einer Empfehlung?</h3>
		<ol>
			<li>Handle aus der Empfehlung oder der Tabelle kopieren.</li>
			<li>Im Tab <em>Regelwerk</em> eine neue Regel anlegen mit diesem Handle und dem passenden URL-Typ.</li>
			<li>Im Dry-Run testen (Performance-Vergleich prüfen).</li>
			<li>Snapshot erstellen, dann Dry-Run deaktivieren.</li>
		</ol>

		<!-- 9 -->
		<h2 id="vt-help-recovery">9. Notfall-Wiederherstellung</h2>

		<div class="vt-danger">
			<strong>Szenario:</strong> Du hast Dry-Run deaktiviert, eine Regel entfernt ein kritisches Asset,
			die Website ist optisch oder funktional kaputt.
		</div>

		<h4>Schritt-für-Schritt Notfallablauf:</h4>
		<ol>
			<li><strong>Recovery-URL aufrufen:</strong><br>
				<code style="word-break:break-all"><?php echo esc_html( $recovery_url ); ?></code><br>
				Damit werden alle Regeln für 30 Minuten deaktiviert.
			</li>
			<li>In dieser Zeit: WordPress-Admin öffnen → <em>Werkzeuge → Vipers Toolset 2.0</em>.</li>
			<li>Tab <em>Übersicht</em>: Dry-Run wieder aktivieren und speichern.</li>
			<li>Tab <em>Sicherheitsnetz</em>: Letzten funktionierenden Snapshot wiederherstellen.</li>
			<li>Die fehlerhafte Regel im Tab <em>Regelwerk</em> korrigieren oder löschen.</li>
			<li>Erneut im Dry-Run testen, bevor du Regeln wieder live schaltest.</li>
		</ol>

		<div class="vt-tip">
			<strong>Alternative:</strong> Falls der WordPress-Admin nicht erreichbar ist,
			kannst du via FTP oder Dateimanager die Datei
			<code>wp-content/plugins/vipers-toolset/vipers-toolset.php</code> vorübergehend umbenennen.
			Das deaktiviert das Plugin vollständig und stellt alle Assets wieder her.
		</div>

		<!-- 10 -->
		<h2 id="vt-help-faq">10. Häufige Fragen (FAQ)</h2>

		<h4>Warum sehe ich keine Daten im Asset Map?</h4>
		<p>Sicherstellen, dass der <em>Scanner</em> in der Übersicht aktiviert ist.
		Danach eine Frontend-Seite (nicht den Admin) aufrufen oder den Quick-Crawl starten.
		Die Daten erscheinen erst nach dem nächsten Reload im Admin.</p>

		<h4>Der Quick-Crawl liefert keine Assets – warum?</h4>
		<p>Der Crawl läuft serverseitig via <code>wp_remote_get()</code>. Falls deine Website
		hinter einem Passwortschutz liegt (z. B. das „Private Website"-Plugin aktiv ist),
		kann der Server seine eigenen Seiten nicht abrufen. In diesem Fall: direkt als Besucher surfen.</p>

		<h4>Meine Empfehlungen zeigen nur „Noch keine Daten".</h4>
		<p>Die Empfehlungsengine benötigt mehrere unterschiedliche URL-Typen in den Scan-Daten.
		Besuche Startseite, einen Beitrag, eine Seite, eine Kategorie – dann neu laden.</p>

		<h4>Was passiert, wenn ich versehentlich einen wichtigen Handle deaktiviere?</h4>
		<p>Wenn Dry-Run aktiv ist: nichts passiert, der Fehler wird nur geloggt.
		Wenn Dry-Run deaktiviert ist: Recovery-URL aufrufen (Abschnitt 9) und Snapshot wiederherstellen.</p>

		<h4>Kann das Plugin mit WP-Optimize oder OMGF in Konflikt geraten?</h4>
		<p>Nein – Vipers Toolset 2.0 greift ausschließlich über den Standard-WordPress-Dequeue-Mechanismus ein
		und berührt weder Cache noch Font-Verarbeitung. Es ergänzt WP-Optimize und OMGF, ohne mit ihnen zu konkurrieren.</p>

		<h4>Wie finde ich den richtigen Handle-Namen?</h4>
		<p>Im Browser-Quelltext nach <code>id="xyz-css"</code> oder <code>id="xyz-js"</code> suchen.
		Der Handle ist <code>xyz</code> (ohne Suffix). Oder: Tab <em>Empfehlungen</em> →
		Tabelle „Schwergewichtige Assets" zeigt alle erfassten Handles.</p>

		<h4>Werden Regeln auch im WP Admin oder für AJAX-Anfragen angewendet?</h4>
		<p>Nein. Regeln wirken ausschließlich auf dem <strong>Frontend</strong> für reguläre Seitenaufrufe.
		Admin-Seiten und AJAX-Anfragen werden nicht beeinflusst.</p>

		<h4>Wie viele Snapshots kann ich speichern?</h4>
		<p>Maximal <strong>20 Snapshots</strong>. Ältere werden automatisch entfernt, wenn das Limit überschritten wird.</p>

		<h4>Kann ich Regeln exportieren (z. B. von Staging nach Live)?</h4>
		<p>In Version 2.0 noch nicht – geplant für Version 2.1. Als Workaround:
		In der Staging-Datenbank den Optionswert <code>vipers_toolset2_rules</code> exportieren
		und auf Live importieren (z. B. via WP-CLI: <code>wp option get vipers_toolset2_rules</code>).</p>

		<p style="margin-top:30px; color:#646970; font-size:13px;">
			Vipers Toolset 2.0 &bull; Lizenz: GPL-2.0+ &bull;
			<a href="<?php echo esc_url( admin_url( 'tools.php?page=vipers-toolset&tab=overview' ) ); ?>">Zur Übersicht</a>
		</p>

		</div><!-- .vt-help -->
		<?php
	}
}

Vipers_Toolset_2::get_instance();
