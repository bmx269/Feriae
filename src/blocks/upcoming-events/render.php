<?php
/**
 * Upcoming events block.
 *
 * @package Feriae
 *
 * @var array $attributes Block attributes.
 */

use Feriae\Frontend\CalendarView;

$feriae_items = feriae_get_upcoming(
	max( 1, min( 50, (int) $attributes['count'] ) ),
	array(
		'categories'   => array_map( 'absint', (array) $attributes['categories'] ),
		'count'        => false, // Not paginated.
		'prime_images' => (bool) $attributes['showImage'],
	)
);
$feriae_level = max( 2, min( 6, (int) $attributes['headingLevel'] ) );
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'feriae-upcoming' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( $feriae_items ) : ?>
		<ul class="feriae-upcoming__items">
			<?php foreach ( $feriae_items as $feriae_occurrence ) : ?>
				<li>
					<?php
					echo CalendarView::list_item( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in list_item().
						$feriae_occurrence,
						'h' . $feriae_level,
						array(
							'image'   => (bool) $attributes['showImage'],
							'excerpt' => (bool) $attributes['showExcerpt'],
							'venue'   => (bool) $attributes['showVenue'],
						)
					);
					?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php else : ?>
		<p class="feriae-upcoming__empty"><?php esc_html_e( 'There are no upcoming events.', 'feriae' ); ?></p>
	<?php endif; ?>
	<?php if ( $attributes['showViewAll'] ) : ?>
		<p class="feriae-upcoming__more"><a href="<?php echo esc_url( feriae_calendar_url() ); ?>"><?php esc_html_e( 'View all events', 'feriae' ); ?></a></p>
	<?php endif; ?>
</div>
