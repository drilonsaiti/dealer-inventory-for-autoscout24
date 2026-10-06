<?php
/**
 * Public REST endpoints.
 *
 * @package DealerInventory
 */

namespace DealerInventory\Tests;

use DealerInventory\Rest;
use DealerInventory\Sync;

/**
 * @covers \DealerInventory\Rest
 */
class RestTest extends Test_Case {

	/**
	 * Run a GET request.
	 *
	 * @param string $route  Route.
	 * @param array  $params Query parameters.
	 */
	private function get( string $route, array $params = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'GET', '/' . Rest::NAMESPACE . $route );
		$request->set_query_params( $params );
		return rest_get_server()->dispatch( $request );
	}

	public function test_routes_are_public_and_read_only(): void {
		$routes = rest_get_server()->get_routes( Rest::NAMESPACE );

		foreach ( array( '/vehicles', '/models', '/status' ) as $route ) {
			$this->assertArrayHasKey( '/' . Rest::NAMESPACE . $route, $routes );
			foreach ( $routes[ '/' . Rest::NAMESPACE . $route ] as $handler ) {
				$this->assertArrayHasKey( 'permission_callback', $handler );
				$this->assertSame( array( 'GET' => true ), $handler['methods'] );
			}
		}
	}

	public function test_vehicles_returns_filtered_html_and_facets(): void {
		$this->fixture();

		$response = $this->get(
			'/vehicles',
			array(
				'dinv_make' => 'bmw',
				'per_page'  => '2',
				'dinv_base' => '/cars/',
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 3, $data['total'] );
		$this->assertSame( 2, $data['totalPages'] );
		$this->assertSame( 2, substr_count( $data['html'], 'data-vehicle-id=' ) );
		$this->assertStringContainsString( '/cars/?dinv_make=bmw&#038;dinv_page=2', $data['pagination'] );
		$this->assertSame( 3, $data['facets']['makes']['bmw'] );
	}

	public function test_items_part_returns_only_vehicles(): void {
		$this->fixture();

		$data = $this->get( '/vehicles', array( 'dinv_part' => 'items', 'per_page' => '4', 'dinv_page' => '2' ) )->get_data();

		$this->assertStringNotContainsString( 'dinv-items', $data['html'] );
		$this->assertSame( 2, substr_count( $data['html'], 'data-vehicle-id=' ) );
		$this->assertArrayNotHasKey( 'facets', $data );
	}

	public function test_preset_filters_are_kept(): void {
		$this->fixture();

		$data = $this->get( '/vehicles', array( 'make' => 'audi', 'dinv_make' => 'bmw' ) )->get_data();

		$this->assertSame( 2, $data['total'] );
	}

	public function test_hostile_base_url_is_ignored(): void {
		$this->fixture();

		$data = $this->get( '/vehicles', array( 'per_page' => '1', 'dinv_base' => '//evil.example/' ) )->get_data();

		$this->assertStringNotContainsString( 'evil.example', $data['pagination'] );
	}

	public function test_cache_headers_depend_on_version(): void {
		$current = $this->get( '/vehicles', array( 'v' => Sync::cache_version() ) );
		$stale   = $this->get( '/vehicles', array( 'v' => 'old' ) );

		$this->assertStringContainsString( 'public', $current->get_headers()['Cache-Control'] );
		$this->assertStringContainsString( 'no-cache', $stale->get_headers()['Cache-Control'] );
	}

	public function test_cache_version_changes_with_settings(): void {
		$before = Sync::cache_version();
		update_option( \DealerInventory\Settings::OPTION, array( 'card_fields' => array( 'image', 'title' ) ) );
		\DealerInventory\Settings::reset_cache();

		$this->assertNotSame( $before, Sync::cache_version(), 'Cached responses with old settings are not reused.' );
		$this->assertStringContainsString( 'no-cache', $this->get( '/vehicles', array( 'v' => $before ) )->get_headers()['Cache-Control'] );
	}
}
