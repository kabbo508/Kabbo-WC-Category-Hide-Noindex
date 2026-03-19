<?php
/**
 * Plugin Name: Kabbo WC Category Hide + Noindex
 * Description: Adds per product category toggles to hide from storefront and apply noindex. Hidden categories are removed from frontend lists and menus, their archives return 404, products in hidden categories are removed from listings and blocked via direct URL. Noindex adds robots meta on category and product pages.
 * Version: 1.0.1
 * Author: Sumon Rahman Kabbo
 * Author URI: https://sumonrahmankabbo.com/
 * License: GPLv2 or later
 * Text Domain: kabbo-wc-category-hide-noindex
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class Kabbo_WC_Category_Hide_Noindex {
	const META_HIDE    = 'kabbo_hide_frontend';
	const META_NOINDEX = 'kabbo_noindex_cat';

	const OPT_HIDE     = 'kabbo_hidden_cat_ids_frontend';
	const OPT_NOINDEX  = 'kabbo_noindex_cat_ids';

	public static function init() {
		// Admin UI
		add_action( 'product_cat_add_form_fields',  array( __CLASS__, 'render_add_fields' ), 20 );
		add_action( 'product_cat_edit_form_fields', array( __CLASS__, 'render_edit_fields' ), 20, 1 );

		add_action( 'created_product_cat', array( __CLASS__, 'save_term_meta' ), 10, 1 );
		add_action( 'edited_product_cat',  array( __CLASS__, 'save_term_meta' ), 10, 1 );

		// Frontend hard hide categories
		add_filter( 'get_terms', array( __CLASS__, 'filter_get_terms_hide_categories' ), 20, 3 );

		// Frontend remove hidden product cats from menus
		add_filter( 'wp_get_nav_menu_items', array( __CLASS__, 'filter_nav_menu_items' ), 20, 3 );

		// Force 404 for hidden category archives
		add_action( 'template_redirect', array( __CLASS__, 'maybe_404_hidden_category_archive' ), 1 );

		// Exclude hidden category products from all product loops, including search and builders
		add_action( 'pre_get_posts', array( __CLASS__, 'exclude_hidden_products_from_queries' ), 5 );

		// Force 404 for hidden category products on single product URLs
		add_action( 'template_redirect', array( __CLASS__, 'maybe_404_hidden_product' ), 2 );

		// Robots meta
		add_action( 'wp_head', array( __CLASS__, 'output_robots_meta' ), 1 );
	}

	public static function activate() {
		self::rebuild_caches();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	/* =========================
	 * Admin UI
	 * ========================= */

	public static function render_add_fields() {
		wp_nonce_field( 'kabbo_wc_cat_ctrl_save', 'kabbo_wc_cat_ctrl_nonce' );
		?>
		<div class="form-field">
			<label>
				<input type="checkbox" name="<?php echo esc_attr( self::META_HIDE ); ?>" value="1">
				Hide from storefront
			</label>
			<p class="description">Hides this category everywhere, and hides all products assigned to it from the storefront.</p>
		</div>

		<div class="form-field">
			<label>
				<input type="checkbox" name="<?php echo esc_attr( self::META_NOINDEX ); ?>" value="1">
				Noindex for search engines
			</label>
			<p class="description">Adds robots noindex to this category, and also noindex products assigned to it.</p>
		</div>
		<?php
	}

	public static function render_edit_fields( $term ) {
		$hide    = get_term_meta( $term->term_id, self::META_HIDE, true );
		$noindex = get_term_meta( $term->term_id, self::META_NOINDEX, true );

		wp_nonce_field( 'kabbo_wc_cat_ctrl_save', 'kabbo_wc_cat_ctrl_nonce' );
		?>
		<tr class="form-field">
			<th scope="row"><label>Visibility</label></th>
			<td>
				<label style="display:block;margin:6px 0;">
					<input type="checkbox" name="<?php echo esc_attr( self::META_HIDE ); ?>" value="1" <?php checked( $hide, '1' ); ?>>
					Hide from storefront
				</label>

				<label style="display:block;margin:6px 0;">
					<input type="checkbox" name="<?php echo esc_attr( self::META_NOINDEX ); ?>" value="1" <?php checked( $noindex, '1' ); ?>>
					Noindex for search engines
				</label>
			</td>
		</tr>
		<?php
	}

	public static function save_term_meta( $term_id ) {
		if ( ! current_user_can( 'manage_product_terms' ) ) return;

		if ( ! isset( $_POST['kabbo_wc_cat_ctrl_nonce'] ) || ! wp_verify_nonce( $_POST['kabbo_wc_cat_ctrl_nonce'], 'kabbo_wc_cat_ctrl_save' ) ) {
			return;
		}

		update_term_meta( $term_id, self::META_HIDE,    isset( $_POST[ self::META_HIDE ] ) ? '1' : '' );
		update_term_meta( $term_id, self::META_NOINDEX, isset( $_POST[ self::META_NOINDEX ] ) ? '1' : '' );

		self::rebuild_caches();
	}

	/* =========================
	 * Cache helpers
	 * ========================= */

	private static function opt_get_ids( $opt_name ) {
		$ids = get_option( $opt_name, array() );
		if ( ! is_array( $ids ) ) $ids = array();
		return array_values( array_unique( array_map( 'absint', $ids ) ) );
	}

	private static function opt_set_ids( $opt_name, $ids ) {
		$ids = is_array( $ids ) ? $ids : array();
		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
		update_option( $opt_name, $ids, false );
	}

	public static function get_hidden_cat_ids() {
		$cached = self::opt_get_ids( self::OPT_HIDE );
		if ( ! empty( $cached ) ) return $cached;

		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = '1'",
			self::META_HIDE
		) );

		$ids = array_values( array_unique( array_map( 'absint', (array) $ids ) ) );
		self::opt_set_ids( self::OPT_HIDE, $ids );

		return $ids;
	}

	public static function get_noindex_cat_ids() {
		$cached = self::opt_get_ids( self::OPT_NOINDEX );
		if ( ! empty( $cached ) ) return $cached;

		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = '1'",
			self::META_NOINDEX
		) );

		$ids = array_values( array_unique( array_map( 'absint', (array) $ids ) ) );
		self::opt_set_ids( self::OPT_NOINDEX, $ids );

		return $ids;
	}

	public static function rebuild_caches() {
		$term_ids = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'fields'     => 'ids',
		) );

		if ( is_wp_error( $term_ids ) ) return;

		$hidden  = array();
		$noindex = array();

		foreach ( (array) $term_ids as $tid ) {
			$tid = (int) $tid;
			if ( get_term_meta( $tid, self::META_HIDE, true ) === '1' ) $hidden[] = $tid;
			if ( get_term_meta( $tid, self::META_NOINDEX, true ) === '1' ) $noindex[] = $tid;
		}

		self::opt_set_ids( self::OPT_HIDE, $hidden );
		self::opt_set_ids( self::OPT_NOINDEX, $noindex );
	}

	/* =========================
	 * Frontend category hiding
	 * ========================= */

	public static function filter_get_terms_hide_categories( $terms, $taxonomies, $args ) {
		if ( is_admin() ) return $terms;

		$taxonomies = (array) $taxonomies;
		if ( ! in_array( 'product_cat', $taxonomies, true ) ) return $terms;

		$hidden = self::get_hidden_cat_ids();
		if ( empty( $hidden ) ) return $terms;

		$out = array();
		foreach ( (array) $terms as $t ) {
			if ( is_object( $t ) && isset( $t->term_id ) && in_array( (int) $t->term_id, $hidden, true ) ) continue;
			$out[] = $t;
		}
		return $out;
	}

	public static function filter_nav_menu_items( $items, $menu, $args ) {
		if ( is_admin() ) return $items;

		$hidden = self::get_hidden_cat_ids();
		if ( empty( $hidden ) ) return $items;

		$out = array();
		foreach ( (array) $items as $item ) {
			if ( isset( $item->object, $item->object_id ) && $item->object === 'product_cat' ) {
				if ( in_array( (int) $item->object_id, $hidden, true ) ) continue;
			}
			$out[] = $item;
		}
		return $out;
	}

	public static function maybe_404_hidden_category_archive() {
		if ( is_admin() ) return;

		if ( is_tax( 'product_cat' ) ) {
			$term = get_queried_object();
			if ( ! $term || is_wp_error( $term ) ) return;

			$hidden = self::get_hidden_cat_ids();
			if ( empty( $hidden ) ) return;

			if ( in_array( (int) $term->term_id, $hidden, true ) ) {
				global $wp_query;
				$wp_query->set_404();
				status_header( 404 );
				nocache_headers();
				include get_404_template();
				exit;
			}
		}
	}

	/* =========================
	 * Product hiding and blocking
	 * ========================= */

	private static function product_is_hidden_by_cat( $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $product_id ) return false;

		$hidden = self::get_hidden_cat_ids();
		if ( empty( $hidden ) ) return false;

		$term_ids = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $term_ids ) || empty( $term_ids ) ) return false;

		foreach ( $term_ids as $tid ) {
			if ( in_array( (int) $tid, $hidden, true ) ) return true;
		}
		return false;
	}

	public static function exclude_hidden_products_from_queries( $q ) {
		if ( is_admin() ) return;
		if ( ! ( $q instanceof WP_Query ) ) return;
		if ( $q->is_singular ) return;

		$post_type = $q->get( 'post_type' );
		$is_product_query = false;

		if ( empty( $post_type ) ) {
			$is_product_query = $q->is_search() || $q->get( 'wc_query' ) || $q->is_tax( array( 'product_cat', 'product_tag' ) );
		} else {
			if ( $post_type === 'product' ) $is_product_query = true;
			if ( is_array( $post_type ) && in_array( 'product', $post_type, true ) ) $is_product_query = true;
		}

		if ( ! $is_product_query ) return;

		$hidden = self::get_hidden_cat_ids();
		if ( empty( $hidden ) ) return;

		$q->set( 'suppress_filters', false );

		$tax_query = (array) $q->get( 'tax_query', array() );
		$tax_query[] = array(
			'taxonomy' => 'product_cat',
			'field'    => 'term_id',
			'terms'    => $hidden,
			'operator' => 'NOT IN',
		);
		$q->set( 'tax_query', $tax_query );
	}

	public static function maybe_404_hidden_product() {
		if ( is_admin() ) return;

		if ( function_exists( 'is_product' ) && is_product() ) {
			$product_id = get_queried_object_id();
			if ( $product_id && self::product_is_hidden_by_cat( $product_id ) ) {
				global $wp_query;
				$wp_query->set_404();
				status_header( 404 );
				nocache_headers();
				include get_404_template();
				exit;
			}
		}
	}

	/* =========================
	 * Robots meta
	 * ========================= */

	public static function output_robots_meta() {
		if ( is_admin() ) return;

		// Category noindex
		if ( is_tax( 'product_cat' ) ) {
			$term = get_queried_object();
			if ( $term && ! is_wp_error( $term ) ) {
				if ( get_term_meta( (int) $term->term_id, self::META_NOINDEX, true ) === '1' ) {
					echo "<meta name=\"robots\" content=\"noindex, nofollow\" />\n";
				}
			}
			return;
		}

		// Product noindex if it belongs to any noindex category
		if ( function_exists( 'is_product' ) && is_product() ) {
			$product_id = get_queried_object_id();
			if ( ! $product_id ) return;

			$noindex_cats = self::get_noindex_cat_ids();
			if ( empty( $noindex_cats ) ) return;

			$term_ids = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
			if ( is_wp_error( $term_ids ) || empty( $term_ids ) ) return;

			foreach ( $term_ids as $tid ) {
				if ( in_array( (int) $tid, $noindex_cats, true ) ) {
					echo "<meta name=\"robots\" content=\"noindex, nofollow\" />\n";
					break;
				}
			}
		}
	}
}

Kabbo_WC_Category_Hide_Noindex::init();
register_activation_hook( __FILE__, array( 'Kabbo_WC_Category_Hide_Noindex', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Kabbo_WC_Category_Hide_Noindex', 'deactivate' ) );
