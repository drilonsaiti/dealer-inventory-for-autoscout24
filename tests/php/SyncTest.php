<?php
/**
 * Synchronization lock ownership.
 *
 * @package DealerInventory
 */

namespace DealerInventory\Tests;

use DealerInventory\Sync;

/**
 * @covers \DealerInventory\Sync
 */
class SyncTest extends Test_Case {

	public function test_lock_refresh_does_not_overwrite_a_concurrent_takeover(): void {
		global $wpdb;

		$lock_option = 'dinv_sync_lock';
		delete_option( $lock_option );

		$acquire = new \ReflectionMethod( Sync::class, 'acquire_lock' );
		$token = $acquire->invoke( null );
		$this->assertIsString( $token );

		$raced = false;
		$simulate_takeover = static function ( string $query ) use ( &$raced, $wpdb, $lock_option ): string {
			if ( ! $raced && str_starts_with( $query, "UPDATE {$wpdb->options} SET option_value" ) ) {
				$raced = true;
				$wpdb->update(
					$wpdb->options,
					array( 'option_value' => maybe_serialize( array( 'token' => 'new-owner', 'expires_at' => time() + 600 ) ) ),
					array( 'option_name' => $lock_option ),
					array( '%s' ),
					array( '%s' )
				);
			}
			return $query;
		};
		add_filter( 'query', $simulate_takeover );

		try {
			$refresh = new \ReflectionMethod( Sync::class, 'refresh_lock' );

			$this->assertFalse( $refresh->invoke( null, $token ) );
			$this->assertTrue( $raced, 'The simulated takeover ran between the lock read and update.' );
			$this->assertSame( 'new-owner', get_option( $lock_option )['token'] );
		} finally {
			remove_filter( 'query', $simulate_takeover );
			delete_option( $lock_option );
		}
	}
}
