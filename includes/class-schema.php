<?php
/**
 * Settings schema: the single source of truth for every option.
 *
 * @package DealerInventory
 */

namespace DealerInventory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes every setting once: type, default, allowed values, scope, label.
 *
 * Stored settings, admin forms, shortcode attributes, the shortcode builder,
 * the block and the Elementor widget all read from here, so a default or an
 * allowed value is never defined twice.
 *
 * Scopes:
 * - "global":   site-wide setting only (credentials, sync, design tokens).
 * - "instance": per-inventory only (filter presets, instance name).
 * - "both":     site-wide default that each inventory instance may override.
 *
 * Resolution order for an instance: instance attribute > global setting > default.
 *
 * Types: bool, int, number, enum, color, url, time, key, filter_value, text,
 * secret, page, multi (unordered subset of options) and list (ordered subset).
 * Multi and list values are arrays; as attributes they are comma separated,
 * and "none" stands for an empty selection.
 */
final class Schema {

	public const SCOPE_GLOBAL   = 'global';
	public const SCOPE_INSTANCE = 'instance';
	public const SCOPE_BOTH     = 'both';

	/**
	 * Field definitions cached per locale.
	 *
	 * @var array<string, array<string, array>>
	 */
	private static array $cache = array();

	/**
	 * All field definitions keyed by setting name.
	 *
	 * @return array<string, array>
	 */
	public static function fields(): array {
		$locale = determine_locale();
		if ( isset( self::$cache[ $locale ] ) ) {
			return self::$cache[ $locale ];
		}

		$fields = array_merge(
			self::connection_fields(),
			self::sync_fields(),
			self::display_fields(),
			self::filter_fields(),
			self::card_fields(),
			self::format_fields(),
			self::detail_fields(),
			self::design_fields(),
			self::preset_fields()
		);

		foreach ( $fields as $key => &$field ) {
			$field += array(
				'key'     => $key,
				'scope'   => self::SCOPE_GLOBAL,
				'section' => '',
				'help'    => '',
				'attr'    => $key,
			);
		}
		unset( $field );

		/**
		 * Filters the settings schema.
		 *
		 * @param array<string, array> $fields Field definitions.
		 */
		self::$cache[ $locale ] = (array) apply_filters( 'dinv_schema_fields', $fields );

		return self::$cache[ $locale ];
	}

	/**
	 * One field definition.
	 *
	 * @param string $key Setting name.
	 */
	public static function field( string $key ): ?array {
		return self::fields()[ $key ] ?? null;
	}

	/**
	 * Fields of one group.
	 *
	 * @param string $group Group name.
	 * @return array<string, array>
	 */
	public static function group( string $group ): array {
		return array_filter( self::fields(), static fn( array $field ) => $group === $field['group'] );
	}

	/**
	 * Defaults of every setting that is stored site-wide.
	 *
	 * @return array<string, mixed>
	 */
	public static function global_defaults(): array {
		$defaults = array();
		foreach ( self::fields() as $key => $field ) {
			if ( self::SCOPE_INSTANCE !== $field['scope'] ) {
				$defaults[ $key ] = $field['default'];
			}
		}
		return $defaults;
	}

	/**
	 * Validate and normalize a value. Invalid input returns the default.
	 *
	 * @param string $key   Setting name.
	 * @param mixed  $value Raw value (already unslashed).
	 * @return mixed
	 */
	public static function sanitize( string $key, $value ) {
		$field = self::field( $key );
		if ( null === $field ) {
			return null;
		}

		$default = $field['default'];

		switch ( $field['type'] ) {
			case 'bool':
				if ( is_bool( $value ) ) {
					return $value;
				}
				$value = strtolower( trim( (string) $value ) );
				if ( in_array( $value, array( '1', 'true', 'yes', 'on' ), true ) ) {
					return true;
				}
				if ( in_array( $value, array( '0', 'false', 'no', 'off', '' ), true ) ) {
					return false;
				}
				return (bool) $default;

			case 'int':
			case 'page':
				if ( ! is_numeric( $value ) ) {
					return $default;
				}
				$value = (int) $value;
				if ( ( isset( $field['min'] ) && $value < $field['min'] ) || ( isset( $field['max'] ) && $value > $field['max'] ) ) {
					return $default;
				}
				return $value;

			case 'number':
				if ( '' === $value || null === $value ) {
					return '';
				}
				return is_numeric( $value ) && (float) $value >= 0 ? (float) $value : '';

			case 'enum':
				// Exact match first: some options are case-sensitive or contain "/" or "." (currencies, date formats).
				if ( is_scalar( $value ) ) {
					$raw = trim( (string) $value );
					foreach ( array_keys( $field['options'] ) as $option ) {
						if ( (string) $option === $raw ) {
							return $option;
						}
					}
				}
				$value = is_int( $value ) ? $value : sanitize_key( is_scalar( $value ) ? (string) $value : '' );
				foreach ( array_keys( $field['options'] ) as $option ) {
					if ( (string) $option === (string) $value ) {
						return $option;
					}
				}
				return $default;

			case 'multi':
			case 'list':
				$items = is_array( $value ) ? $value : explode( ',', (string) $value );
				$items = array_values( array_unique( array_filter( array_map( static fn( $item ) => sanitize_key( (string) $item ), $items ) ) ) );
				if ( array( 'none' ) === $items ) {
					return array();
				}
				$allowed = array_map( 'strval', array_keys( $field['options'] ) );
				$items   = array_values( array_intersect( $items, $allowed ) );
				if ( 'multi' === $field['type'] ) {
					// Unordered: keep the option order.
					$items = array_values( array_intersect( $allowed, $items ) );
				}
				return $items;

			case 'color':
				$color = sanitize_hex_color( (string) $value );
				return $color ? strtoupper( $color ) : $default;

			case 'url':
				$url = esc_url_raw( trim( (string) $value ) );
				if ( '' !== $url && isset( $field['validate'] ) && is_callable( $field['validate'] ) && ! call_user_func( $field['validate'], $url ) ) {
					return $default;
				}
				return $url;

			case 'time':
				$value = sanitize_text_field( (string) $value );
				return preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : $default;

			case 'key':
			case 'filter_value':
				$value = sanitize_key( (string) $value );
				return isset( $field['max_length'] ) ? substr( $value, 0, (int) $field['max_length'] ) : $value;

			case 'text':
			case 'secret':
			default:
				$value = sanitize_text_field( (string) $value );
				if ( isset( $field['pattern'] ) && '' !== $value && ! preg_match( $field['pattern'], $value ) ) {
					return $default;
				}
				if ( isset( $field['max_length'] ) ) {
					$value = mb_substr( $value, 0, (int) $field['max_length'] );
				}
				return $value;
		}
	}

	/**
	 * Resolve the configuration of one inventory instance.
	 *
	 * Instance attribute > global setting > plugin default. Empty attribute
	 * values ("" or null, e.g. "Default" in the block or widget) inherit.
	 *
	 * @param array $atts Raw shortcode / block / widget attributes.
	 * @return array<string, mixed>
	 */
	public static function resolve( array $atts ): array {
		$config = array();

		foreach ( self::fields() as $key => $field ) {
			if ( self::SCOPE_GLOBAL === $field['scope'] ) {
				continue;
			}

			$attr = $field['attr'];
			if ( array_key_exists( $attr, $atts ) && null !== $atts[ $attr ] && '' !== $atts[ $attr ] && array() !== $atts[ $attr ] ) {
				$config[ $key ] = self::sanitize( $key, $atts[ $attr ] );
				continue;
			}

			if ( self::SCOPE_BOTH === $field['scope'] ) {
				$config[ $key ] = Settings::get( $key, $field['default'] );
				continue;
			}

			$config[ $key ] = $field['default'];
		}

		/**
		 * Filters the resolved configuration of an inventory instance.
		 *
		 * @param array $config Resolved configuration.
		 * @param array $atts   Raw attributes.
		 */
		return (array) apply_filters( 'dinv_inventory_config', $config, $atts );
	}

	/**
	 * Fields an instance may set, keyed by attribute name.
	 *
	 * @return array<string, array>
	 */
	public static function instance_fields(): array {
		$out = array();
		foreach ( self::fields() as $field ) {
			if ( self::SCOPE_GLOBAL !== $field['scope'] ) {
				$out[ $field['attr'] ] = $field;
			}
		}
		return $out;
	}

	/**
	 * Value as a shortcode attribute string.
	 *
	 * @param mixed $value Value.
	 */
	public static function to_attr( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		if ( is_array( $value ) ) {
			return $value ? implode( ',', $value ) : 'none';
		}
		return (string) $value;
	}

	/**
	 * Labels of the instance setting groups (admin screens, block panels,
	 * Elementor sections and builder sections).
	 *
	 * @return array<string, string>
	 */
	public static function instance_groups(): array {
		return array(
			'display' => __( 'Layout and results', 'dealer-inventory-for-autoscout24' ),
			'filters' => __( 'Filters', 'dealer-inventory-for-autoscout24' ),
			'card'    => __( 'Vehicle cards', 'dealer-inventory-for-autoscout24' ),
			'format'  => __( 'Units', 'dealer-inventory-for-autoscout24' ),
			'preset'  => __( 'Pre-filter the vehicles', 'dealer-inventory-for-autoscout24' ),
		);
	}

	/**
	 * Instance fields for editors (block, Elementor widget, shortcode builder).
	 *
	 * Each field carries its current site-wide value as an attribute string,
	 * so editors can show "Default (…)" and only store real overrides.
	 * Pre-filter fields get the values that exist in the local inventory.
	 *
	 * @return array{groups: array<string, string>, fields: array[]}
	 */
	public static function editor_schema(): array {
		$choices = array();
		$options = Repository::filter_options();
		foreach ( array(
			'category'     => array( 'categories', 'category' ),
			'fuel'         => array( 'fuels', 'fuel' ),
			'transmission' => array( 'transmissions', 'transmission' ),
			'body'         => array( 'body_types', 'body' ),
			'drive'        => array( 'drive_types', 'drive' ),
			'condition'    => array( 'conditions', 'condition' ),
		) as $attr => $source ) {
			foreach ( (array) ( $options[ $source[0] ] ?? array() ) as $row ) {
				$choices[ $attr ][ (string) $row['value'] ] = Labels::enum( $source[1], (string) $row['value'] );
			}
		}
		foreach ( (array) ( $options['makes'] ?? array() ) as $row ) {
			$choices['make'][ (string) $row['value'] ] = (string) $row['label'];
		}

		$fields = array();
		foreach ( self::instance_fields() as $attr => $field ) {
			$entry = array(
				'attr'    => $attr,
				'group'   => $field['group'],
				'section' => (string) $field['section'],
				'type'    => $field['type'],
				'label'   => (string) ( $field['label'] ?? $attr ),
				'help'    => (string) $field['help'],
				'global'  => self::SCOPE_BOTH === $field['scope'] ? self::to_attr( Settings::get( $field['key'], $field['default'] ) ) : '',
			);
			if ( isset( $field['options'] ) ) {
				$entry['options'] = array();
				foreach ( $field['options'] as $value => $label ) {
					$entry['options'][] = array(
						'value' => (string) $value,
						'label' => (string) $label,
					);
				}
			}
			if ( 'filter_value' === $field['type'] && isset( $choices[ $attr ] ) ) {
				$entry['options'] = array();
				foreach ( $choices[ $attr ] as $value => $label ) {
					$entry['options'][] = array(
						'value' => (string) $value,
						'label' => $label,
					);
				}
			}
			foreach ( array( 'min', 'max' ) as $limit ) {
				if ( isset( $field[ $limit ] ) ) {
					$entry[ $limit ] = $field[ $limit ];
				}
			}
			$fields[] = $entry;
		}

		return array(
			'groups' => self::instance_groups(),
			'fields' => $fields,
		);
	}

	/**
	 * Reset the per-request cache (tests, locale switches).
	 */
	public static function reset(): void {
		self::$cache = array();
	}

	/**
	 * Sort options with labels.
	 *
	 * @return array<string, string>
	 */
	public static function sort_options(): array {
		return array(
			'newest'          => __( 'Newest listings first', 'dealer-inventory-for-autoscout24' ),
			'oldest'          => __( 'Oldest listings first', 'dealer-inventory-for-autoscout24' ),
			'price_asc'       => __( 'Price: low to high', 'dealer-inventory-for-autoscout24' ),
			'price_desc'      => __( 'Price: high to low', 'dealer-inventory-for-autoscout24' ),
			'mileage_asc'     => __( 'Mileage: low to high', 'dealer-inventory-for-autoscout24' ),
			'mileage_desc'    => __( 'Mileage: high to low', 'dealer-inventory-for-autoscout24' ),
			'year_desc'       => __( 'Year: newest first', 'dealer-inventory-for-autoscout24' ),
			'year_asc'        => __( 'Year: oldest first', 'dealer-inventory-for-autoscout24' ),
			'power_desc'      => __( 'Power: highest first', 'dealer-inventory-for-autoscout24' ),
			'make_model_asc'  => __( 'Make: A to Z', 'dealer-inventory-for-autoscout24' ),
			'make_model_desc' => __( 'Make: Z to A', 'dealer-inventory-for-autoscout24' ),
		);
	}

	/**
	 * Visitor filters, in default display order.
	 *
	 * "make" is the make/model control; price, year, mileage and power are
	 * ranges (from / to).
	 *
	 * @return array<string, string> Filter key => label.
	 */
	public static function filter_labels(): array {
		return array(
			'make'         => __( 'Make and model', 'dealer-inventory-for-autoscout24' ),
			'price'        => __( 'Price', 'dealer-inventory-for-autoscout24' ),
			'year'         => __( 'First registration', 'dealer-inventory-for-autoscout24' ),
			'mileage'      => __( 'Mileage', 'dealer-inventory-for-autoscout24' ),
			'fuel'         => __( 'Fuel', 'dealer-inventory-for-autoscout24' ),
			'body'         => __( 'Body type', 'dealer-inventory-for-autoscout24' ),
			'transmission' => __( 'Transmission', 'dealer-inventory-for-autoscout24' ),
			'drive'        => __( 'Drive', 'dealer-inventory-for-autoscout24' ),
			'power'        => __( 'Power', 'dealer-inventory-for-autoscout24' ),
			'condition'    => __( 'Condition', 'dealer-inventory-for-autoscout24' ),
			'category'     => __( 'Vehicle type', 'dealer-inventory-for-autoscout24' ),
			'version'      => __( 'Keyword', 'dealer-inventory-for-autoscout24' ),
			'warranty'     => __( 'With warranty', 'dealer-inventory-for-autoscout24' ),
		);
	}

	/**
	 * Parts of a vehicle card that can be shown or hidden.
	 *
	 * @return array<string, string>
	 */
	public static function card_field_labels(): array {
		return array(
			'image'        => __( 'Image', 'dealer-inventory-for-autoscout24' ),
			'title'        => __( 'Make and model', 'dealer-inventory-for-autoscout24' ),
			'version'      => __( 'Version', 'dealer-inventory-for-autoscout24' ),
			'teaser'       => __( 'Teaser text', 'dealer-inventory-for-autoscout24' ),
			'price'        => __( 'Price', 'dealer-inventory-for-autoscout24' ),
			'monthly_rate' => __( 'Monthly rate', 'dealer-inventory-for-autoscout24' ),
			'year'         => __( 'First registration', 'dealer-inventory-for-autoscout24' ),
			'mileage'      => __( 'Mileage', 'dealer-inventory-for-autoscout24' ),
			'fuel'         => __( 'Fuel', 'dealer-inventory-for-autoscout24' ),
			'transmission' => __( 'Transmission', 'dealer-inventory-for-autoscout24' ),
			'power'        => __( 'Power', 'dealer-inventory-for-autoscout24' ),
			'drive'        => __( 'Drive', 'dealer-inventory-for-autoscout24' ),
			'badges'       => __( 'Badges', 'dealer-inventory-for-autoscout24' ),
			'button'       => __( 'Button', 'dealer-inventory-for-autoscout24' ),
		);
	}

	/**
	 * Connection fields.
	 *
	 * @return array<string, array>
	 */
	private static function connection_fields(): array {
		$providers = array();
		foreach ( Providers::all() as $id => $provider ) {
			$providers[ $id ] = $provider->label();
		}

		$languages = array( 'auto' => __( 'Site language', 'dealer-inventory-for-autoscout24' ) );
		foreach ( array(
			'de' => __( 'German', 'dealer-inventory-for-autoscout24' ),
			'fr' => __( 'French', 'dealer-inventory-for-autoscout24' ),
			'it' => __( 'Italian', 'dealer-inventory-for-autoscout24' ),
			'en' => __( 'English', 'dealer-inventory-for-autoscout24' ),
		) as $code => $label ) {
			$languages[ $code ] = $label;
		}

		return array(
			'provider'         => array(
				'group'   => 'connection',
				'type'    => 'enum',
				'default' => Providers::DEFAULT_ID,
				'options' => $providers,
				'label'   => __( 'Marketplace', 'dealer-inventory-for-autoscout24' ),
				'help'    => __( 'The AutoScout24 country site your listings are published on.', 'dealer-inventory-for-autoscout24' ),
			),
			'client_id'        => array(
				'group'    => 'connection',
				'type'     => 'text',
				'default'  => '',
				'constant' => 'DINV_CLIENT_ID',
				'label'    => __( 'Client ID', 'dealer-inventory-for-autoscout24' ),
			),
			'client_secret'    => array(
				'group'    => 'connection',
				'type'     => 'secret',
				'default'  => '',
				'constant' => 'DINV_CLIENT_SECRET',
				'label'    => __( 'Client Secret', 'dealer-inventory-for-autoscout24' ),
				'help'     => __( 'Stored encrypted. Leave empty to keep the saved secret.', 'dealer-inventory-for-autoscout24' ),
			),
			'seller_id'        => array(
				'group'    => 'connection',
				'type'     => 'int',
				'default'  => 0,
				'min'      => 0,
				'constant' => 'DINV_SELLER_ID',
				'label'    => __( 'Seller ID', 'dealer-inventory-for-autoscout24' ),
				'help'     => __( 'Your dealer number on AutoScout24.', 'dealer-inventory-for-autoscout24' ),
			),
			'dealer_url'       => array(
				'group'    => 'connection',
				'type'     => 'url',
				'default'  => '',
				'validate' => static fn( string $url ): bool => Connection::current()->provider()->is_valid_dealer_url( $url ),
				'label'    => __( 'Dealer page URL', 'dealer-inventory-for-autoscout24' ),
				'help'     => __( 'Optional. Your public dealer page on AutoScout24, used for the header link.', 'dealer-inventory-for-autoscout24' ),
			),
			'content_language' => array(
				'group'   => 'connection',
				'type'    => 'enum',
				'default' => 'auto',
				'options' => $languages,
				'label'   => __( 'Listing text language', 'dealer-inventory-for-autoscout24' ),
				'help'    => __( 'Language in which listing texts are downloaded. Unsupported languages fall back to the marketplace default.', 'dealer-inventory-for-autoscout24' ),
			),
		);
	}

	/**
	 * Synchronization fields.
	 *
	 * @return array<string, array>
	 */
	private static function sync_fields(): array {
		return array(
			'sync_mode'      => array(
				'group'   => 'sync',
				'type'    => 'enum',
				'default' => 'interval',
				'options' => array(
					'interval' => __( 'Recurring interval', 'dealer-inventory-for-autoscout24' ),
					'daily'    => __( 'Once a day at a fixed time', 'dealer-inventory-for-autoscout24' ),
				),
				'label'   => __( 'Schedule', 'dealer-inventory-for-autoscout24' ),
			),
			'sync_interval'  => array(
				'group'   => 'sync',
				'type'    => 'enum',
				'default' => 60,
				'options' => array(
					15  => __( 'Every 15 minutes', 'dealer-inventory-for-autoscout24' ),
					30  => __( 'Every 30 minutes', 'dealer-inventory-for-autoscout24' ),
					60  => __( 'Every hour', 'dealer-inventory-for-autoscout24' ),
					120 => __( 'Every 2 hours', 'dealer-inventory-for-autoscout24' ),
					240 => __( 'Every 4 hours', 'dealer-inventory-for-autoscout24' ),
					360 => __( 'Every 6 hours', 'dealer-inventory-for-autoscout24' ),
					720 => __( 'Every 12 hours', 'dealer-inventory-for-autoscout24' ),
				),
				'label'   => __( 'Interval', 'dealer-inventory-for-autoscout24' ),
				'help'    => __( 'Used with the recurring schedule. Shorter intervals use more API requests.', 'dealer-inventory-for-autoscout24' ),
			),
			'sync_time'      => array(
				'group'   => 'sync',
				'type'    => 'time',
				'default' => '03:00',
				'label'   => __( 'Daily time', 'dealer-inventory-for-autoscout24' ),
				'help'    => __( 'Used with the daily schedule, in the site time zone.', 'dealer-inventory-for-autoscout24' ),
			),
			'sync_warranty'  => array(
				'group'   => 'sync',
				'type'    => 'bool',
				'default' => true,
				'label'   => __( 'Download warranty information', 'dealer-inventory-for-autoscout24' ),
				'help'    => __( 'Needed for the "With warranty" filter. Costs one extra pass over the listings per sync.', 'dealer-inventory-for-autoscout24' ),
			),
			'sync_details'   => array(
				'group'   => 'sync',
				'type'    => 'bool',
				'default' => false,
				'label'   => __( 'Download descriptions and equipment', 'dealer-inventory-for-autoscout24' ),
				'help'    => __( 'For vehicle detail pages on your site. Costs two extra API requests per new vehicle; at most 25 vehicles are updated per sync.', 'dealer-inventory-for-autoscout24' ),
			),
			'uninstall_data' => array(
				'group'   => 'sync',
				'type'    => 'enum',
				'default' => 'keep',
				'options' => array(
					'keep'   => __( 'Keep settings and vehicles', 'dealer-inventory-for-autoscout24' ),
					'delete' => __( 'Remove everything', 'dealer-inventory-for-autoscout24' ),
				),
				'label'   => __( 'When the plugin is deleted', 'dealer-inventory-for-autoscout24' ),
				'help'    => __( 'Keeping the data lets you reinstall without entering the credentials again. Removing deletes the settings, the stored vehicles and the logs.', 'dealer-inventory-for-autoscout24' ),
			),
		);
	}

	/**
	 * Layout, results and visible parts (site-wide defaults, overridable per instance).
	 *
	 * @return array<string, array>
	 */
	private static function display_fields(): array {
		$layout  = __( 'Layout', 'dealer-inventory-for-autoscout24' );
		$results = __( 'Results', 'dealer-inventory-for-autoscout24' );
		$parts   = __( 'Visible parts', 'dealer-inventory-for-autoscout24' );
		$links   = __( 'Vehicle links', 'dealer-inventory-for-autoscout24' );
		$both    = self::SCOPE_BOTH;

		return array(
			'layout'            => array(
				'group'   => 'display',
				'scope'   => $both,
				'section' => $layout,
				'type'    => 'enum',
				'default' => 'card',
				'options' => array(
					'card'  => __( 'Cards', 'dealer-inventory-for-autoscout24' ),
					'grid'  => __( 'Compact grid', 'dealer-inventory-for-autoscout24' ),
					'list'  => __( 'List', 'dealer-inventory-for-autoscout24' ),
					'table' => __( 'Table', 'dealer-inventory-for-autoscout24' ),
				),
				'label'   => __( 'Layout', 'dealer-inventory-for-autoscout24' ),
			),
			'columns'           => array(
				'group'   => 'display',
				'scope'   => $both,
				'section' => $layout,
				'type'    => 'int',
				'default' => 3,
				'min'     => 1,
				'max'     => 6,
				'label'   => __( 'Columns on desktop', 'dealer-inventory-for-autoscout24' ),
				'help'    => __( 'For cards and the compact grid.', 'dealer-inventory-for-autoscout24' ),
			),
			'columns_tablet'    => array(
				'group'   => 'display',
				'scope'   => $both,
				'section' => $layout,
				'type'    => 'int',
				'default' => 2,
				'min'     => 1,
				'max'     => 4,
				'label'   => __( 'Columns on tablets', 'dealer-inventory-for-autoscout24' ),
			),
			'columns_mobile'    => array(
				'group'   => 'display',
				'scope'   => $both,
				'section' => $layout,
				'type'    => 'int',
				'default' => 1,
				'min'     => 1,
				'max'     => 2,
				'label'   => __( 'Columns on phones', 'dealer-inventory-for-autoscout24' ),
			),
			'view_switcher'     => array(
				'group'   => 'display',
				'scope'   => $both,
				'section' => $layout,
				'type'    => 'bool',
				'default' => false,
				'label'   => __( 'Grid / list switch for visitors', 'dealer-inventory-for-autoscout24' ),
				'help'    => __( 'The visitor\'s choice is remembered in their browser.', 'dealer-inventory-for-autoscout24' ),
			),
			'per_page'          => array(
				'group'   => 'display',
				'scope'   => $both,
				'section' => $results,
				'type'    => 'int',
				'default' => 12,
				'min'     => 1,
				'max'     => 48,
				'label'   => __( 'Vehicles per page', 'dealer-inventory-for-autoscout24' ),
			),
			'per_page_selector' => array(
				'group'   => 'display',
				'scope'   => $both,
				'section' => $results,
				'type'    => 'bool',
				'default' => false,
				'label'   => __( 'Let visitors choose vehicles per page', 'dealer-inventory-for-autoscout24' ),
			),
			'per_page_options'  => array(
				'group'   => 'display',
				'scope'   => $both,
				'section' => $results,
				'type'    => 'text',
				'default' => '12,24,48',
				'pattern' => '/^\d{1,2}(,\d{1,2}){0,5}$/',
				'label'   => __( 'Choices for vehicles per page', 'dealer-inventory-for-autoscout24' ),
				'help'    => __( 'Comma separated, up to 48. Example: 12,24,48', 'dealer-inventory-for-autoscout24' ),
			),
			'pagination'        => array(
				'group'   => 'display',
				'scope'   => $both,
				'section' => $results,
				'type'    => 'enum',
				'default' => 'numbers',
				'options' => array(
					'numbers'   => __( 'Page numbers', 'dealer-inventory-for-autoscout24' ),
					'load_more' => __( '"Load more" button', 'dealer-inventory-for-autoscout24' ),
					'infinite'  => __( 'Infinite scrolling', 'dealer-inventory-for-autoscout24' ),
				),
				'label'   => __( 'Pagination', 'dealer-inventory-for-autoscout24' ),
				'help'    => __( 'All types keep crawlable page links for search engines.', 'dealer-inventory-for-autoscout24' ),
			),
			'sort'              => array(
				'group'   => 'display',
				'scope'   => $both,
				'section' => $results,
				'type'    => 'enum',
				'default' => 'newest',
				'options' => self::sort_options(),
				'label'   => __( 'Default sort order', 'dealer-inventory-for-autoscout24' ),
			),
			'sort_options'      => array(
				'group'   => 'display',
				'scope'   => $both,
				'section' => $results,
				'type'    => 'list',
				'default' => array_keys( self::sort_options() ),
				'options' => self::sort_options(),
				'label'   => __( 'Sort options offered', 'dealer-inventory-for-autoscout24' ),
				'help'    => __( 'Choose and order the entries of the sort menu.', 'dealer-inventory-for-autoscout24' ),
			),
			'show_header'       => array(
				'group'   => 'display',
				'scope'   => $both,
				'section' => $parts,
				'type'    => 'bool',
				'default' => true,
				'label'   => __( 'Header bar', 'dealer-inventory-for-autoscout24' ),
			),
			'header_title'      => array(
				'group'      => 'display',
				'scope'      => $both,
				'section'    => $parts,
				'type'       => 'text',
				'default'    => '',
				'max_length' => 120,
				'label'      => __( 'Header title', 'dealer-inventory-for-autoscout24' ),
				'help'       => __( 'Leave empty to use "Site name · Vehicle search".', 'dealer-inventory-for-autoscout24' ),
			),
			'show_count'        => array(
				'group'   => 'display',
				'scope'   => $both,
				'section' => $parts,
				'type'    => 'bool',
				'default' => true,
				'label'   => __( 'Vehicle count', 'dealer-inventory-for-autoscout24' ),
			),
			'show_sort'         => array(
				'group'   => 'display',
				'scope'   => $both,
				'section' => $parts,
				'type'    => 'bool',
				'default' => true,
				'label'   => __( 'Sort menu', 'dealer-inventory-for-autoscout24' ),
			),
			'show_pagination'   => array(
				'group'   => 'display',
				'scope'   => $both,
				'section' => $parts,
				'type'    => 'bool',
				'default' => true,
				'label'   => __( 'Pagination', 'dealer-inventory-for-autoscout24' ),
			),
			'show_dealer_link'  => array(
				'group'   => 'display',
				'scope'   => $both,
				'section' => $parts,
				'type'    => 'bool',
				'default' => true,
				'label'   => __( 'Link to the dealer page on AutoScout24', 'dealer-inventory-for-autoscout24' ),
				'help'    => __( 'Shown only when a dealer page URL is set.', 'dealer-inventory-for-autoscout24' ),
			),
			'show_powered_by'   => array(
				'group'   => 'display',
				'scope'   => $both,
				'section' => $parts,
				'type'    => 'bool',
				'default' => true,
				'label'   => __( '"Listings from AutoScout24" note', 'dealer-inventory-for-autoscout24' ),
			),
			'url_state'         => array(
				'group'   => 'display',
				'scope'   => $both,
				'section' => $parts,
				'type'    => 'bool',
				'default' => true,
				'label'   => __( 'Keep filters, sort and page in the URL', 'dealer-inventory-for-autoscout24' ),
				'help'    => __( 'Makes results shareable and paginated pages crawlable.', 'dealer-inventory-for-autoscout24' ),
			),
			'link_to'           => array(
				'group'   => 'display',
				'scope'   => $both,
				'section' => $links,
				'type'    => 'enum',
				'default' => 'autoscout',
				'options' => array(
					'autoscout' => __( 'Listing on AutoScout24', 'dealer-inventory-for-autoscout24' ),
					'local'     => __( 'Detail page on this site', 'dealer-inventory-for-autoscout24' ),
					'none'      => __( 'No link', 'dealer-inventory-for-autoscout24' ),
				),
				'label'   => __( 'Vehicle links open', 'dealer-inventory-for-autoscout24' ),
			),
			'new_tab'           => array(
				'group'   => 'display',
				'scope'   => $both,
				'section' => $links,
				'type'    => 'bool',
				'default' => false,
				'label'   => __( 'Open AutoScout24 links in a new tab', 'dealer-inventory-for-autoscout24' ),
			),
			'button_text'       => array(
				'group'      => 'display',
				'scope'      => $both,
				'section'    => $links,
				'type'       => 'text',
				'default'    => '',
				'max_length' => 40,
				'label'      => __( 'Button text', 'dealer-inventory-for-autoscout24' ),
				'help'       => __( 'Leave empty for "View vehicle".', 'dealer-inventory-for-autoscout24' ),
			),
		);
	}

	/**
	 * Visitor filters (site-wide defaults, overridable per instance).
	 *
	 * @return array<string, array>
	 */
	private static function filter_fields(): array {
		$section = __( 'Filters', 'dealer-inventory-for-autoscout24' );
		$make    = __( 'Make and model filter', 'dealer-inventory-for-autoscout24' );
		$both    = self::SCOPE_BOTH;

		return array(
			'show_filters'      => array(
				'group'   => 'filters',
				'scope'   => $both,
				'section' => $section,
				'type'    => 'bool',
				'default' => true,
				'label'   => __( 'Show filters', 'dealer-inventory-for-autoscout24' ),
			),
			'filters'           => array(
				'group'   => 'filters',
				'scope'   => $both,
				'section' => $section,
				'type'    => 'list',
				'default' => array( 'make', 'price', 'year', 'mileage', 'fuel', 'body', 'transmission', 'drive', 'power', 'condition', 'warranty' ),
				'options' => self::filter_labels(),
				'label'   => __( 'Filters and their order', 'dealer-inventory-for-autoscout24' ),
				'help'    => __( 'Tick the filters to show and drag them into order.', 'dealer-inventory-for-autoscout24' ),
			),
			'filter_position'   => array(
				'group'   => 'filters',
				'scope'   => $both,
				'section' => $section,
				'type'    => 'enum',
				'default' => 'top',
				'options' => array(
					'top'     => __( 'Above the results', 'dealer-inventory-for-autoscout24' ),
					'sidebar' => __( 'Sidebar', 'dealer-inventory-for-autoscout24' ),
				),
				'label'   => __( 'Position', 'dealer-inventory-for-autoscout24' ),
			),
			'filters_visible'   => array(
				'group'   => 'filters',
				'scope'   => $both,
				'section' => $section,
				'type'    => 'int',
				'default' => 4,
				'min'     => 0,
				'max'     => 13,
				'label'   => __( 'Filters shown before "More filters"', 'dealer-inventory-for-autoscout24' ),
				'help'    => __( 'Above the results only. The rest open with "More filters".', 'dealer-inventory-for-autoscout24' ),
			),
			'filters_collapsed' => array(
				'group'   => 'filters',
				'scope'   => $both,
				'section' => $section,
				'type'    => 'bool',
				'default' => false,
				'label'   => __( 'Start collapsed', 'dealer-inventory-for-autoscout24' ),
				'help'    => __( 'Above the results: filters open with a button. Sidebar: every filter starts folded.', 'dealer-inventory-for-autoscout24' ),
			),
			'mobile_drawer'     => array(
				'group'   => 'filters',
				'scope'   => $both,
				'section' => $section,
				'type'    => 'bool',
				'default' => true,
				'label'   => __( 'Slide-in filter panel on phones', 'dealer-inventory-for-autoscout24' ),
			),
			'range_style'       => array(
				'group'   => 'filters',
				'scope'   => $both,
				'section' => $section,
				'type'    => 'enum',
				'default' => 'inputs',
				'options' => array(
					'inputs' => __( 'From / to fields', 'dealer-inventory-for-autoscout24' ),
					'slider' => __( 'Sliders', 'dealer-inventory-for-autoscout24' ),
				),
				'label'   => __( 'Price, year, mileage and power', 'dealer-inventory-for-autoscout24' ),
			),
			'make_model_mode'   => array(
				'group'   => 'filters',
				'scope'   => $both,
				'section' => $make,
				'type'    => 'enum',
				'default' => 'separate',
				'options' => array(
					'separate'   => __( 'Two dropdowns: make, then model', 'dealer-inventory-for-autoscout24' ),
					'combined'   => __( 'One picker with makes and models', 'dealer-inventory-for-autoscout24' ),
					'searchable' => __( 'One search field with suggestions', 'dealer-inventory-for-autoscout24' ),
					'hidden'     => __( 'Hidden', 'dealer-inventory-for-autoscout24' ),
				),
				'label'   => __( 'Style', 'dealer-inventory-for-autoscout24' ),
			),
			'filter_counts'     => array(
				'group'   => 'filters',
				'scope'   => $both,
				'section' => $make,
				'type'    => 'bool',
				'default' => true,
				'label'   => __( 'Show the number of vehicles per make and model', 'dealer-inventory-for-autoscout24' ),
			),
			'hide_empty'        => array(
				'group'   => 'filters',
				'scope'   => $both,
				'section' => $make,
				'type'    => 'bool',
				'default' => true,
				'label'   => __( 'Hide makes and models without matching vehicles', 'dealer-inventory-for-autoscout24' ),
				'help'    => __( 'When other filters are active. Otherwise they are shown greyed out.', 'dealer-inventory-for-autoscout24' ),
			),
		);
	}

	/**
	 * Vehicle card content (site-wide defaults, overridable per instance).
	 *
	 * @return array<string, array>
	 */
	private static function card_fields(): array {
		$section = __( 'Vehicle cards', 'dealer-inventory-for-autoscout24' );
		$both    = self::SCOPE_BOTH;

		return array(
			'card_fields'  => array(
				'group'   => 'card',
				'scope'   => $both,
				'section' => $section,
				'type'    => 'multi',
				'default' => array( 'image', 'title', 'version', 'teaser', 'price', 'monthly_rate', 'year', 'mileage', 'fuel', 'power', 'badges', 'button' ),
				'options' => self::card_field_labels(),
				'label'   => __( 'Show on each vehicle', 'dealer-inventory-for-autoscout24' ),
			),
			'badges'       => array(
				'group'   => 'card',
				'scope'   => $both,
				'section' => $section,
				'type'    => 'multi',
				'default' => array( 'new', 'warranty', 'price_reduced' ),
				'options' => array(
					'new'           => __( 'New', 'dealer-inventory-for-autoscout24' ),
					'warranty'      => __( 'Warranty', 'dealer-inventory-for-autoscout24' ),
					'price_reduced' => __( 'Price reduced', 'dealer-inventory-for-autoscout24' ),
				),
				'label'   => __( 'Badges', 'dealer-inventory-for-autoscout24' ),
			),
			'image_ratio'  => array(
				'group'   => 'card',
				'scope'   => $both,
				'section' => $section,
				'type'    => 'enum',
				'default' => '4-3',
				'options' => self::ratio_options(),
				'label'   => __( 'Image ratio', 'dealer-inventory-for-autoscout24' ),
			),
			'image_count'  => array(
				'group'   => 'card',
				'scope'   => $both,
				'section' => $section,
				'type'    => 'int',
				'default' => 1,
				'min'     => 1,
				'max'     => 5,
				'label'   => __( 'Images per vehicle', 'dealer-inventory-for-autoscout24' ),
				'help'    => __( 'More than one shows a small gallery when the pointer moves over the image.', 'dealer-inventory-for-autoscout24' ),
			),
			'hover_effect' => array(
				'group'   => 'card',
				'scope'   => $both,
				'section' => $section,
				'type'    => 'enum',
				'default' => 'lift',
				'options' => array(
					'none'   => __( 'None', 'dealer-inventory-for-autoscout24' ),
					'lift'   => __( 'Lift', 'dealer-inventory-for-autoscout24' ),
					'zoom'   => __( 'Zoom image', 'dealer-inventory-for-autoscout24' ),
					'shadow' => __( 'Shadow', 'dealer-inventory-for-autoscout24' ),
				),
				'label'   => __( 'Hover effect', 'dealer-inventory-for-autoscout24' ),
			),
			'card_label'   => array(
				'group'      => 'card',
				'scope'      => $both,
				'section'    => $section,
				'type'       => 'text',
				'default'    => '',
				'max_length' => 80,
				'label'      => __( 'Card label', 'dealer-inventory-for-autoscout24' ),
				'help'       => __( 'Optional small label above each title, for example "New arrival".', 'dealer-inventory-for-autoscout24' ),
			),
		);
	}

	/**
	 * Units and number formatting.
	 *
	 * @return array<string, array>
	 */
	private static function format_fields(): array {
		$both = self::SCOPE_BOTH;
		return array(
			'currency'    => array(
				'group'   => 'format',
				'scope'   => $both,
				'type'    => 'enum',
				'default' => 'auto',
				'options' => array(
					'auto' => __( 'Marketplace currency', 'dealer-inventory-for-autoscout24' ),
					'CHF'  => 'CHF',
					'EUR'  => 'EUR',
				),
				'label'   => __( 'Currency', 'dealer-inventory-for-autoscout24' ),
			),
			'power_unit'  => array(
				'group'   => 'format',
				'scope'   => $both,
				'type'    => 'enum',
				'default' => 'hp',
				'options' => array(
					'hp'   => __( 'Horsepower', 'dealer-inventory-for-autoscout24' ),
					'kw'   => __( 'Kilowatts', 'dealer-inventory-for-autoscout24' ),
					'both' => __( 'Both', 'dealer-inventory-for-autoscout24' ),
				),
				'label'   => __( 'Power unit', 'dealer-inventory-for-autoscout24' ),
			),
			'date_format' => array(
				'group'   => 'format',
				'scope'   => $both,
				'type'    => 'enum',
				'default' => 'auto',
				'options' => array(
					'auto' => __( 'Site language', 'dealer-inventory-for-autoscout24' ),
					'm/Y'  => '03/2021',
					'm.Y'  => '03.2021',
					'Y'    => '2021',
				),
				'label'   => __( 'First registration format', 'dealer-inventory-for-autoscout24' ),
			),
		);
	}

	/**
	 * Local vehicle detail pages (site-wide).
	 *
	 * @return array<string, array>
	 */
	private static function detail_fields(): array {
		$section = __( 'Vehicle detail pages', 'dealer-inventory-for-autoscout24' );
		return array(
			'detail_page' => array(
				'group'   => 'detail',
				'section' => $section,
				'type'    => 'page',
				'default' => 0,
				'min'     => 0,
				'label'   => __( 'Inventory page for detail links', 'dealer-inventory-for-autoscout24' ),
				'help'    => __( 'Used when vehicles link to "Detail page on this site". Pick the page with your main inventory; inventories on other pages (for example the homepage) link to it.', 'dealer-inventory-for-autoscout24' ),
			),
			'detail_base' => array(
				'group'      => 'detail',
				'section'    => $section,
				'type'       => 'key',
				'default'    => 'vehicle',
				'max_length' => 30,
				'label'      => __( 'Detail URL part', 'dealer-inventory-for-autoscout24' ),
				'help'       => __( 'Example: /cars/vehicle/12345-bmw-x5/. Lowercase letters, numbers and dashes.', 'dealer-inventory-for-autoscout24' ),
			),
		);
	}

	/**
	 * Image ratio choices.
	 *
	 * @return array<string, string>
	 */
	public static function ratio_options(): array {
		return array(
			'3-2'   => '3:2',
			'4-3'   => '4:3',
			'16-10' => '16:10',
			'16-9'  => '16:9',
			'1-1'   => '1:1',
		);
	}

	/**
	 * Design tokens (site-wide).
	 *
	 * @return array<string, array>
	 */
	private static function design_fields(): array {
		$colors = __( 'Colors', 'dealer-inventory-for-autoscout24' );
		$type   = __( 'Typography and spacing', 'dealer-inventory-for-autoscout24' );
		$preset = Design::presets()[ Design::DEFAULT_PRESET ];

		$fields = array(
			'design_preset'    => array(
				'group'   => 'design',
				'type'    => 'enum',
				'default' => Design::DEFAULT_PRESET,
				'options' => wp_list_pluck( Design::presets(), 'label' ),
				'label'   => __( 'Preset', 'dealer-inventory-for-autoscout24' ),
			),
			'use_theme_styles' => array(
				'group'   => 'design',
				'type'    => 'bool',
				'default' => false,
				'label'   => __( 'Use theme styles', 'dealer-inventory-for-autoscout24' ),
				'help'    => __( 'Take colors and fonts from your theme instead of the settings below. Layout stays the same.', 'dealer-inventory-for-autoscout24' ),
			),
		);

		foreach ( Design::color_labels() as $key => $label ) {
			$fields[ $key ] = array(
				'group'   => 'design',
				'section' => $colors,
				'type'    => 'color',
				'default' => $preset[ $key ],
				'label'   => $label,
			);
		}

		return $fields + array(
			'design_font'         => array(
				'group'   => 'design',
				'section' => $type,
				'type'    => 'enum',
				'default' => 'inherit',
				'options' => array(
					'inherit' => __( 'Theme font', 'dealer-inventory-for-autoscout24' ),
					'system'  => __( 'System UI', 'dealer-inventory-for-autoscout24' ),
					'serif'   => __( 'Serif', 'dealer-inventory-for-autoscout24' ),
					'custom'  => __( 'Custom', 'dealer-inventory-for-autoscout24' ),
				),
				'label'   => __( 'Font', 'dealer-inventory-for-autoscout24' ),
			),
			'design_font_custom'  => array(
				'group'      => 'design',
				'section'    => $type,
				'type'       => 'text',
				'default'    => '',
				'max_length' => 120,
				// Comma-separated names, each bare or in balanced quotes.
				'pattern'    => '/^\s*(?:[A-Za-z0-9 \-]+|"[A-Za-z0-9 \-]+"|\'[A-Za-z0-9 \-]+\')(?:\s*,\s*(?:[A-Za-z0-9 \-]+|"[A-Za-z0-9 \-]+"|\'[A-Za-z0-9 \-]+\'))*\s*$/',
				'label'      => __( 'Custom font family', 'dealer-inventory-for-autoscout24' ),
				'help'       => __( 'A font your theme already loads, for example "Inter", sans-serif.', 'dealer-inventory-for-autoscout24' ),
			),
			'design_max_width'    => self::px( $type, __( 'Maximum width', 'dealer-inventory-for-autoscout24' ), $preset['design_max_width'], 600, 2400 ),
			'design_padding'      => self::px( $type, __( 'Side padding', 'dealer-inventory-for-autoscout24' ), $preset['design_padding'], 0, 96 ),
			'design_gap'          => self::px( $type, __( 'Base spacing', 'dealer-inventory-for-autoscout24' ), $preset['design_gap'], 4, 48 ),
			'design_radius_large' => self::px( $type, __( 'Outer radius', 'dealer-inventory-for-autoscout24' ), $preset['design_radius_large'], 0, 48 ),
			'design_radius'       => self::px( $type, __( 'Card radius', 'dealer-inventory-for-autoscout24' ), $preset['design_radius'], 0, 40 ),
			'design_radius_small' => self::px( $type, __( 'Input and button radius', 'dealer-inventory-for-autoscout24' ), $preset['design_radius_small'], 0, 24 ),
			'design_shadow'       => array(
				'group'   => 'design',
				'section' => $type,
				'type'    => 'enum',
				'default' => $preset['design_shadow'],
				'options' => array(
					'none'   => __( 'None', 'dealer-inventory-for-autoscout24' ),
					'soft'   => __( 'Soft', 'dealer-inventory-for-autoscout24' ),
					'strong' => __( 'Strong', 'dealer-inventory-for-autoscout24' ),
				),
				'label'   => __( 'Shadows', 'dealer-inventory-for-autoscout24' ),
			),
			'design_image_width'  => self::px( $type, __( 'Image width in the list layout', 'dealer-inventory-for-autoscout24' ), 280, 160, 480 ),
			'design_mobile_ratio' => array(
				'group'   => 'design',
				'section' => $type,
				'type'    => 'enum',
				'default' => '16-10',
				'options' => self::ratio_options(),
				'label'   => __( 'Image ratio on phones', 'dealer-inventory-for-autoscout24' ),
			),
		);
	}

	/**
	 * Pixel size field.
	 *
	 * @param string $section Section label.
	 * @param string $label   Label.
	 * @param int    $value   Default.
	 * @param int    $min     Minimum.
	 * @param int    $max     Maximum.
	 * @return array
	 */
	private static function px( string $section, string $label, int $value, int $min, int $max ): array {
		return array(
			'group'   => 'design',
			'section' => $section,
			'type'    => 'int',
			'default' => $value,
			'min'     => $min,
			'max'     => $max,
			'unit'    => 'px',
			'label'   => $label,
		);
	}

	/**
	 * Per-instance preset filters and identifiers.
	 *
	 * @return array<string, array>
	 */
	private static function preset_fields(): array {
		$fields = array(
			'instance' => array(
				'group'      => 'preset',
				'scope'      => self::SCOPE_INSTANCE,
				'type'       => 'key',
				'default'    => '',
				'max_length' => 40,
				'label'      => __( 'Instance name', 'dealer-inventory-for-autoscout24' ),
				'help'       => __( 'Unique name when several inventories are on one page; keeps their URL parameters apart.', 'dealer-inventory-for-autoscout24' ),
			),
			'query'    => array(
				'group'   => 'preset',
				'scope'   => self::SCOPE_INSTANCE,
				'type'    => 'text',
				'default' => '',
				'label'   => __( 'Compact filter query', 'dealer-inventory-for-autoscout24' ),
				'help'    => __( 'Example: make=bmw&body=suv&price_to=60000. Values here win over the single filter fields.', 'dealer-inventory-for-autoscout24' ),
			),
		);

		foreach ( array(
			'category'     => __( 'Vehicle type', 'dealer-inventory-for-autoscout24' ),
			'make'         => __( 'Make', 'dealer-inventory-for-autoscout24' ),
			'model'        => __( 'Model', 'dealer-inventory-for-autoscout24' ),
			'fuel'         => __( 'Fuel', 'dealer-inventory-for-autoscout24' ),
			'transmission' => __( 'Transmission', 'dealer-inventory-for-autoscout24' ),
			'body'         => __( 'Body type', 'dealer-inventory-for-autoscout24' ),
			'drive'        => __( 'Drive', 'dealer-inventory-for-autoscout24' ),
			'condition'    => __( 'Condition', 'dealer-inventory-for-autoscout24' ),
		) as $key => $label ) {
			$fields[ $key ] = array(
				'group'   => 'preset',
				'scope'   => self::SCOPE_INSTANCE,
				'type'    => 'filter_value',
				'default' => '',
				'label'   => $label,
			);
		}

		$fields['version']  = array(
			'group'   => 'preset',
			'scope'   => self::SCOPE_INSTANCE,
			'type'    => 'text',
			'default' => '',
			'label'   => __( 'Version contains', 'dealer-inventory-for-autoscout24' ),
		);
		$fields['warranty'] = array(
			'group'   => 'preset',
			'scope'   => self::SCOPE_INSTANCE,
			'type'    => 'bool',
			'default' => false,
			'label'   => __( 'Only vehicles with warranty', 'dealer-inventory-for-autoscout24' ),
		);

		foreach ( array(
			'price_from'   => __( 'Price from', 'dealer-inventory-for-autoscout24' ),
			'price_to'     => __( 'Price up to', 'dealer-inventory-for-autoscout24' ),
			'year_from'    => __( 'Year from', 'dealer-inventory-for-autoscout24' ),
			'year_to'      => __( 'Year up to', 'dealer-inventory-for-autoscout24' ),
			'mileage_from' => __( 'Mileage from', 'dealer-inventory-for-autoscout24' ),
			'mileage_to'   => __( 'Mileage up to', 'dealer-inventory-for-autoscout24' ),
			'power_from'   => __( 'Power from (hp)', 'dealer-inventory-for-autoscout24' ),
			'power_to'     => __( 'Power up to (hp)', 'dealer-inventory-for-autoscout24' ),
		) as $key => $label ) {
			$fields[ $key ] = array(
				'group'   => 'preset',
				'scope'   => self::SCOPE_INSTANCE,
				'type'    => 'number',
				'default' => '',
				'label'   => $label,
			);
		}

		return $fields;
	}
}
