<?php
/**
 * [dealer_inventory] shortcode.
 *
 * @package DealerInventory
 */

namespace DealerInventory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses an inventory instance, runs the local search and renders the template.
 */
final class Shortcode {

	public const TAG = 'dealer_inventory';

	/**
	 * Request parameter (without the "dinv_" prefix) => normalized filter key.
	 */
	private const REQUEST_FILTERS = array(
		'category'     => 'vehicle_category',
		'make'         => 'make',
		'model'        => 'model',
		'version'      => 'version',
		'fuel'         => 'fuel',
		'transmission' => 'transmission',
		'body'         => 'body_type',
		'drive'        => 'drive_type',
		'condition'    => 'condition',
		'warranty'     => 'has_warranty',
		'price_from'   => 'price_from',
		'price_to'     => 'price_to',
		'year_from'    => 'year_from',
		'year_to'      => 'year_to',
		'mileage_from' => 'mileage_from',
		'mileage_to'   => 'mileage_to',
		'power_from'   => 'power_from',
		'power_to'     => 'power_to',
	);

	/**
	 * Groups whose values the browser may send back to the REST endpoint.
	 */
	public const CLIENT_GROUPS = array( 'display', 'filters', 'card', 'format', 'preset' );

	/**
	 * Render the shortcode (also used by the block and the Elementor widget).
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public static function render( $atts ): string {
		$atts = is_array( $atts ) ? array_change_key_case( $atts, CASE_LOWER ) : array();

		// On a vehicle detail URL the first inventory of the page shows the vehicle.
		if ( Detail::is_detail_request() ) {
			return Detail::render_once( Schema::resolve( $atts ) );
		}

		return self::render_inventory( Schema::resolve( $atts ) );
	}

	/**
	 * Render an inventory for a resolved configuration.
	 *
	 * @param array $config Resolved configuration.
	 */
	public static function render_inventory( array $config ): string {
		$interactive = Assets::is_interactive( $config );

		Assets::enqueue( $interactive );

		$inventory = new Inventory(
			$config,
			$_GET, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public, read-only filtering.
			(string) $config['instance'],
			$config['url_state'] ? self::current_base_url() : '',
			(bool) $config['url_state']
		);

		$show_make = $inventory->shows_make_control();

		$vars = array(
			'inventory'      => $inventory,
			'config'         => $inventory->config,
			'instance'       => $inventory->instance,
			'results'        => $inventory->results,
			'filters'        => $inventory->filters,
			'preset_filters' => $inventory->preset_filters,
			'sort'           => $inventory->sort,
			'view'           => $inventory->view,
			'options'        => $config['show_filters'] ? Repository::filter_options() : array(),
			'tree'           => $show_make ? Repository::make_tree() : array(),
			'facets'         => $show_make ? $inventory->facets() : array(),
			'choice_counts'  => $config['show_filters'] ? $inventory->choice_counts() : array(),
			'labels'         => Labels::ui(),
			'renderer'       => $inventory->renderer,
			'preset_params'  => self::request_params( $inventory->preset_filters ),
			'client_config'  => self::client_config( $config ),
			'dealer_url'     => (string) Settings::get( 'dealer_url', '' ),
			'interactive'    => $interactive,
			'uid'            => 'dinv-' . ( '' !== $inventory->instance ? $inventory->instance : wp_unique_id() ),
		);

		ob_start();
		/**
		 * Fires before an inventory is rendered.
		 *
		 * @param array $config Instance configuration.
		 */
		do_action( 'dinv_before_inventory', $inventory->config );
		echo Template::render( 'inventory.php', $vars ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Templates escape their output.
		/**
		 * Fires after an inventory is rendered.
		 *
		 * @param array $config Instance configuration.
		 */
		do_action( 'dinv_after_inventory', $inventory->config );
		return (string) ob_get_clean();
	}

	/**
	 * Normalized filters from request parameters (dinv_make=…, or
	 * dinv_{instance}_make=… for named instances).
	 *
	 * @param array  $source   Request parameters.
	 * @param string $instance Instance name.
	 * @return array<string, mixed>
	 */
	public static function filters_from_request( array $source, string $instance = '' ): array {
		$out = array();

		foreach ( self::REQUEST_FILTERS as $param => $target ) {
			$key = self::request_key( $param, $instance );
			if ( ! isset( $source[ $key ] ) || is_array( $source[ $key ] ) || '' === (string) $source[ $key ] ) {
				continue;
			}

			$value = wp_unslash( (string) $source[ $key ] );

			if ( str_ends_with( $target, '_from' ) || str_ends_with( $target, '_to' ) ) {
				if ( is_numeric( $value ) && (float) $value >= 0 ) {
					$out[ $target ] = (float) $value;
				}
			} elseif ( 'has_warranty' === $target ) {
				if ( in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true ) ) {
					$out[ $target ] = 1;
				}
			} elseif ( 'version' === $target ) {
				$out[ $target ] = mb_substr( sanitize_text_field( $value ), 0, 80 );
			} else {
				$out[ $target ] = sanitize_key( $value );
			}
		}

		return $out;
	}

	/**
	 * Request parameter name for an instance.
	 *
	 * @param string $param    Parameter without prefix, e.g. "make".
	 * @param string $instance Instance name.
	 */
	public static function request_key( string $param, string $instance = '' ): string {
		$instance = sanitize_key( $instance );
		return '' === $instance ? 'dinv_' . $param : 'dinv_' . $instance . '_' . $param;
	}

	/**
	 * Instance-scoped URL parameters for filters and a non-default sort.
	 *
	 * @param array  $filters      Normalized filters.
	 * @param string $sort         Active sort.
	 * @param string $default_sort Instance default sort.
	 * @param string $instance     Instance name.
	 * @return array<string, string>
	 */
	public static function url_params( array $filters, string $sort, string $default_sort, string $instance ): array {
		$params = array();
		foreach ( self::request_params( $filters ) as $key => $value ) {
			$params[ self::request_key( substr( $key, 5 ), $instance ) ] = $value;
		}
		if ( $sort !== $default_sort ) {
			$params[ self::request_key( 'sort', $instance ) ] = $sort;
		}
		return $params;
	}

	/**
	 * Current URL path and query without this plugin's parameters.
	 */
	public static function current_base_url(): string {
		$uri   = wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_base_url().
		$query = array();
		wp_parse_str( (string) ( $uri['query'] ?? '' ), $query );
		$query = array_filter( $query, static fn( $key ) => ! str_starts_with( (string) $key, 'dinv_' ), ARRAY_FILTER_USE_KEY );

		return self::sanitize_base_url( (string) ( $uri['path'] ?? '/' ) . ( $query ? '?' . http_build_query( $query ) : '' ) );
	}

	/**
	 * Accept only same-site relative paths ("/inventory/?lang=fr").
	 *
	 * @param string $url Candidate.
	 */
	public static function sanitize_base_url( string $url ): string {
		if ( '' === $url || '/' !== $url[0] || str_starts_with( $url, '//' ) || str_contains( $url, '\\' ) ) {
			return '';
		}
		$origin = 'https://example.invalid';
		$clean  = esc_url_raw( $origin . $url );
		return str_starts_with( $clean, $origin . '/' ) ? substr( $clean, strlen( $origin ) ) : '';
	}

	/**
	 * Sort key if allowed, otherwise the fallback.
	 *
	 * @param string $sort     Requested sort.
	 * @param string $fallback Fallback sort.
	 */
	public static function valid_sort( string $sort, string $fallback = 'newest' ): string {
		return in_array( $sort, Repository::sort_keys(), true ) ? $sort : $fallback;
	}

	/**
	 * Public URL of a vehicle for a link target.
	 *
	 * @param array  $vehicle Vehicle row.
	 * @param string $link_to Link target.
	 */
	public static function vehicle_link( array $vehicle, string $link_to = 'autoscout' ): string {
		switch ( $link_to ) {
			case 'none':
				$url = '';
				break;
			case 'local':
				$url = Detail::url( $vehicle );
				break;
			default:
				$provider = Connection::current()->provider();
				$url      = $provider->listing_url( $vehicle, I18n::listing_language( $provider ) );
		}

		/**
		 * Filters the URL a vehicle links to.
		 *
		 * @param string $url     URL ('' for no link).
		 * @param array  $vehicle Vehicle row.
		 * @param string $link_to Link target setting.
		 */
		return (string) apply_filters( 'dinv_vehicle_url', $url, $vehicle, $link_to );
	}

	/**
	 * Resized image URL from the provider CDN.
	 *
	 * @param string $url   Original URL.
	 * @param int    $width Width.
	 */
	public static function image_url( string $url, int $width ): string {
		return Connection::current()->provider()->image_url( $url, $width );
	}

	/**
	 * Srcset for CDN images; empty when the host cannot resize.
	 *
	 * @param string $url Original URL.
	 */
	public static function image_srcset( string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		$provider = Connection::current()->provider();
		$items    = array();
		foreach ( $provider->image_widths() as $width ) {
			$resized = $provider->image_url( $url, $width );
			if ( esc_url_raw( $url ) === $resized ) {
				return '';
			}
			$items[] = $resized . ' ' . $width . 'w';
		}
		return implode( ', ', $items );
	}

	/**
	 * Settings of this instance that differ from the site defaults.
	 *
	 * Sent back by the browser with every REST request so refreshed results
	 * render exactly like the server-rendered page.
	 *
	 * @param array $config Resolved configuration.
	 * @return array<string, mixed>
	 */
	public static function client_config( array $config ): array {
		$out = array();
		foreach ( Schema::instance_fields() as $attr => $field ) {
			if ( ! in_array( $field['group'], self::CLIENT_GROUPS, true ) ) {
				continue;
			}
			$global = Settings::get( $field['key'], $field['default'] );
			if ( $config[ $field['key'] ] !== $global ) {
				$out[ $attr ] = Schema::to_attr( $config[ $field['key'] ] );
			}
		}
		return $out;
	}

	/**
	 * Preset filters from shortcode attributes and the compact query attribute.
	 *
	 * @param array $config Resolved configuration.
	 * @return array<string, mixed>
	 */
	public static function filters_from_config( array $config ): array {
		$source = array();
		foreach ( array_keys( self::REQUEST_FILTERS ) as $param ) {
			if ( isset( $config[ $param ] ) && '' !== $config[ $param ] && false !== $config[ $param ] ) {
				$source[ 'dinv_' . $param ] = true === $config[ $param ] ? '1' : (string) $config[ $param ];
			}
		}

		// Compact syntax: query="make=bmw&body=suv&price_to=50000".
		if ( '' !== (string) $config['query'] ) {
			$parsed = array();
			wp_parse_str( html_entity_decode( (string) $config['query'], ENT_QUOTES, 'UTF-8' ), $parsed );
			foreach ( $parsed as $key => $value ) {
				$key = preg_replace( '/^dinv_/', '', sanitize_key( (string) $key ) );
				if ( ! is_array( $value ) && isset( self::REQUEST_FILTERS[ $key ] ) ) {
					$source[ 'dinv_' . $key ] = (string) $value;
				}
			}
		}

		return self::filters_from_request( $source );
	}

	/**
	 * Request parameters (unprefixed instance) for normalized filters.
	 *
	 * @param array $filters Normalized filters.
	 * @return array<string, string>
	 */
	public static function request_params( array $filters ): array {
		$reverse = array_flip( self::REQUEST_FILTERS );
		$params  = array();
		foreach ( $filters as $key => $value ) {
			if ( isset( $reverse[ $key ] ) && '' !== (string) $value ) {
				$params[ 'dinv_' . $reverse[ $key ] ] = (string) $value;
			}
		}
		return $params;
	}
}
