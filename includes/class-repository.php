<?php
/**
 * Local vehicle storage and queries.
 *
 * @package DealerInventory
 */

namespace DealerInventory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the synchronized local inventory.
 *
 * Public pages never call the marketplace API; every query here runs against
 * the local table. Queries use the plugin's own tables, so direct database
 * access is intentional; results that are expensive and shared between
 * visitors are cached in transients and cleared after each synchronization.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
 * phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Arguments are spread from arrays.
 */
final class Repository {

	/**
	 * One non-autoloaded option holds every derived list (filter options,
	 * makes and models, number of active vehicles). It is rebuilt after each
	 * sync, so a page view reads it with a single query.
	 */
	public const CACHE_OPTION = 'dinv_public_cache';

	/**
	 * Transients used by version 1.1.0 and earlier (removed on invalidation).
	 */
	private const LEGACY_TRANSIENTS = array( 'dinv_filter_options', 'dinv_make_model_rows' );

	/**
	 * Per-request copy of the cache option.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * The request copy has parts that are not stored yet.
	 *
	 * @var bool
	 */
	private static bool $cache_dirty = false;

	/**
	 * Allowed sort keys mapped to ORDER BY clauses. Never built from input.
	 */
	private const SORTS = array(
		'newest'          => 'created_at DESC, external_id DESC',
		'oldest'          => 'created_at ASC, external_id ASC',
		'price_asc'       => 'price IS NULL, price ASC, external_id DESC',
		'price_desc'      => 'price IS NULL, price DESC, external_id DESC',
		'mileage_asc'     => 'mileage IS NULL, mileage ASC, external_id DESC',
		'mileage_desc'    => 'mileage IS NULL, mileage DESC, external_id DESC',
		'year_desc'       => 'first_registration_year IS NULL, first_registration_year DESC, external_id DESC',
		'year_asc'        => 'first_registration_year IS NULL, first_registration_year ASC, external_id DESC',
		'power_desc'      => 'horse_power IS NULL, horse_power DESC, external_id DESC',
		'make_model_asc'  => "make_name = '' ASC, make_name ASC, model_name ASC, version_full_name ASC, external_id ASC",
		'make_model_desc' => "make_name = '' ASC, make_name DESC, model_name DESC, version_full_name DESC, external_id DESC",
	);

	/**
	 * Columns needed to render a result card or list row.
	 */
	private const CARD_COLUMNS = array(
		'external_id',
		'make_name',
		'model_name',
		'version_full_name',
		'teaser',
		'price',
		'previous_price',
		'mileage',
		'first_registration_date',
		'first_registration_year',
		'fuel_type',
		'transmission_type',
		'drive_type',
		'condition_type',
		'horse_power',
		'kilo_watts',
		'consumption_combined',
		'range_km',
		'image_url',
		'leasing_monthly_rate',
		'has_warranty',
		'body_type',
		'images_json',
	);

	/**
	 * Column formats for writes.
	 */
	private const FORMATS = array(
		'connection_id'           => '%s',
		'external_id'             => '%d',
		'seller_id'               => '%d',
		'seller_vehicle_id'       => '%s',
		'vehicle_category'        => '%s',
		'make_key'                => '%s',
		'make_name'               => '%s',
		'model_key'               => '%s',
		'model_name'              => '%s',
		'version_full_name'       => '%s',
		'teaser'                  => '%s',
		'price'                   => '%f',
		'previous_price'          => '%f',
		'list_price'              => '%f',
		'mileage'                 => '%d',
		'first_registration_date' => '%s',
		'first_registration_year' => '%d',
		'fuel_type'               => '%s',
		'transmission_type'       => '%s',
		'transmission_group'      => '%s',
		'body_type'               => '%s',
		'condition_type'          => '%s',
		'drive_type'              => '%s',
		'horse_power'             => '%d',
		'kilo_watts'              => '%d',
		'consumption_combined'    => '%f',
		'co2_emission'            => '%d',
		'range_km'                => '%d',
		'image_url'               => '%s',
		'images_json'             => '%s',
		'quali_logo_image_url'    => '%s',
		'leasing_monthly_rate'    => '%f',
		'status'                  => '%s',
		'source_order'            => '%d',
		'sync_batch'              => '%s',
		'created_at'              => '%s',
		'updated_at'              => '%s',
		'synced_at'               => '%s',
	);

	/**
	 * Vehicles table name.
	 */
	public static function vehicles_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'dinv_vehicles';
	}

	/**
	 * Create or update both plugin tables (dbDelta is idempotent).
	 */
	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$vehicles = self::vehicles_table();
		$logs     = Logger::table();

		dbDelta(
			"CREATE TABLE {$vehicles} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			connection_id VARCHAR(40) NOT NULL DEFAULT 'default',
			external_id BIGINT UNSIGNED NOT NULL,
			seller_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			seller_vehicle_id VARCHAR(191) NOT NULL DEFAULT '',
			vehicle_category VARCHAR(40) NOT NULL DEFAULT '',
			make_key VARCHAR(120) NOT NULL DEFAULT '',
			make_name VARCHAR(160) NOT NULL DEFAULT '',
			model_key VARCHAR(160) NOT NULL DEFAULT '',
			model_name VARCHAR(191) NOT NULL DEFAULT '',
			version_full_name VARCHAR(255) NOT NULL DEFAULT '',
			teaser TEXT NULL,
			price DECIMAL(12,2) NULL,
			previous_price DECIMAL(12,2) NULL,
			list_price DECIMAL(12,2) NULL,
			mileage INT UNSIGNED NULL,
			first_registration_date DATE NULL,
			first_registration_year SMALLINT UNSIGNED NULL,
			fuel_type VARCHAR(80) NOT NULL DEFAULT '',
			transmission_type VARCHAR(80) NOT NULL DEFAULT '',
			transmission_group VARCHAR(80) NOT NULL DEFAULT '',
			body_type VARCHAR(80) NOT NULL DEFAULT '',
			condition_type VARCHAR(80) NOT NULL DEFAULT '',
			drive_type VARCHAR(80) NOT NULL DEFAULT '',
			horse_power SMALLINT UNSIGNED NULL,
			kilo_watts SMALLINT UNSIGNED NULL,
			consumption_combined DECIMAL(8,2) NULL,
			co2_emission INT UNSIGNED NULL,
			range_km INT UNSIGNED NULL,
			image_url TEXT NULL,
			images_json LONGTEXT NULL,
			quali_logo_image_url TEXT NULL,
			leasing_monthly_rate DECIMAL(12,2) NULL,
			has_warranty TINYINT(1) NOT NULL DEFAULT 0,
			detail_json LONGTEXT NULL,
			equipment_json LONGTEXT NULL,
			detail_synced_at DATETIME NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			source_order INT UNSIGNED NOT NULL DEFAULT 0,
			sync_batch VARCHAR(64) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			synced_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY connection_external (connection_id,external_id),
			KEY connection_status (connection_id,status),
			KEY make_model (make_key,model_key),
			KEY price (price),
			KEY mileage (mileage),
			KEY reg_year (first_registration_year),
			KEY fuel (fuel_type),
			KEY body_type (body_type),
			KEY created (created_at)
		) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$logs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			level VARCHAR(20) NOT NULL,
			event VARCHAR(80) NOT NULL,
			message VARCHAR(1000) NOT NULL,
			context LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY event (event)
		) {$charset};"
		);
	}

	/**
	 * Insert or update normalized rows in one statement.
	 *
	 * Uses INSERT ... ON DUPLICATE KEY UPDATE on (connection_id, external_id),
	 * so one API page costs one query instead of a SELECT plus a write per
	 * vehicle. created_at is only written on first insert.
	 *
	 * @param array[] $rows  Normalized rows with source_order set.
	 * @param string  $batch Sync batch id.
	 */
	public static function upsert_rows( array $rows, string $batch ): void {
		global $wpdb;

		if ( ! $rows ) {
			return;
		}

		$now     = current_time( 'mysql', true );
		$columns = array_keys( self::FORMATS );
		$tuples  = array();
		$params  = array();

		foreach ( $rows as $row ) {
			$row['status']     = 'active';
			$row['sync_batch'] = $batch;
			$row['created_at'] = $now;
			$row['updated_at'] = $now;
			$row['synced_at']  = $now;

			$placeholders = array();
			foreach ( $columns as $column ) {
				$value = $row[ $column ] ?? null;
				if ( null === $value ) {
					$placeholders[] = 'NULL';
					continue;
				}
				$placeholders[] = self::FORMATS[ $column ];
				$params[]       = $value;
			}
			$tuples[] = '(' . implode( ',', $placeholders ) . ')';
		}

		$updates = array();
		foreach ( $columns as $column ) {
			if ( in_array( $column, array( 'connection_id', 'external_id', 'created_at' ), true ) ) {
				continue;
			}
			$updates[] = "{$column}=VALUES({$column})";
		}

		$sql = 'INSERT INTO ' . self::vehicles_table() . ' (' . implode( ',', $columns ) . ') VALUES '
			. implode( ',', $tuples )
			. ' ON DUPLICATE KEY UPDATE ' . implode( ',', $updates );

		// Built only from the FORMATS constant and placeholders; values are prepared.
		$wpdb->query( $wpdb->prepare( $sql, ...$params ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Mark vehicles that were not part of the latest complete sync as inactive.
	 *
	 * @param string $connection_id Connection id.
	 * @param string $batch         Current sync batch id.
	 * @return int Number of deactivated vehicles.
	 */
	public static function deactivate_missing( string $connection_id, string $batch ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status='inactive', updated_at=%s WHERE connection_id=%s AND status='active' AND sync_batch<>%s",
				self::vehicles_table(),
				current_time( 'mysql', true ),
				$connection_id,
				$batch
			)
		);
	}

	/**
	 * Hide every vehicle of a connection (used when the dealer account changes).
	 *
	 * @param string $connection_id Connection id.
	 */
	public static function deactivate_connection( string $connection_id ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status='inactive', updated_at=%s WHERE connection_id=%s AND status='active'",
				self::vehicles_table(),
				current_time( 'mysql', true ),
				$connection_id
			)
		);
		self::invalidate_public_cache();
	}

	/**
	 * Active vehicles of a connection.
	 *
	 * @param string $connection_id Connection id.
	 */
	public static function count_active( string $connection_id = Connection::DEFAULT_ID ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE connection_id=%s AND status='active'", self::vehicles_table(), $connection_id )
		);
	}

	/**
	 * Valid sort keys.
	 *
	 * @return string[]
	 */
	public static function sort_keys(): array {
		return array_keys( self::SORTS );
	}

	/**
	 * Search active vehicles.
	 *
	 * The total comes from (in order): $known_total, the cached number of
	 * active vehicles when no filter is set, the page itself when it is the
	 * last one, and only then a COUNT query.
	 *
	 * @param array    $filters     Normalized filters.
	 * @param int      $page        1-based page.
	 * @param int      $per_page    Page size (1-48).
	 * @param string   $sort        Sort key.
	 * @param bool     $need_total  Whether the total is needed.
	 * @param int|null $known_total Total already known to the caller.
	 * @return array{items: array, total: int, page: int, per_page: int, total_pages: int}
	 */
	public static function search( array $filters, int $page, int $per_page, string $sort, bool $need_total = true, ?int $known_total = null ): array {
		global $wpdb;

		$page     = max( 1, $page );
		$per_page = max( 1, min( 48, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		list( $where_sql, $params ) = self::where_sql( $filters );

		// ORDER BY comes from the SORTS whitelist; WHERE uses placeholders only.
		$order = self::SORTS[ $sort ] ?? self::SORTS['newest'];
		$items = $wpdb->get_results( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prepare(
				'SELECT ' . implode( ', ', self::CARD_COLUMNS ) . ' FROM ' . self::vehicles_table()
				. ' WHERE ' . $where_sql . " ORDER BY {$order} LIMIT %d OFFSET %d",
				...array_merge( $params, array( $per_page, $offset ) )
			),
			ARRAY_A
		);
		$items = is_array( $items ) ? $items : array();

		if ( ! $need_total ) {
			// Compact blocks only need to know whether this page has results.
			$total = count( $items );
		} elseif ( null !== $known_total ) {
			$total = $known_total;
		} elseif ( ! self::has_filters( $filters ) ) {
			$total = self::active_total();
		} elseif ( count( $items ) < $per_page && ( $items || 1 === $page ) ) {
			// A short page is the last one: no COUNT query needed.
			$total = $offset + count( $items );
		} else {
			$count_sql = 'SELECT COUNT(*) FROM ' . self::vehicles_table() . ' WHERE ' . $where_sql;
			$total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- WHERE built from whitelisted columns and placeholders.
		}

		return array(
			'items'       => $items,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $need_total
				? ( $total > 0 ? (int) ceil( $total / $per_page ) : 0 )
				: ( $total > 0 ? 1 : 0 ),
		);
	}

	/**
	 * Whether any filter is set.
	 *
	 * @param array $filters Normalized filters.
	 */
	public static function has_filters( array $filters ): bool {
		return (bool) array_filter( $filters, static fn( $value ) => '' !== $value && null !== $value && 0 !== $value && false !== $value );
	}

	/**
	 * Replace warranty flags after a complete warranty query succeeded.
	 *
	 * @param string $connection_id Connection id.
	 * @param int[]  $external_ids  Listings with a warranty.
	 */
	public static function set_warranty_flags( string $connection_id, array $external_ids ): void {
		global $wpdb;

		$table = self::vehicles_table();
		$ids   = array_values( array_filter( array_unique( array_map( 'absint', $external_ids ) ) ) );

		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET has_warranty=0 WHERE connection_id=%s AND has_warranty=1', $table, $connection_id ) );

		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET has_warranty=1 WHERE connection_id=%s AND external_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One %d per id.
					...array_merge( array( $table, $connection_id ), $chunk )
				)
			);
		}
	}

	/**
	 * Distinct filter values with counts (cached until the next sync).
	 *
	 * @return array<string, array>
	 */
	public static function filter_options(): array {
		$cached = self::cache_get( 'filter_options' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table      = self::vehicles_table();
		$connection = Connection::DEFAULT_ID;

		$makes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT make_key AS value, MAX(make_name) AS label, COUNT(*) AS count
				FROM %i WHERE connection_id=%s AND status='active' AND make_key<>'' GROUP BY make_key ORDER BY label ASC",
				$table,
				$connection
			),
			ARRAY_A
		);
		$years = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT first_registration_year FROM %i
				WHERE connection_id=%s AND status='active' AND first_registration_year IS NOT NULL ORDER BY first_registration_year DESC",
				$table,
				$connection
			)
		);

		$options = array(
			'categories'    => self::distinct_values( 'vehicle_category', $connection ),
			'makes'         => is_array( $makes ) ? $makes : array(),
			'fuels'         => self::distinct_values( 'fuel_type', $connection ),
			'transmissions' => self::distinct_values( 'transmission_type', $connection ),
			'body_types'    => self::distinct_values( 'body_type', $connection ),
			'drive_types'   => self::distinct_values( 'drive_type', $connection ),
			'conditions'    => self::distinct_values( 'condition_type', $connection ),
			'years'         => is_array( $years ) ? $years : array(),
			'bounds'        => self::bounds( $connection ),
		);

		self::cache_set( 'filter_options', $options );
		return $options;
	}

	/**
	 * Smallest and largest price, year, mileage and power (for sliders).
	 *
	 * @param string $connection_id Connection id.
	 * @return array<string, array{min: int, max: int}>
	 */
	private static function bounds( string $connection_id ): array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT MIN(price) AS price_min, MAX(price) AS price_max,
					MIN(first_registration_year) AS year_min, MAX(first_registration_year) AS year_max,
					MIN(mileage) AS mileage_min, MAX(mileage) AS mileage_max,
					MIN(horse_power) AS power_min, MAX(horse_power) AS power_max
				FROM %i WHERE connection_id=%s AND status='active'",
				self::vehicles_table(),
				$connection_id
			),
			ARRAY_A
		);

		$bounds = array();
		foreach ( array( 'price', 'year', 'mileage', 'power' ) as $key ) {
			$min = isset( $row[ $key . '_min' ] ) ? (int) floor( (float) $row[ $key . '_min' ] ) : 0;
			$max = isset( $row[ $key . '_max' ] ) ? (int) ceil( (float) $row[ $key . '_max' ] ) : 0;
			if ( $max > $min ) {
				$bounds[ $key ] = array(
					'min' => $min,
					'max' => $max,
				);
			}
		}
		return $bounds;
	}

	/**
	 * Vehicle counts per make and model for the given filters, ignoring the
	 * make and model filters themselves (so visitors can switch make).
	 *
	 * Without other active filters the cached tree is used (no query).
	 *
	 * @param array $filters Normalized filters.
	 * @return array{makes: array<string, int>, models: array<string, array<string, int>>}
	 */
	public static function facets( array $filters ): array {
		unset( $filters['make'], $filters['model'] );

		$facets = array(
			'makes'  => array(),
			'models' => array(),
			'total'  => 0,
		);

		if ( ! self::has_filters( $filters ) ) {
			$rows            = self::make_model_rows();
			$facets['total'] = self::active_total();
		} else {
			global $wpdb;
			list( $where_sql, $params ) = self::where_sql( $filters );
			$sql                        = 'SELECT make_key, model_key, COUNT(*) AS count FROM ' . self::vehicles_table() . ' WHERE ' . $where_sql . ' GROUP BY make_key, model_key';
			$rows                       = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- WHERE built from whitelisted columns and placeholders.
			$rows                       = is_array( $rows ) ? $rows : array();
			foreach ( $rows as $row ) {
				$facets['total'] += (int) $row['count'];
			}
		}

		foreach ( $rows as $row ) {
			$make  = (string) $row['make_key'];
			$count = (int) $row['count'];
			if ( '' === $make ) {
				continue;
			}

			$facets['makes'][ $make ] = ( $facets['makes'][ $make ] ?? 0 ) + $count;
			if ( '' !== (string) $row['model_key'] ) {
				$facets['models'][ $make ][ (string) $row['model_key'] ] = $count;
			}
		}

		return $facets;
	}

	/**
	 * Choice filter columns, keyed by filter name.
	 */
	public const CHOICE_COLUMNS = array(
		'vehicle_category' => array( 'vehicle_category', 'categories' ),
		'fuel'             => array( 'fuel_type', 'fuels' ),
		'transmission'     => array( 'transmission_type', 'transmissions' ),
		'body_type'        => array( 'body_type', 'body_types' ),
		'drive_type'       => array( 'drive_type', 'drive_types' ),
		'condition'        => array( 'condition_type', 'conditions' ),
	);

	/**
	 * Vehicle counts per value of each choice filter (fuel, body type, …).
	 *
	 * Each filter is counted with all other active filters applied, but not
	 * its own, so the visitor sees how many vehicles every choice would give.
	 *
	 * @param array    $filters Normalized filters.
	 * @param string[] $targets Filter names to count (keys of CHOICE_COLUMNS).
	 * @return array<string, array<string, int>>
	 */
	public static function choice_counts( array $filters, array $targets ): array {
		global $wpdb;
		$counts = array();

		foreach ( $targets as $target ) {
			if ( ! isset( self::CHOICE_COLUMNS[ $target ] ) ) {
				continue;
			}
			list( $column, $group ) = self::CHOICE_COLUMNS[ $target ];
			$others                 = $filters;
			unset( $others[ $target ] );

			if ( ! self::has_filters( $others ) ) {
				$rows = (array) ( self::filter_options()[ $group ] ?? array() );
			} else {
				list( $where_sql, $params ) = self::where_sql( $others );
				$sql                        = 'SELECT ' . $column . ' AS value, COUNT(*) AS count FROM ' . self::vehicles_table() . ' WHERE ' . $where_sql . ' AND ' . $column . "<>'' GROUP BY " . $column;
				$rows                       = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Column from a constant whitelist, WHERE built from whitelisted columns and placeholders.
				$rows                       = is_array( $rows ) ? $rows : array();
			}

			$counts[ $target ] = array();
			foreach ( $rows as $row ) {
				$counts[ $target ][ (string) $row['value'] ] = (int) $row['count'];
			}
		}

		return $counts;
	}

	/**
	 * Makes with their models, labels and total counts (cached tree).
	 *
	 * @return array<int, array{value: string, label: string, count: int, models: array}>
	 */
	public static function make_tree(): array {
		$tree = array();
		foreach ( self::make_model_rows() as $row ) {
			$make = (string) $row['make_key'];
			if ( ! isset( $tree[ $make ] ) ) {
				$tree[ $make ] = array(
					'value'  => $make,
					'label'  => '' !== (string) $row['make_name'] ? (string) $row['make_name'] : $make,
					'count'  => 0,
					'models' => array(),
				);
			}
			$tree[ $make ]['count'] += (int) $row['count'];
			if ( '' !== (string) $row['model_key'] ) {
				$tree[ $make ]['models'][] = array(
					'value' => (string) $row['model_key'],
					'label' => '' !== (string) $row['model_name'] ? (string) $row['model_name'] : (string) $row['model_key'],
					'count' => (int) $row['count'],
				);
			}
		}

		$tree = array_values( $tree );
		usort( $tree, static fn( $a, $b ) => strnatcasecmp( $a['label'], $b['label'] ) );
		foreach ( $tree as &$make ) {
			usort( $make['models'], static fn( $a, $b ) => strnatcasecmp( $a['label'], $b['label'] ) );
		}
		unset( $make );

		return $tree;
	}

	/**
	 * One vehicle with all columns (detail page).
	 *
	 * @param int  $external_id Listing id.
	 * @param bool $active_only Only active vehicles.
	 */
	public static function get_vehicle( int $external_id, bool $active_only = true ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE connection_id=%s AND external_id=%d' . ( $active_only ? " AND status='active'" : '' ) . ' LIMIT 1',
				self::vehicles_table(),
				Connection::DEFAULT_ID,
				$external_id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Active vehicles whose description and equipment are missing or older
	 * than a week.
	 *
	 * @param string $connection_id Connection id.
	 * @param int    $limit         Maximum rows.
	 * @return int[] External ids.
	 */
	public static function vehicles_needing_details( string $connection_id, int $limit ): array {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT external_id FROM %i WHERE connection_id=%s AND status='active'
				AND (detail_synced_at IS NULL OR detail_synced_at < %s)
				ORDER BY detail_synced_at IS NULL DESC, detail_synced_at ASC, created_at DESC LIMIT %d",
				self::vehicles_table(),
				$connection_id,
				gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ),
				max( 1, $limit )
			)
		);
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Store normalized detail data (description, specs, equipment).
	 *
	 * @param string $connection_id Connection id.
	 * @param int    $external_id   Listing id.
	 * @param array  $detail        Normalized detail data.
	 */
	public static function store_details( string $connection_id, int $external_id, array $detail ): void {
		global $wpdb;
		$wpdb->update(
			self::vehicles_table(),
			array(
				'detail_json'      => (string) wp_json_encode( $detail ),
				'detail_synced_at' => current_time( 'mysql', true ),
			),
			array(
				'connection_id' => $connection_id,
				'external_id'   => $external_id,
			),
			array( '%s', '%s' ),
			array( '%s', '%d' )
		);
	}

	/**
	 * Active vehicle ids and title parts for sitemaps (one page).
	 *
	 * @param int $page     1-based page.
	 * @param int $per_page Rows per page.
	 * @return array[]
	 */
	public static function sitemap_rows( int $page, int $per_page ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT external_id, make_name, model_name, version_full_name, updated_at FROM %i
				WHERE connection_id=%s AND status='active' ORDER BY external_id ASC LIMIT %d OFFSET %d",
				self::vehicles_table(),
				Connection::DEFAULT_ID,
				$per_page,
				( max( 1, $page ) - 1 ) * $per_page
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Models of one make with counts.
	 *
	 * @param string $make_key Make key.
	 * @return array<int, array{value: string, label: string, count: int}>
	 */
	public static function models_for_make( string $make_key ): array {
		$make_key = sanitize_key( $make_key );
		if ( '' === $make_key ) {
			return array();
		}

		$models = array();
		foreach ( self::make_model_rows() as $row ) {
			if ( $make_key !== $row['make_key'] || '' === $row['model_key'] ) {
				continue;
			}
			$models[] = array(
				'value' => $row['model_key'],
				'label' => '' !== $row['model_name'] ? $row['model_name'] : $row['model_key'],
				'count' => absint( $row['count'] ),
			);
		}

		return $models;
	}

	/**
	 * The complete make/model tree in one grouped query (cached).
	 *
	 * @return array<int, array{make_key: string, make_name: string, model_key: string, model_name: string, count: int}>
	 */
	public static function make_model_rows(): array {
		$cached = self::cache_get( 'make_model_rows' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT make_key, MAX(make_name) AS make_name, model_key, MAX(model_name) AS model_name, COUNT(*) AS count
				FROM %i
				WHERE connection_id=%s AND status='active' AND make_key<>''
				GROUP BY make_key, model_key
				ORDER BY make_name ASC, model_name ASC",
				self::vehicles_table(),
				Connection::DEFAULT_ID
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		self::cache_set( 'make_model_rows', $rows );
		return $rows;
	}

	/**
	 * Clear cached filter metadata.
	 */
	public static function invalidate_public_cache(): void {
		self::$cache       = array();
		self::$cache_dirty = false;
		delete_option( self::CACHE_OPTION );
		foreach ( self::LEGACY_TRANSIENTS as $transient ) {
			delete_transient( $transient );
		}
	}

	/**
	 * Build the cache right away (after a sync), so visitors never wait for it.
	 */
	public static function warm_public_cache(): void {
		self::filter_options();
		self::make_model_rows();
		self::active_total();
		self::save_public_cache();
	}

	/**
	 * Number of active vehicles of the default connection (cached).
	 */
	public static function active_total(): int {
		$cached = self::cache_get( 'total' );
		if ( is_int( $cached ) ) {
			return $cached;
		}
		$total = self::count_active();
		self::cache_set( 'total', $total );
		return $total;
	}

	/**
	 * One part of the cache.
	 *
	 * @param string $part Part name.
	 * @return mixed Null when missing.
	 */
	private static function cache_get( string $part ) {
		if ( null === self::$cache ) {
			$stored      = get_option( self::CACHE_OPTION );
			self::$cache = is_array( $stored ) ? $stored : array();
		}
		return self::$cache[ $part ] ?? null;
	}

	/**
	 * Store one part of the cache.
	 *
	 * @param string $part  Part name.
	 * @param mixed  $value Value.
	 */
	private static function cache_set( string $part, $value ): void {
		if ( null === self::$cache ) {
			self::cache_get( $part );
		}
		self::$cache[ $part ] = $value;

		// Write once per request, however many parts were rebuilt.
		if ( ! self::$cache_dirty ) {
			self::$cache_dirty = true;
			add_action( 'shutdown', array( self::class, 'save_public_cache' ), 0 );
		}
	}

	/**
	 * Store rebuilt cache parts.
	 */
	public static function save_public_cache(): void {
		if ( self::$cache_dirty && is_array( self::$cache ) ) {
			update_option( self::CACHE_OPTION, self::$cache, false );
		}
		self::$cache_dirty = false;
	}

	/**
	 * Distinct values of one whitelisted column with counts.
	 *
	 * @param string $column        Column name.
	 * @param string $connection_id Connection id.
	 * @return array
	 */
	private static function distinct_values( string $column, string $connection_id ): array {
		global $wpdb;
		$allowed = array( 'vehicle_category', 'fuel_type', 'transmission_type', 'body_type', 'drive_type', 'condition_type' );
		if ( ! in_array( $column, $allowed, true ) ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT %i AS value, COUNT(*) AS count FROM %i WHERE connection_id=%s AND status='active' AND %i<>'' GROUP BY %i ORDER BY %i ASC",
				$column,
				self::vehicles_table(),
				$connection_id,
				$column,
				$column,
				$column
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Build the WHERE clause for normalized filters.
	 *
	 * @param array $filters Normalized filters.
	 * @return array{0: string, 1: array}
	 */
	private static function where_sql( array $filters ): array {
		global $wpdb;

		$clauses = array( 'connection_id=%s', "status='active'" );
		$params  = array( Connection::DEFAULT_ID );

		$exact = array(
			'vehicle_category' => 'vehicle_category',
			'make'             => 'make_key',
			'model'            => 'model_key',
			'fuel'             => 'fuel_type',
			'transmission'     => 'transmission_type',
			'body_type'        => 'body_type',
			'drive_type'       => 'drive_type',
			'condition'        => 'condition_type',
		);
		foreach ( $exact as $filter => $column ) {
			if ( ! empty( $filters[ $filter ] ) ) {
				$clauses[] = "{$column}=%s";
				$params[]  = sanitize_key( (string) $filters[ $filter ] );
			}
		}

		if ( ! empty( $filters['version'] ) ) {
			$clauses[] = '(version_full_name LIKE %s OR teaser LIKE %s)';
			$like      = '%' . $wpdb->esc_like( sanitize_text_field( (string) $filters['version'] ) ) . '%';
			$params[]  = $like;
			$params[]  = $like;
		}

		if ( ! empty( $filters['has_warranty'] ) ) {
			$clauses[] = 'has_warranty=1';
		}

		$numeric = array(
			'price_from'   => array( 'price', '>=' ),
			'price_to'     => array( 'price', '<=' ),
			'year_from'    => array( 'first_registration_year', '>=' ),
			'year_to'      => array( 'first_registration_year', '<=' ),
			'mileage_from' => array( 'mileage', '>=' ),
			'mileage_to'   => array( 'mileage', '<=' ),
			'power_from'   => array( 'horse_power', '>=' ),
			'power_to'     => array( 'horse_power', '<=' ),
		);
		foreach ( $numeric as $filter => $rule ) {
			if ( isset( $filters[ $filter ] ) && '' !== (string) $filters[ $filter ] && is_numeric( $filters[ $filter ] ) ) {
				$value = (float) $filters[ $filter ];
				if ( $value >= 0 ) {
					$clauses[] = $rule[0] . $rule[1] . '%f';
					$params[]  = $value;
				}
			}
		}

		return array( implode( ' AND ', $clauses ), $params );
	}
}
