<?php
/**
 * Versioned, idempotent database migrations.
 *
 * @package DealerInventory
 */

namespace DealerInventory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs schema and data migrations exactly once per version.
 *
 * Each step must be idempotent: running it twice leaves the same result.
 * The stored version is only advanced after a step succeeds, so a failed
 * step is retried on the next request.
 */
final class Migrations {

	public const OPTION = 'dinv_db_version';

	/**
	 * Latest schema version.
	 */
	public const VERSION = 3;

	/**
	 * Run pending migrations. Cheap when up to date (one autoloaded option read).
	 */
	public static function maybe_run(): void {
		$current = (int) get_option( self::OPTION, 0 );
		if ( $current >= self::VERSION ) {
			return;
		}

		// Avoid two concurrent requests running the same step.
		if ( get_transient( 'dinv_migrating' ) ) {
			return;
		}
		set_transient( 'dinv_migrating', 1, 5 * MINUTE_IN_SECONDS );

		foreach ( self::steps() as $version => $step ) {
			if ( $version <= $current ) {
				continue;
			}
			call_user_func( $step );
			update_option( self::OPTION, $version, true );
			$current = $version;
		}

		delete_transient( 'dinv_migrating' );
	}

	/**
	 * Ordered migration steps keyed by the version they produce.
	 *
	 * @return array<int, callable>
	 */
	public static function steps(): array {
		return array(
			1 => array( self::class, 'v1_install' ),
			2 => array( self::class, 'v2_phase_two_settings' ),
			3 => array( self::class, 'v3_fewer_queries' ),
		);
	}

	/**
	 * Version 1: create the vehicles and logs tables.
	 */
	public static function v1_install(): void {
		Repository::create_tables();
	}

	/**
	 * Version 2: map 1.0 display settings to the 1.1 options and register the
	 * detail page URLs.
	 *
	 * - show_<filter> booleans become the ordered "filters" list.
	 * - design_show_* toggles become "card_fields".
	 * - design_image_ratio becomes the per-instance "image_ratio".
	 * - Old preset names map to the new presets (colors stay as saved).
	 */
	public static function v2_phase_two_settings(): void {
		Repository::create_tables();

		$stored = get_option( Settings::OPTION );
		if ( is_array( $stored ) ) {
			$map = array(
				'make'         => array( 'show_make' ),
				'price'        => array( 'show_price_from', 'show_price_to' ),
				'year'         => array( 'show_year_from', 'show_year_to' ),
				'mileage'      => array( 'show_mileage_from', 'show_mileage_to' ),
				'fuel'         => array( 'show_fuel' ),
				'body'         => array( 'show_body' ),
				'transmission' => array( 'show_transmission' ),
				'drive'        => array( 'show_drive' ),
				'power'        => array( 'show_power_from', 'show_power_to' ),
				'condition'    => array( 'show_condition' ),
				'category'     => array( 'show_category' ),
				'version'      => array( 'show_version' ),
				'warranty'     => array( 'show_warranty' ),
			);

			$had_old = false;
			$filters = array();
			foreach ( $map as $filter => $keys ) {
				$on = false;
				foreach ( $keys as $key ) {
					if ( array_key_exists( $key, $stored ) ) {
						$had_old = true;
						$on      = $on || ! empty( $stored[ $key ] );
					}
					unset( $stored[ $key ] );
				}
				if ( $on ) {
					$filters[] = $filter;
				}
			}
			if ( $had_old ) {
				$stored['filters'] = $filters;
			}

			$fields  = array( 'image', 'title', 'version', 'teaser', 'price', 'monthly_rate', 'year', 'mileage', 'fuel', 'power', 'badges', 'button' );
			$hide    = array(
				'design_show_teaser'  => array( 'teaser' ),
				'design_show_specs'   => array( 'year', 'mileage', 'fuel', 'power' ),
				'design_show_cta'     => array( 'button' ),
				'design_show_quality' => array(),
			);
			$touched = false;
			foreach ( $hide as $key => $remove ) {
				if ( array_key_exists( $key, $stored ) ) {
					$touched = true;
					if ( empty( $stored[ $key ] ) ) {
						$fields = array_values( array_diff( $fields, $remove ) );
					}
					unset( $stored[ $key ] );
				}
			}
			if ( $touched ) {
				$stored['card_fields'] = $fields;
			}

			if ( isset( $stored['design_image_ratio'] ) ) {
				$stored['image_ratio'] = $stored['design_image_ratio'];
				unset( $stored['design_image_ratio'] );
			}

			$presets = array(
				'light'        => 'classic',
				'dark'         => 'premium_dark',
				'dark_neutral' => 'premium_dark',
			);
			if ( isset( $stored['design_preset'], $presets[ $stored['design_preset'] ] ) ) {
				$stored['design_preset'] = $presets[ $stored['design_preset'] ];
			}

			// Fill the new keys with their defaults on next read.
			unset( $stored['_version'] );
			update_option( Settings::OPTION, $stored, true );
			Settings::reset_cache();
		}

		update_option( 'dinv_flush_rewrite', '1', true );
	}

	/**
	 * Version 3: options read on every request are autoloaded, and the
	 * derived lists move from two transients to one cache option.
	 */
	public static function v3_fewer_queries(): void {
		if ( false === get_option( 'dinv_flush_rewrite' ) ) {
			add_option( 'dinv_flush_rewrite', '0', '', true );
		}
		// Re-add instead of wp_set_options_autoload(): it is unreliable in WP 6.5.
		foreach ( array( 'dinv_flush_rewrite', 'dinv_seller_profile' ) as $name ) {
			$value = get_option( $name );
			if ( false !== $value ) {
				delete_option( $name );
				add_option( $name, $value, '', true );
			}
		}

		Repository::invalidate_public_cache();
	}
}
