<?php
/**
 * Events archive for classic themes. Copy to
 * your-theme/feriae/archive.php to customise.
 *
 * @package Feriae
 */

get_header();

$feriae_attrs = array(
	'showLegend' => true,
	'views'      => array( 'month', 'week', 'day', 'list' ),
);
if ( is_tax( Feriae\Content\PostTypes::CATEGORY ) ) {
	$feriae_attrs['categories'] = array( get_queried_object_id() );
}
?>
<main id="primary" class="site-main feriae-classic-archive">
	<header class="page-header">
		<h1 class="page-title"><?php echo is_tax() ? esc_html( single_term_title( '', false ) ) : esc_html__( 'Events', 'feriae' ); ?></h1>
		<?php the_archive_description( '<div class="archive-description">', '</div>' ); ?>
	</header>
	<?php
	echo do_blocks( '<!-- wp:feriae/calendar ' . wp_json_encode( $feriae_attrs ) . ' /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
</main>
<?php
get_footer();
