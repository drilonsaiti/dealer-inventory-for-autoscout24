<?php
/**
 * Settings: sanitizing and precedence (instance > global > default).
 *
 * @package DealerInventory
 */

namespace DealerInventory\Tests;

use DealerInventory\Schema;
use DealerInventory\Settings;

/**
 * @covers \DealerInventory\Schema
 * @covers \DealerInventory\Settings
 */
class SettingsTest extends Test_Case {

	public function test_defaults_when_nothing_is_saved(): void {
		$config = Schema::resolve( array() );

		$this->assertSame( 'card', $config['layout'] );
		$this->assertSame( 12, $config['per_page'] );
		$this->assertSame( 'separate', $config['make_model_mode'] );
		$this->assertTrue( $config['show_filters'] );
	}

	public function test_global_setting_overrides_default(): void {
		Settings::save( array( 'layout' => 'list', 'per_page' => '24' ) );

		$config = Schema::resolve( array() );

		$this->assertSame( 'list', $config['layout'] );
		$this->assertSame( 24, $config['per_page'] );
	}

	public function test_instance_attribute_overrides_global_setting(): void {
		Settings::save( array( 'layout' => 'list', 'show_filters' => '0' ) );

		$config = Schema::resolve(
			array(
				'layout'       => 'table',
				'show_filters' => 'yes',
			)
		);

		$this->assertSame( 'table', $config['layout'] );
		$this->assertTrue( $config['show_filters'] );
	}

	public function test_empty_attributes_inherit(): void {
		Settings::save( array( 'layout' => 'grid' ) );

		$this->assertSame( 'grid', Schema::resolve( array( 'layout' => '' ) )['layout'] );
		$this->assertSame( 'grid', Schema::resolve( array( 'layout' => null ) )['layout'] );
	}

	public function test_invalid_values_fall_back_to_defaults(): void {
		$config = Schema::resolve(
			array(
				'layout'   => 'carousel',
				'per_page' => '999',
				'columns'  => 'abc',
			)
		);

		$this->assertSame( 'card', $config['layout'] );
		$this->assertSame( 12, $config['per_page'] );
		$this->assertSame( 3, $config['columns'] );
	}

	public function test_global_only_settings_cannot_be_set_per_instance(): void {
		$config = Schema::resolve( array( 'client_secret' => 'x', 'design_accent' => '#000000' ) );

		$this->assertArrayNotHasKey( 'client_secret', $config );
		$this->assertArrayNotHasKey( 'design_accent', $config );
	}

	public function test_list_keeps_order_and_drops_unknown_values(): void {
		$this->assertSame(
			array( 'price', 'make', 'fuel' ),
			Schema::sanitize( 'filters', 'price,make,unknown,fuel,price' )
		);
		$this->assertSame( array(), Schema::sanitize( 'filters', 'none' ) );
	}

	public function test_multi_uses_option_order(): void {
		$this->assertSame( array( 'image', 'price' ), Schema::sanitize( 'card_fields', 'price,image' ) );
	}

	public function test_bool_parsing(): void {
		foreach ( array( 'yes', '1', 'true', 'on', true ) as $value ) {
			$this->assertTrue( Schema::sanitize( 'show_count', $value ) );
		}
		foreach ( array( 'no', '0', 'false', 'off', '', false ) as $value ) {
			$this->assertFalse( Schema::sanitize( 'show_count', $value ) );
		}
	}

	public function test_saved_secret_is_encrypted_and_kept_when_empty(): void {
		Settings::save( array( 'client_secret' => 'top-secret' ) );
		$stored = get_option( Settings::OPTION )['client_secret'];

		$this->assertNotSame( 'top-secret', $stored );
		$this->assertNotSame( '', $stored );

		Settings::save( array( 'client_secret' => '' ) );
		$this->assertSame( $stored, get_option( Settings::OPTION )['client_secret'] );
	}

	public function test_preview_values_are_not_saved_and_limited_to_display_groups(): void {
		Settings::preview( array( 'layout' => 'table', 'seller_id' => '99' ) );

		$this->assertSame( 'table', Settings::get( 'layout' ) );
		$this->assertSame( 0, Settings::get( 'seller_id' ) );
		$this->assertFalse( isset( get_option( Settings::OPTION, array() )['layout'] ) && 'table' === get_option( Settings::OPTION )['layout'] );
	}

	public function test_config_filter(): void {
		add_filter(
			'dinv_inventory_config',
			static function ( array $config ): array {
				$config['per_page'] = 7;
				return $config;
			}
		);

		$this->assertSame( 7, Schema::resolve( array() )['per_page'] );
	}

	public function test_case_sensitive_enum_options_can_be_saved(): void {
		$this->assertSame( 'CHF', Schema::sanitize( 'currency', 'CHF' ) );
		$this->assertSame( 'm/Y', Schema::sanitize( 'date_format', 'm/Y' ) );
		$this->assertSame( Schema::field( 'currency' )['default'], Schema::sanitize( 'currency', 'XYZ' ) );
	}

	public function test_custom_font_needs_balanced_quotes(): void {
		$this->assertSame( '"Open Sans", Arial, sans-serif', Schema::sanitize( 'design_font_custom', '"Open Sans", Arial, sans-serif' ) );
		$this->assertSame( '', Schema::sanitize( 'design_font_custom', '"Inter, sans-serif' ) );
	}

	public function test_description_keeps_only_text_formatting(): void {
		$html = '<p style="x">Top <strong>car</strong> <a href="https://evil.example">link</a><img src="https://t.example/p.gif"><form action="x"><button>Go</button></form></p>';
		$this->assertSame( '<p>Top <strong>car</strong> linkGo</p>', \DealerInventory\Format::description_html( $html ) );
	}
}
