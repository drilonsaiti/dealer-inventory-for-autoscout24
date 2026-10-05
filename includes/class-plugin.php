<?php
/**
 * Plugin bootstrap.
 *
 * @package DealerInventory
 */

namespace DealerInventory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires hooks. Classes are loaded lazily, so a normal page view without an
 * inventory only reads two autoloaded options.
 */
final class Plugin {

	private const VERSION_OPTION = 'dinv_plugin_version';

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
		add_action( 'init', array( Migrations::class, 'maybe_run' ), 1 );
		add_action( 'init', array( $this, 'maybe_handle_update' ), 2 );
		add_action( 'init', array( Scheduler::class, 'ensure' ), 20 );
		add_filter( 'cron_schedules', array( Scheduler::class, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Interval is chosen by the site owner (15 min or more).
		add_action( Sync::HOOK, array( Scheduler::class, 'run' ) );

		add_shortcode( Shortcode::TAG, array( Shortcode::class, 'render' ) );
		add_action( 'init', array( Assets::class, 'register' ), 8 );
		add_action( 'init', array( Block::class, 'register' ), 9 );
		add_action( 'elementor/widgets/register', array( Elementor::class, 'register' ) );
		Detail::register();
		add_action( 'template_redirect', array( Preview::class, 'maybe_render' ), 1 );
		add_action( 'wp_enqueue_scripts', array( Assets::class, 'maybe_enqueue' ), 5 );
		add_filter( 'wp_resource_hints', array( Assets::class, 'resource_hints' ), 10, 2 );
		add_filter( 'wp_robots', array( $this, 'robots' ) );
		add_filter( 'get_canonical_url', array( $this, 'canonical' ) );
		add_filter( 'wpseo_canonical', array( $this, 'canonical' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast SEO.
		add_filter( 'rank_math/frontend/canonical', array( $this, 'canonical' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Rank Math.
		add_action( 'rest_api_init', array( Rest::class, 'routes' ) );
		add_filter( 'elementor/widget/render_content', array( $this, 'elementor_html_widget' ), 10, 2 );
		add_filter( 'elementor/element/is_dynamic_content', array( self::class, 'elementor_is_dynamic' ), 10, 2 );

		if ( is_admin() ) {
			( new Admin() )->register();
		}
	}

	/**
	 * Load bundled translations. Translations from translate.wordpress.org
	 * in wp-content/languages/plugins take precedence.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'dealer-inventory-for-autoscout24', false, dirname( plugin_basename( DINV_PLUGIN_FILE ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Ships translations for de_DE, de_CH, de_AT, fr_FR and it_IT.
	}

	/**
	 * Run shortcodes inside Elementor's HTML widget when it contains an inventory.
	 *
	 * @param mixed $content Widget output.
	 * @param mixed $widget  Widget.
	 * @return mixed
	 */
	public function elementor_html_widget( $content, $widget ) {
		if (
			is_string( $content ) &&
			is_object( $widget ) &&
			method_exists( $widget, 'get_name' ) &&
			'html' === $widget->get_name() &&
			false !== strpos( $content, '[' . Shortcode::TAG )
		) {
			// Run only this plugin's shortcode, not every shortcode in the widget.
			$pattern = get_shortcode_regex( array( Shortcode::TAG ) );
			return (string) preg_replace_callback( "/$pattern/", 'do_shortcode_tag', $content );
		}
		return $content;
	}

	/**
	 * Tell Elementor's element cache that widgets containing an inventory are
	 * dynamic, so cached HTML never shows an outdated vehicle list.
	 *
	 * @param mixed $is_dynamic Current flag.
	 * @param mixed $raw_data   Element data.
	 * @return bool
	 */
	public static function elementor_is_dynamic( $is_dynamic, $raw_data = array() ): bool {
		if ( ! is_array( $raw_data ) || empty( $raw_data['settings'] ) || ! is_array( $raw_data['settings'] ) ) {
			return (bool) $is_dynamic;
		}

		$found = false;
		array_walk_recursive(
			$raw_data['settings'],
			static function ( $value ) use ( &$found ): void {
				if ( ! $found && is_string( $value ) && false !== strpos( $value, '[' . Shortcode::TAG ) ) {
					$found = true;
				}
			}
		);

		return $found || (bool) $is_dynamic;
	}

	/**
	 * Filtered or re-sorted result URLs are not indexed (they duplicate the
	 * main page). Plain result pages (?dinv_page=2) stay indexable so every
	 * vehicle is reachable by crawlers.
	 *
	 * @param array $robots Robots directives.
	 * @return array
	 */
	public function robots( array $robots ): array {
		if ( self::url_state()['other'] ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}

	/**
	 * Self-referencing canonical for paginated result pages.
	 *
	 * @param mixed $url Canonical URL.
	 * @return mixed
	 */
	public function canonical( $url ) {
		$state = self::url_state();
		if ( ! is_string( $url ) || '' === $url || $state['other'] || ! $state['pages'] ) {
			return $url;
		}
		return add_query_arg( $state['pages'], $url );
	}

	/**
	 * Inventory parameters in the current request: page parameters (> 1)
	 * and whether any other filter/sort parameter is present.
	 *
	 * @return array{pages: array<string, int>, other: bool}
	 */
	private static function url_state(): array {
		$state = array(
			'pages' => array(),
			'other' => false,
		);
		foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only.
			if ( ! is_string( $key ) || ! str_starts_with( $key, 'dinv_' ) || Detail::QUERY_VAR === $key ) {
				continue;
			}
			if ( preg_match( '/^dinv_(?:[a-z0-9_-]+_)?page$/', $key ) ) {
				$page = is_scalar( $value ) ? absint( $value ) : 0;
				if ( $page > 1 ) {
					$state['pages'][ $key ] = $page;
				}
				continue;
			}
			$state['other'] = true;
		}
		return $state;
	}

	/**
	 * After an update, clear Elementor's cached render of inventory pages once.
	 */
	public function maybe_handle_update(): void {
		if ( DINV_VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}
		Repository::invalidate_public_cache();
		self::clear_elementor_caches();
		update_option( self::VERSION_OPTION, DINV_VERSION, true );
	}

	/**
	 * Activation.
	 */
	public static function activate(): void {
		Migrations::maybe_run();
		Settings::all();
		update_option( self::VERSION_OPTION, DINV_VERSION, true );
		Scheduler::reschedule();
		update_option( 'dinv_flush_rewrite', '1', true );
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate(): void {
		Scheduler::clear();
		flush_rewrite_rules( false );
	}

	/**
	 * Elementor caches rendered widget HTML; invalidate pages with an inventory.
	 */
	private static function clear_elementor_caches(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return;
		}

		global $wpdb;
		$like = '%' . $wpdb->esc_like( '[' . Shortcode::TAG ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off after updates.
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE %s",
				$like
			)
		);

		foreach ( array_map( 'absint', (array) $post_ids ) as $post_id ) {
			delete_post_meta( $post_id, '_elementor_element_cache' );
			delete_post_meta( $post_id, '_elementor_css' );
			clean_post_cache( $post_id );
		}

		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) && method_exists( \Elementor\Plugin::$instance->files_manager, 'clear_cache' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	}
}
