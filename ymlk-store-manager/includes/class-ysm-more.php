<?php
/**
 * Store Manager phase 2: customers, discounts (coupons), CSV export, new-order alerts and help videos.
 * Same REST namespace as the rest of the app (ysm/v1). Uses WooCommerce's own data; no new tables.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YSM_More {

	const ALERTS = 'ysm_order_alerts';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		// New-order alerts: remember orders the moment they are paid (or put on hold, e.g. bank transfer / cash on delivery).
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'remember_order' ) );
		add_action( 'woocommerce_order_status_on-hold', array( __CLASS__, 'remember_order' ) );
	}

	public static function routes() {
		$orders  = function () {
			return current_user_can( 'edit_shop_orders' );
		};
		$coupons = function () {
			return current_user_can( 'edit_shop_coupons' );
		};
		$manage  = function () {
			return current_user_can( 'manage_woocommerce' );
		};
		$r = function ( $route, $method, $cb, $perm ) {
			register_rest_route( YSM_REST::NS, $route, array( 'methods' => $method, 'callback' => array( __CLASS__, $cb ), 'permission_callback' => $perm ) );
		};
		$r( '/customers', 'GET', 'customers', $orders );
		$r( '/customer', 'GET', 'customer', $orders );
		$r( '/coupons', 'GET', 'coupons', $coupons );
		$r( '/coupons', 'POST', 'coupon_save', $coupons );
		$r( '/coupons/(?P<id>\d+)', 'GET', 'coupon_get', $coupons );
		$r( '/coupons/(?P<id>\d+)', 'DELETE', 'coupon_delete', $coupons );
		$r( '/export', 'GET', 'export', $orders );
		$r( '/alerts/poll', 'GET', 'alerts_poll', $orders );
		$r( '/alerts/settings', 'GET', 'alerts_settings', $manage );
		$r( '/alerts/settings', 'POST', 'alerts_settings_save', $manage );
		$r( '/help', 'GET', 'help', $orders );
	}

	/* ------------------------------------------------------------ helpers */

	private static function money( $v ) {
		return round( (float) $v, wc_get_price_decimals() );
	}

	private static function is_test_order( $order ) {
		return $order->get_meta( '_ymlk_smt_test' ) || $order->get_meta( '_ymlk_tr_test' );
	}

	/** Statuses that count as real sales. */
	private static function paid_statuses() {
		return array( 'wc-processing', 'wc-completed', 'wc-on-hold' );
	}

	private static function in_list( $list ) {
		global $wpdb;
		return "'" . implode( "','", array_map( 'esc_sql', $list ) ) . "'";
	}

	/* ---------------------------------------------------------- customers */

	/**
	 * Everyone who has ordered, grouped by billing email (covers guests and registered customers).
	 */
	public static function customers( WP_REST_Request $r ) {
		global $wpdb;
		$page   = max( 1, (int) $r['page'] );
		$per    = 20;
		$sort   = in_array( $r['sort'], array( 'spent', 'orders' ), true ) ? $r['sort'] : 'recent';
		$order  = array( 'recent' => 'last_date DESC', 'spent' => 'spent DESC', 'orders' => 'orders DESC' )[ $sort ];
		$search = trim( (string) $r['search'] );
		$paid   = self::in_list( self::paid_statuses() );
		$skip   = self::in_list( array( 'trash', 'auto-draft', 'wc-checkout-draft', 'checkout-draft', 'draft' ) );

		if ( YSM_REST::hpos() ) {
			$o     = $wpdb->prefix . 'wc_orders';
			$a     = $wpdb->prefix . 'wc_order_addresses';
			$where = "o.type='shop_order' AND o.status NOT IN ({$skip}) AND o.billing_email <> ''";
			if ( '' !== $search ) {
				$like   = '%' . $wpdb->esc_like( $search ) . '%';
				$where .= $wpdb->prepare( " AND (o.billing_email LIKE %s OR CONCAT_WS(' ', a.first_name, a.last_name) LIKE %s OR a.phone LIKE %s)", $like, $like, $like );
			}
			$from  = "{$o} o LEFT JOIN {$a} a ON a.order_id=o.id AND a.address_type='billing'";
			$total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT o.billing_email) FROM {$from} WHERE {$where}" ); // phpcs:ignore
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT o.billing_email AS email, COUNT(*) AS orders, SUM(CASE WHEN o.status IN ({$paid}) THEN o.total_amount ELSE 0 END) AS spent, MAX(o.date_created_gmt) AS last_date, MAX(o.id) AS last_id FROM {$from} WHERE {$where} GROUP BY o.billing_email ORDER BY {$order} LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ) ); // phpcs:ignore
		} else {
			$p     = $wpdb->posts;
			$m     = $wpdb->postmeta;
			$where = "p.post_type='shop_order' AND p.post_status NOT IN ({$skip}) AND e.meta_value <> ''";
			if ( '' !== $search ) {
				$ids    = wc_order_search( $search );
				$where .= $ids ? ' AND p.ID IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')' : ' AND 0';
			}
			$from  = "{$p} p JOIN {$m} e ON e.post_id=p.ID AND e.meta_key='_billing_email' LEFT JOIN {$m} t ON t.post_id=p.ID AND t.meta_key='_order_total'";
			$total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT e.meta_value) FROM {$from} WHERE {$where}" ); // phpcs:ignore
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT e.meta_value AS email, COUNT(*) AS orders, SUM(CASE WHEN p.post_status IN ({$paid}) THEN t.meta_value ELSE 0 END) AS spent, MAX(p.post_date_gmt) AS last_date, MAX(p.ID) AS last_id FROM {$from} WHERE {$where} GROUP BY e.meta_value ORDER BY {$order} LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ) ); // phpcs:ignore
		}

		$items = array();
		foreach ( (array) $rows as $row ) {
			$last    = wc_get_order( (int) $row->last_id );
			$name    = $last ? trim( $last->get_billing_first_name() . ' ' . $last->get_billing_last_name() ) : '';
			$items[] = array(
				'email'  => $row->email,
				'name'   => '' !== $name ? $name : $row->email,
				'phone'  => $last ? $last->get_billing_phone() : '',
				'city'   => $last ? $last->get_billing_city() : '',
				'orders' => (int) $row->orders,
				'spent'  => self::money( $row->spent ),
				'last'   => $row->last_date ? wp_date( 'j M Y', strtotime( $row->last_date . ' UTC' ) ) : '',
			);
		}
		return rest_ensure_response( array( 'items' => $items, 'total' => $total, 'pages' => (int) ceil( $total / $per ) ) );
	}

	public static function customer( WP_REST_Request $r ) {
		$email = sanitize_email( (string) $r['email'] );
		if ( ! $email ) {
			return new WP_Error( 'ysm_bad', 'Customer not found.', array( 'status' => 404 ) );
		}
		$orders = wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => 100,
				'orderby'       => 'date',
				'order'         => 'DESC',
				'type'          => 'shop_order',
				'status'        => array_keys( wc_get_order_statuses() ),
			)
		);
		if ( ! $orders ) {
			return new WP_Error( 'ysm_404', 'Customer not found.', array( 'status' => 404 ) );
		}
		$paid  = array( 'processing', 'completed', 'on-hold' );
		$spent = 0;
		$count = 0;
		$rows  = array();
		foreach ( $orders as $o ) {
			if ( in_array( $o->get_status(), $paid, true ) ) {
				$spent += (float) $o->get_total();
				$count++;
			}
			$rows[] = array(
				'id'       => $o->get_id(),
				'number'   => $o->get_order_number(),
				'status'   => $o->get_status(),
				'date'     => $o->get_date_created() ? $o->get_date_created()->date_i18n( 'j M Y, H:i' ) : '',
				'total'    => self::money( $o->get_total() ),
				'customer' => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
				'phone'    => $o->get_billing_phone(),
				'items'    => $o->get_item_count(),
			);
		}
		$latest = $orders[0];
		$first  = end( $orders );
		$user   = get_user_by( 'email', $email );
		$addr   = WC()->countries->get_formatted_address( $latest->get_address( 'billing' ), "\n" );
		$name   = trim( $latest->get_billing_first_name() . ' ' . $latest->get_billing_last_name() );
		$labels = array();
		foreach ( wc_get_order_statuses() as $k => $l ) {
			$labels[ substr( $k, 3 ) ] = $l;
		}
		return rest_ensure_response(
			array(
				'email'     => $email,
				'name'      => '' !== $name ? $name : $email,
				'phone'     => $latest->get_billing_phone(),
				'address'   => trim( wp_strip_all_tags( str_replace( array( '<br/>', '<br>' ), "\n", $addr ) ) ),
				'orders'    => count( $orders ),
				'paid'      => $count,
				'spent'     => self::money( $spent ),
				'average'   => $count ? self::money( $spent / $count ) : 0,
				'first'     => $first->get_date_created() ? $first->get_date_created()->date_i18n( 'j M Y' ) : '',
				'last'      => $latest->get_date_created() ? $latest->get_date_created()->date_i18n( 'j M Y' ) : '',
				'account'   => $user ? 'Has an account since ' . date_i18n( 'j M Y', strtotime( $user->user_registered ) ) : 'Ordered as a guest',
				'list'      => $rows,
				'labels'    => $labels,
			)
		);
	}

	/* ----------------------------------------------------------- coupons */

	private static function coupon_row( WC_Coupon $c ) {
		$post    = get_post( $c->get_id() );
		$expires = $c->get_date_expires();
		$state   = 'active';
		if ( $post && 'publish' !== $post->post_status ) {
			$state = 'off';
		} elseif ( $expires && $expires->getTimestamp() < time() ) {
			$state = 'expired';
		} elseif ( $c->get_usage_limit() && $c->get_usage_count() >= $c->get_usage_limit() ) {
			$state = 'used';
		}
		return array(
			'id'          => $c->get_id(),
			'code'        => strtoupper( $c->get_code() ),
			'type'        => $c->get_discount_type(),
			'amount'      => (float) $c->get_amount(),
			'freeShip'    => $c->get_free_shipping(),
			'minimum'     => $c->get_minimum_amount(),
			'expires'     => $expires ? $expires->date( 'Y-m-d' ) : '',
			'expiresText' => $expires ? $expires->date_i18n( 'j M Y' ) : '',
			'used'        => (int) $c->get_usage_count(),
			'limit'       => $c->get_usage_limit() ? (int) $c->get_usage_limit() : '',
			'perUser'     => $c->get_usage_limit_per_user() ? (int) $c->get_usage_limit_per_user() : '',
			'individual'  => $c->get_individual_use(),
			'noSale'      => $c->get_exclude_sale_items(),
			'note'        => $c->get_description(),
			'state'       => $state,
		);
	}

	public static function coupons( WP_REST_Request $r ) {
		$args = array(
			'post_type'      => 'shop_coupon',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => 20,
			'paged'          => max( 1, (int) $r['page'] ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( '' !== trim( (string) $r['search'] ) ) {
			$args['s'] = sanitize_text_field( $r['search'] );
		}
		$q     = new WP_Query( $args );
		$items = array();
		foreach ( $q->posts as $p ) {
			$items[] = self::coupon_row( new WC_Coupon( $p->ID ) );
		}
		$filter = (string) $r['filter'];
		if ( 'active' === $filter ) {
			$items = array_values( array_filter( $items, function ( $c ) { return 'active' === $c['state']; } ) );
		} elseif ( 'ended' === $filter ) {
			$items = array_values( array_filter( $items, function ( $c ) { return 'active' !== $c['state']; } ) );
		}
		return rest_ensure_response( array( 'items' => $items, 'total' => (int) $q->found_posts, 'pages' => (int) $q->max_num_pages ) );
	}

	public static function coupon_get( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( 'shop_coupon' !== get_post_type( $id ) ) {
			return new WP_Error( 'ysm_404', 'Discount not found.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( self::coupon_row( new WC_Coupon( $id ) ) );
	}

	public static function coupon_save( WP_REST_Request $r ) {
		$d    = $r->get_json_params();
		$id   = isset( $d['id'] ) ? (int) $d['id'] : 0;
		$code = wc_format_coupon_code( isset( $d['code'] ) ? sanitize_text_field( $d['code'] ) : '' );
		if ( '' === $code ) {
			return new WP_Error( 'ysm_bad', 'Please enter a discount code.', array( 'status' => 400 ) );
		}
		if ( $id && 'shop_coupon' !== get_post_type( $id ) ) {
			return new WP_Error( 'ysm_404', 'Discount not found.', array( 'status' => 404 ) );
		}
		if ( wc_get_coupon_id_by_code( $code, $id ) ) {
			return new WP_Error( 'ysm_dup', 'The code ' . strtoupper( $code ) . ' is already used by another discount. Please choose a different code.', array( 'status' => 400 ) );
		}
		$type   = isset( $d['type'] ) && in_array( $d['type'], array( 'percent', 'fixed_cart', 'fixed_product' ), true ) ? $d['type'] : 'percent';
		$amount = isset( $d['amount'] ) ? (float) wc_format_decimal( $d['amount'] ) : 0;
		$free   = ! empty( $d['freeShip'] );
		if ( $amount <= 0 && ! $free ) {
			return new WP_Error( 'ysm_bad', 'Enter how much the discount is, or turn on free delivery.', array( 'status' => 400 ) );
		}
		if ( 'percent' === $type && $amount > 100 ) {
			return new WP_Error( 'ysm_bad', 'A percentage discount cannot be more than 100%.', array( 'status' => 400 ) );
		}
		$c = new WC_Coupon( $id );
		$c->set_code( $code );
		$c->set_discount_type( $type );
		$c->set_amount( $amount );
		$c->set_free_shipping( $free );
		$c->set_minimum_amount( isset( $d['minimum'] ) && '' !== (string) $d['minimum'] ? wc_format_decimal( $d['minimum'] ) : '' );
		$c->set_date_expires( ! empty( $d['expires'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d['expires'] ) ? strtotime( $d['expires'] . ' 23:59:59 ' . wp_timezone_string() ) : null );
		$c->set_usage_limit( isset( $d['limit'] ) && '' !== (string) $d['limit'] ? absint( $d['limit'] ) : 0 );
		$c->set_usage_limit_per_user( isset( $d['perUser'] ) && '' !== (string) $d['perUser'] ? absint( $d['perUser'] ) : 0 );
		$c->set_individual_use( ! empty( $d['individual'] ) );
		$c->set_exclude_sale_items( ! empty( $d['noSale'] ) );
		$c->set_description( isset( $d['note'] ) ? sanitize_textarea_field( $d['note'] ) : '' );
		$c->set_status( isset( $d['on'] ) && ! $d['on'] ? 'draft' : 'publish' );
		$c->save();
		return rest_ensure_response( self::coupon_row( new WC_Coupon( $c->get_id() ) ) );
	}

	public static function coupon_delete( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( 'shop_coupon' !== get_post_type( $id ) ) {
			return new WP_Error( 'ysm_404', 'Discount not found.', array( 'status' => 404 ) );
		}
		wp_trash_post( $id );
		return rest_ensure_response( array( 'trashed' => $id ) );
	}

	/* -------------------------------------------------------------- export */

	private static function csv( $rows ) {
		$fh = fopen( 'php://temp', 'w+' );
		foreach ( $rows as $row ) {
			fputcsv( $fh, array_map( function ( $v ) {
				$v = (string) $v;
				// Stop spreadsheet programs from running cell text as a formula.
				return preg_match( '/^[=+\-@]/', $v ) && ! is_numeric( $v ) ? "'" . $v : $v;
			}, $row ) );
		}
		rewind( $fh );
		$out = stream_get_contents( $fh );
		fclose( $fh );
		return "\xEF\xBB\xBF" . $out; // BOM so Excel shows accents and the currency symbol correctly
	}

	public static function export( WP_REST_Request $r ) {
		$type = (string) $r['type'];
		$site = sanitize_title( get_bloginfo( 'name' ) );
		$date = wp_date( 'Y-m-d' );
		if ( 'orders' === $type ) {
			$args = array( 'limit' => 5000, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order' );
			$st   = sanitize_key( (string) $r['status'] );
			$args['status'] = $st && 'all' !== $st ? array( 'wc-' . $st ) : array_keys( wc_get_order_statuses() );
			$from = (string) $r['from'];
			$to   = (string) $r['to'];
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
				$args['date_created'] = strtotime( $from . ' 00:00:00 ' . wp_timezone_string() ) . '...' . strtotime( $to . ' 23:59:59 ' . wp_timezone_string() );
			}
			$rows = array( array( 'Order', 'Date', 'Status', 'Customer', 'Phone', 'Email', 'Address', 'City', 'Items', 'Payment', 'Subtotal', 'Discount', 'Delivery', 'Total', 'Customer note' ) );
			foreach ( wc_get_orders( $args ) as $o ) {
				$items = array();
				foreach ( $o->get_items() as $it ) {
					$items[] = $it->get_name() . ' x ' . $it->get_quantity();
				}
				$rows[] = array(
					$o->get_order_number(),
					$o->get_date_created() ? $o->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '',
					wc_get_order_status_name( $o->get_status() ),
					trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
					$o->get_billing_phone(),
					$o->get_billing_email(),
					trim( $o->get_billing_address_1() . ' ' . $o->get_billing_address_2() ),
					$o->get_billing_city(),
					implode( '; ', $items ),
					$o->get_payment_method_title(),
					self::money( $o->get_subtotal() ),
					self::money( $o->get_discount_total() ),
					self::money( $o->get_shipping_total() ),
					self::money( $o->get_total() ),
					$o->get_customer_note(),
				);
			}
			return rest_ensure_response( array( 'filename' => $site . '-orders-' . $date . '.csv', 'count' => count( $rows ) - 1, 'csv' => self::csv( $rows ) ) );
		}
		if ( 'customers' === $type ) {
			$rows = array( array( 'Name', 'Email', 'Phone', 'City', 'Orders', 'Total spent', 'Last order' ) );
			$page = 1;
			do {
				$req = new WP_REST_Request( 'GET' );
				$req->set_param( 'page', $page );
				$req->set_param( 'sort', 'spent' );
				$res = self::customers( $req )->get_data();
				foreach ( $res['items'] as $c ) {
					$rows[] = array( $c['name'], $c['email'], $c['phone'], $c['city'], $c['orders'], $c['spent'], $c['last'] );
				}
				$page++;
			} while ( $page <= $res['pages'] && $page <= 250 );
			return rest_ensure_response( array( 'filename' => $site . '-customers-' . $date . '.csv', 'count' => count( $rows ) - 1, 'csv' => self::csv( $rows ) ) );
		}
		if ( 'products' === $type ) {
			$rows = array( array( 'Product', 'Option', 'SKU', 'Price', 'Sale price', 'Stock', 'Stock status', 'Visibility' ) );
			$ids  = wc_get_products( array( 'limit' => 3000, 'status' => array( 'publish', 'draft', 'private' ), 'type' => array( 'simple', 'variable' ), 'return' => 'ids', 'orderby' => 'title', 'order' => 'ASC' ) );
			foreach ( $ids as $id ) {
				$p = wc_get_product( $id );
				if ( ! $p || $p->get_meta( '_ymlk_smt_test' ) || $p->get_meta( '_ymlk_tr_test' ) ) {
					continue;
				}
				$list = $p->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $p->get_children() ) ) : array( $p );
				foreach ( $list as $x ) {
					$rows[] = array(
						$p->get_name(),
						$x->is_type( 'variation' ) ? wc_get_formatted_variation( $x, true, false, false ) : '',
						$x->get_sku(),
						$x->get_regular_price(),
						$x->get_sale_price(),
						$x->get_manage_stock() ? $x->get_stock_quantity() : 'Not counted',
						'outofstock' === $x->get_stock_status() ? 'Out of stock' : 'In stock',
						'publish' === $p->get_status() ? 'Live' : 'Hidden',
					);
				}
			}
			return rest_ensure_response( array( 'filename' => $site . '-products-' . $date . '.csv', 'count' => count( $rows ) - 1, 'csv' => self::csv( $rows ) ) );
		}
		return new WP_Error( 'ysm_bad', 'Unknown export.', array( 'status' => 400 ) );
	}

	/* -------------------------------------------------------------- alerts */

	public static function remember_order( $order_id ) {
		$o = wc_get_order( $order_id );
		if ( ! $o || 'shop_order' !== $o->get_type() || self::is_test_order( $o ) ) {
			return;
		}
		$list = get_option( self::ALERTS, array() );
		$list = is_array( $list ) ? $list : array();
		foreach ( $list as $item ) {
			if ( (int) $item['id'] === (int) $order_id ) {
				return; // already announced (e.g. on hold, then processing)
			}
		}
		$list[] = array( 'id' => (int) $order_id, 't' => microtime( true ) );
		update_option( self::ALERTS, array_slice( $list, -30 ), false );
	}

	public static function alerts_poll( WP_REST_Request $r ) {
		$since = (float) $r['since'];
		$list  = get_option( self::ALERTS, array() );
		$list  = is_array( $list ) ? $list : array();
		$now   = $list ? (float) end( $list )['t'] : 0;
		$items = array();
		if ( $since > 0 ) {
			foreach ( $list as $a ) {
				if ( (float) $a['t'] > $since ) {
					$o = wc_get_order( (int) $a['id'] );
					if ( $o ) {
						$items[] = array(
							'id'       => $o->get_id(),
							'number'   => $o->get_order_number(),
							'customer' => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
							'total'    => self::money( $o->get_total() ),
						);
					}
				}
			}
		}
		$counts = (array) wp_count_posts( 'shop_order' );
		if ( YSM_REST::hpos() ) {
			global $wpdb;
			$waiting = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type='shop_order' AND status IN ('wc-processing','wc-on-hold')" ); // phpcs:ignore
		} else {
			$waiting = (int) ( isset( $counts['wc-processing'] ) ? $counts['wc-processing'] : 0 ) + (int) ( isset( $counts['wc-on-hold'] ) ? $counts['wc-on-hold'] : 0 );
		}
		return rest_ensure_response( array( 'now' => max( $now, $since ), 'items' => $items, 'waiting' => $waiting ) );
	}

	private static function new_order_email() {
		$s = get_option( 'woocommerce_new_order_settings', array() );
		return is_array( $s ) ? $s : array();
	}

	public static function alerts_settings() {
		$s = self::new_order_email();
		return rest_ensure_response(
			array(
				'emailOn' => ! isset( $s['enabled'] ) || 'yes' === $s['enabled'],
				'emails'  => isset( $s['recipient'] ) && '' !== $s['recipient'] ? $s['recipient'] : get_option( 'admin_email' ),
			)
		);
	}

	public static function alerts_settings_save( WP_REST_Request $r ) {
		$list  = array_filter( array_map( 'trim', explode( ',', (string) $r['emails'] ) ) );
		$valid = array();
		foreach ( $list as $e ) {
			if ( ! is_email( $e ) ) {
				return new WP_Error( 'ysm_bad', '"' . $e . '" is not a valid email address.', array( 'status' => 400 ) );
			}
			$valid[] = sanitize_email( $e );
		}
		$s              = self::new_order_email();
		$s['enabled']   = $r['emailOn'] ? 'yes' : 'no';
		$s['recipient'] = implode( ', ', $valid );
		update_option( 'woocommerce_new_order_settings', $s );
		return self::alerts_settings();
	}

	/* ---------------------------------------------------------------- help */

	public static function help() {
		$titles = array();
		if ( class_exists( 'YMLK_SM_Tutorials' ) ) {
			foreach ( YMLK_SM_Tutorials::tutorials() as $k => $t ) {
				$titles[ $k ] = $t['title'];
			}
		}
		$posts = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 100,
				'meta_key'       => '_ymlk_smt_tutorial', // phpcs:ignore
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$latest = array();
		foreach ( $posts as $p ) {
			$k = get_post_meta( $p->ID, '_ymlk_smt_tutorial', true );
			if ( ! isset( $latest[ $k ] ) ) {
				$latest[ $k ] = array( 'key' => $k, 'title' => isset( $titles[ $k ] ) ? $titles[ $k ] : get_the_title( $p ), 'url' => wp_get_attachment_url( $p->ID ) );
			}
		}
		// Show them in the tutorial order (log in first, and so on).
		$order = array_flip( array_keys( $titles ) );
		uasort( $latest, function ( $a, $b ) use ( $order ) {
			$x = isset( $order[ $a['key'] ] ) ? $order[ $a['key'] ] : 99;
			$y = isset( $order[ $b['key'] ] ) ? $order[ $b['key'] ] : 99;
			return $x - $y;
		} );
		$wa = preg_replace( '/[^\d]/', '', (string) get_option( 'ysm_support_whatsapp', '' ) );
		return rest_ensure_response(
			array(
				'videos'   => array_values( $latest ),
				'whatsapp' => $wa,
				'website'  => 'https://ymlktechnologies.com',
			)
		);
	}
}
