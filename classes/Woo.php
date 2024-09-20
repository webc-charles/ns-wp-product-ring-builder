<?php
namespace OTW\WooRingBuilder\Classes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Woo extends \OTW\WooRingBuilder\Plugin {
	use \OTW\GeneralWooRingBuilder\Traits\Singleton;

	public $current_selected_variation = null;

	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
	}

	public function init() {
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 25, 2 );

		add_action( 'woocommerce_add_to_cart', array( $this, 'woocommerce_add_to_cart' ), 99, 6 );

		add_filter( 'woocommerce_cart_item_price', array( $this, 'cart_item_price' ), 10, 3 );

		add_filter( 'woocommerce_get_item_data', array( $this, 'get_item_data' ), 10, 2 );

		add_action( 'woocommerce_before_calculate_totals', array( $this, 'before_calculate_totals' ), 11 );

		add_action( 'woocommerce_new_order_item', array( $this, 'add_order_item_meta' ), 10, 3 );

		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'checkout_create_order_line_item' ), 10, 4 );
	}

	public function add_cart_item_data( $cart_item_data, $product_id ) {
		$parent_product = wc_get_product( $product_id );

		$woocommerce_product_extra_data = isset( $_SERVER['HTTP_WOOCOMMERCE_PRODUCT_EXTRA_DATA'] )
			? sanitize_text_field( $_SERVER['HTTP_WOOCOMMERCE_PRODUCT_EXTRA_DATA'] )
			: null;

		if ( isset( $woocommerce_product_extra_data ) ) {
			$extra_data = json_decode( stripslashes( $woocommerce_product_extra_data ), true );

			if ( json_last_error() === JSON_ERROR_NONE ) {
				WC()->session->set( 'next_session', true );

				WC()->session->set( 'next_diamond_id', $extra_data['diamond_id'] );
			}
		}

		if ( ! $parent_product ) {
			return $cart_item_data;
		}

		$product_cats_ids = wc_get_product_term_ids( $parent_product->get_id(), 'product_cat' );

		error_log( 'product_cat_ids' . print_r( $product_cats_ids, true ) );

		error_log( 'setting_category' . $this->get_option( 'setting_category' ) );

		if ( ! (
			$product_cats_ids &&
			is_array( $product_cats_ids ) &&
			count( $product_cats_ids ) >= 1 &&
			in_array( $this->get_option( 'setting_category' ), $product_cats_ids )
		) ) {
			error_log( 'add_cart_item_data: condition 1' );

			return $cart_item_data;
		}

		if ( ! (
			otw_woo_ring_builder()->diamonds &&
			isset( otw_woo_ring_builder()->diamonds->current_diamond ) &&
			otw_woo_ring_builder()->diamonds->current_diamond
		) ) {
			error_log( 'add_cart_item_data: condition 2' );

			otw_woo_ring_builder()->diamonds->get_current_diamond();
		}

		if ( ! (
			otw_woo_ring_builder()->diamonds &&
			isset( otw_woo_ring_builder()->diamonds->current_diamond ) &&
			otw_woo_ring_builder()->diamonds->current_diamond
		) ) {
			error_log( 'add_cart_item_data: condition 3' );
			return $cart_item_data;
		}

		$diamond = otw_woo_ring_builder()->diamonds->current_diamond;

		$cart_item_data['diamond'] = $diamond;

		WC()->session->set( 'diamond', $diamond );

		return $cart_item_data;
	}

	public function woocommerce_add_to_cart(
		$cart_id,
		$product_id,
		$request_quantity,
		$variation_id,
		$variation,
		$cart_item_data
	) {
		if ( empty( $variation_id ) || ! $variation_id ) {
			return true;
		}

		$variable_product = new \WC_Product_Variation( $variation_id );

		if ( ! $variable_product ) {
			return true;
		}

		if ( ! $this->is_setting_product( $variable_product ) ) {
			return true;
		}

		$cart_items = WC()->cart->get_cart();

		foreach ( $cart_items as $cart_key => $cart_item ) {
			if ( $this->is_setting_product( $cart_item['data'] ) && $cart_key !== $cart_id ) {
				WC()->cart->remove_cart_item( $cart_key );
			}
		}
	}

	public function cart_item_price( $price_html, $cart_item, $cart_item_key ) {
		if ( $this->is_setting_product( $cart_item['data'] ) ) {
			if ( ! (
				otw_woo_ring_builder()->diamonds &&
				isset( otw_woo_ring_builder()->diamonds->current_diamond ) &&
				otw_woo_ring_builder()->diamonds->current_diamond
			) ) {
				otw_woo_ring_builder()->diamonds->get_current_diamond();
			}

			if ( otw_woo_ring_builder()->diamonds &&
				isset( otw_woo_ring_builder()->diamonds->current_diamond ) &&
				otw_woo_ring_builder()->diamonds->current_diamond
			) {
				$diamond = otw_woo_ring_builder()->diamonds->current_diamond;

				$_product = wc_get_product( $cart_item['data']->get_id() );

				if ( $this->is_setting_product( $_product ) &&
					( ( (float) $cart_item['data']->get_price() ) <= (float) $_product->get_price() )
				) {
					$total_ring_price = ( (float) $diamond['total_sales_price'] ) + ( (float) $cart_item['data']->get_price() );

					return wc_price( $total_ring_price );
				}
			}
		}

		return $price_html;

		if ( isset( $cart_item['custom_price'] ) ) {
			$args = array( 'price' => 40 );

			if ( WC()->cart->display_prices_including_tax() ) {
				$product_price = wc_get_price_including_tax( $cart_item['data'], $args );
			} else {
				$product_price = wc_get_price_excluding_tax( $cart_item['data'], $args );
			}

			return wc_price( $product_price );
		}

		return $price_html;
	}

	public function get_item_data( $item_data, $cart_item ) {
		if ( ! is_array( $item_data ) ) {
			$item_data = array();
		}

		$_product = $cart_item['data'];

		if ( ! $_product || ! isset( $cart_item['diamond'] ) ) {
			return $item_data;
		}

		if ( ! (
			otw_woo_ring_builder()->diamonds &&
			isset( otw_woo_ring_builder()->diamonds->current_diamond ) &&
			otw_woo_ring_builder()->diamonds->current_diamond
		) ) {
			return $item_data;
		}

		if ( ! (
			otw_woo_ring_builder()->diamonds &&
			isset( otw_woo_ring_builder()->diamonds->current_diamond ) &&
			otw_woo_ring_builder()->diamonds->current_diamond
		) ) {
			otw_woo_ring_builder()->diamonds->get_current_diamond();
		}

		$diamond = otw_woo_ring_builder()->diamonds->current_diamond;

		if ( $diamond['stock_num'] != $cart_item['diamond']['stock_num'] ) {
			return $item_data;
		}

		$item_data[] = array(
			'type'  => 'text',
			'name'  => 'Stone',
			'key'   => 'Stone',
			'value' => $diamond['size'] . ' carats ' . $diamond['color'] . ' ' . $diamond['clarity'] . ' ',
		);

		if ( isset( $diamond['meas_length'] ) && $diamond['meas_length'] ) {
			$item_data[] = array(
				'type'  => 'text',
				'name'  => 'Dimensions',
				'key'   => 'Dimensions',
				'value' => $diamond['meas_length'] . '/' . $diamond['meas_width'],
			);
		}

		if ( isset( $diamond['cert_num'] ) && $diamond['cert_num'] ) {
			$item_data[] = array(
				'type'  => 'text',
				'name'  => 'Certificate',
				'key'   => 'Certificate',
				'value' => $diamond['cert_num'],
			);
		}

		return $item_data;
	}

	public function checkout_create_order_line_item( $item, $cart_item_key, $values, $order ) {
		if ( ! isset( $values['diamond'] ) ) {
			return false;
		}

		$item->add_meta_data( 'Stone', $values['diamond']['short_title'] );

		if ( isset( $values['diamond']['meas_length'] ) && $values['diamond']['meas_length'] ) {
			$item->add_meta_data( 'Dimensions', $values['diamond']['meas_length'] . '/' . $values['diamond']['meas_width'] );
		}

		if ( isset( $values['diamond']['cert_num'] ) && $values['diamond']['cert_num'] ) {
			$item->add_meta_data( 'Certificate', $values['diamond']['cert_num'] );
		}

		$item->add_meta_data( 'Diamond SKU: ', $values['diamond']['stock_num'] );
	}

	public function before_calculate_totals( $cart ) {
		// Log the cart for debugging purposes
		error_log( 'before_calculate_totals' . print_r( $cart, true ) );

		// Prevent action from running in the admin or during non-AJAX calls
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			error_log( 'before_calculate_totals exception. abort.' );

			return;
		}

		// Loop through all items in the cart
		foreach ( $cart->get_cart() as $cart_key => $cart_item ) {
			// Check if the product is a setting product
			if ( $this->is_setting_product( $cart_item['data'] ) ) {

				// Ensure the current diamond data is available
				if ( ! (
				otw_woo_ring_builder()->diamonds &&
				isset( otw_woo_ring_builder()->diamonds->current_diamond ) &&
				otw_woo_ring_builder()->diamonds->current_diamond )
				) {
					otw_woo_ring_builder()->diamonds->get_current_diamond();
				}

				// If the diamond data is available, adjust the price
				if ( otw_woo_ring_builder()->diamonds &&
				isset( otw_woo_ring_builder()->diamonds->current_diamond ) &&
				otw_woo_ring_builder()->diamonds->current_diamond
				) {
					$diamond = otw_woo_ring_builder()->diamonds->current_diamond;

					$_product = wc_get_product( $cart_item['data']->get_id() );

					// Set the new price if necessary
					if ( (float) $cart_item['data']->get_price() <= (float) $_product->get_price() ) {
						$total_ring_price = ( (float) $diamond['total_sales_price'] ) + (float) $cart_item['data']->get_price();

						$cart_item['data']->set_price( $total_ring_price );
					}
				}

				// Ensure the quantity of this product does not exceed 1
				$cart_quantity = WC()->cart->get_cart_item_quantity( $cart_key );

				if ( $cart_quantity > 1 ) {
					WC()->cart->set_quantity( $cart_key, 1 );
				}
			}
		}
	}


	public function add_order_item_meta( $item_id, $cart_item, $cart_item_key ) {
		if ( isset( $cart_item['diamond'] ) ) {
			wc_add_order_item_meta( $item_id, 'diamond_data', $cart_item['diamond'] );
		}
	}

	public function is_setting_product( $_product ) {
		if ( ! $_product->is_type( 'variation' ) ) {
			return false;
		}

		$product_cats_ids = wc_get_product_term_ids( $_product->get_parent_id(), 'product_cat' );

		if ( $product_cats_ids &&
			is_array( $product_cats_ids ) &&
			count( $product_cats_ids ) >= 1 &&
			in_array( $this->get_option( 'setting_category' ), $product_cats_ids )
		) {
			return true;
		}

		return false;
	}
}
