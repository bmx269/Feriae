<?php
/**
 * Admin list tables.
 *
 * @package Feriae
 */

namespace Feriae\Admin;

use Feriae\Content\PostTypes;
use Feriae\Frontend\CategoryColors;
use Feriae\Frontend\Formatter;

/**
 * Adds an "Event date" column to every event-enabled post type, and a colour
 * field to event categories.
 */
final class AdminColumns {

	public function register(): void {
		add_action( 'admin_init', array( $this, 'hook_columns' ) );
		add_action( PostTypes::CATEGORY . '_add_form_fields', array( $this, 'color_field_add' ) );
		add_action( PostTypes::CATEGORY . '_edit_form_fields', array( $this, 'color_field_edit' ) );
		add_action( 'created_' . PostTypes::CATEGORY, array( $this, 'save_color' ) );
		add_action( 'edited_' . PostTypes::CATEGORY, array( $this, 'save_color' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_color_picker' ) );
	}

	public function hook_columns(): void {
		foreach ( feriae_event_post_types() as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'columns' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'column' ), 10, 2 );
			add_filter( "manage_edit-{$post_type}_sortable_columns", array( $this, 'sortable' ) );
		}
		add_filter( 'the_posts', array( $this, 'prime_dates' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'sort_by_date' ) );
	}

	/**
	 * One query for every row's event date, instead of one per row.
	 *
	 * @param \WP_Post[] $posts Posts.
	 * @return \WP_Post[]
	 */
	public function prime_dates( array $posts, \WP_Query $query ): array {
		if ( $query->is_main_query() && $posts && in_array( $query->get( 'post_type' ), feriae_event_post_types(), true ) ) {
			feriae()->occurrences()->for_events( wp_list_pluck( $posts, 'ID' ) );
		}
		return $posts;
	}

	/**
	 * @param array<string, string> $columns Sortable columns.
	 * @return array<string, string>
	 */
	public function sortable( array $columns ): array {
		$columns['feriae_event_date'] = 'feriae_event_date';
		return $columns;
	}

	/**
	 * Sorts the list table by first occurrence using the occurrences index.
	 * Posts without a date sort last.
	 */
	public function sort_by_date( \WP_Query $query ): void {
		if ( ! $query->is_main_query() || 'feriae_event_date' !== $query->get( 'orderby' ) ) {
			return;
		}
		$order = 'ASC' === strtoupper( (string) $query->get( 'order' ) ) ? 'ASC' : 'DESC';
		add_filter(
			'posts_clauses',
			static function ( array $clauses, \WP_Query $current ) use ( $order, $query ): array {
				if ( $current !== $query ) {
					return $clauses;
				}
				global $wpdb;
				$table              = \Feriae\Installer::occurrences_table();
				$clauses['join']   .= " LEFT JOIN {$table} feriae_sort ON feriae_sort.event_id = {$wpdb->posts}.ID AND feriae_sort.sequence = 0";
				$clauses['orderby'] = "feriae_sort.start_utc IS NULL, feriae_sort.start_utc {$order}";
				return $clauses;
			},
			10,
			2
		);
	}

	/**
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['feriae_event_date'] = __( 'Event date', 'feriae' );
			}
		}
		return $out;
	}

	public function column( string $column, int $post_id ): void {
		if ( 'feriae_event_date' !== $column ) {
			return;
		}
		$occurrence = feriae_is_event( $post_id ) ? feriae()->occurrences()->for_event( $post_id ) : null;
		echo $occurrence ? esc_html( Formatter::range( $occurrence ) ) : '<span aria-hidden="true">—</span>';
	}

	public function enqueue_color_picker( string $hook ): void {
		$screen = get_current_screen();
		if ( $screen && PostTypes::CATEGORY === $screen->taxonomy && in_array( $hook, array( 'edit-tags.php', 'term.php' ), true ) ) {
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_script( 'wp-color-picker' );
			wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){$(".feriae-color-field").wpColorPicker();});' );
		}
	}

	public function color_field_add(): void {
		?>
		<div class="form-field">
			<label for="feriae-color"><?php esc_html_e( 'Colour', 'feriae' ); ?></label>
			<?php wp_nonce_field( 'feriae_category_color', 'feriae_category_color_nonce' ); ?>
			<input type="text" id="feriae-color" name="feriae_color" class="feriae-color-field" value="">
			<p><?php esc_html_e( 'Background colour for events in this category on the calendar. Text colour is chosen automatically for contrast.', 'feriae' ); ?></p>
		</div>
		<?php
	}

	public function color_field_edit( \WP_Term $term ): void {
		?>
		<tr class="form-field">
			<th scope="row"><label for="feriae-color"><?php esc_html_e( 'Colour', 'feriae' ); ?></label></th>
			<td>
				<?php wp_nonce_field( 'feriae_category_color', 'feriae_category_color_nonce' ); ?>
				<input type="text" id="feriae-color" name="feriae_color" class="feriae-color-field" value="<?php echo esc_attr( CategoryColors::color( $term->term_id ) ); ?>">
				<p class="description"><?php esc_html_e( 'Background colour for events in this category on the calendar.', 'feriae' ); ?></p>
			</td>
		</tr>
		<?php
	}

	public function save_color( int $term_id ): void {
		if (
			! isset( $_POST['feriae_category_color_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['feriae_category_color_nonce'] ) ), 'feriae_category_color' )
			|| ! current_user_can( 'edit_term', $term_id )
		) {
			return;
		}
		$color = sanitize_hex_color( wp_unslash( $_POST['feriae_color'] ?? '' ) );
		if ( $color ) {
			update_term_meta( $term_id, CategoryColors::META_KEY, $color );
		} else {
			delete_term_meta( $term_id, CategoryColors::META_KEY );
		}
	}
}
