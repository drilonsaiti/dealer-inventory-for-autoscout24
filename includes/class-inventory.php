<?php
/**
 * State of one inventory instance for one request.
 *
 * @package DealerInventory
 */

namespace DealerInventory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Combines an instance configuration with request parameters (filters,
 * sort, page, per page, view) and runs the local search.
 *
 * Used for the server-rendered page and for REST refreshes, so both produce
 * the same results and markup.
 */
final class Inventory {

	/**
	 * Resolved configuration with the effective layout and page size.
	 *
	 * @var array<string, mixed>
	 */
	public array $config;

	/**
	 * Instance name (used in URL parameter names).
	 *
	 * @var string
	 */
	public string $instance;

	/**
	 * Filters set by the shortcode / block.
	 *
	 * @var array<string, mixed>
	 */
	public array $preset_filters;

	/**
	 * Filters chosen by the visitor.
	 *
	 * @var array<string, mixed>
	 */
	public array $request_filters;

	/**
	 * All active filters.
	 *
	 * @var array<string, mixed>
	 */
	public array $filters;

	/**
	 * Active sort.
	 *
	 * @var string
	 */
	public string $sort;

	/**
	 * Visitor view choice: "", "grid" or "list".
	 *
	 * @var string
	 */
	public string $view = '';

	/**
	 * Search results.
	 *
	 * @var array
	 */
	public array $results;

	/**
	 * Make / model counts (computed once).
	 *
	 * @var array|null
	 */
	private ?array $facets = null;

	/**
	 * Choice filter counts (computed once).
	 *
	 * @var array|null
	 */
	private ?array $choice_counts = null;

	/**
	 * Result renderer.
	 *
	 * @var Renderer
	 */
	public Renderer $renderer;

	/**
	 * Build the state.
	 *
	 * @param array  $config          Resolved instance configuration.
	 * @param array  $params          Request parameters.
	 * @param string $param_instance  Instance prefix used in $params ("" for REST).
	 * @param string $base_url        Same-site URL for page links ("" = buttons).
	 * @param bool   $accept_filters  Read filters from $params.
	 */
	public function __construct( array $config, array $params, string $param_instance, string $base_url, bool $accept_filters ) {
		$this->instance       = (string) $config['instance'];
		$this->preset_filters = Shortcode::filters_from_config( $config );

		$this->request_filters = $accept_filters ? Shortcode::filters_from_request( $params, $param_instance ) : array();
		$this->filters         = self::merge_filters( $this->preset_filters, $this->request_filters );

		/**
		 * Filters the active search filters of an inventory.
		 *
		 * @param array $filters Normalized filters.
		 * @param array $config  Instance configuration.
		 */
		$this->filters = (array) apply_filters( 'dinv_search_filters', $this->filters, $config );

		$read = static function ( string $param ) use ( $params, $param_instance, $accept_filters ): string {
			$key = Shortcode::request_key( $param, $param_instance );
			return $accept_filters && isset( $params[ $key ] ) && is_scalar( $params[ $key ] ) ? sanitize_key( wp_unslash( (string) $params[ $key ] ) ) : '';
		};

		// Sort: only entries offered in the sort menu (or the default).
		$this->sort = (string) $config['sort'];
		$requested  = $read( 'sort' );
		if ( '' !== $requested && ( in_array( $requested, (array) $config['sort_options'], true ) || $requested === $config['sort'] ) ) {
			$this->sort = Shortcode::valid_sort( $requested, $this->sort );
		}

		// Vehicles per page chosen by the visitor.
		$per_page = (int) $config['per_page'];
		if ( $config['per_page_selector'] ) {
			$requested_per_page = absint( $read( 'per_page' ) );
			if ( in_array( $requested_per_page, self::per_page_choices( $config ), true ) ) {
				$per_page = $requested_per_page;
			}
		}
		$config['per_page_default'] = (int) $config['per_page'];
		$config['per_page']         = $per_page;

		// Grid / list switch.
		if ( $config['view_switcher'] ) {
			$view = $read( 'view' );
			if ( in_array( $view, array( 'grid', 'list' ), true ) ) {
				$this->view = $view;
			}
		}
		$config['layout_default'] = (string) $config['layout'];
		$config['layout']         = self::effective_layout( (string) $config['layout'], $this->view );

		$this->config = $config;

		$page = 1;
		if ( $config['show_pagination'] ) {
			$page = max( 1, absint( $read( 'page' ) ) );
		}

		$need_total = $config['show_count'] || $config['show_pagination'];

		// The make / model counts ignore the make and model filters; without
		// those filters their sum is the total, so no COUNT query is needed.
		$known_total = null;
		if ( $this->shows_make_control() && empty( $this->filters['make'] ) && empty( $this->filters['model'] ) ) {
			$known_total = (int) $this->facets()['total'];
		}

		$this->results = Repository::search( $this->filters, $page, $per_page, $this->sort, $need_total, $known_total );
		if ( $page > 1 && $this->results['total_pages'] > 0 && $page > $this->results['total_pages'] ) {
			$this->results = Repository::search( $this->filters, $this->results['total_pages'], $per_page, $this->sort, $need_total, $known_total );
		}

		$this->renderer = new Renderer( $config );
		if ( '' !== $base_url ) {
			$this->renderer->link_pages( $base_url, $this->url_params(), $this->instance );
		}
	}

	/**
	 * Combine preset and visitor filters. Presets cannot be widened: a preset
	 * value wins, and for ranges the stricter bound is used.
	 *
	 * @param array $preset  Preset filters.
	 * @param array $request Visitor filters.
	 * @return array<string, mixed>
	 */
	public static function merge_filters( array $preset, array $request ): array {
		$filters = array_merge( $request, $preset );
		foreach ( $request as $key => $value ) {
			if ( ! isset( $preset[ $key ] ) ) {
				continue;
			}
			if ( str_ends_with( $key, '_from' ) ) {
				$filters[ $key ] = max( (float) $preset[ $key ], (float) $value );
			} elseif ( str_ends_with( $key, '_to' ) ) {
				$filters[ $key ] = min( (float) $preset[ $key ], (float) $value );
			}
		}
		return $filters;
	}

	/**
	 * Layout after the visitor's grid / list choice.
	 *
	 * @param string $layout Configured layout.
	 * @param string $view   Visitor view.
	 */
	public static function effective_layout( string $layout, string $view ): string {
		if ( 'list' === $view ) {
			return 'list';
		}
		if ( 'grid' === $view ) {
			return in_array( $layout, array( 'card', 'grid' ), true ) ? $layout : 'card';
		}
		return $layout;
	}

	/**
	 * Allowed visitor choices for vehicles per page.
	 *
	 * @param array $config Configuration.
	 * @return int[]
	 */
	public static function per_page_choices( array $config ): array {
		$choices   = array_map( 'absint', explode( ',', (string) $config['per_page_options'] ) );
		$choices   = array_filter( $choices, static fn( int $value ) => $value >= 1 && $value <= 48 );
		$choices[] = (int) ( $config['per_page_default'] ?? $config['per_page'] );
		$choices   = array_values( array_unique( $choices ) );
		sort( $choices );
		return $choices;
	}

	/**
	 * Instance-scoped URL parameters for the visitor's choices (filters,
	 * non-default sort, per page and view). Used in page links.
	 *
	 * @return array<string, string>
	 */
	public function url_params(): array {
		$params = Shortcode::url_params( $this->request_filters, $this->sort, (string) $this->config['sort'], $this->instance );
		if ( (int) $this->config['per_page'] !== (int) $this->config['per_page_default'] ) {
			$params[ Shortcode::request_key( 'per_page', $this->instance ) ] = (string) $this->config['per_page'];
		}
		if ( '' !== $this->view ) {
			$params[ Shortcode::request_key( 'view', $this->instance ) ] = $this->view;
		}
		return $params;
	}

	/**
	 * Whether the make / model control is shown.
	 */
	public function shows_make_control(): bool {
		return $this->config['show_filters']
			&& 'hidden' !== $this->config['make_model_mode']
			&& in_array( 'make', (array) $this->config['filters'], true );
	}

	/**
	 * Vehicle counts per value of the shown choice filters (fuel, body type, …)
	 * for the current filters.
	 *
	 * @return array<string, array<string, int>>
	 */
	public function choice_counts(): array {
		if ( null === $this->choice_counts ) {
			$map     = array(
				'category'     => 'vehicle_category',
				'fuel'         => 'fuel',
				'transmission' => 'transmission',
				'body'         => 'body_type',
				'drive'        => 'drive_type',
				'condition'    => 'condition',
			);
			$targets = array();
			if ( $this->config['show_filters'] ) {
				foreach ( (array) $this->config['filters'] as $key ) {
					if ( isset( $map[ $key ] ) && ! isset( $this->preset_filters[ $map[ $key ] ] ) ) {
						$targets[] = $map[ $key ];
					}
				}
			}
			$this->choice_counts = $targets ? Repository::choice_counts( $this->filters, $targets ) : array();
		}
		return $this->choice_counts;
	}

	/**
	 * Vehicle counts per make and model for the current filters.
	 *
	 * @return array{makes: array<string, int>, models: array<string, array<string, int>>, total: int}
	 */
	public function facets(): array {
		if ( null === $this->facets ) {
			$this->facets = $this->shows_make_control() ? Repository::facets( $this->filters ) : array(
				'makes'  => array(),
				'models' => array(),
				'total'  => 0,
			);
		}
		return $this->facets;
	}
}
