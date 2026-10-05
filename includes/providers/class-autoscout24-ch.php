<?php
/**
 * AutoScout24 Switzerland provider.
 *
 * @package DealerInventory
 */

namespace DealerInventory\Providers;

use DealerInventory\Connection;
use DealerInventory\Crypto;
use DealerInventory\Format;
use DealerInventory\Logger;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client for the AutoScout24 Switzerland public API (api.autoscout24.ch).
 *
 * Authentication is OAuth 2 client credentials. Each dealer requests their own
 * client id and secret from AutoScout24 Switzerland. Only read endpoints are
 * used: listing search and the seller profile.
 */
final class AutoScout24_CH implements Provider {

	public const ID = 'autoscout24_ch';

	private const PRODUCTION_BASE = 'https://api.autoscout24.ch';
	private const PREPROD_BASE    = 'https://api.preprod.autoscout24.dev';
	private const AUDIENCE        = 'https://api.autoscout24.ch';
	private const IMAGE_HOST      = 'images.autoscout24.ch';
	private const PAGE_SIZE       = 20;
	private const MAX_PAGES       = 1000;

	/**
	 * Provider id.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Admin label.
	 */
	public function label(): string {
		return __( 'AutoScout24 Switzerland (autoscout24.ch)', 'dealer-inventory-for-autoscout24' );
	}

	/**
	 * Country code.
	 */
	public function country(): string {
		return 'CH';
	}

	/**
	 * Price currency.
	 */
	public function currency(): string {
		return 'CHF';
	}

	/**
	 * Supported content languages.
	 *
	 * @return string[]
	 */
	public function languages(): array {
		return array( 'de', 'fr', 'it' );
	}

	/**
	 * Optional features.
	 *
	 * @return string[]
	 */
	public function features(): array {
		return array( 'warranty' );
	}

	/**
	 * Authenticate, read the seller and probe listing search.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $language   Content language.
	 * @return array|WP_Error
	 */
	public function test_connection( Connection $connection, string $language ): array|WP_Error {
		if ( ! $connection->is_configured() ) {
			return new WP_Error(
				'dinv_not_configured',
				__( 'Client ID, Client Secret and Seller ID are required.', 'dealer-inventory-for-autoscout24' )
			);
		}

		$token = $this->access_token( $connection, true );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$seller = $this->fetch_seller( $connection );
		if ( is_wp_error( $seller ) ) {
			return $seller;
		}

		// A seller lookup alone does not prove that listing search works for
		// this account, so probe the exact read path used by synchronization.
		$probe = $this->search( $connection, array(), 0, 1, $language );
		if ( is_wp_error( $probe ) ) {
			return new WP_Error(
				'dinv_listing_search_failed',
				sprintf(
					/* translators: %s: error message returned by the API. */
					__( 'The seller was found, but listing search failed: %s', 'dealer-inventory-for-autoscout24' ),
					$probe->get_error_message()
				),
				$probe->get_error_data()
			);
		}

		return $seller;
	}

	/**
	 * Download all listings of the seller.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $language   Content language.
	 * @param callable   $on_row     Row callback.
	 * @return int|WP_Error
	 */
	public function fetch_listings( Connection $connection, string $language, callable $on_row ): int|WP_Error {
		$page     = 0;
		$received = 0;

		do {
			$response = $this->search( $connection, array(), $page, self::PAGE_SIZE, $language );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$items = is_array( $response['content'] ?? null ) ? $response['content'] : array();
			foreach ( $items as $index => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$row = $this->normalize( $item, $connection );
				if ( null === $row ) {
					continue;
				}
				$on_row( $row, ( $page * self::PAGE_SIZE ) + $index );
				++$received;
			}

			$last        = (bool) ( $response['last'] ?? true );
			$total_pages = max( 1, (int) ( $response['totalPages'] ?? 1 ) );
			++$page;
		} while ( ! $last && $page < $total_pages && $page < self::MAX_PAGES );

		// Stopped at the page cap: the list is incomplete, so nothing may be hidden as "missing".
		if ( ! $last && $page < $total_pages ) {
			return new WP_Error( 'dinv_too_many_pages', __( 'The stock is too large to download in one sync. No vehicles were hidden.', 'dealer-inventory-for-autoscout24' ) );
		}

		return $received;
	}

	/**
	 * Ids of listings with a warranty (one paged query for the whole stock).
	 *
	 * @param Connection $connection Connection.
	 * @param string     $language   Content language.
	 * @return int[]|WP_Error
	 */
	public function fetch_warranty_ids( Connection $connection, string $language ): array|WP_Error {
		$ids  = array();
		$page = 0;

		do {
			$response = $this->search( $connection, array( 'hasWarrantyOnly' => true ), $page, self::PAGE_SIZE, $language );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			foreach ( (array) ( $response['content'] ?? array() ) as $item ) {
				$id = absint( $item['id'] ?? 0 );
				if ( $id ) {
					$ids[] = $id;
				}
			}

			$last        = ! empty( $response['last'] );
			$total_pages = max( 1, (int) ( $response['totalPages'] ?? 1 ) );
			++$page;
		} while ( ! $last && $page < min( $total_pages, self::MAX_PAGES ) );

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Listing detail and equipment (two requests).
	 *
	 * @param Connection $connection Connection.
	 * @param int        $listing_id Listing id.
	 * @param string     $language   Content language.
	 * @return array|WP_Error
	 */
	public function fetch_listing_detail( Connection $connection, int $listing_id, string $language ): array|WP_Error {
		$lang   = rawurlencode( $this->language( $language ) );
		$detail = $this->request( $connection, 'GET', '/public/v1/listings/' . $listing_id . '?language=' . $lang );
		if ( is_wp_error( $detail ) ) {
			return $detail;
		}

		$specs = array();
		foreach ( array( 'bodyColor', 'interiorColor' ) as $key ) {
			if ( isset( $detail[ $key ] ) && is_scalar( $detail[ $key ] ) ) {
				$specs[ $key ] = sanitize_key( (string) $detail[ $key ] );
			}
		}
		foreach ( array( 'doors', 'seats', 'cylinders', 'cubicCapacity', 'co2Emission', 'gears', 'weight' ) as $key ) {
			if ( isset( $detail[ $key ] ) && is_numeric( $detail[ $key ] ) ) {
				$specs[ $key ] = (int) $detail[ $key ];
			}
		}
		foreach ( array( 'batteryCapacity', 'chargingPower' ) as $key ) {
			if ( isset( $detail[ $key ] ) && is_numeric( $detail[ $key ] ) ) {
				$specs[ $key ] = (float) $detail[ $key ];
			}
		}
		$date = self::date_or_null( $detail['lastInspectionDate'] ?? null );
		if ( null !== $date ) {
			$specs['lastInspectionDate'] = $date;
		}

		$warranty = is_array( $detail['warranty'] ?? null ) ? $detail['warranty'] : array();
		if ( $warranty && 'none' !== ( $warranty['type'] ?? '' ) ) {
			$specs['warrantyMonths'] = self::nullable_int( $warranty['duration'] ?? null );
			$specs['warrantyKm']     = self::nullable_int( $warranty['mileage'] ?? null );
			$specs['warrantyText']   = sanitize_text_field( (string) ( $warranty['details'] ?? '' ) );
		}

		$images = array();
		foreach ( (array) ( $detail['images'] ?? array() ) as $image ) {
			$url = is_array( $image ) ? esc_url_raw( (string) ( $image['url'] ?? '' ) ) : '';
			if ( '' !== $url ) {
				$images[] = $url;
			}
		}

		$equipment = array();
		$items     = $this->request( $connection, 'GET', '/public/v1/listings/' . $listing_id . '/equipment?language=' . $lang );
		if ( ! is_wp_error( $items ) ) {
			foreach ( array( 'standard', 'optional' ) as $kind ) {
				foreach ( (array) ( $items[ $kind ] ?? array() ) as $item ) {
					if ( ! is_array( $item ) || empty( $item['name'] ) ) {
						continue;
					}
					$equipment[] = sanitize_text_field( (string) $item['name'] );
					foreach ( (array) ( $item['packageItems'] ?? array() ) as $package_item ) {
						if ( is_array( $package_item ) && ! empty( $package_item['name'] ) ) {
							$equipment[] = sanitize_text_field( (string) $package_item['name'] );
						}
					}
				}
			}
		}

		return array(
			'description' => Format::description_html( (string) ( $detail['description'] ?? '' ) ),
			'specs'       => array_filter( $specs, static fn( $value ) => null !== $value && '' !== $value ),
			'equipment'   => array_values( array_unique( array_filter( $equipment ) ) ),
			'images'      => array_slice( $images, 0, 40 ),
		);
	}

	/**
	 * Seller profile.
	 *
	 * @param Connection $connection Connection.
	 * @return array|WP_Error
	 */
	public function fetch_seller( Connection $connection ): array|WP_Error {
		$seller = $this->request( $connection, 'GET', '/public/v1/sellers/' . $connection->seller_id );
		if ( is_wp_error( $seller ) ) {
			return $seller;
		}

		$profile = array();
		foreach ( array( 'name', 'address', 'zipCode', 'city', 'phoneNumber' ) as $key ) {
			if ( isset( $seller[ $key ] ) && is_scalar( $seller[ $key ] ) ) {
				$profile[ $key ] = sanitize_text_field( (string) $seller[ $key ] );
			}
		}

		return $profile;
	}

	/**
	 * Listing URL on autoscout24.ch.
	 *
	 * @param array  $vehicle  Vehicle row.
	 * @param string $language Two-letter language.
	 */
	public function listing_url( array $vehicle, string $language ): string {
		$external_id = absint( $vehicle['external_id'] ?? 0 );
		if ( ! $external_id ) {
			return '';
		}

		$slug = sanitize_title( \DealerInventory\Vehicle::title( $vehicle ) );
		if ( '' === $slug ) {
			$slug = 'vehicle';
		}

		$language = in_array( $language, $this->languages(), true ) ? $language : 'de';

		return sprintf(
			'https://www.autoscout24.ch/%s/d/%s-%d',
			rawurlencode( $language ),
			rawurlencode( $slug ),
			$external_id
		);
	}

	/**
	 * Only official autoscout24.ch pages are accepted as dealer links.
	 *
	 * @param string $url URL.
	 */
	public function is_valid_dealer_url( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return 'autoscout24.ch' === $host || str_ends_with( $host, '.autoscout24.ch' );
	}

	/**
	 * Resized image from the AutoScout24 image CDN.
	 *
	 * @param string $url   Original URL.
	 * @param int    $width Requested width.
	 */
	public function image_url( string $url, int $width ): string {
		if ( '' === $url ) {
			return '';
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( self::IMAGE_HOST !== $host ) {
			// Only AutoScout24 hosts are loaded by visitors' browsers (see the privacy policy text).
			return ( 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) && str_ends_with( $host, '.autoscout24.ch' ) ) ? esc_url_raw( $url ) : '';
		}
		$allowed = array( 256, 320, 360, 384, 768, 1024, 1280, 1920 );
		$width   = in_array( $width, $allowed, true ) ? $width : 768;
		return esc_url_raw( add_query_arg( 'w', $width, $url ) );
	}

	/**
	 * Widths used for srcset.
	 *
	 * @return int[]
	 */
	public function image_widths(): array {
		return array( 320, 384, 768, 1024 );
	}

	/**
	 * Preconnect origins.
	 *
	 * @return string[]
	 */
	public function asset_origins(): array {
		return array( 'https://' . self::IMAGE_HOST );
	}

	/**
	 * Map one API listing to vehicle table columns.
	 *
	 * @param array      $item       API listing.
	 * @param Connection $connection Connection.
	 * @return array|null
	 */
	public function normalize( array $item, Connection $connection ): ?array {
		$id = absint( $item['id'] ?? 0 );
		if ( ! $id ) {
			return null;
		}

		$make        = is_array( $item['make'] ?? null ) ? $item['make'] : array();
		$model       = is_array( $item['model'] ?? null ) ? $item['model'] : array();
		$seller      = is_array( $item['seller'] ?? null ) ? $item['seller'] : array();
		$consumption = is_array( $item['consumption'] ?? null ) ? $item['consumption'] : array();
		$quali       = is_array( $item['qualiLogo'] ?? null ) ? $item['qualiLogo'] : array();
		$leasing     = is_array( $item['leasing'] ?? null ) ? $item['leasing'] : array();

		$images = array();
		foreach ( (array) ( $item['images'] ?? array() ) as $image ) {
			if ( is_array( $image ) && ! empty( $image['url'] ) ) {
				$url = esc_url_raw( (string) $image['url'] );
				if ( '' !== $url ) {
					$images[] = $url;
				}
			}
			if ( count( $images ) >= 30 ) {
				break;
			}
		}

		return array(
			'connection_id'           => $connection->id,
			'external_id'             => $id,
			'seller_id'               => absint( $seller['id'] ?? $connection->seller_id ),
			'seller_vehicle_id'       => sanitize_text_field( (string) ( $item['sellerVehicleId'] ?? '' ) ),
			'vehicle_category'        => sanitize_key( (string) ( $item['vehicleCategory'] ?? '' ) ),
			'make_key'                => sanitize_key( (string) ( $make['key'] ?? '' ) ),
			'make_name'               => sanitize_text_field( (string) ( $make['name'] ?? '' ) ),
			'model_key'               => sanitize_key( (string) ( $model['key'] ?? '' ) ),
			'model_name'              => sanitize_text_field( (string) ( $model['name'] ?? '' ) ),
			'version_full_name'       => sanitize_text_field( (string) ( $item['versionFullName'] ?? '' ) ),
			'teaser'                  => sanitize_textarea_field( (string) ( $item['teaser'] ?? '' ) ),
			'price'                   => self::nullable_float( $item['price'] ?? null ),
			'previous_price'          => self::nullable_float( $item['previousPrice'] ?? null ),
			'list_price'              => self::nullable_float( $item['listPrice'] ?? null ),
			'mileage'                 => self::nullable_int( $item['mileage'] ?? null ),
			'first_registration_date' => self::date_or_null( $item['firstRegistrationDate'] ?? null ),
			'first_registration_year' => self::nullable_int( $item['firstRegistrationYear'] ?? null ),
			'fuel_type'               => sanitize_key( (string) ( $item['fuelType'] ?? '' ) ),
			'transmission_type'       => sanitize_key( (string) ( $item['transmissionType'] ?? '' ) ),
			'transmission_group'      => sanitize_key( (string) ( $item['transmissionTypeGroup'] ?? '' ) ),
			'body_type'               => sanitize_key( (string) ( $item['bodyType'] ?? '' ) ),
			'condition_type'          => sanitize_key( (string) ( $item['conditionType'] ?? '' ) ),
			'drive_type'              => sanitize_key( (string) ( $item['driveType'] ?? '' ) ),
			'horse_power'             => self::nullable_int( $item['horsePower'] ?? null ),
			'kilo_watts'              => self::nullable_int( $item['kiloWatts'] ?? null ),
			'consumption_combined'    => self::nullable_float( $consumption['combined'] ?? null ),
			'co2_emission'            => self::nullable_int( $item['co2Emission'] ?? null ),
			'range_km'                => self::nullable_int( $item['range'] ?? null ),
			'image_url'               => $images[0] ?? '',
			'images_json'             => $images ? (string) wp_json_encode( $images ) : null,
			'quali_logo_image_url'    => esc_url_raw( (string) ( $quali['imageUrl'] ?? '' ) ),
			'leasing_monthly_rate'    => self::nullable_float( $leasing['monthlyRate'] ?? null ),
		);
	}

	/**
	 * Listing search request.
	 *
	 * @param Connection $connection Connection.
	 * @param array      $query      Extra ListingQuery fields.
	 * @param int        $page       Zero-based page.
	 * @param int        $size       Page size (max 20).
	 * @param string     $language   Content language.
	 * @return array|WP_Error
	 */
	private function search( Connection $connection, array $query, int $page, int $size, string $language ): array|WP_Error {
		$query['sellerIds'] = array( $connection->seller_id );

		return $this->request(
			$connection,
			'POST',
			'/public/v1/listings/search?language=' . rawurlencode( $this->language( $language ) ),
			array(
				'query'      => $query,
				'pagination' => array(
					'page' => max( 0, $page ),
					'size' => max( 1, min( self::PAGE_SIZE, $size ) ),
				),
			)
		);
	}

	/**
	 * OAuth access token, cached encrypted in a transient.
	 *
	 * @param Connection $connection Connection.
	 * @param bool       $force      Skip the cache.
	 * @return string|WP_Error
	 */
	private function access_token( Connection $connection, bool $force = false ): string|WP_Error {
		if ( '' === $connection->client_id || '' === $connection->client_secret ) {
			return new WP_Error(
				'dinv_missing_credentials',
				$connection->secret_unreadable
					? __( 'The stored Client Secret can no longer be decrypted (the WordPress security keys changed). Please enter it again.', 'dealer-inventory-for-autoscout24' )
					: __( 'Client ID or Client Secret is missing.', 'dealer-inventory-for-autoscout24' )
			);
		}

		$cache_key = 'dinv_token_' . substr( hash( 'sha256', $connection->client_id . '|' . self::base_url() ), 0, 24 );

		if ( $force ) {
			delete_transient( $cache_key );
		} else {
			$cached = get_transient( $cache_key );
			if ( is_string( $cached ) && '' !== $cached ) {
				$token = Crypto::decrypt( $cached );
				if ( '' !== $token ) {
					return $token;
				}
				delete_transient( $cache_key );
			}
		}

		$response = wp_safe_remote_post(
			self::base_url() . '/public/v1/clients/oauth/token',
			array(
				'timeout'     => 12,
				'redirection' => 0,
				'headers'     => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'        => array(
					'client_id'     => $connection->client_id,
					'client_secret' => $connection->client_secret,
					'grant_type'    => 'client_credentials',
					'audience'      => self::AUDIENCE,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::log( 'error', 'oauth_failed', 'OAuth request failed.', array( 'reason' => $response->get_error_message() ) );
			return new WP_Error( 'dinv_oauth_transport', __( 'The AutoScout24 authentication server could not be reached.', 'dealer-inventory-for-autoscout24' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $status || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			Logger::log( 'error', 'oauth_failed', 'OAuth authentication failed.', array( 'http_status' => $status ) );
			return new WP_Error( 'dinv_oauth_failed', __( 'AutoScout24 rejected the credentials. Check the Client ID and Client Secret.', 'dealer-inventory-for-autoscout24' ) );
		}

		$expires_in = max( 60, (int) ( $data['expires_in'] ?? 3600 ) );
		$ttl        = max( 60, $expires_in - min( 300, (int) floor( $expires_in * 0.1 ) ) );
		$encrypted  = Crypto::encrypt( (string) $data['access_token'] );
		if ( '' !== $encrypted ) {
			set_transient( $cache_key, $encrypted, $ttl );
		}

		return (string) $data['access_token'];
	}

	/**
	 * Authenticated JSON request with one automatic token refresh on 401.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $method     HTTP method.
	 * @param string     $path       Path below the API base.
	 * @param array|null $payload    JSON body.
	 * @param bool       $retry_auth Retry once with a fresh token on 401.
	 * @return array|WP_Error
	 */
	private function request( Connection $connection, string $method, string $path, ?array $payload = null, bool $retry_auth = true ): array|WP_Error {
		$token = $this->access_token( $connection );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_safe_remote_request(
			self::base_url() . '/' . ltrim( $path, '/' ),
			array(
				'method'      => strtoupper( $method ),
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array(
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
				'body'        => null === $payload ? null : wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::log(
				'error',
				'api_transport_error',
				'AutoScout24 API request failed.',
				array(
					'path'   => $path,
					'reason' => $response->get_error_message(),
				)
			);
			return new WP_Error( 'dinv_api_transport', __( 'The AutoScout24 API is temporarily unavailable.', 'dealer-inventory-for-autoscout24' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $status && $retry_auth ) {
			$fresh = $this->access_token( $connection, true );
			if ( is_wp_error( $fresh ) ) {
				return $fresh;
			}
			return $this->request( $connection, $method, $path, $payload, false );
		}

		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = '' !== $raw ? json_decode( $raw, true ) : array();

		if ( $status >= 200 && $status < 300 ) {
			if ( '' === $raw ) {
				return array( '_status' => $status );
			}
			if ( ! is_array( $data ) ) {
				Logger::log(
					'error',
					'api_invalid_json',
					'AutoScout24 returned an invalid JSON response.',
					array(
						'path'        => $path,
						'http_status' => $status,
					)
				);
				return new WP_Error( 'dinv_invalid_json', __( 'AutoScout24 returned an invalid response.', 'dealer-inventory-for-autoscout24' ) );
			}
			return $data;
		}

		return $this->error_from_response( $response, $status, $data, $path );
	}

	/**
	 * Build a diagnostic WP_Error from an API error response.
	 *
	 * Diagnostics never include rejected values, credentials or tokens.
	 *
	 * @param array|\WP_HTTP_Response $response Raw response.
	 * @param int                     $status   HTTP status.
	 * @param mixed                   $data     Decoded body.
	 * @param string                  $path     Request path.
	 */
	private function error_from_response( $response, int $status, $data, string $path ): WP_Error {
		$code         = is_array( $data ) ? sanitize_text_field( (string) ( $data['code'] ?? '' ) ) : '';
		$description  = is_array( $data ) ? sanitize_text_field( (string) ( $data['description'] ?? '' ) ) : '';
		$field_errors = array();

		if ( is_array( $data ) && is_array( $data['fieldErrors'] ?? null ) ) {
			foreach ( $data['fieldErrors'] as $field_error ) {
				if ( ! is_array( $field_error ) ) {
					continue;
				}
				$parts = array_filter(
					array(
						sanitize_text_field( (string) ( $field_error['path'] ?? $field_error['property'] ?? '' ) ),
						sanitize_text_field( (string) ( $field_error['code'] ?? '' ) ),
						sanitize_text_field( (string) ( $field_error['message'] ?? '' ) ),
					),
					static fn( $part ) => '' !== $part
				);
				if ( $parts ) {
					$field_errors[] = implode( ': ', $parts );
				}
			}
		}

		$detail = trim( implode( ' ', array_filter( array( $code, $description !== $code ? $description : '', $field_errors ? 'Fields: ' . implode( '; ', $field_errors ) : '' ) ) ) );

		Logger::log(
			'error',
			'api_error',
			'AutoScout24 API returned an error. ' . $detail,
			array(
				'path'        => $path,
				'http_status' => $status,
				'retry_after' => (string) wp_remote_retrieve_header( $response, 'retry-after' ),
			)
		);

		switch ( $status ) {
			case 429:
				return new WP_Error( 'dinv_rate_limited', __( 'AutoScout24 rate limit reached. Please try again later.', 'dealer-inventory-for-autoscout24' ) );
			case 403:
				return new WP_Error( 'dinv_forbidden', __( 'These AutoScout24 credentials are not allowed to read this seller\'s listings.', 'dealer-inventory-for-autoscout24' ) );
			case 404:
				return new WP_Error( 'dinv_not_found', __( 'AutoScout24 could not find the requested seller or listing. Check the Seller ID.', 'dealer-inventory-for-autoscout24' ) );
		}

		$message = __( 'The AutoScout24 API request failed.', 'dealer-inventory-for-autoscout24' );
		if ( '' !== $detail ) {
			$message .= ' ' . $detail;
		}

		return new WP_Error(
			'dinv_api_error',
			$message,
			array(
				'status'       => $status,
				'code'         => $code,
				'field_errors' => $field_errors,
			)
		);
	}

	/**
	 * API base URL. DINV_API_BASE_URL may switch to the pre-production API.
	 */
	public static function base_url(): string {
		$base = defined( 'DINV_API_BASE_URL' ) ? untrailingslashit( (string) DINV_API_BASE_URL ) : self::PRODUCTION_BASE;
		return in_array( $base, array( self::PRODUCTION_BASE, self::PREPROD_BASE ), true ) ? $base : self::PRODUCTION_BASE;
	}

	/**
	 * Supported API language.
	 *
	 * @param string $language Requested language.
	 */
	private function language( string $language ): string {
		$language = strtolower( substr( $language, 0, 2 ) );
		return in_array( $language, $this->languages(), true ) ? $language : $this->languages()[0];
	}

	/**
	 * Int or null.
	 *
	 * @param mixed $value Value.
	 */
	private static function nullable_int( $value ): ?int {
		return is_numeric( $value ) ? (int) $value : null;
	}

	/**
	 * Float or null.
	 *
	 * @param mixed $value Value.
	 */
	private static function nullable_float( $value ): ?float {
		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * Y-m-d date or null.
	 *
	 * @param mixed $value Value.
	 */
	private static function date_or_null( $value ): ?string {
		return is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : null;
	}
}
