<?php
/**
 * Event details block.
 *
 * @package Feriae
 *
 * @var array    $attributes Block attributes.
 * @var WP_Block $block      Block instance.
 */

$feriae_post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
$feriae_details = $feriae_post_id ? Feriae\Frontend\EventDetails::render( $feriae_post_id, $attributes ) : '';

if ( '' === $feriae_details ) {
	// In the editor (template editing or an unsaved event) show where the panel goes.
	if ( wp_is_serving_rest_request() ) {
		printf( '<div %s><p>%s</p></div>', get_block_wrapper_attributes( array( 'class' => 'feriae-details' ) ), esc_html__( 'Event details appear here once the event has a date.', 'feriae' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	return;
}
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'feriae-details' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php echo $feriae_details; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in EventDetails. ?>
</div>
