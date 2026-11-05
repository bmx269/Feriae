<?php
/**
 * Calendar block.
 *
 * @package Feriae
 *
 * @var array $attributes Block attributes.
 */

$feriae_view = new Feriae\Frontend\CalendarView( $attributes );
?>
<div <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php echo $feriae_view->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in CalendarView. ?>
</div>
