<?php
/**
 * Vehicle detail page.
 *
 * Override: yourtheme/dealer-inventory/single-vehicle.php
 *
 * @package DealerInventory
 *
 * @var array $vehicle View model (see Detail::data()).
 * @var array $config  Instance configuration.
 * @var array $labels  Interface labels.
 */

use DealerInventory\Design;
use DealerInventory\Renderer;
use DealerInventory\Template;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dinv_uid    = 'dinv-vehicle-' . (int) $vehicle['id'];
$dinv_count  = count( $vehicle['images'] );
$dinv_dealer = $vehicle['dealer'];
$dinv_desc   = (string) $vehicle['description'];
if ( '' !== $dinv_desc && ! preg_match( '/<(p|br|ul|ol|div)\b/i', $dinv_desc ) ) {
	$dinv_desc = wpautop( $dinv_desc );
}
?>
<article class="dinv-detail" id="<?php echo esc_attr( $dinv_uid ); ?>" style="<?php echo esc_attr( '--dinv-image-ratio:' . Design::ratio( (string) $config['image_ratio'] ) ); ?>">
	<nav class="dinv-detail__nav">
		<a class="dinv-detail__back" href="<?php echo esc_url( $vehicle['list_url'] ); ?>" data-dinv-back>
			<?php echo Renderer::icon( 'back' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
			<span><?php echo esc_html( $labels['back_to_list'] ); ?></span>
		</a>
	</nav>

	<div class="dinv-detail__top">
		<div class="dinv-photos" data-dinv-photos>
			<?php if ( $dinv_count ) : ?>
				<div class="dinv-photos__stage">
					<ul class="dinv-photos__track" data-dinv-photos-track tabindex="0" aria-label="<?php echo esc_attr( $vehicle['title'] ); ?>">
						<?php foreach ( $vehicle['images'] as $dinv_index => $dinv_image ) : ?>
							<li class="dinv-photos__slide" id="<?php echo esc_attr( $dinv_uid . '-photo-' . ( $dinv_index + 1 ) ); ?>">
								<img
									src="<?php echo esc_url( $dinv_image['src'] ); ?>"
									<?php if ( '' !== $dinv_image['srcset'] ) : ?>
										srcset="<?php echo esc_attr( $dinv_image['srcset'] ); ?>"
										sizes="(max-width: 900px) 100vw, 60vw"
									<?php endif; ?>
									alt="<?php echo esc_attr( $dinv_image['alt'] ); ?>"
									loading="<?php echo 0 === $dinv_index ? 'eager' : 'lazy'; ?>"
									<?php echo 0 === $dinv_index ? 'fetchpriority="high"' : ''; ?>
									decoding="async"
								>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ( $dinv_count > 1 ) : ?>
						<button type="button" class="dinv-photos__nav dinv-photos__nav--prev" data-dinv-photos-prev hidden>
							<?php echo Renderer::icon( 'back' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
							<span class="screen-reader-text"><?php echo esc_html( $labels['prev_photo'] ); ?></span>
						</button>
						<button type="button" class="dinv-photos__nav dinv-photos__nav--next" data-dinv-photos-next hidden>
							<?php echo Renderer::icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
							<span class="screen-reader-text"><?php echo esc_html( $labels['next_photo'] ); ?></span>
						</button>
						<span class="dinv-photos__counter" data-dinv-photos-counter aria-live="polite">1 / <?php echo esc_html( (string) $dinv_count ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( $dinv_count > 1 ) : ?>
					<ul class="dinv-photos__thumbs">
						<?php foreach ( $vehicle['images'] as $dinv_index => $dinv_image ) : ?>
							<li>
								<a href="<?php echo esc_attr( '#' . $dinv_uid . '-photo-' . ( $dinv_index + 1 ) ); ?>" data-dinv-photos-thumb="<?php echo esc_attr( (string) $dinv_index ); ?>" <?php echo 0 === $dinv_index ? 'aria-current="true"' : ''; ?>>
									<img src="<?php echo esc_url( $dinv_image['thumb'] ); ?>" alt="" loading="lazy" decoding="async" width="120" height="90">
									<span class="screen-reader-text"><?php echo esc_html( sprintf( $labels['photo_n'], $dinv_index + 1, $dinv_count ) ); ?></span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php else : ?>
				<div class="dinv-photos__stage dinv-media__placeholder" aria-hidden="true"></div>
			<?php endif; ?>
		</div>

		<aside class="dinv-detail__summary">
			<?php if ( $vehicle['badges'] ) : ?>
				<ul class="dinv-badges">
					<?php foreach ( $vehicle['badges'] as $dinv_key => $dinv_badge ) : ?>
						<li class="dinv-badge dinv-badge--<?php echo esc_attr( (string) $dinv_key ); ?>"><?php echo esc_html( $dinv_badge ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h1 class="dinv-detail__title"><?php echo esc_html( $vehicle['title'] ); ?></h1>
			<?php if ( '' !== $vehicle['teaser'] ) : ?>
				<p class="dinv-detail__teaser"><?php echo esc_html( $vehicle['teaser'] ); ?></p>
			<?php endif; ?>

			<?php echo Template::render( 'parts/vehicle-price.php', array( 'vehicle' => $vehicle ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template output. ?>

			<?php if ( $vehicle['key_specs'] ) : ?>
				<ul class="dinv-detail__keyspecs">
					<?php foreach ( $vehicle['key_specs'] as $dinv_type => $dinv_text ) : ?>
						<li>
							<?php echo Renderer::icon( (string) $dinv_type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
							<span><?php echo esc_html( $dinv_text ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<div class="dinv-detail__actions">
				<?php if ( '' !== $dinv_dealer['phone'] ) : ?>
					<a class="dinv-button dinv-button--primary" href="<?php echo esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $dinv_dealer['phone'] ) ); ?>">
						<?php echo Renderer::icon( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
						<span><?php echo esc_html( $dinv_dealer['phone'] ); ?></span>
					</a>
				<?php endif; ?>
				<?php if ( '' !== $vehicle['market_url'] ) : ?>
					<a class="dinv-button <?php echo '' !== $dinv_dealer['phone'] ? 'dinv-button--ghost' : 'dinv-button--primary'; ?>" href="<?php echo esc_url( $vehicle['market_url'] ); ?>" target="_blank" rel="noopener">
						<span><?php echo esc_html( $labels['view_on_market'] ); ?></span>
						<span class="screen-reader-text"><?php echo esc_html( $labels['opens_new_tab'] ); ?></span>
						<?php echo Renderer::icon( 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
					</a>
				<?php endif; ?>
			</div>

			<?php if ( '' !== $dinv_dealer['name'] ) : ?>
				<div class="dinv-detail__dealer">
					<span class="dinv-detail__dealer-label"><?php echo esc_html( $labels['dealer'] ); ?></span>
					<strong><?php echo esc_html( $dinv_dealer['name'] ); ?></strong>
					<?php if ( '' !== $dinv_dealer['address'] ) : ?>
						<span class="dinv-detail__dealer-address">
							<?php echo Renderer::icon( 'pin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
							<?php echo esc_html( $dinv_dealer['address'] ); ?>
						</span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</aside>
	</div>

	<div class="dinv-detail__sections">
		<?php if ( $vehicle['specs'] ) : ?>
			<section class="dinv-detail__section dinv-detail__specs" aria-labelledby="<?php echo esc_attr( $dinv_uid ); ?>-specs">
				<h2 id="<?php echo esc_attr( $dinv_uid ); ?>-specs"><?php echo esc_html( $labels['specifications'] ); ?></h2>
				<dl class="dinv-spectable">
					<?php foreach ( $vehicle['specs'] as $dinv_key => $dinv_row ) : ?>
						<div class="dinv-spectable__row dinv-spectable__row--<?php echo esc_attr( (string) $dinv_key ); ?>">
							<dt><?php echo esc_html( $dinv_row['label'] ); ?></dt>
							<dd><?php echo esc_html( $dinv_row['value'] ); ?></dd>
						</div>
					<?php endforeach; ?>
				</dl>
			</section>
		<?php endif; ?>

		<?php if ( '' !== $dinv_desc ) : ?>
			<section class="dinv-detail__section dinv-detail__description" aria-labelledby="<?php echo esc_attr( $dinv_uid ); ?>-desc">
				<h2 id="<?php echo esc_attr( $dinv_uid ); ?>-desc"><?php echo esc_html( $labels['description'] ); ?></h2>
				<div class="dinv-detail__text"><?php echo \DealerInventory\Format::description_html( $dinv_desc ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Filtered with wp_kses(). ?></div>
			</section>
		<?php endif; ?>

		<?php if ( $vehicle['equipment'] ) : ?>
			<section class="dinv-detail__section dinv-detail__equipment" aria-labelledby="<?php echo esc_attr( $dinv_uid ); ?>-equipment">
				<h2 id="<?php echo esc_attr( $dinv_uid ); ?>-equipment"><?php echo esc_html( $labels['equipment'] ); ?></h2>
				<ul class="dinv-equipment">
					<?php foreach ( $vehicle['equipment'] as $dinv_item ) : ?>
						<li><?php echo Renderer::icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?><span><?php echo esc_html( $dinv_item ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>
	</div>
</article>
