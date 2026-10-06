<?php
/**
 * Filter form (top bar or sidebar; off-canvas drawer on small screens).
 *
 * Without JavaScript the form submits as a normal GET request, so every
 * filter also works on cached pages and for crawlers.
 *
 * Override: yourtheme/dealer-inventory/parts/filters.php
 *
 * @package DealerInventory
 *
 * @var array  $config        Instance configuration.
 * @var string $instance      Instance name.
 * @var array  $filters       Active filters.
 * @var array  $preset_filters Filters fixed by the shortcode / block.
 * @var array  $options       Filter options.
 * @var array  $tree          Makes with models.
 * @var array  $facets        Counts for the active filters.
 * @var array  $choice_counts Counts per choice (fuel, body type, …) for the active filters.
 * @var array  $labels        Interface labels.
 * @var array  $preset_params Preset filters as request parameters.
 * @var string $uid           Unique element id.
 */

use DealerInventory\Labels;
use DealerInventory\Renderer;
use DealerInventory\Schema;
use DealerInventory\Settings;
use DealerInventory\Shortcode;
use DealerInventory\Template;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dinv_form_id = $uid . '-filters';

// Other query arguments of the page (e.g. ?lang=fr) survive a GET submit.
$dinv_keep = array();
foreach ( $_GET as $dinv_key => $dinv_value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, values are only echoed escaped.
	$dinv_key = (string) $dinv_key;
	if ( ! str_starts_with( $dinv_key, 'dinv_' ) && is_scalar( $dinv_value ) && preg_match( '/^[A-Za-z0-9_\-]{1,64}$/', $dinv_key ) ) {
		$dinv_keep[ $dinv_key ] = sanitize_text_field( wp_unslash( (string) $dinv_value ) );
	}
}

if ( ! $config['show_filters'] ) :
	?>
	<form id="<?php echo esc_attr( $dinv_form_id ); ?>" data-dinv-form method="get" hidden>
		<?php foreach ( $dinv_keep as $dinv_name => $dinv_value ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $dinv_name ); ?>" value="<?php echo esc_attr( $dinv_value ); ?>">
		<?php endforeach; ?>
	</form>
	<?php
	return;
endif;

$dinv_position = 'sidebar' === $config['filter_position'] ? 'sidebar' : 'top';
$dinv_names    = Schema::filter_labels();
$dinv_parts    = get_defined_vars();

$dinv_enabled = array_values(
	array_filter(
		(array) $config['filters'],
		static function ( string $key ) use ( $config ): bool {
			if ( 'make' === $key ) {
				return 'hidden' !== $config['make_model_mode'];
			}
			if ( 'warranty' === $key ) {
				return (bool) Settings::get( 'sync_warranty', true );
			}
			return true;
		}
	)
);

$dinv_choice_groups = array(
	'category'     => array( 'categories', 'category', 'vehicle_category' ),
	'fuel'         => array( 'fuels', 'fuel', 'fuel' ),
	'body'         => array( 'body_types', 'body', 'body_type' ),
	'transmission' => array( 'transmissions', 'transmission', 'transmission' ),
	'drive'        => array( 'drive_types', 'drive', 'drive_type' ),
	'condition'    => array( 'conditions', 'condition', 'condition' ),
);

/**
 * Render one filter control.
 *
 * @param string $key Filter key.
 */
$dinv_choice_counts = isset( $choice_counts ) ? (array) $choice_counts : array();

$dinv_field = static function ( string $key ) use ( $config, $instance, $filters, $preset_filters, $options, $labels, $uid, $dinv_names, $dinv_choice_groups, $dinv_parts, $dinv_choice_counts ): string {
	$name  = static fn( string $param ): string => Shortcode::request_key( $param, $instance );
	$id    = $uid . '-f-' . $key;
	$label = $dinv_names[ $key ] ?? $key;

	if ( 'make' === $key ) {
		return Template::render( 'parts/filter-make.php', $dinv_parts );
	}

	if ( in_array( $key, array( 'price', 'year', 'mileage', 'power' ), true ) ) {
		return Template::render( 'parts/filter-range.php', array_merge( $dinv_parts, array( 'range' => $key ) ) );
	}

	if ( isset( $dinv_choice_groups[ $key ] ) ) {
		list( $group, $enum, $target ) = $dinv_choice_groups[ $key ];
		$rows                          = (array) ( $options[ $group ] ?? array() );
		$current                       = (string) ( $filters[ $target ] ?? '' );
		if ( isset( $preset_filters[ $target ] ) || ( count( $rows ) < 2 && '' === $current ) ) {
			return '';
		}
	}

	ob_start();
	if ( isset( $dinv_choice_groups[ $key ] ) ) {
		?>
		<div class="dinv-field dinv-field--<?php echo esc_attr( $key ); ?>" data-dinv-filter="<?php echo esc_attr( $key ); ?>">
			<label class="dinv-field__label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<span class="dinv-select">
				<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name( $key ) ); ?>" data-dinv-choice="<?php echo esc_attr( $target ); ?>" data-counts="<?php echo $config['filter_counts'] ? '1' : '0'; ?>" data-hide-empty="<?php echo $config['hide_empty'] ? '1' : '0'; ?>">
					<option value=""><?php echo esc_html( $labels['all'] ); ?></option>
					<?php
					foreach ( $rows as $row ) :
						$value    = (string) $row['value'];
						$text     = Labels::enum( $enum, $value );
						$count    = isset( $dinv_choice_counts[ $target ] ) ? (int) ( $dinv_choice_counts[ $target ][ $value ] ?? 0 ) : (int) $row['count'];
						$selected = $current === $value;
						$empty    = 0 === $count && ! $selected;
						?>
						<option value="<?php echo esc_attr( $value ); ?>" data-label="<?php echo esc_attr( $text ); ?>" <?php selected( $selected ); ?> <?php disabled( $empty ); ?> <?php echo $empty && $config['hide_empty'] ? 'hidden' : ''; ?>>
							<?php echo esc_html( $config['filter_counts'] ? sprintf( '%s (%s)', $text, number_format_i18n( $count ) ) : $text ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</span>
		</div>
		<?php
	} elseif ( 'version' === $key ) {
		?>
		<div class="dinv-field dinv-field--version" data-dinv-filter="version">
			<label class="dinv-field__label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<input class="dinv-input" id="<?php echo esc_attr( $id ); ?>" type="search" name="<?php echo esc_attr( $name( 'version' ) ); ?>" value="<?php echo esc_attr( (string) ( $filters['version'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $labels['version_hint'] ); ?>" maxlength="80" enterkeyhint="search">
		</div>
		<?php
	} elseif ( 'warranty' === $key ) {
		?>
		<div class="dinv-field dinv-field--warranty" data-dinv-filter="warranty">
			<label class="dinv-check" for="<?php echo esc_attr( $id ); ?>">
				<input id="<?php echo esc_attr( $id ); ?>" type="checkbox" name="<?php echo esc_attr( $name( 'warranty' ) ); ?>" value="1" <?php checked( ! empty( $filters['has_warranty'] ) ); ?>>
				<span><?php echo esc_html( $label ); ?></span>
			</label>
		</div>
		<?php
	}
	return (string) ob_get_clean();
};

$dinv_rendered = array();
foreach ( $dinv_enabled as $dinv_key ) {
	$dinv_html = $dinv_field( $dinv_key );
	if ( '' !== trim( $dinv_html ) ) {
		$dinv_rendered[ $dinv_key ] = $dinv_html;
	}
}

$dinv_classes = array( 'dinv-filters', 'dinv-filters--' . $dinv_position );
if ( $config['filters_collapsed'] && 'top' === $dinv_position ) {
	$dinv_classes[] = 'is-collapsed';
}
if ( $config['mobile_drawer'] ) {
	$dinv_classes[] = 'dinv-filters--drawer';
}

$dinv_visible = 'top' === $dinv_position ? max( 1, (int) $config['filters_visible'] ) : count( $dinv_rendered );
$dinv_first   = array_slice( $dinv_rendered, 0, $dinv_visible, true );
$dinv_more    = array_slice( $dinv_rendered, $dinv_visible, null, true );

$dinv_more_active = false;
foreach ( array_keys( $dinv_more ) as $dinv_key ) {
	$dinv_targets = array(
		'price'    => array( 'price_from', 'price_to' ),
		'year'     => array( 'year_from', 'year_to' ),
		'mileage'  => array( 'mileage_from', 'mileage_to' ),
		'power'    => array( 'power_from', 'power_to' ),
		'make'     => array( 'make', 'model' ),
		'warranty' => array( 'has_warranty' ),
		'version'  => array( 'version' ),
	);
	$dinv_keys    = $dinv_targets[ $dinv_key ] ?? array( $dinv_choice_groups[ $dinv_key ][2] ?? $dinv_key );
	foreach ( $dinv_keys as $dinv_target ) {
		if ( isset( $filters[ $dinv_target ] ) && '' !== (string) $filters[ $dinv_target ] ) {
			$dinv_more_active = true;
		}
	}
}

$dinv_group = static function ( string $key, string $html ) use ( $config, $dinv_names ): string {
	if ( 'warranty' === $key ) {
		return $html;
	}
	return '<details class="dinv-filter-group dinv-filter-group--' . esc_attr( $key ) . '"' . ( $config['filters_collapsed'] ? '' : ' open' ) . '><summary>' . esc_html( $dinv_names[ $key ] ?? $key ) . Renderer::icon( 'chevron' ) . '</summary><div class="dinv-filter-group__body">' . $html . '</div></details>';
};
?>
<div
	id="<?php echo esc_attr( $uid ); ?>-panel"
	class="<?php echo esc_attr( implode( ' ', $dinv_classes ) ); ?>"
	data-dinv-panel
	aria-labelledby="<?php echo esc_attr( $uid ); ?>-filters-title"
>
<form id="<?php echo esc_attr( $dinv_form_id ); ?>" class="dinv-filters__form" data-dinv-form method="get" aria-label="<?php echo esc_attr( $labels['filters'] ); ?>">
	<div class="dinv-filters__head">
		<h3 class="dinv-filters__title" id="<?php echo esc_attr( $uid ); ?>-filters-title"><?php echo esc_html( $labels['filters'] ); ?></h3>
		<button type="button" class="dinv-icon-button dinv-filters__close" data-dinv-drawer-close>
			<?php echo Renderer::icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
			<span class="screen-reader-text"><?php echo esc_html( $labels['close'] ); ?></span>
		</button>
	</div>

	<?php foreach ( $dinv_keep as $dinv_name => $dinv_value ) : ?>
		<input type="hidden" name="<?php echo esc_attr( $dinv_name ); ?>" value="<?php echo esc_attr( $dinv_value ); ?>">
	<?php endforeach; ?>

	<div class="dinv-filters__body">
		<?php if ( 'top' === $dinv_position ) : ?>
			<div class="dinv-filters__grid">
				<?php echo implode( '', $dinv_first ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while rendering. ?>
			</div>
			<?php if ( $dinv_more ) : ?>
				<details class="dinv-filters__more" <?php echo $dinv_more_active ? 'open' : ''; ?>>
					<summary>
						<span class="dinv-filters__more-open"><?php echo esc_html( $labels['advanced'] ); ?></span>
						<span class="dinv-filters__more-close"><?php echo esc_html( $labels['fewer_filters'] ); ?></span>
						<?php echo Renderer::icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
					</summary>
					<div class="dinv-filters__grid">
						<?php echo implode( '', $dinv_more ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while rendering. ?>
					</div>
				</details>
			<?php endif; ?>
		<?php else : ?>
			<?php
			foreach ( $dinv_rendered as $dinv_key => $dinv_html ) {
				echo $dinv_group( $dinv_key, $dinv_html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while rendering.
			}
			?>
		<?php endif; ?>
	</div>

	<div class="dinv-filters__footer">
		<a class="dinv-button dinv-button--ghost dinv-filters__reset" href="<?php echo esc_url( Shortcode::current_base_url() ); ?>" data-dinv-reset>
			<?php echo Renderer::icon( 'reset' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
			<span><?php echo esc_html( $labels['reset'] ); ?></span>
		</a>
		<button type="submit" class="dinv-button dinv-button--primary dinv-filters__submit" data-dinv-submit><?php echo esc_html( $labels['apply'] ); ?></button>
	</div>
</form>
</div>
<div class="dinv-drawer-backdrop" data-dinv-drawer-backdrop hidden></div>
