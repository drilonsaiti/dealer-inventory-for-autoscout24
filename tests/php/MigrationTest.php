<?php
/**
 * Settings migration from 1.0 to 1.1.
 *
 * @package DealerInventory
 */

namespace DealerInventory\Tests;

use DealerInventory\Migrations;
use DealerInventory\Schema;
use DealerInventory\Settings;

/**
 * @covers \DealerInventory\Migrations
 */
class MigrationTest extends Test_Case {

	/**
	 * CREATE TABLE commits the test transaction on MySQL, so the migration lock
	 * of an earlier test can survive. Start every test without it.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_transient( 'dinv_migrating' );
	}

	public function test_version_one_settings_are_mapped(): void {
		update_option(
			Settings::OPTION,
			array(
				'_version'           => '1.0.0',
				'layout'             => 'list',
				'show_make'          => true,
				'show_price_from'    => false,
				'show_price_to'      => true,
				'show_fuel'          => false,
				'show_warranty'      => true,
				'design_show_teaser' => false,
				'design_show_cta'    => true,
				'design_image_ratio' => '16-9',
				'design_preset'      => 'dark',
				'design_accent'      => '#FF0000',
			)
		);
		update_option( Migrations::OPTION, 1 );

		Migrations::maybe_run();
		Settings::reset_cache();
		$stored = get_option( Settings::OPTION );

		$this->assertSame( Migrations::VERSION, (int) get_option( Migrations::OPTION ) );
		$this->assertSame( array( 'make', 'price', 'warranty' ), $stored['filters'] );
		$this->assertNotContains( 'teaser', $stored['card_fields'] );
		$this->assertContains( 'button', $stored['card_fields'] );
		$this->assertSame( '16-9', $stored['image_ratio'] );
		$this->assertSame( 'premium_dark', $stored['design_preset'] );
		$this->assertSame( '#FF0000', $stored['design_accent'], 'Saved colors are kept.' );
		$this->assertSame( 'list', $stored['layout'] );
		$this->assertArrayNotHasKey( 'show_make', $stored );
		$this->assertArrayNotHasKey( 'design_image_ratio', $stored );
		$this->assertSame( '1', (string) get_option( 'dinv_flush_rewrite' ) );

		// New keys are filled with defaults on the next read.
		$this->assertSame( 'separate', Settings::get( 'make_model_mode' ) );
		$this->assertSame( 'list', Schema::resolve( array() )['layout'] );
	}

	public function test_fresh_install_keeps_defaults(): void {
		delete_option( Settings::OPTION );
		update_option( Migrations::OPTION, 1 );

		Migrations::maybe_run();
		Settings::reset_cache();

		$this->assertSame( Schema::field( 'filters' )['default'], Settings::get( 'filters' ) );
	}

	public function test_up_to_date_install_does_nothing(): void {
		update_option( Migrations::OPTION, Migrations::VERSION );
		update_option( Settings::OPTION, array( 'show_make' => true ) );

		Migrations::maybe_run();

		$this->assertSame( array( 'show_make' => true ), get_option( Settings::OPTION ) );
	}

	public function test_version_three_autoloads_hot_options_and_drops_old_transients(): void {
		global $wpdb;
		add_option( 'dinv_seller_profile', array( 'name' => 'Demo' ), '', false );
		update_option( 'dinv_flush_rewrite', '1', false );
		set_transient( 'dinv_filter_options', array( 1 ), HOUR_IN_SECONDS );
		update_option( Migrations::OPTION, 2 );

		Migrations::maybe_run();

		$autoload = $wpdb->get_col( "SELECT autoload FROM {$wpdb->options} WHERE option_name IN ('dinv_seller_profile','dinv_flush_rewrite')" );
		$this->assertCount( 2, $autoload );
		foreach ( $autoload as $value ) {
			$this->assertContains( $value, array( 'yes', 'on', 'auto-on' ) );
		}
		$this->assertSame( array( 'name' => 'Demo' ), get_option( 'dinv_seller_profile' ) );
		$this->assertSame( '1', get_option( 'dinv_flush_rewrite' ), 'A pending flush is kept.' );
		$this->assertFalse( get_transient( 'dinv_filter_options' ) );
	}
}
