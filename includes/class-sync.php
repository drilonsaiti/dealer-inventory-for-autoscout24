<?php
/**
 * Inventory synchronization.
 *
 * @package DealerInventory
 */

namespace DealerInventory;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Downloads the dealer's listings from the provider into the local table.
 */
final class Sync {

	public const HOOK = 'dinv_sync_event';

	private const LOCK_OPTION  = 'dinv_sync_lock';
	private const LOCK_TTL     = 10 * MINUTE_IN_SECONDS;
	private const WRITE_BUFFER = 20;

	/**
	 * Run a full synchronization.
	 *
	 * @param bool $manual Started from the admin instead of cron.
	 * @return array|WP_Error Stats on success.
	 */
	public static function run( bool $manual = false ): array|WP_Error {
		$connection = Connection::current();
		if ( ! $connection->is_configured() ) {
			return new WP_Error(
				'dinv_not_configured',
				$connection->secret_unreadable
					? __( 'The stored Client Secret can no longer be decrypted (the WordPress security keys changed). Please enter it again.', 'dealer-inventory-for-autoscout24' )
					: __( 'The connection is not configured yet.', 'dealer-inventory-for-autoscout24' )
			);
		}

		$lock_token = self::acquire_lock();
		if ( is_wp_error( $lock_token ) ) {
			return $lock_token;
		}

		$provider = $connection->provider();
		$language = I18n::content_language( $provider );
		$started  = microtime( true );
		$batch    = gmdate( 'YmdHis' ) . '-' . wp_generate_password( 8, false, false );
		$buffer   = array();

		update_option( 'dinv_last_sync_attempt', current_time( 'mysql', true ), false );
		Logger::log( 'info', 'sync_started', $manual ? 'Manual sync started.' : 'Scheduled sync started.' );

		try {
			$received = $provider->fetch_listings(
				$connection,
				$language,
				static function ( array $row, int $position ) use ( &$buffer, $batch, $lock_token ): void {
					$row['source_order'] = max( 0, $position );
					$buffer[]            = $row;
					if ( count( $buffer ) >= self::WRITE_BUFFER ) {
						Repository::upsert_rows( $buffer, $batch );
						$buffer = array();
						self::refresh_lock( $lock_token );
					}
				}
			);
			Repository::upsert_rows( $buffer, $batch );

			if ( is_wp_error( $received ) ) {
				self::store_failure( $received->get_error_message(), $started );
				return $received;
			}

			// A slow run whose lock was taken over must not hide the other run's vehicles.
			if ( ! self::refresh_lock( $lock_token ) ) {
				$error = new WP_Error( 'dinv_sync_lock_lost', __( 'Another synchronization took over. This run stopped without hiding vehicles.', 'dealer-inventory-for-autoscout24' ) );
				self::store_failure( $error->get_error_message(), $started );
				return $error;
			}

			// Only a complete download may deactivate vehicles.
			$deactivated = Repository::deactivate_missing( $connection->id, $batch );
			$metadata    = self::refresh_metadata( $connection, $language );
			$details     = self::refresh_details( $connection, $language );

			Repository::invalidate_public_cache();
			Repository::warm_public_cache();

			$stats = array(
				'last_success'       => current_time( 'mysql', true ),
				'version'            => time(),
				'duration'           => round( microtime( true ) - $started, 3 ),
				'received'           => $received,
				'active'             => Repository::active_total(),
				'deactivated'        => $deactivated,
				'warranty_refreshed' => $metadata['warranty_refreshed'],
				'metadata_failures'  => $metadata['failures'],
				'details_updated'    => $details,
				'last_error'         => '',
			);
			update_option( 'dinv_sync_stats', $stats, true );
			update_option( 'dinv_connection_status', 'connected', false );
			Logger::log( 'info', 'sync_completed', 'Synchronization completed successfully.', $stats );

			/**
			 * Fires after a successful synchronization, for example to purge page caches.
			 *
			 * @param array $stats Synchronization statistics.
			 */
			do_action( 'dinv_inventory_synced', $stats );

			return $stats;
		} finally {
			self::release_lock( $lock_token );
		}
	}

	/**
	 * Inventory version used by the front end to detect stale cached pages.
	 */
	public static function version(): int {
		$stats = get_option( 'dinv_sync_stats', array() );
		return is_array( $stats ) ? absint( $stats['version'] ?? 0 ) : 0;
	}

	/**
	 * Seller profile and warranty flags.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $language   Content language.
	 * @return array{warranty_refreshed: bool, failures: int}
	 */
	private static function refresh_metadata( Connection $connection, string $language ): array {
		$provider = $connection->provider();
		$result   = array(
			'warranty_refreshed' => false,
			'failures'           => 0,
		);

		$seller = $provider->fetch_seller( $connection );
		if ( is_wp_error( $seller ) ) {
			++$result['failures'];
			Logger::log( 'warning', 'seller_refresh_failed', 'Seller profile refresh failed.', array( 'error_code' => $seller->get_error_code() ) );
		} else {
			update_option( 'dinv_seller_profile', $seller, true );
		}

		if ( ! in_array( 'warranty', $provider->features(), true ) || ! Settings::get( 'sync_warranty', true ) ) {
			return $result;
		}

		// One paged query for the whole stock, not one request per vehicle.
		$warranty_ids = $provider->fetch_warranty_ids( $connection, $language );
		if ( is_wp_error( $warranty_ids ) ) {
			++$result['failures'];
			Logger::log( 'warning', 'warranty_refresh_failed', 'Warranty data refresh failed; previous flags were kept.', array( 'error_code' => $warranty_ids->get_error_code() ) );
		} else {
			Repository::set_warranty_flags( $connection->id, $warranty_ids );
			$result['warranty_refreshed'] = true;
		}

		return $result;
	}

	/**
	 * Download description and equipment for new or outdated vehicles, when
	 * local detail pages are used. Limited per run to protect the API quota.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $language   Content language.
	 * @return int Number of vehicles updated.
	 */
	private static function refresh_details( Connection $connection, string $language ): int {
		if ( ! self::details_enabled() ) {
			return 0;
		}

		/**
		 * Filters how many vehicles get their details refreshed per sync.
		 *
		 * @param int $limit Vehicles per run (two API requests each).
		 */
		$limit    = max( 1, (int) apply_filters( 'dinv_detail_batch_size', 25 ) );
		$provider = $connection->provider();
		$updated  = 0;

		foreach ( Repository::vehicles_needing_details( $connection->id, $limit ) as $external_id ) {
			$detail = $provider->fetch_listing_detail( $connection, $external_id, $language );
			if ( is_wp_error( $detail ) ) {
				Logger::log( 'warning', 'detail_refresh_failed', 'Vehicle detail refresh failed.', array( 'error_code' => $detail->get_error_code() ) );
				if ( 'dinv_rate_limited' === $detail->get_error_code() ) {
					break;
				}
				continue;
			}
			Repository::store_details( $connection->id, $external_id, $detail );
			++$updated;
		}

		return $updated;
	}

	/**
	 * Whether descriptions and equipment are downloaded.
	 */
	public static function details_enabled(): bool {
		return (bool) Settings::get( 'sync_details', false ) || 'local' === Settings::get( 'link_to', 'autoscout' );
	}

	/**
	 * Atomically acquire the sync lock.
	 *
	 * @return string|WP_Error Lock token.
	 */
	private static function acquire_lock(): string|WP_Error {
		$token   = wp_generate_uuid4();
		$payload = array(
			'token'      => $token,
			'expires_at' => time() + self::LOCK_TTL,
		);

		// Only one concurrent request can create the option.
		if ( add_option( self::LOCK_OPTION, $payload, '', false ) ) {
			return $token;
		}

		$existing = get_option( self::LOCK_OPTION, null );
		if ( is_array( $existing ) && (int) ( $existing['expires_at'] ?? 0 ) > time() ) {
			return new WP_Error( 'dinv_sync_locked', __( 'A synchronization is already running.', 'dealer-inventory-for-autoscout24' ) );
		}

		// Expired or malformed lock: replace it only if nobody else did first.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap on the lock row.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s",
				maybe_serialize( $payload ),
				self::LOCK_OPTION,
				maybe_serialize( $existing )
			)
		);

		if ( 1 === $updated ) {
			wp_cache_delete( self::LOCK_OPTION, 'options' );
			return $token;
		}

		return new WP_Error( 'dinv_sync_locked', __( 'A synchronization is already running.', 'dealer-inventory-for-autoscout24' ) );
	}

	/**
	 * Extend the lock while this process still owns it.
	 *
	 * @param string $token Lock token.
	 * @return bool False when another run took the lock.
	 */
	private static function refresh_lock( string $token ): bool {
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		$existing = get_option( self::LOCK_OPTION, null );
		if ( ! is_array( $existing ) || ! is_string( $existing['token'] ?? null ) || ! hash_equals( $existing['token'], $token ) ) {
			return false;
		}
		$existing['expires_at'] = time() + self::LOCK_TTL;
		update_option( self::LOCK_OPTION, $existing, false );
		return true;
	}

	/**
	 * Release the lock only if this process still owns it.
	 *
	 * @param string $token Lock token.
	 */
	private static function release_lock( string $token ): void {
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		$existing = get_option( self::LOCK_OPTION, null );
		if ( is_array( $existing ) && is_string( $existing['token'] ?? null ) && hash_equals( $existing['token'], $token ) ) {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Store a failed run.
	 *
	 * @param string $message Error message.
	 * @param float  $started Start time.
	 */
	private static function store_failure( string $message, float $started ): void {
		$stats               = get_option( 'dinv_sync_stats', array() );
		$stats               = is_array( $stats ) ? $stats : array();
		$stats['duration']   = round( microtime( true ) - $started, 3 );
		$stats['last_error'] = sanitize_text_field( $message );
		update_option( 'dinv_sync_stats', $stats, true );
		update_option( 'dinv_connection_status', 'error', false );
		Logger::log( 'error', 'sync_failed', 'Synchronization failed.', array( 'reason' => $message ) );
	}
}
