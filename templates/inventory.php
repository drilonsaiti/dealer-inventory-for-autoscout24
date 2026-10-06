<?php
/**
 * Inventory.
 *
 * Override: yourtheme/dealer-inventory/inventory.php
 *
 * @package DealerInventory
 *
 * @var \DealerInventory\Inventory $inventory     State of this inventory.
 * @var array                      $config        Resolved instance configuration.
 * @var string                     $instance      Instance name.
 * @var array                      $results       Search results.
 * @var array                      $filters       Active filters.
 * @var string                     $sort          Active sort.
 * @var string                     $view          Visitor view ("", "grid", "list").
 * @var array                      $options       Filter options.
 * @var array                      $tree          Makes with models.
 * @var array                      $facets        Counts per make / model for the active filters.
 * @var array                      $choice_counts Counts per choice (fuel, body type, …) for the active filters.
 * @var array                      $labels        Interface labels.
 * @var \DealerInventory\Renderer  $renderer      Result renderer.
 * @var array                      $preset_params Preset filters as request parameters.
 * @var array                      $client_config Instance settings for REST refreshes.
 * @var string                     $dealer_url    Dealer page URL.
 * @var bool                       $interactive   Instance needs the script.
 * @var string                     $uid           Unique element id.
 */

use DealerInventory\Design;
use DealerInventory\Sync;
use DealerInventory\Template;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dinv_parts = get_defined_vars();
$dinv_side  = $config['show_filters'] && 'sidebar' === $config['filter_position'];

$dinv_classes = array(
	'dinv-inventory',
	'dinv-layout-' . $config['layout'],
	'dinv-filters-' . ( $config['show_filters'] ? $config['filter_position'] : 'none' ),
);
if ( $config['show_filters'] && $config['mobile_drawer'] ) {
	$dinv_classes[] = 'dinv-has-drawer';
}

$dinv_style = sprintf(
	'--dinv-cols:%d;--dinv-cols-tablet:%d;--dinv-cols-mobile:%d;--dinv-image-ratio:%s',
	(int) $config['columns'],
	(int) $config['columns_tablet'],
	(int) $config['columns_mobile'],
	Design::ratio( (string) $config['image_ratio'] )
);
?>
<section
	id="<?php echo esc_attr( $uid ); ?>"
	class="<?php echo esc_attr( implode( ' ', $dinv_classes ) ); ?>"
	style="<?php echo esc_attr( $dinv_style ); ?>"
	data-instance="<?php echo esc_attr( $instance ); ?>"
	data-interactive="<?php echo $interactive ? '1' : '0'; ?>"
	data-url-state="<?php echo $config['url_state'] ? '1' : '0'; ?>"
	data-pagination="<?php echo esc_attr( (string) $config['pagination'] ); ?>"
	data-preset-params="<?php echo esc_attr( (string) wp_json_encode( (object) $preset_params ) ); ?>"
	data-preset-sort="<?php echo esc_attr( (string) $config['sort'] ); ?>"
	data-per-page-default="<?php echo esc_attr( (string) $config['per_page_default'] ); ?>"
	data-layout-default="<?php echo esc_attr( (string) $config['layout_default'] ); ?>"
	data-view="<?php echo esc_attr( $view ); ?>"
	data-config="<?php echo esc_attr( (string) wp_json_encode( (object) $client_config ) ); ?>"
	data-locale="<?php echo esc_attr( determine_locale() ); ?>"
	data-version="<?php echo esc_attr( Sync::cache_version() ); ?>"
	data-rendered="<?php echo esc_attr( (string) time() ); ?>"
>
	<?php if ( $config['show_header'] ) : ?>
		<?php echo Template::render( 'parts/header.php', $dinv_parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template output. ?>
	<?php endif; ?>

	<div class="dinv-inventory__body">
		<?php echo Template::render( 'parts/toolbar.php', $dinv_parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template output. ?>

		<div class="dinv-inventory__main<?php echo $dinv_side ? ' dinv-inventory__main--sidebar' : ''; ?>">
			<?php echo Template::render( 'parts/filters.php', $dinv_parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template output. ?>
			<?php echo Template::render( 'parts/results.php', $dinv_parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template output. ?>
		</div>
	</div>
</section>
