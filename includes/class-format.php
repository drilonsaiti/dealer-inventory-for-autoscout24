<?php
/**
 * Locale-aware formatting of prices, numbers and units.
 *
 * @package DealerInventory
 */

namespace DealerInventory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Formats values for the visitor's locale.
 *
 * Prices use PHP intl when available ("CHF 59’900" for de_CH, "59.900 €" for
 * de_DE) and fall back to WordPress number formatting.
 */
final class Format {

	/**
	 * Formatted price.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency ISO 4217 code.
	 */
	public static function price( float $amount, string $currency ): string {
		$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', $currency ) );
		$currency = 3 === strlen( $currency ) ? $currency : 'CHF';

		/**
		 * Short-circuit price formatting.
		 *
		 * @param string|null $formatted Formatted price or null.
		 * @param float       $amount    Amount.
		 * @param string      $currency  Currency code.
		 */
		$filtered = apply_filters( 'dinv_format_price', null, $amount, $currency );
		if ( is_string( $filtered ) ) {
			return $filtered;
		}

		$formatter = self::formatter( \NumberFormatter::CURRENCY );
		if ( $formatter ) {
			$formatted = $formatter->formatCurrency( $amount, $currency );
			if ( is_string( $formatted ) && '' !== $formatted ) {
				// Non-breaking spaces keep "CHF 59 900" on one line.
				return $formatted;
			}
		}

		return $currency . "\u{00A0}" . number_format_i18n( $amount, 0 );
	}

	/**
	 * Whole number with locale separators.
	 *
	 * @param int $value Value.
	 */
	public static function integer( int $value ): string {
		$formatter = self::formatter( \NumberFormatter::DECIMAL );
		if ( $formatter ) {
			$formatted = $formatter->format( $value );
			if ( is_string( $formatted ) && '' !== $formatted ) {
				return $formatted;
			}
		}
		return number_format_i18n( $value );
	}

	/**
	 * "54 300 km".
	 *
	 * @param int $km Kilometres.
	 */
	public static function mileage( int $km ): string {
		/* translators: %s: formatted number of kilometres. */
		return sprintf( _x( '%s km', 'mileage', 'dealer-inventory-for-autoscout24' ), self::integer( $km ) );
	}

	/**
	 * Engine power in the configured unit.
	 *
	 * @param int|null $hp   Horsepower.
	 * @param int|null $kw   Kilowatts.
	 * @param string   $unit "hp", "kw" or "both".
	 */
	public static function power( ?int $hp, ?int $kw, string $unit ): string {
		if ( ! $hp && $kw ) {
			$hp = (int) round( $kw * 1.35962 );
		}
		if ( ! $kw && $hp ) {
			$kw = (int) round( $hp * 0.73549875 );
		}
		if ( ! $hp ) {
			return '';
		}

		/* translators: %s: engine power in horsepower. German: PS, French: ch, Italian: CV. */
		$hp_text = sprintf( _x( '%s hp', 'engine power', 'dealer-inventory-for-autoscout24' ), self::integer( $hp ) );
		/* translators: %s: engine power in kilowatts. */
		$kw_text = sprintf( _x( '%s kW', 'engine power', 'dealer-inventory-for-autoscout24' ), self::integer( (int) $kw ) );

		switch ( $unit ) {
			case 'kw':
				return $kw_text;
			case 'both':
				return $hp_text . ' (' . $kw_text . ')';
			default:
				return $hp_text;
		}
	}

	/**
	 * First registration, "03/2021" (format translatable per locale).
	 *
	 * @param string     $date   Y-m-d.
	 * @param int|string $year   Fallback year.
	 * @param string     $format "auto" (translated) or a PHP date format: m/Y, m.Y, Y.
	 */
	public static function registration( string $date, $year, string $format = 'auto' ): string {
		if ( preg_match( '/^(\d{4})-(\d{2})-\d{2}$/', $date ) ) {
			$timestamp = strtotime( $date . ' 12:00:00 UTC' );
			if ( false !== $timestamp ) {
				if ( ! in_array( $format, array( 'm/Y', 'm.Y', 'Y' ), true ) ) {
					/* translators: PHP date format for the first registration month, see https://www.php.net/date. German: m.Y */
					$format = _x( 'm/Y', 'first registration date format', 'dealer-inventory-for-autoscout24' );
				}
				return wp_date( $format, $timestamp, new \DateTimeZone( 'UTC' ) );
			}
		}
		return $year ? (string) $year : '';
	}

	/**
	 * Combined fuel consumption.
	 *
	 * @param float $liters Litres per 100 km.
	 */
	public static function consumption( float $liters ): string {
		/* translators: %s: litres per 100 kilometres. */
		return sprintf( _x( '%s l/100 km', 'fuel consumption', 'dealer-inventory-for-autoscout24' ), number_format_i18n( $liters, 1 ) );
	}

	/**
	 * Electric range.
	 *
	 * @param int $km Kilometres.
	 */
	public static function range( int $km ): string {
		/* translators: %s: formatted electric range in kilometres. */
		return sprintf( _x( '%s km range', 'electric range', 'dealer-inventory-for-autoscout24' ), self::integer( $km ) );
	}

	/**
	 * Currency for the current configuration.
	 *
	 * @param string $setting "auto" or a currency code.
	 */
	public static function currency( string $setting ): string {
		return 'auto' === $setting || '' === $setting
			? Connection::current()->provider()->currency()
			: strtoupper( $setting );
	}

	/**
	 * Cached intl formatter for the current locale (null without intl).
	 *
	 * @param int $style NumberFormatter style.
	 */
	private static function formatter( int $style ): ?\NumberFormatter {
		static $formatters = array();

		if ( ! class_exists( '\NumberFormatter' ) ) {
			return null;
		}

		$key = determine_locale() . '|' . $style;
		if ( ! array_key_exists( $key, $formatters ) ) {
			$formatter = new \NumberFormatter( determine_locale(), $style );
			$formatter->setAttribute( \NumberFormatter::MIN_FRACTION_DIGITS, 0 );
			$formatter->setAttribute( \NumberFormatter::MAX_FRACTION_DIGITS, 0 );
			$formatters[ $key ] = $formatter;
		}

		return $formatters[ $key ];
	}

	/**
	 * Vehicle description HTML: text formatting only. Links, images, forms
	 * and styles from the marketplace are removed.
	 *
	 * @param string $html Description.
	 */
	public static function description_html( string $html ): string {
		$allowed = array_fill_keys( array( 'p', 'br', 'ul', 'ol', 'li', 'strong', 'em', 'b', 'i', 'u', 'h3', 'h4' ), array() );
		return trim( wp_kses( $html, $allowed ) );
	}
}
