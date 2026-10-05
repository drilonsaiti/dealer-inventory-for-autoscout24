<?php
/**
 * Local vehicle detail pages.
 *
 * @package DealerInventory
 */

namespace DealerInventory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vehicle detail pages on the site itself (link_to = "local").
 *
 * URLs use a rewrite endpoint on the inventory page (or the configured
 * detail page): /inventory/vehicle/12345-bmw-x5-xdrive30d/. With plain
 * permalinks the same works as ?dinv_vehicle=12345.
 *
 * The first inventory on such a URL renders the vehicle instead of the list.
 * Adds the document title, meta description, canonical URL, Open Graph tags
 * and Vehicle (schema.org Car) JSON-LD, and lists the pages in the core
 * XML sitemap when a detail page is configured.
 */
final class Detail {

	public const QUERY_VAR = 'dinv_vehicle';

	/**
	 * Vehicle of the current request (false = not looked up yet).
	 *
	 * @var array|null|false
	 */
	private static $vehicle = false;

	/**
	 * The detail view was rendered on this request.
	 *
	 * @var bool
	 */
	private static bool $rendered = false;

	/**
	 * Base page URL for links built outside a page request (REST).
	 *
	 * @var string
	 */
	private static string $context_base = '';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'add_endpoint' ), 5 );
		add_action( 'init', array( self::class, 'maybe_flush' ), 99 );
		add_filter( 'request', array( self::class, 'front_page_request' ) );
		add_action( 'template_redirect', array( self::class, 'template_redirect' ), 5 );
		add_filter( 'the_content', array( self::class, 'content_fallback' ), 20 );
		add_action( 'init', array( self::class, 'register_sitemap' ), 20 );
	}

	/**
	 * URL segment for vehicle pages.
	 */
	public static function base(): string {
		$base = sanitize_title( (string) Settings::get( 'detail_base', 'vehicle' ) );
		return '' !== $base ? $base : 'vehicle';
	}

	/**
	 * Rewrite endpoint on pages, posts and the front page.
	 */
	public static function add_endpoint(): void {
		add_rewrite_endpoint( self::base(), EP_ROOT | EP_PAGES | EP_PERMALINK, self::QUERY_VAR );
	}

	/**
	 * Flush rewrite rules once after the endpoint changed (set by the
	 * migration or when the URL segment is saved).
	 */
	public static function maybe_flush(): void {
		// The flag is kept as an autoloaded option, so checking it costs no query.
		$flag = get_option( 'dinv_flush_rewrite' );
		if ( false === $flag ) {
			add_option( 'dinv_flush_rewrite', '0', '', true );
			return;
		}
		if ( '1' === (string) $flag ) {
			update_option( 'dinv_flush_rewrite', '0', true );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * On the root endpoint (/vehicle/123/) of a site with a static front page,
	 * show the front page.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function front_page_request( array $vars ): array {
		if ( isset( $vars[ self::QUERY_VAR ] ) && count( $vars ) === 1 && 'page' === get_option( 'show_on_front' ) && get_option( 'page_on_front' ) ) {
			$vars['page_id'] = (int) get_option( 'page_on_front' );
		}
		return $vars;
	}

	/**
	 * Raw endpoint value of the current request.
	 */
	private static function requested(): string {
		$value = get_query_var( self::QUERY_VAR, '' );
		if ( '' === $value && isset( $_GET[ self::QUERY_VAR ] ) && is_scalar( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only.
			$value = sanitize_title( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only.
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Whether the current request is a vehicle detail URL.
	 */
	public static function is_detail_request(): bool {
		return ! is_admin() && '' !== self::requested() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/**
	 * Vehicle of the current request.
	 */
	public static function current_vehicle(): ?array {
		if ( false === self::$vehicle ) {
			$id            = (int) self::requested();
			self::$vehicle = $id > 0 ? Repository::get_vehicle( $id ) : null;
		}
		return self::$vehicle;
	}

	/**
	 * 404 for unknown or sold vehicles; redirect outdated slugs; head output.
	 */
	public static function template_redirect(): void {
		if ( ! self::is_detail_request() ) {
			return;
		}

		$vehicle = self::current_vehicle();
		if ( null === $vehicle ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			remove_action( 'template_redirect', 'redirect_canonical' );
			return;
		}

		// With a detail page configured, the endpoint on any other post or page
		// is duplicate content: send it to the detail page.
		$detail_page = (int) Settings::get( 'detail_page', 0 );
		if ( $detail_page > 0 && 'publish' === get_post_status( $detail_page ) && get_queried_object_id() !== $detail_page ) {
			$target = self::url( $vehicle );
			if ( '' !== $target ) {
				wp_safe_redirect( $target, 301 );
				exit;
			}
		}

		// Canonical slug (the title may have changed since the link was shared).
		if ( get_option( 'permalink_structure' ) && self::slug( $vehicle ) !== self::requested() ) {
			$canonical = self::url( $vehicle, (string) get_permalink( get_queried_object_id() ) );
			if ( '' !== $canonical ) {
				wp_safe_redirect( $canonical, 301 );
				exit;
			}
		}

		Assets::enqueue( true );
		self::seo_hooks();
	}

	/**
	 * URL slug of a vehicle: "12345-bmw-x5-xdrive30d".
	 *
	 * @param array $vehicle Vehicle row.
	 */
	public static function slug( array $vehicle ): string {
		$title = sanitize_title( Vehicle::title( $vehicle ) );
		$title = trim( mb_substr( $title, 0, 80 ), '-' );
		return (int) $vehicle['external_id'] . ( '' !== $title ? '-' . $title : '' );
	}

	/**
	 * Base page URL for links built during a REST request.
	 *
	 * @param string $path Same-site relative path of the inventory page.
	 */
	public static function set_context_base( string $path ): void {
		self::$context_base = $path;
	}

	/**
	 * Public detail URL of a vehicle.
	 *
	 * @param array  $vehicle Vehicle row.
	 * @param string $page    Page URL the endpoint is added to ('' = detail page or current page).
	 */
	public static function url( array $vehicle, string $page = '' ): string {
		if ( '' === $page ) {
			$page = self::page_url();
		}
		if ( '' === $page ) {
			return '';
		}

		$page = strtok( $page, '?#' );
		$slug = self::slug( $vehicle );

		if ( ! get_option( 'permalink_structure' ) ) {
			return add_query_arg( self::QUERY_VAR, $slug, $page );
		}

		// Remove an endpoint the page URL already carries (links rendered on a detail page).
		$page = (string) preg_replace( '#/' . preg_quote( self::base(), '#' ) . '/[^/]+/?$#', '/', $page );
		return user_trailingslashit( trailingslashit( $page ) . self::base() . '/' . $slug );
	}

	/**
	 * URL of the page vehicles are shown on.
	 */
	private static function page_url(): string {
		static $cache = array();

		$page_id = (int) Settings::get( 'detail_page', 0 );
		if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
			$key = 'page:' . $page_id;
			if ( ! isset( $cache[ $key ] ) ) {
				$cache[ $key ] = (string) get_permalink( $page_id );
			}
			return $cache[ $key ];
		}

		if ( '' !== self::$context_base ) {
			return home_url( self::$context_base );
		}

		$queried = get_queried_object_id();
		if ( $queried ) {
			$key = 'post:' . $queried;
			if ( ! isset( $cache[ $key ] ) ) {
				$cache[ $key ] = is_front_page() ? home_url( '/' ) : (string) get_permalink( $queried );
			}
			return $cache[ $key ];
		}

		return '';
	}

	/**
	 * URL of the list the visitor came from (the page without the endpoint).
	 */
	public static function list_url(): string {
		$queried = get_queried_object_id();
		if ( ! $queried ) {
			return home_url( '/' );
		}
		return is_front_page() ? home_url( '/' ) : (string) get_permalink( $queried );
	}

	/**
	 * Render the detail view once per request (the first inventory on the page).
	 *
	 * @param array $config Resolved configuration.
	 */
	public static function render_once( array $config ): string {
		if ( self::$rendered ) {
			return '';
		}
		self::$rendered = true;

		$vehicle = self::current_vehicle();
		if ( null === $vehicle ) {
			return '';
		}

		Assets::enqueue( true );
		return self::render( $vehicle, $config );
	}

	/**
	 * Show the vehicle on a configured detail page that has no inventory.
	 *
	 * @param string $content Post content.
	 */
	public static function content_fallback( $content ) {
		if ( self::$rendered || ! is_string( $content ) || ! self::is_detail_request() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		if ( get_the_ID() !== get_queried_object_id() ) {
			return $content;
		}
		return self::render_once( Schema::resolve( array() ) );
	}

	/**
	 * Render a vehicle.
	 *
	 * @param array $vehicle Vehicle row.
	 * @param array $config  Resolved configuration.
	 */
	public static function render( array $vehicle, array $config ): string {
		$data = self::data( $vehicle, $config );

		ob_start();
		/**
		 * Fires before a vehicle detail view.
		 *
		 * @param array $data View model.
		 */
		do_action( 'dinv_before_detail', $data );
		$html = Template::render(
			'single-vehicle.php',
			array(
				'vehicle' => $data,
				'config'  => $config,
				'labels'  => Labels::ui(),
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Templates escape their output.
		/**
		 * Fires after a vehicle detail view.
		 *
		 * @param array $data View model.
		 */
		do_action( 'dinv_after_detail', $data );
		return (string) ob_get_clean();
	}

	/**
	 * Stored detail data (description, specs, equipment, images).
	 *
	 * @param array $vehicle Vehicle row.
	 * @return array{description: string, specs: array, equipment: string[], images: string[]}
	 */
	private static function details( array $vehicle ): array {
		$detail = json_decode( (string) ( $vehicle['detail_json'] ?? '' ), true );
		$detail = is_array( $detail ) ? $detail : array();
		return array(
			'description' => (string) ( $detail['description'] ?? '' ),
			'specs'       => (array) ( $detail['specs'] ?? array() ),
			'equipment'   => array_values( array_filter( array_map( 'strval', (array) ( $detail['equipment'] ?? array() ) ) ) ),
			'images'      => array_values( array_filter( array_map( 'strval', (array) ( $detail['images'] ?? array() ) ) ) ),
		);
	}

	/**
	 * View model of the detail page.
	 *
	 * @param array $vehicle Vehicle row.
	 * @param array $config  Resolved configuration.
	 * @return array<string, mixed>
	 */
	public static function data( array $vehicle, array $config ): array {
		$details  = self::details( $vehicle );
		$currency = Format::currency( (string) $config['currency'] );
		$title    = Vehicle::title( $vehicle );
		$provider = Connection::current()->provider();

		// The card view model gives price, badges and monthly rate.
		$card_config                = $config;
		$card_config['card_fields'] = array_keys( Schema::card_field_labels() );
		$card_config['link_to']     = 'none';
		$card_config['image_count'] = 1;
		$card                       = ( new Renderer( $card_config ) )->vehicle( $vehicle, 0 );

		$urls = $details['images'];
		if ( ! $urls ) {
			$urls = json_decode( (string) ( $vehicle['images_json'] ?? '' ), true );
			$urls = is_array( $urls ) && $urls ? $urls : array_filter( array( (string) ( $vehicle['image_url'] ?? '' ) ) );
		}

		$images = array();
		foreach ( array_values( $urls ) as $position => $url ) {
			$src = $provider->image_url( (string) $url, 1280 );
			if ( '' === $src ) {
				continue;
			}
			$images[] = array(
				'src'    => $src,
				'srcset' => Shortcode::image_srcset( (string) $url ),
				'thumb'  => $provider->image_url( (string) $url, 240 ),
				'full'   => esc_url_raw( (string) $url ),
				/* translators: 1: vehicle title, 2: photo number. */
				'alt'    => 0 === $position ? $title : sprintf( __( '%1$s, photo %2$d', 'dealer-inventory-for-autoscout24' ), $title, $position + 1 ),
			);
		}

		$market_url = $provider->listing_url( $vehicle, I18n::listing_language( $provider ) );

		$data = array(
			'id'          => (int) $vehicle['external_id'],
			'title'       => $title,
			'make'        => (string) $vehicle['make_name'],
			'model'       => (string) $vehicle['model_name'],
			'version'     => (string) $vehicle['version_full_name'],
			'teaser'      => sanitize_text_field( (string) ( $vehicle['teaser'] ?? '' ) ),
			'price'       => $card['price'],
			'price_old'   => $card['price_old'],
			'price_value' => isset( $vehicle['price'] ) && '' !== (string) $vehicle['price'] ? (float) $vehicle['price'] : null,
			'currency'    => $currency,
			'monthly'     => $card['monthly'],
			'badges'      => $card['badges'],
			'key_specs'   => $card['specs'],
			'specs'       => self::spec_rows( $vehicle, $details['specs'], $config ),
			'description' => $details['description'],
			'equipment'   => $details['equipment'],
			'images'      => $images,
			'url'         => self::url( $vehicle ),
			'market_url'  => $market_url,
			'list_url'    => self::list_url(),
			'dealer'      => self::dealer(),
			'raw'         => $vehicle,
		);

		/**
		 * Filters the view model of a vehicle detail page.
		 *
		 * @param array $data    View model.
		 * @param array $vehicle Vehicle row.
		 * @param array $config  Instance configuration.
		 */
		return (array) apply_filters( 'dinv_detail_data', $data, $vehicle, $config );
	}

	/**
	 * Specification rows (label => value) in display order.
	 *
	 * @param array $vehicle Vehicle row.
	 * @param array $specs   Detail specs from the API.
	 * @param array $config  Configuration.
	 * @return array<string, array{label: string, value: string}>
	 */
	private static function spec_rows( array $vehicle, array $specs, array $config ): array {
		$int  = static fn( $value ): ?int => isset( $value ) && '' !== (string) $value && is_numeric( $value ) ? (int) $value : null;
		$rows = array(
			'condition'    => array( __( 'Condition', 'dealer-inventory-for-autoscout24' ), Labels::enum( 'condition', (string) $vehicle['condition_type'] ) ),
			'year'         => array( __( 'First registration', 'dealer-inventory-for-autoscout24' ), Format::registration( (string) ( $vehicle['first_registration_date'] ?? '' ), $vehicle['first_registration_year'] ?? '', (string) $config['date_format'] ) ),
			'mileage'      => array( __( 'Mileage', 'dealer-inventory-for-autoscout24' ), null !== $int( $vehicle['mileage'] ?? null ) ? Format::mileage( (int) $vehicle['mileage'] ) : '' ),
			'body'         => array( __( 'Body type', 'dealer-inventory-for-autoscout24' ), Labels::enum( 'body', (string) $vehicle['body_type'] ) ),
			'fuel'         => array( __( 'Fuel', 'dealer-inventory-for-autoscout24' ), Labels::enum( 'fuel', (string) $vehicle['fuel_type'] ) ),
			'transmission' => array( __( 'Transmission', 'dealer-inventory-for-autoscout24' ), Labels::enum( 'transmission', (string) $vehicle['transmission_type'] ) ),
			'drive'        => array( __( 'Drive', 'dealer-inventory-for-autoscout24' ), Labels::enum( 'drive', (string) $vehicle['drive_type'] ) ),
			'power'        => array( __( 'Power', 'dealer-inventory-for-autoscout24' ), Format::power( $int( $vehicle['horse_power'] ?? null ), $int( $vehicle['kilo_watts'] ?? null ), (string) $config['power_unit'] ) ),
			'capacity'     => array( __( 'Engine capacity', 'dealer-inventory-for-autoscout24' ), isset( $specs['cubicCapacity'] ) ? Format::integer( (int) $specs['cubicCapacity'] ) . ' cm³' : '' ),
			'cylinders'    => array( __( 'Cylinders', 'dealer-inventory-for-autoscout24' ), isset( $specs['cylinders'] ) ? (string) (int) $specs['cylinders'] : '' ),
			'gears'        => array( __( 'Gears', 'dealer-inventory-for-autoscout24' ), isset( $specs['gears'] ) ? (string) (int) $specs['gears'] : '' ),
			'consumption'  => array( __( 'Consumption (combined)', 'dealer-inventory-for-autoscout24' ), ! empty( $vehicle['consumption_combined'] ) ? Format::consumption( (float) $vehicle['consumption_combined'] ) : '' ),
			'co2'          => array( __( 'CO₂ emissions', 'dealer-inventory-for-autoscout24' ), ! empty( $vehicle['co2_emission'] ) ? Format::integer( (int) $vehicle['co2_emission'] ) . ' g/km' : '' ),
			'range'        => array( __( 'Electric range', 'dealer-inventory-for-autoscout24' ), ! empty( $vehicle['range_km'] ) ? Format::integer( (int) $vehicle['range_km'] ) . ' km' : '' ),
			'battery'      => array( __( 'Battery capacity', 'dealer-inventory-for-autoscout24' ), ! empty( $specs['batteryCapacity'] ) ? number_format_i18n( (float) $specs['batteryCapacity'], 1 ) . ' kWh' : '' ),
			'color'        => array( __( 'Exterior color', 'dealer-inventory-for-autoscout24' ), isset( $specs['bodyColor'] ) ? Labels::enum( 'color', (string) $specs['bodyColor'] ) : '' ),
			'interior'     => array( __( 'Interior color', 'dealer-inventory-for-autoscout24' ), isset( $specs['interiorColor'] ) ? Labels::enum( 'color', (string) $specs['interiorColor'] ) : '' ),
			'doors'        => array( __( 'Doors', 'dealer-inventory-for-autoscout24' ), isset( $specs['doors'] ) ? (string) (int) $specs['doors'] : '' ),
			'seats'        => array( __( 'Seats', 'dealer-inventory-for-autoscout24' ), isset( $specs['seats'] ) ? (string) (int) $specs['seats'] : '' ),
			'weight'       => array( __( 'Curb weight', 'dealer-inventory-for-autoscout24' ), isset( $specs['weight'] ) ? Format::integer( (int) $specs['weight'] ) . ' kg' : '' ),
			'inspection'   => array( __( 'Last inspection', 'dealer-inventory-for-autoscout24' ), isset( $specs['lastInspectionDate'] ) ? Format::registration( (string) $specs['lastInspectionDate'], '', (string) $config['date_format'] ) : '' ),
			'warranty'     => array( __( 'Warranty', 'dealer-inventory-for-autoscout24' ), self::warranty_text( $vehicle, $specs ) ),
			'reference'    => array( __( 'Reference', 'dealer-inventory-for-autoscout24' ), sanitize_text_field( (string) ( $vehicle['seller_vehicle_id'] ?? '' ) ) ),
		);

		$out = array();
		foreach ( $rows as $key => $row ) {
			if ( '' !== (string) $row[1] ) {
				$out[ $key ] = array(
					'label' => $row[0],
					'value' => (string) $row[1],
				);
			}
		}

		/**
		 * Filters the specification rows of a vehicle detail page.
		 *
		 * @param array $out     key => {label, value}.
		 * @param array $vehicle Vehicle row.
		 * @param array $specs   Detail specs from the API.
		 */
		return (array) apply_filters( 'dinv_detail_specs', $out, $vehicle, $specs );
	}

	/**
	 * Readable warranty text.
	 *
	 * @param array $vehicle Vehicle row.
	 * @param array $specs   Detail specs.
	 */
	private static function warranty_text( array $vehicle, array $specs ): string {
		$parts = array();
		if ( ! empty( $specs['warrantyMonths'] ) ) {
			/* translators: %d: number of months. */
			$parts[] = sprintf( _n( '%d month', '%d months', (int) $specs['warrantyMonths'], 'dealer-inventory-for-autoscout24' ), (int) $specs['warrantyMonths'] );
		}
		if ( ! empty( $specs['warrantyKm'] ) ) {
			$parts[] = Format::mileage( (int) $specs['warrantyKm'] );
		}
		if ( ! empty( $specs['warrantyText'] ) ) {
			$parts[] = (string) $specs['warrantyText'];
		}
		if ( ! $parts && ! empty( $vehicle['has_warranty'] ) ) {
			$parts[] = __( 'Yes', 'dealer-inventory-for-autoscout24' );
		}
		return implode( ' · ', $parts );
	}

	/**
	 * Dealer profile from the last sync.
	 *
	 * @return array{name: string, address: string, phone: string, url: string}
	 */
	private static function dealer(): array {
		$profile = (array) get_option( 'dinv_seller_profile', array() );
		$address = trim( implode( ', ', array_filter( array( (string) ( $profile['address'] ?? '' ), trim( (string) ( $profile['zipCode'] ?? '' ) . ' ' . (string) ( $profile['city'] ?? '' ) ) ) ) ) );
		return array(
			'name'    => (string) ( $profile['name'] ?? '' ),
			'address' => $address,
			'phone'   => (string) ( $profile['phoneNumber'] ?? '' ),
			'url'     => (string) Settings::get( 'dealer_url', '' ),
		);
	}

	/**
	 * Title, description, canonical, Open Graph and JSON-LD for the vehicle.
	 */
	private static function seo_hooks(): void {
		$vehicle = self::current_vehicle();
		if ( null === $vehicle ) {
			return;
		}

		$data        = self::data( $vehicle, Schema::resolve( array() ) );
		$title       = $data['title'] . ( '' !== $data['price'] ? ' – ' . $data['price'] : '' );
		$description = self::description( $data );
		$canonical   = $data['url'];
		$image       = $data['images'][0]['src'] ?? '';

		$set_title = static fn() => $title . ' | ' . get_bloginfo( 'name' );
		$set_desc  = static fn() => $description;
		$set_url   = static fn() => $canonical;

		add_filter( 'pre_get_document_title', $set_title, 20 );
		add_filter( 'get_canonical_url', $set_url, 20 );

		// Yoast SEO.
		add_filter( 'wpseo_title', $set_title, 20 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast SEO.
		add_filter( 'wpseo_metadesc', $set_desc, 20 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast SEO.
		add_filter( 'wpseo_canonical', $set_url, 20 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast SEO.
		add_filter( 'wpseo_opengraph_title', $set_title, 20 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast SEO.
		add_filter( 'wpseo_opengraph_desc', $set_desc, 20 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast SEO.
		add_filter( 'wpseo_opengraph_url', $set_url, 20 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast SEO.
		if ( '' !== $image ) {
			add_filter( 'wpseo_opengraph_image', static fn() => $image, 20 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Yoast SEO.
		}

		// Rank Math.
		add_filter( 'rank_math/frontend/title', $set_title, 20 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Rank Math.
		add_filter( 'rank_math/frontend/description', $set_desc, 20 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Rank Math.
		add_filter( 'rank_math/frontend/canonical', $set_url, 20 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Rank Math.

		$seo_plugin = defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' );

		add_action(
			'wp_head',
			static function () use ( $data, $title, $description, $canonical, $image, $seo_plugin ): void {
				if ( ! $seo_plugin ) {
					printf( '<meta name="description" content="%s">' . "\n", esc_attr( $description ) );
					printf( '<meta property="og:type" content="product">' . "\n" );
					printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( $title ) );
					printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $description ) );
					printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $canonical ) );
					if ( '' !== $image ) {
						printf( '<meta property="og:image" content="%s">' . "\n", esc_url( $image ) );
					}
				}
				echo '<script type="application/ld+json">' . wp_json_encode( self::json_ld( $data ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with HTML characters hex-encoded.
			},
			5
		);
	}

	/**
	 * Meta description (max. 160 characters).
	 *
	 * @param array $data View model.
	 */
	private static function description( array $data ): string {
		$parts = array_merge( array( $data['title'] ), array_values( $data['key_specs'] ) );
		if ( '' !== $data['price'] ) {
			$parts[] = $data['price'];
		}
		$text = implode( ' · ', array_filter( $parts ) );
		if ( '' !== $data['teaser'] ) {
			$text .= '. ' . $data['teaser'];
		}
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
		return mb_strlen( $text ) > 160 ? rtrim( mb_substr( $text, 0, 157 ) ) . '…' : $text;
	}

	/**
	 * Schema.org Car structured data.
	 *
	 * @param array $data View model.
	 * @return array<string, mixed>
	 */
	public static function json_ld( array $data ): array {
		$row  = $data['raw'];
		$json = array(
			'@context'      => 'https://schema.org',
			'@type'         => 'Car',
			'name'          => $data['title'],
			'url'           => $data['url'],
			'brand'         => array(
				'@type' => 'Brand',
				'name'  => $data['make'],
			),
			'model'         => $data['model'],
			'image'         => array_slice( array_column( $data['images'], 'src' ), 0, 10 ),
			'itemCondition' => 'new' === ( $row['condition_type'] ?? '' ) ? 'https://schema.org/NewCondition' : 'https://schema.org/UsedCondition',
		);

		if ( '' !== $data['version'] ) {
			$json['vehicleConfiguration'] = $data['version'];
		}
		if ( '' !== $data['teaser'] || '' !== $data['description'] ) {
			$json['description'] = mb_substr( trim( wp_strip_all_tags( '' !== $data['description'] ? $data['description'] : $data['teaser'] ) ), 0, 5000 );
		}
		if ( ! empty( $row['mileage'] ) ) {
			$json['mileageFromOdometer'] = array(
				'@type'    => 'QuantitativeValue',
				'value'    => (int) $row['mileage'],
				'unitCode' => 'KMT',
			);
		}
		if ( ! empty( $row['first_registration_date'] ) ) {
			$json['dateVehicleFirstRegistered'] = (string) $row['first_registration_date'];
		} elseif ( ! empty( $row['first_registration_year'] ) ) {
			$json['dateVehicleFirstRegistered'] = (string) (int) $row['first_registration_year'];
		}
		if ( '' !== (string) $row['fuel_type'] ) {
			$json['fuelType'] = Labels::enum( 'fuel', (string) $row['fuel_type'] );
		}
		if ( '' !== (string) $row['transmission_type'] ) {
			$json['vehicleTransmission'] = Labels::enum( 'transmission', (string) $row['transmission_type'] );
		}
		if ( '' !== (string) $row['body_type'] ) {
			$json['bodyType'] = Labels::enum( 'body', (string) $row['body_type'] );
		}
		if ( ! empty( $row['horse_power'] ) ) {
			$json['vehicleEngine'] = array(
				'@type'       => 'EngineSpecification',
				'enginePower' => array(
					'@type'    => 'QuantitativeValue',
					'value'    => (int) $row['horse_power'],
					'unitCode' => 'BHP',
				),
			);
		}
		if ( isset( $data['specs']['color'] ) ) {
			$json['color'] = $data['specs']['color']['value'];
		}
		if ( null !== $data['price_value'] ) {
			$offer = array(
				'@type'         => 'Offer',
				'price'         => $data['price_value'],
				'priceCurrency' => $data['currency'],
				'availability'  => 'https://schema.org/InStock',
				'url'           => $data['url'],
			);
			if ( '' !== $data['dealer']['name'] ) {
				$offer['seller'] = array(
					'@type' => 'AutoDealer',
					'name'  => $data['dealer']['name'],
				);
				if ( '' !== $data['dealer']['address'] ) {
					$offer['seller']['address'] = $data['dealer']['address'];
				}
				if ( '' !== $data['dealer']['phone'] ) {
					$offer['seller']['telephone'] = $data['dealer']['phone'];
				}
			}
			$json['offers'] = $offer;
		}

		/**
		 * Filters the schema.org Car data of a vehicle detail page.
		 *
		 * @param array $json JSON-LD data.
		 * @param array $data View model.
		 */
		return (array) apply_filters( 'dinv_vehicle_json_ld', $json, $data );
	}

	/**
	 * List vehicle pages in the core XML sitemap (needs a detail page).
	 */
	public static function register_sitemap(): void {
		if ( ! function_exists( 'wp_register_sitemap_provider' ) || (int) Settings::get( 'detail_page', 0 ) <= 0 || 'local' !== Settings::get( 'link_to', 'autoscout' ) ) {
			return;
		}
		wp_register_sitemap_provider( 'dinvvehicles', new Sitemap() );
	}
}
