<?php
/**
 * YMLK Store Manager REST API (namespace ysm/v1).
 *
 * Lean endpoints built on WooCommerce's own data and functions. Nothing is copied into new tables,
 * so the shop, payments, emails and reports keep working exactly as before.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YSM_REST {

	const NS = 'ysm/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		// Any order or stock change refreshes the overview numbers.
		foreach ( array( 'woocommerce_new_order', 'woocommerce_order_status_changed', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_order_refunded' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'bump_cache' ) );
		}
	}

	public static function hpos() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	public static function bump_cache() {
		update_option( 'ysm_cache_v', (string) time(), false );
	}

	/* ------------------------------------------------------------------ */

	public static function routes() {
		$orders   = function () {
			return current_user_can( 'edit_shop_orders' );
		};
		$products = function () {
			return current_user_can( 'edit_products' );
		};
		$r = function ( $route, $method, $cb, $perm ) {
			register_rest_route( self::NS, $route, array( 'methods' => $method, 'callback' => array( __CLASS__, $cb ), 'permission_callback' => $perm ) );
		};
		$r( '/overview', 'GET', 'overview', $orders );
		$r( '/orders', 'GET', 'orders', $orders );
		$r( '/orders/(?P<id>\d+)', 'GET', 'order', $orders );
		$r( '/orders/(?P<id>\d+)', 'POST', 'order_update', $orders );
		$r( '/products', 'GET', 'products', $products );
		$r( '/products/(?P<id>\d+)/quick', 'POST', 'product_quick', $products );
		$r( '/product/(?P<id>\d+)', 'GET', 'product_get', $products );
		$r( '/product/(?P<id>\d+)', 'DELETE', 'product_delete', $products );
		$r( '/product', 'POST', 'product_save', $products );
		$r( '/meta', 'GET', 'meta', $products );
		$r( '/attributes', 'POST', 'attribute_create', $products );
		$r( '/attributes/(?P<id>\d+)/terms', 'POST', 'term_create', $products );
		$r( '/categories', 'POST', 'category_create', $products );
		$r( '/inventory', 'GET', 'inventory', $products );
		$r( '/stock', 'POST', 'stock_set', $products );
	}

	/* ---------------------------------------------------------- helpers */

	private static function money( $v ) {
		return round( (float) $v, wc_get_price_decimals() );
	}

	private static function status_labels() {
		$out = array();
		foreach ( wc_get_order_statuses() as $k => $label ) {
			$out[ substr( $k, 3 ) ] = $label;
		}
		return $out;
	}

	/** Site-timezone date (Y-m-d) to a GMT datetime string for the start/end of that day. */
	private static function gmt_bound( $ymd, $end = false ) {
		$tz = wp_timezone();
		$d  = DateTime::createFromFormat( 'Y-m-d H:i:s', $ymd . ( $end ? ' 23:59:59' : ' 00:00:00' ), $tz );
		if ( ! $d ) {
			$d = new DateTime( 'now', $tz );
		}
		$d->setTimezone( new DateTimeZone( 'UTC' ) );
		return $d->format( 'Y-m-d H:i:s' );
	}

	private static function thumb( $image_id, $size = 'thumbnail' ) {
		return $image_id ? (string) wp_get_attachment_image_url( (int) $image_id, $size ) : '';
	}

	private static function customer_name( $order ) {
		$n = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		return '' !== $n ? $n : ( $order->get_billing_email() ? $order->get_billing_email() : 'Guest' );
	}

	/* --------------------------------------------------------- overview */

	public static function overview( WP_REST_Request $r ) {
		global $wpdb;
		$tz   = wp_timezone();
		$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $r['to'] ) ? $r['to'] : ( new DateTime( 'now', $tz ) )->format( 'Y-m-d' );
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $r['from'] ) ? $r['from'] : ( new DateTime( 'first day of this month', $tz ) )->format( 'Y-m-d' );
		$key  = 'ysm_ov_' . md5( $from . $to . get_option( 'ysm_cache_v', '1' ) );
		$hit  = get_transient( $key );
		if ( false !== $hit ) {
			return rest_ensure_response( $hit );
		}

		$paid  = array( 'wc-processing', 'wc-completed', 'wc-on-hold' );
		$gfrom = self::gmt_bound( $from );
		$gto   = self::gmt_bound( $to, true );
		$in    = "'" . implode( "','", array_map( 'esc_sql', $paid ) ) . "'";

		if ( self::hpos() ) {
			$t     = $wpdb->prefix . 'wc_orders';
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, total_amount AS total, date_created_gmt AS d FROM {$t} WHERE type='shop_order' AND status IN ({$in}) AND date_created_gmt BETWEEN %s AND %s", $gfrom, $gto ) ); // phpcs:ignore
			$count = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$t} WHERE type='shop_order' GROUP BY status" ); // phpcs:ignore
		} else {
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID AS id, pm.meta_value AS total, p.post_date_gmt AS d FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_order_total' WHERE p.post_type='shop_order' AND p.post_status IN ({$in}) AND p.post_date_gmt BETWEEN %s AND %s", $gfrom, $gto ) ); // phpcs:ignore
			$count = $wpdb->get_results( "SELECT post_status AS status, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type='shop_order' GROUP BY post_status" ); // phpcs:ignore
		}

		// Daily series in the site's timezone.
		$series = array();
		$cur    = new DateTime( $from, $tz );
		$last   = new DateTime( $to, $tz );
		$guard  = 0;
		while ( $cur <= $last && $guard++ < 400 ) {
			$series[ $cur->format( 'Y-m-d' ) ] = array( 'date' => $cur->format( 'Y-m-d' ), 'revenue' => 0, 'orders' => 0 );
			$cur->modify( '+1 day' );
		}
		$revenue = 0;
		$ids     = array();
		foreach ( $rows as $row ) {
			$ids[]    = (int) $row->id;
			$revenue += (float) $row->total;
			$d        = new DateTime( $row->d, new DateTimeZone( 'UTC' ) );
			$d->setTimezone( $tz );
			$k = $d->format( 'Y-m-d' );
			if ( isset( $series[ $k ] ) ) {
				$series[ $k ]['revenue'] += (float) $row->total;
				$series[ $k ]['orders']++;
			}
		}
		foreach ( $series as &$s ) {
			$s['revenue'] = self::money( $s['revenue'] );
		}
		unset( $s );

		// Best sellers in the period.
		$top = array();
		if ( $ids ) {
			$idlist = implode( ',', array_map( 'intval', array_slice( $ids, 0, 5000 ) ) );
			$oi     = $wpdb->prefix . 'woocommerce_order_items';
			$oim    = $wpdb->prefix . 'woocommerce_order_itemmeta';
			$top    = $wpdb->get_results( "SELECT pid.meta_value AS product_id, i.order_item_name AS name, SUM(q.meta_value) AS qty, SUM(t.meta_value) AS revenue FROM {$oi} i JOIN {$oim} pid ON pid.order_item_id=i.order_item_id AND pid.meta_key='_product_id' JOIN {$oim} q ON q.order_item_id=i.order_item_id AND q.meta_key='_qty' JOIN {$oim} t ON t.order_item_id=i.order_item_id AND t.meta_key='_line_total' WHERE i.order_item_type='line_item' AND i.order_id IN ({$idlist}) GROUP BY pid.meta_value ORDER BY qty DESC LIMIT 5", ARRAY_A ); // phpcs:ignore
			foreach ( $top as &$p ) {
				$prod         = wc_get_product( (int) $p['product_id'] );
				$p['name']    = $prod ? $prod->get_name() : $p['name'];
				$p['image']   = $prod ? self::thumb( $prod->get_image_id() ) : '';
				$p['qty']     = (int) $p['qty'];
				$p['revenue'] = self::money( $p['revenue'] );
			}
			unset( $p );
		}

		$counts = array();
		foreach ( $count as $c ) {
			$counts[ preg_replace( '/^wc-/', '', $c->status ) ] = (int) $c->n;
		}

		$data = array(
			'from'     => $from,
			'to'       => $to,
			'revenue'  => self::money( $revenue ),
			'orders'   => count( $rows ),
			'average'  => count( $rows ) ? self::money( $revenue / count( $rows ) ) : 0,
			'counts'   => $counts,
			'labels'   => self::status_labels(),
			'series'   => array_values( $series ),
			'top'      => $top,
			'lowStock' => self::low_stock( 8 ),
			'recent'   => self::recent_orders( 6 ),
		);
		set_transient( $key, $data, 10 * MINUTE_IN_SECONDS );
		return rest_ensure_response( $data );
	}

	private static function low_stock( $limit ) {
		global $wpdb;
		$threshold = (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} ms ON ms.post_id=p.ID AND ms.meta_key='_manage_stock' AND ms.meta_value='yes' JOIN {$wpdb->postmeta} s ON s.post_id=p.ID AND s.meta_key='_stock' WHERE p.post_type IN ('product','product_variation') AND p.post_status IN ('publish','private') AND CAST(s.meta_value AS SIGNED) <= %d ORDER BY CAST(s.meta_value AS SIGNED) ASC LIMIT %d", $threshold, $limit ) ); // phpcs:ignore
		$out       = array();
		foreach ( $rows as $row ) {
			$p = wc_get_product( (int) $row->ID );
			if ( ! $p ) {
				continue;
			}
			$out[] = array(
				'id'       => $p->get_id(),
				'parentId' => $p->get_parent_id(),
				'name'     => $p->get_name(),
				'stock'    => (int) $p->get_stock_quantity(),
				'image'    => self::thumb( $p->get_image_id() ? $p->get_image_id() : ( $p->get_parent_id() ? get_post_thumbnail_id( $p->get_parent_id() ) : 0 ) ),
			);
		}
		return $out;
	}

	private static function order_row( $o ) {
		return array(
			'id'       => $o->get_id(),
			'number'   => $o->get_order_number(),
			'status'   => $o->get_status(),
			'date'     => $o->get_date_created() ? $o->get_date_created()->date_i18n( 'j M Y, H:i' ) : '',
			'total'    => self::money( $o->get_total() ),
			'customer' => self::customer_name( $o ),
			'phone'    => $o->get_billing_phone(),
			'items'    => $o->get_item_count(),
		);
	}

	private static function recent_orders( $n ) {
		$orders = wc_get_orders( array( 'limit' => $n, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ) ) );
		return array_map( array( __CLASS__, 'order_row' ), $orders );
	}

	/* ----------------------------------------------------------- orders */

	public static function orders( WP_REST_Request $r ) {
		$status = sanitize_key( (string) $r['status'] );
		$args   = array(
			'limit'    => 20,
			'page'     => max( 1, (int) $r['page'] ),
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'type'     => 'shop_order',
			'status'   => $status && 'all' !== $status ? array( 'wc-' . $status ) : array_keys( wc_get_order_statuses() ),
		);
		$search = trim( (string) $r['search'] );
		if ( '' !== $search ) {
			if ( ctype_digit( ltrim( $search, '#' ) ) && wc_get_order( (int) ltrim( $search, '#' ) ) ) {
				$o = wc_get_order( (int) ltrim( $search, '#' ) );
				return rest_ensure_response( array( 'items' => array( self::order_row( $o ) ), 'total' => 1, 'pages' => 1 ) );
			}
			if ( self::hpos() ) {
				$args['s'] = $search;
			} else {
				$found            = wc_order_search( $search );
				$args['post__in'] = $found ? $found : array( 0 );
			}
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $r['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $r['to'] ) ) {
			$args['date_created'] = strtotime( $r['from'] . ' 00:00:00 ' . wp_timezone_string() ) . '...' . strtotime( $r['to'] . ' 23:59:59 ' . wp_timezone_string() );
		}
		$res = wc_get_orders( $args );
		return rest_ensure_response(
			array(
				'items'  => array_map( array( __CLASS__, 'order_row' ), $res->orders ),
				'total'  => (int) $res->total,
				'pages'  => (int) $res->max_num_pages,
				'labels' => self::status_labels(),
			)
		);
	}

	public static function order( WP_REST_Request $r ) {
		$o = wc_get_order( (int) $r['id'] );
		if ( ! $o || 'shop_order' !== $o->get_type() ) {
			return new WP_Error( 'ysm_404', 'Order not found.', array( 'status' => 404 ) );
		}
		$items = array();
		foreach ( $o->get_items() as $item ) {
			$p    = $item->get_product();
			$meta = array();
			foreach ( $item->get_formatted_meta_data( '_', true ) as $m ) {
				$meta[] = wp_strip_all_tags( $m->display_key ) . ': ' . wp_strip_all_tags( $m->display_value );
			}
			$items[] = array(
				'name'  => $item->get_name(),
				'qty'   => $item->get_quantity(),
				'total' => self::money( $item->get_total() ),
				'meta'  => $meta,
				'image' => $p ? self::thumb( $p->get_image_id() ? $p->get_image_id() : ( $p->get_parent_id() ? get_post_thumbnail_id( $p->get_parent_id() ) : 0 ) ) : '',
				'sku'   => $p ? $p->get_sku() : '',
			);
		}
		$notes = array();
		foreach ( wc_get_order_notes( array( 'order_id' => $o->get_id(), 'limit' => 30 ) ) as $n ) {
			$notes[] = array(
				'text'     => wp_strip_all_tags( $n->content ),
				'date'     => $n->date_created ? $n->date_created->date_i18n( 'j M Y, H:i' ) : '',
				'customer' => (bool) $n->customer_note,
				'by'       => $n->added_by,
			);
		}
		$addr = function ( $type ) use ( $o ) {
			$raw = 'billing' === $type ? $o->get_address( 'billing' ) : $o->get_address( 'shipping' );
			$fmt = WC()->countries->get_formatted_address( $raw, "\n" );
			return trim( wp_strip_all_tags( str_replace( array( '<br/>', '<br>' ), "\n", $fmt ) ) );
		};
		return rest_ensure_response(
			array(
				'id'        => $o->get_id(),
				'number'    => $o->get_order_number(),
				'status'    => $o->get_status(),
				'labels'    => self::status_labels(),
				'date'      => $o->get_date_created() ? $o->get_date_created()->date_i18n( 'j M Y, H:i' ) : '',
				'customer'  => self::customer_name( $o ),
				'email'     => $o->get_billing_email(),
				'phone'     => $o->get_billing_phone(),
				'billing'   => $addr( 'billing' ),
				'shipping'  => $o->has_shipping_address() ? $addr( 'shipping' ) : '',
				'note'      => $o->get_customer_note(),
				'payment'   => $o->get_payment_method_title(),
				'paid'      => (bool) $o->get_date_paid(),
				'items'     => $items,
				'subtotal'  => self::money( $o->get_subtotal() ),
				'shippingTotal' => self::money( $o->get_shipping_total() ),
				'shippingMethod' => $o->get_shipping_method(),
				'discount'  => self::money( $o->get_discount_total() ),
				'tax'       => self::money( $o->get_total_tax() ),
				'total'     => self::money( $o->get_total() ),
				'refunded'  => self::money( $o->get_total_refunded() ),
				'notes'     => $notes,
				'editUrl'   => $o->get_edit_order_url(),
			)
		);
	}

	public static function order_update( WP_REST_Request $r ) {
		$o = wc_get_order( (int) $r['id'] );
		if ( ! $o ) {
			return new WP_Error( 'ysm_404', 'Order not found.', array( 'status' => 404 ) );
		}
		$status = sanitize_key( (string) $r['status'] );
		if ( $status && isset( self::status_labels()[ $status ] ) && $status !== $o->get_status() ) {
			$o->update_status( $status, 'Status changed in Store Manager.', true );
		}
		$note = trim( sanitize_textarea_field( (string) $r['note'] ) );
		if ( '' !== $note ) {
			$o->add_order_note( $note, ! empty( $r['toCustomer'] ) ? 1 : 0, true );
		}
		self::bump_cache();
		$r2 = new WP_REST_Request( 'GET' );
		$r2->set_url_params( array( 'id' => $o->get_id() ) );
		return self::order( $r2 );
	}

	/* --------------------------------------------------------- products */

	private static function product_row( $p ) {
		$row = array(
			'id'       => $p->get_id(),
			'name'     => $p->get_name(),
			'type'     => $p->get_type(),
			'status'   => $p->get_status(),
			'image'    => self::thumb( $p->get_image_id() ),
			'regular'  => $p->get_regular_price(),
			'sale'     => $p->get_sale_price(),
			'manage'   => $p->get_manage_stock(),
			'stock'    => $p->get_stock_quantity(),
			'inStock'  => $p->is_in_stock(),
			'permalink' => get_permalink( $p->get_id() ),
		);
		if ( $p->is_type( 'variable' ) ) {
			$prices          = $p->get_variation_prices( false );
			$min             = $prices['price'] ? min( $prices['price'] ) : '';
			$max             = $prices['price'] ? max( $prices['price'] ) : '';
			$row['range']    = array( $min, $max );
			$row['variants'] = count( $p->get_children() );
			$total           = 0;
			$managed         = false;
			foreach ( $p->get_children() as $cid ) {
				$c = wc_get_product( $cid );
				if ( $c && $c->get_manage_stock() ) {
					$managed = true;
					$total  += (int) $c->get_stock_quantity();
				}
			}
			$row['stockTotal'] = $managed ? $total : null;
		}
		return $row;
	}

	public static function products( WP_REST_Request $r ) {
		$args = array(
			'limit'    => 20,
			'page'     => max( 1, (int) $r['page'] ),
			'paginate' => true,
			'status'   => in_array( $r['status'], array( 'publish', 'draft', 'private' ), true ) ? array( $r['status'] ) : array( 'publish', 'draft', 'private', 'pending' ),
			'orderby'  => 'date',
			'order'    => 'DESC',
			'type'     => array( 'simple', 'variable' ),
		);
		if ( '' !== trim( (string) $r['search'] ) ) {
			$args['s'] = sanitize_text_field( $r['search'] );
		}
		if ( (int) $r['category'] ) {
			$term = get_term( (int) $r['category'], 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$args['category'] = array( $term->slug );
			}
		}
		$res = wc_get_products( $args );
		return rest_ensure_response(
			array(
				'items' => array_map( array( __CLASS__, 'product_row' ), $res->products ),
				'total' => (int) $res->total,
				'pages' => (int) $res->max_num_pages,
			)
		);
	}

	public static function product_quick( WP_REST_Request $r ) {
		$p = wc_get_product( (int) $r['id'] );
		if ( ! $p ) {
			return new WP_Error( 'ysm_404', 'Product not found.', array( 'status' => 404 ) );
		}
		if ( $r->has_param( 'status' ) && in_array( $r['status'], array( 'publish', 'draft' ), true ) ) {
			$p->set_status( $r['status'] );
		}
		if ( ! $p->is_type( 'variable' ) ) {
			if ( $r->has_param( 'regular' ) ) {
				$p->set_regular_price( wc_format_decimal( $r['regular'] ) );
			}
			if ( $r->has_param( 'sale' ) ) {
				$p->set_sale_price( '' === (string) $r['sale'] ? '' : wc_format_decimal( $r['sale'] ) );
			}
			if ( $r->has_param( 'stock' ) ) {
				self::apply_stock( $p, $r['stock'] );
			}
		}
		$p->save();
		return rest_ensure_response( self::product_row( wc_get_product( $p->get_id() ) ) );
	}

	public static function product_delete( WP_REST_Request $r ) {
		$p = wc_get_product( (int) $r['id'] );
		if ( ! $p ) {
			return new WP_Error( 'ysm_404', 'Product not found.', array( 'status' => 404 ) );
		}
		$p->delete( false ); // move to Trash (can be restored from WordPress)
		return rest_ensure_response( array( 'trashed' => $p->get_id() ) );
	}

	/** Empty value = stock not tracked (always in stock); a number = track stock. */
	private static function apply_stock( $p, $stock ) {
		if ( '' === (string) $stock || null === $stock ) {
			$p->set_manage_stock( false );
			$p->set_stock_status( 'instock' );
		} else {
			$p->set_manage_stock( true );
			$p->set_stock_quantity( (int) $stock );
		}
	}

	/** Store-wide data the editor needs: product options (attributes with values) and categories. */
	public static function meta() {
		$attrs = array();
		foreach ( wc_get_attribute_taxonomies() as $a ) {
			$tax   = wc_attribute_taxonomy_name( $a->attribute_name );
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'orderby' => 'menu_order' ) );
			$attrs[] = array(
				'id'       => (int) $a->attribute_id,
				'name'     => $a->attribute_label,
				'taxonomy' => $tax,
				'terms'    => is_wp_error( $terms ) ? array() : array_map(
					function ( $t ) {
						return array( 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug );
					},
					$terms
				),
			);
		}
		$cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ) );
		$cats = is_wp_error( $cats ) ? array() : array_map(
			function ( $t ) {
				return array( 'id' => $t->term_id, 'name' => html_entity_decode( $t->name ), 'parent' => $t->parent );
			},
			$cats
		);
		return rest_ensure_response( array( 'attributes' => $attrs, 'categories' => $cats, 'defaultCat' => (int) get_option( 'default_product_cat' ) ) );
	}

	public static function attribute_create( WP_REST_Request $r ) {
		$name = trim( sanitize_text_field( (string) $r['name'] ) );
		if ( '' === $name ) {
			return new WP_Error( 'ysm_bad', 'Enter a name for the option.', array( 'status' => 400 ) );
		}
		foreach ( wc_get_attribute_taxonomies() as $a ) {
			if ( strtolower( $a->attribute_label ) === strtolower( $name ) ) {
				return self::meta();
			}
		}
		$id = wc_create_attribute( array( 'name' => $name, 'slug' => sanitize_title( $name ), 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		// Make the new taxonomy usable within this same request.
		$tax = wc_attribute_taxonomy_name( sanitize_title( $name ) );
		if ( ! taxonomy_exists( $tax ) ) {
			register_taxonomy( $tax, array( 'product' ), array( 'hierarchical' => false, 'show_ui' => false, 'query_var' => false, 'rewrite' => false ) );
		}
		delete_transient( 'wc_attribute_taxonomies' );
		return self::meta();
	}

	public static function term_create( WP_REST_Request $r ) {
		$attr = wc_get_attribute( (int) $r['id'] );
		$name = trim( sanitize_text_field( (string) $r['name'] ) );
		if ( ! $attr || '' === $name ) {
			return new WP_Error( 'ysm_bad', 'Could not add that value.', array( 'status' => 400 ) );
		}
		$tax = $attr->slug;
		if ( ! taxonomy_exists( $tax ) ) {
			register_taxonomy( $tax, array( 'product' ), array( 'hierarchical' => false, 'show_ui' => false, 'query_var' => false, 'rewrite' => false ) );
		}
		$existing = get_term_by( 'name', $name, $tax );
		if ( ! $existing ) {
			$t = wp_insert_term( $name, $tax );
			if ( is_wp_error( $t ) ) {
				return $t;
			}
			$term_id = $t['term_id'];
		} else {
			$term_id = $existing->term_id;
		}
		$term = get_term( $term_id, $tax );
		return rest_ensure_response( array( 'id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug ) );
	}

	public static function category_create( WP_REST_Request $r ) {
		$name = trim( sanitize_text_field( (string) $r['name'] ) );
		if ( '' === $name ) {
			return new WP_Error( 'ysm_bad', 'Enter a category name.', array( 'status' => 400 ) );
		}
		$t = wp_insert_term( $name, 'product_cat', array( 'parent' => (int) $r['parent'] ) );
		if ( is_wp_error( $t ) ) {
			if ( 'term_exists' === $t->get_error_code() ) {
				$id = (int) $t->get_error_data();
				return rest_ensure_response( array( 'id' => $id, 'name' => $name, 'parent' => (int) $r['parent'] ) );
			}
			return $t;
		}
		return rest_ensure_response( array( 'id' => $t['term_id'], 'name' => $name, 'parent' => (int) $r['parent'] ) );
	}

	/** Everything the product editor needs for one product. */
	public static function product_get( WP_REST_Request $r ) {
		$p = wc_get_product( (int) $r['id'] );
		if ( ! $p || ! in_array( $p->get_type(), array( 'simple', 'variable' ), true ) ) {
			return new WP_Error( 'ysm_404', 'This product can only be edited in the full WordPress editor.', array( 'status' => 404 ) );
		}
		$gallery = array();
		foreach ( $p->get_gallery_image_ids() as $gid ) {
			$gallery[] = array( 'id' => (int) $gid, 'url' => self::thumb( $gid, 'medium' ) );
		}
		$data = array(
			'id'          => $p->get_id(),
			'name'        => $p->get_name(),
			'status'      => $p->get_status(),
			'description' => $p->get_description(),
			'short'       => $p->get_short_description(),
			'sku'         => $p->get_sku(),
			'regular'     => $p->get_regular_price(),
			'sale'        => $p->get_sale_price(),
			'stock'       => $p->get_manage_stock() ? $p->get_stock_quantity() : '',
			'categories'  => $p->get_category_ids(),
			'image'       => $p->get_image_id() ? array( 'id' => (int) $p->get_image_id(), 'url' => self::thumb( $p->get_image_id(), 'medium' ) ) : null,
			'gallery'     => $gallery,
			'hasOptions'  => $p->is_type( 'variable' ),
			'options'     => array(),
			'variants'    => array(),
			'permalink'   => get_permalink( $p->get_id() ),
		);
		if ( $p->is_type( 'variable' ) ) {
			foreach ( $p->get_attributes() as $attr ) {
				if ( ! $attr->is_taxonomy() || ! $attr->get_variation() ) {
					continue;
				}
				$data['options'][] = array(
					'attributeId' => (int) $attr->get_id(),
					'taxonomy'    => $attr->get_name(),
					'termIds'     => array_map( 'intval', $attr->get_options() ),
				);
			}
			foreach ( $p->get_children() as $cid ) {
				$v = wc_get_product( $cid );
				if ( ! $v ) {
					continue;
				}
				$key = array();
				foreach ( $v->get_attributes() as $tax => $slug ) {
					$term        = get_term_by( 'slug', $slug, $tax );
					$key[ $tax ] = $term ? (int) $term->term_id : 0;
				}
				$data['variants'][] = array(
					'id'      => $v->get_id(),
					'key'     => $key,
					'regular' => $v->get_regular_price(),
					'sale'    => $v->get_sale_price(),
					'stock'   => $v->get_manage_stock() ? $v->get_stock_quantity() : '',
					'sku'     => $v->get_sku(),
					'enabled' => 'publish' === $v->get_status(),
					'image'   => $v->get_image_id() ? array( 'id' => (int) $v->get_image_id(), 'url' => self::thumb( $v->get_image_id() ) ) : null,
				);
			}
		}
		return rest_ensure_response( $data );
	}

	/**
	 * Creates or updates a product. With options, every variation is created/updated in the same request:
	 * one price for all, per-row overrides, combinations that were removed are deleted.
	 */
	public static function product_save( WP_REST_Request $r ) {
		$d    = $r->get_json_params();
		$id   = isset( $d['id'] ) ? (int) $d['id'] : 0;
		$name = isset( $d['name'] ) ? trim( sanitize_text_field( $d['name'] ) ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'ysm_bad', 'Please give the product a name.', array( 'status' => 400 ) );
		}
		$has_options = ! empty( $d['hasOptions'] ) && ! empty( $d['options'] );

		$p = $id ? wc_get_product( $id ) : null;
		if ( $id && ! $p ) {
			return new WP_Error( 'ysm_404', 'Product not found.', array( 'status' => 404 ) );
		}
		// Switch product type when options are turned on or off.
		$want = $has_options ? 'WC_Product_Variable' : 'WC_Product_Simple';
		if ( ! $p ) {
			$p = new $want();
		} elseif ( ! ( $p instanceof $want ) ) {
			if ( ! $has_options && $p->is_type( 'variable' ) ) {
				foreach ( $p->get_children() as $cid ) {
					$c = wc_get_product( $cid );
					if ( $c ) {
						$c->delete( true );
					}
				}
			}
			wp_set_object_terms( $p->get_id(), $has_options ? 'variable' : 'simple', 'product_type' );
			$p = new $want( $p->get_id() );
		}

		$p->set_name( $name );
		$p->set_status( isset( $d['status'] ) && 'draft' === $d['status'] ? 'draft' : 'publish' );
		$p->set_description( isset( $d['description'] ) ? wp_kses_post( $d['description'] ) : '' );
		$p->set_short_description( isset( $d['short'] ) ? wp_kses_post( $d['short'] ) : '' );
		if ( isset( $d['sku'] ) ) {
			try {
				$p->set_sku( sanitize_text_field( $d['sku'] ) );
			} catch ( Exception $e ) {
				return new WP_Error( 'ysm_sku', 'That SKU is already used by another product.', array( 'status' => 400 ) );
			}
		}
		$cats = isset( $d['categories'] ) ? array_filter( array_map( 'intval', (array) $d['categories'] ) ) : array();
		$p->set_category_ids( $cats );
		$p->set_image_id( ! empty( $d['imageId'] ) ? (int) $d['imageId'] : '' );
		$p->set_gallery_image_ids( isset( $d['galleryIds'] ) ? array_map( 'intval', (array) $d['galleryIds'] ) : array() );

		if ( ! $has_options ) {
			$p->set_regular_price( isset( $d['regular'] ) ? wc_format_decimal( $d['regular'] ) : '' );
			$p->set_sale_price( isset( $d['sale'] ) && '' !== (string) $d['sale'] ? wc_format_decimal( $d['sale'] ) : '' );
			self::apply_stock( $p, isset( $d['stock'] ) ? $d['stock'] : '' );
			$p->save();
			return self::product_get( self::req_id( $p->get_id() ) );
		}

		// Options (attributes) used for variations.
		$attributes = array();
		$position   = 0;
		$taxes      = array();
		foreach ( (array) $d['options'] as $opt ) {
			$aid  = (int) $opt['attributeId'];
			$attr = wc_get_attribute( $aid );
			$ids  = array_values( array_filter( array_map( 'intval', (array) $opt['termIds'] ) ) );
			if ( ! $attr || ! $ids ) {
				continue;
			}
			$a = new WC_Product_Attribute();
			$a->set_id( $aid );
			$a->set_name( $attr->slug );
			$a->set_options( $ids );
			$a->set_position( $position++ );
			$a->set_visible( true );
			$a->set_variation( true );
			$attributes[ $attr->slug ] = $a;
			$taxes[]                   = $attr->slug;
		}
		// Keep any non-variation attributes the product already had (e.g. "Material" text set in WordPress).
		foreach ( $p->get_attributes() as $k => $old ) {
			if ( ! isset( $attributes[ $k ] ) && ! $old->get_variation() ) {
				$attributes[ $k ] = $old;
			}
		}
		$p->set_attributes( $attributes );
		$p->save();
		$pid = $p->get_id();
		foreach ( $attributes as $tax => $a ) {
			if ( $a->is_taxonomy() ) {
				wp_set_object_terms( $pid, $a->get_options(), $tax );
			}
		}

		// Variations.
		$default_regular = isset( $d['regular'] ) ? wc_format_decimal( $d['regular'] ) : '';
		$default_sale    = isset( $d['sale'] ) && '' !== (string) $d['sale'] ? wc_format_decimal( $d['sale'] ) : '';
		$existing        = array();
		foreach ( $p->get_children() as $cid ) {
			$existing[ (int) $cid ] = true;
		}
		$kept = array();
		foreach ( (array) $d['variants'] as $vd ) {
			$v = ! empty( $vd['id'] ) && isset( $existing[ (int) $vd['id'] ] ) ? wc_get_product( (int) $vd['id'] ) : null;
			if ( ! $v ) {
				$v = new WC_Product_Variation();
				$v->set_parent_id( $pid );
			}
			$va = array();
			foreach ( (array) $vd['key'] as $tax => $term_id ) {
				if ( ! in_array( $tax, $taxes, true ) ) {
					continue;
				}
				$term = get_term( (int) $term_id, $tax );
				if ( $term && ! is_wp_error( $term ) ) {
					$va[ $tax ] = $term->slug;
				}
			}
			if ( count( $va ) !== count( $taxes ) ) {
				continue;
			}
			$v->set_attributes( $va );
			$reg  = isset( $vd['regular'] ) && '' !== (string) $vd['regular'] ? wc_format_decimal( $vd['regular'] ) : $default_regular;
			$sale = isset( $vd['sale'] ) && '' !== (string) $vd['sale'] ? wc_format_decimal( $vd['sale'] ) : $default_sale;
			$v->set_regular_price( $reg );
			$v->set_sale_price( $sale && (float) $sale < (float) $reg ? $sale : '' );
			self::apply_stock( $v, isset( $vd['stock'] ) ? $vd['stock'] : '' );
			$v->set_status( isset( $vd['enabled'] ) && ! $vd['enabled'] ? 'private' : 'publish' );
			$v->set_image_id( ! empty( $vd['imageId'] ) ? (int) $vd['imageId'] : '' );
			if ( isset( $vd['sku'] ) ) {
				try {
					$v->set_sku( sanitize_text_field( $vd['sku'] ) );
				} catch ( Exception $e ) {
					$v->set_sku( '' );
				}
			}
			$v->save();
			$kept[ $v->get_id() ] = true;
		}
		foreach ( array_keys( $existing ) as $cid ) {
			if ( ! isset( $kept[ $cid ] ) ) {
				$c = wc_get_product( $cid );
				if ( $c ) {
					$c->delete( true );
				}
			}
		}
		WC_Product_Variable::sync( $pid );
		wc_delete_product_transients( $pid );
		return self::product_get( self::req_id( $pid ) );
	}

	private static function req_id( $id ) {
		$r = new WP_REST_Request( 'GET' );
		$r->set_url_params( array( 'id' => $id ) );
		return $r;
	}

	/* -------------------------------------------------------- inventory */

	public static function inventory( WP_REST_Request $r ) {
		global $wpdb;
		$filter    = in_array( $r['filter'], array( 'low', 'out' ), true ) ? $r['filter'] : 'all';
		$page      = max( 1, (int) $r['page'] );
		$per       = 30;
		$threshold = (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
		$search    = trim( (string) $r['search'] );

		// Sellable items = simple products + variations of variable products (not the variable parents).
		$where = "p.post_status IN ('publish','private','draft') AND ( (p.post_type='product' AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id JOIN {$wpdb->terms} t ON t.term_id=tt.term_id WHERE tr.object_id=p.ID AND tt.taxonomy='product_type' AND t.slug <> 'simple')) OR (p.post_type='product_variation' AND EXISTS (SELECT 1 FROM {$wpdb->posts} pp WHERE pp.ID=p.post_parent AND pp.post_status IN ('publish','private','draft'))) )";
		$join  = "LEFT JOIN {$wpdb->postmeta} ss ON ss.post_id=p.ID AND ss.meta_key='_stock_status' LEFT JOIN {$wpdb->postmeta} st ON st.post_id=p.ID AND st.meta_key='_stock' LEFT JOIN {$wpdb->postmeta} ms ON ms.post_id=p.ID AND ms.meta_key='_manage_stock'";
		if ( 'out' === $filter ) {
			$where .= " AND ss.meta_value='outofstock'";
		} elseif ( 'low' === $filter ) {
			$where .= $wpdb->prepare( " AND ms.meta_value='yes' AND CAST(st.meta_value AS SIGNED) <= %d", $threshold );
		}
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= $wpdb->prepare( " AND (p.post_title LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->posts} pp WHERE pp.ID=p.post_parent AND pp.post_title LIKE %s))", $like, $like );
		}
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p {$join} WHERE {$where}" ); // phpcs:ignore
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p {$join} WHERE {$where} ORDER BY COALESCE(NULLIF(p.post_parent,0),p.ID) DESC, p.menu_order ASC, p.ID ASC LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ) ); // phpcs:ignore
		$items = array();
		foreach ( $ids as $id ) {
			$x = wc_get_product( (int) $id );
			if ( ! $x ) {
				continue;
			}
			$variant = $x->is_type( 'variation' );
			$items[] = array(
				'id'       => $x->get_id(),
				'parentId' => $x->get_parent_id(),
				'name'     => $variant ? get_the_title( $x->get_parent_id() ) : $x->get_name(),
				'variant'  => $variant ? wc_get_formatted_variation( $x, true, false, false ) : '',
				'sku'      => $x->get_sku(),
				'manage'   => $x->get_manage_stock(),
				'stock'    => $x->get_stock_quantity(),
				'status'   => $x->get_stock_status(),
				'image'    => self::thumb( $x->get_image_id() ? $x->get_image_id() : ( $variant ? get_post_thumbnail_id( $x->get_parent_id() ) : 0 ) ),
			);
		}
		return rest_ensure_response( array( 'items' => $items, 'total' => $total, 'pages' => (int) ceil( $total / $per ), 'threshold' => $threshold ) );
	}

	public static function stock_set( WP_REST_Request $r ) {
		$x = wc_get_product( (int) $r['id'] );
		if ( ! $x || $x->is_type( 'variable' ) ) {
			return new WP_Error( 'ysm_404', 'Item not found.', array( 'status' => 404 ) );
		}
		if ( $r->has_param( 'delta' ) ) {
			$x->set_manage_stock( true );
			$x->set_stock_quantity( max( 0, (int) $x->get_stock_quantity() + (int) $r['delta'] ) );
		} else {
			self::apply_stock( $x, $r['stock'] );
		}
		$x->save();
		if ( $x->get_parent_id() ) {
			WC_Product_Variable::sync( $x->get_parent_id() );
		}
		self::bump_cache();
		return rest_ensure_response( array( 'id' => $x->get_id(), 'stock' => $x->get_stock_quantity(), 'manage' => $x->get_manage_stock(), 'status' => $x->get_stock_status() ) );
	}
}
