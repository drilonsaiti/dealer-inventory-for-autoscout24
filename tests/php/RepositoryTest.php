<?php
/**
 * Repository: storage, search, sorting, facets and sync bookkeeping.
 *
 * @package DealerInventory
 */

namespace DealerInventory\Tests;

use DealerInventory\Repository;

/**
 * @covers \DealerInventory\Repository
 */
class RepositoryTest extends Test_Case {

	public function test_search_returns_active_vehicles_with_totals(): void {
		$this->fixture();

		$result = Repository::search( array(), 1, 4, 'price_asc' );

		$this->assertSame( 6, $result['total'] );
		$this->assertSame( 2, $result['total_pages'] );
		$this->assertCount( 4, $result['items'] );
		$this->assertSame( array( '4', '1', '6', '2' ), array_column( $result['items'], 'external_id' ) );
	}

	public function test_second_page_and_page_size_limit(): void {
		$this->fixture();

		$page2 = Repository::search( array(), 2, 4, 'price_asc' );
		$this->assertSame( array( '5', '3' ), array_column( $page2['items'], 'external_id' ) );

		$capped = Repository::search( array(), 1, 500, 'newest' );
		$this->assertSame( 48, $capped['per_page'] );
	}

	/**
	 * @dataProvider filter_cases
	 *
	 * @param array    $filters  Filters.
	 * @param string[] $expected Expected ids (price ascending).
	 */
	public function test_filters( array $filters, array $expected ): void {
		$this->fixture();
		$result = Repository::search( $filters, 1, 48, 'price_asc' );
		$this->assertSame( $expected, array_column( $result['items'], 'external_id' ) );
		$this->assertSame( count( $expected ), $result['total'] );
	}

	/**
	 * Filter cases.
	 */
	public function filter_cases(): array {
		return array(
			'make'           => array( array( 'make' => 'audi' ), array( '4', '5' ) ),
			'make and model' => array( array( 'make' => 'bmw', 'model' => 'm3' ), array( '3' ) ),
			'price range'    => array( array( 'price_from' => 30000, 'price_to' => 55000 ), array( '1', '6', '2' ) ),
			'year from'      => array( array( 'year_from' => 2021 ), array( '4', '6', '2', '5', '3' ) ),
			'mileage to'     => array( array( 'mileage_to' => 100 ), array( '3' ) ),
			'fuel'           => array( array( 'fuel' => 'electric' ), array( '6' ) ),
			'body type'      => array( array( 'body_type' => 'estate' ), array( '4' ) ),
			'condition'      => array( array( 'condition' => 'new' ), array( '3' ) ),
			'warranty'       => array( array( 'has_warranty' => 1 ), array( '2', '5' ) ),
			'keyword'        => array( array( 'version' => 'xDrive' ), array( '4', '1', '6', '2', '5', '3' ) ),
			'no match'       => array( array( 'make' => 'fiat' ), array() ),
			'invalid number' => array( array( 'price_to' => 'abc' ), array( '4', '1', '6', '2', '5', '3' ) ),
		);
	}

	public function test_unknown_sort_falls_back_to_newest(): void {
		$this->fixture();
		$result = Repository::search( array(), 1, 10, '1; DROP TABLE x' );
		$this->assertSame( 6, $result['total'] );
	}

	public function test_missing_vehicles_are_deactivated_after_a_sync(): void {
		$this->fixture();
		$this->store( array( $this->vehicle( 1 ), $this->vehicle( 2 ) ), 'next' );

		$this->assertSame( 4, Repository::deactivate_missing( 'default', 'next' ) );
		$this->assertSame( 2, Repository::count_active() );
		$this->assertNull( Repository::get_vehicle( 3 ) );
		$this->assertNotNull( Repository::get_vehicle( 3, false ) );
	}

	public function test_upsert_updates_existing_rows(): void {
		$this->fixture();
		$this->store( array( $this->vehicle( 2, array( 'price' => 1234.0 ) ) ), 'next' );

		$this->assertSame( 1234.0, (float) Repository::get_vehicle( 2 )['price'] );
		$this->assertSame( 6, Repository::count_active() );
	}

	public function test_facets_count_makes_and_models_ignoring_make_filter(): void {
		$this->fixture();

		$facets = Repository::facets( array( 'make' => 'bmw', 'fuel' => 'petrol' ) );

		$this->assertSame( array( 'bmw' => 2 ), $facets['makes'] );
		$this->assertSame( array( '3-series' => 1, 'm3' => 1 ), $facets['models']['bmw'] );

		$all = Repository::facets( array() );
		$this->assertSame( 3, $all['makes']['bmw'] );
		$this->assertSame( 2, $all['makes']['audi'] );
	}

	public function test_choice_counts_apply_the_other_filters_but_not_their_own(): void {
		$this->fixture();

		$counts = Repository::choice_counts( array( 'body_type' => 'suv', 'fuel' => 'diesel' ), array( 'fuel', 'body_type' ) );

		$this->assertEqualsCanonicalizing( array( 'petrol' => 2, 'diesel' => 2, 'electric' => 1 ), $counts['fuel'], 'Fuel counts use body = SUV only.' );
		$this->assertEqualsCanonicalizing( array( 'suv' => 2, 'estate' => 1 ), $counts['body_type'], 'Body counts use fuel = diesel only.' );

		$all = Repository::choice_counts( array(), array( 'fuel' ) );
		$this->assertEqualsCanonicalizing( array( 'diesel' => 3, 'petrol' => 2, 'electric' => 1 ), $all['fuel'] );
	}

	public function test_make_tree_is_sorted_with_counts(): void {
		$this->fixture();
		$tree = Repository::make_tree();

		$this->assertSame( array( 'Audi', 'BMW', 'VW' ), array_column( $tree, 'label' ) );
		$this->assertSame( 3, $tree[1]['count'] );
		$this->assertSame( array( '3 Series', 'M3', 'X5' ), array_column( $tree[1]['models'], 'label' ) );
	}

	public function test_filter_options_contain_bounds(): void {
		$this->fixture();
		$options = Repository::filter_options();

		$this->assertSame( array( 'min' => 25000, 'max' => 80000 ), $options['bounds']['price'] );
		$this->assertSame( array( 'min' => 2016, 'max' => 2024 ), $options['bounds']['year'] );
		$this->assertContains( 'electric', array_column( $options['fuels'], 'value' ) );
	}

	public function test_details_are_stored_and_listed_for_refresh(): void {
		$this->fixture();

		$this->assertCount( 6, Repository::vehicles_needing_details( 'default', 10 ) );

		Repository::store_details( 'default', 2, array( 'description' => 'Hello' ) );
		$row = Repository::get_vehicle( 2 );

		$this->assertSame( 'Hello', json_decode( $row['detail_json'], true )['description'] );
		$this->assertCount( 5, Repository::vehicles_needing_details( 'default', 10 ) );
	}

	/**
	 * Run a callback and count the database queries it makes.
	 *
	 * @param callable $callback Callback.
	 * @return array{0: mixed, 1: int} Result and query count.
	 */
	private function queries( callable $callback ): array {
		global $wpdb;
		$before = $wpdb->num_queries;
		$result = $callback();
		return array( $result, $wpdb->num_queries - $before );
	}

	public function test_unfiltered_search_needs_one_query_with_a_warm_cache(): void {
		$this->fixture();
		Repository::warm_public_cache();

		list( $result, $queries ) = $this->queries( static fn() => Repository::search( array(), 1, 4, 'newest' ) );

		$this->assertSame( 6, $result['total'] );
		$this->assertSame( 1, $queries, 'Only the page itself is queried; the total comes from the cache.' );
	}

	public function test_last_page_needs_no_count_query(): void {
		$this->fixture();

		list( $result, $queries ) = $this->queries( static fn() => Repository::search( array( 'make' => 'bmw' ), 1, 12, 'newest' ) );
		$this->assertSame( 3, $result['total'] );
		$this->assertSame( 1, $queries );

		list( $result, $queries ) = $this->queries( static fn() => Repository::search( array( 'make' => 'bmw' ), 2, 2, 'newest' ) );
		$this->assertSame( 3, $result['total'], 'Short last page: offset + items.' );
		$this->assertSame( 1, $queries );

		list( $result, $queries ) = $this->queries( static fn() => Repository::search( array( 'make' => 'bmw' ), 1, 2, 'newest' ) );
		$this->assertSame( 3, $result['total'], 'A full page still counts.' );
		$this->assertSame( 2, $queries );

		$beyond = Repository::search( array( 'make' => 'bmw' ), 9, 2, 'newest' );
		$this->assertSame( 3, $beyond['total'], 'Pages beyond the end still report the real total.' );
	}

	public function test_known_total_skips_counting(): void {
		$this->fixture();

		list( $result, $queries ) = $this->queries( static fn() => Repository::search( array( 'fuel' => 'petrol' ), 1, 1, 'newest', true, 2 ) );

		$this->assertSame( 2, $result['total'] );
		$this->assertSame( 2, $result['total_pages'] );
		$this->assertSame( 1, $queries );
	}

	public function test_facets_include_the_total(): void {
		$this->fixture();
		$this->store( array( $this->vehicle( 9, array( 'make_key' => '', 'make_name' => '', 'model_key' => '', 'fuel_type' => 'petrol' ) ) ) );

		$facets = Repository::facets( array( 'fuel' => 'petrol' ) );

		$this->assertSame( 3, $facets['total'], 'Vehicles without a make are counted too.' );
		$this->assertArrayNotHasKey( '', $facets['makes'] );
		$this->assertSame( 7, Repository::facets( array() )['total'] );
	}

	public function test_public_cache_is_one_option_and_cleared_on_invalidation(): void {
		$this->fixture();
		Repository::warm_public_cache();

		$cache = get_option( Repository::CACHE_OPTION );
		$this->assertSame( 6, $cache['total'] );
		$this->assertArrayHasKey( 'filter_options', $cache );
		$this->assertArrayHasKey( 'make_model_rows', $cache );

		Repository::invalidate_public_cache();
		$this->assertFalse( get_option( Repository::CACHE_OPTION ) );

		list( , $queries ) = $this->queries( static fn() => Repository::filter_options() );
		$this->assertGreaterThan( 1, $queries, 'Rebuilt after invalidation.' );
		list( , $queries ) = $this->queries( static fn() => Repository::filter_options() );
		$this->assertSame( 0, $queries, 'Served from the request copy.' );
	}
}
