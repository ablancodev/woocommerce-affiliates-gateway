<?php
class WoocommerceAffiliatesGateway extends WC_Payment_Gateway {

	function __construct() {

		$this->id = WOO_AFFILIATES_GATEWAY_ID;

		$this->method_title = __( "Affiliates Gateway", WOO_AFFILIATES_GATEWAY_DOMAIN );

		$this->method_description = __( "Pay with your commissions", WOO_AFFILIATES_GATEWAY_DOMAIN );

		$this->title = __( "Affiliates Gateway", WOO_AFFILIATES_GATEWAY_DOMAIN );

		$this->icon = WOO_AFFILIATES_GATEWAY_PLUGIN_URL . 'images/affiliates-gateway.png';

		$this->has_fields = true;

		$this->init_form_fields();

		$this->init_settings();
		
		// Turn these settings into variables we can use
		foreach ( $this->settings as $setting_key => $value ) {
			$this->$setting_key = $value;
		}
		
		// Save settings
		if ( is_admin() ) {
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		}
	} 

	/** 
	 * Build the administration fields for this specific Gateway
	 **/
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'		=> __( 'Enable / Disable', WOO_AFFILIATES_GATEWAY_DOMAIN ),
				'label'		=> __( 'Enable this payment gateway', WOO_AFFILIATES_GATEWAY_DOMAIN ),
				'type'		=> 'checkbox',
				'default'	=> 'no',
			),
			'title' => array(
				'title'		=> __( 'Title', WOO_AFFILIATES_GATEWAY_DOMAIN ),
				'type'		=> 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
				'desc_tip'	=> true,
				'default'	=> __( 'Pay with your commissions', WOO_AFFILIATES_GATEWAY_DOMAIN ),
			),
			'description' => array(
				'title'		=> __( 'Description', WOO_AFFILIATES_GATEWAY_DOMAIN ),
				'type'		=> 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
				'desc_tip'	=> true,
				'default'	=> __( 'Use your $ %.2f on commissions to pay it.', WOO_AFFILIATES_GATEWAY_DOMAIN ),
				'css'		=> 'max-width:350px;'
			),
			'refund' => array(
					'title'		=> __( 'Refund?', WOO_AFFILIATES_GATEWAY_DOMAIN ),
					'label'		=> __( 'Add a referral if the order is refunded', WOO_AFFILIATES_GATEWAY_DOMAIN ),
					'type'		=> 'checkbox',
					'default'	=> 'no',
			)
		);
	}

	// Submit payment and handle response
	public function process_payment( $order_id ) {
		global $woocommerce;
		global $wpdb;
		
		$referrals_table = _affiliates_get_tablename( 'referrals' );
		
		// Get this Order's information so that we know
		// who to charge and how much
		$customer_order = new WC_Order( $order_id );
		
		$aff_id = Affiliates_Affiliate_WordPress::get_user_affiliate_id();
		if ( $aff_id === false ) {
			// Transaction was not succesful
			// Add notice to the cart
			wc_add_notice( __( "Error, you are not an affiliate !!", WOO_AFFILIATES_GATEWAY_DOMAIN ), 'error' );
			// Add note to the order for your reference
			$customer_order->add_order_note( __( "Error, you are not an affiliate !!", WOO_AFFILIATES_GATEWAY_DOMAIN ) );
		} else {
			$totals = Affiliates_Shortcodes::get_total( $aff_id, null, null, AFFILIATES_REFERRAL_STATUS_ACCEPTED );
			$total = $totals[$customer_order->order_currency];
			$order_total = $customer_order->order_total;
			
			if ( $total >= $order_total ) {
				// get the referrals 
				$query = $wpdb->prepare("
						SELECT r.*
						FROM $referrals_table r
						WHERE affiliate_id = %d AND status = %s AND currency_id = %s
						",
						$aff_id,
						AFFILIATES_REFERRAL_STATUS_ACCEPTED,
						$customer_order->order_currency
				);
				$referrals = $wpdb->get_results( $query, OBJECT );
				
				$referrals_to_close = array();
				$ac_total = 0;
				foreach ( $referrals as $referral ) {
					if ( $ac_total < $order_total ) {
						$referrals_to_close[] = $referral->referral_id;
						$ac_total += $referral->amount;
					}
				}
				// Closing the referrals
				$closed_ok = true;
				foreach ( $referrals_to_close as $referral ) {
					if ( ! $wpdb->query( $wpdb->prepare(
							"UPDATE $referrals_table SET status = %s WHERE affiliate_id = %d AND referral_id = %d",
							AFFILIATES_REFERRAL_STATUS_CLOSED,
							$aff_id,
							$referral
					) ) ) {
						
						$closed_ok = false;
					}
				}
				if ( $closed_ok ) {
					if ( $ac_total > $order_total ) { // we need to add a referral with the difference
						$description = sprintf( __( "Woocommerce payment difference. Order id %s", WOO_AFFILIATES_GATEWAY_DOMAIN ), $order_id );
						$amount = $ac_total - $order_total;
						$currency_id = $customer_order->order_currency;
						
						if ( class_exists( 'Affiliates_Referral_WordPress' ) ) {
							$r = new Affiliates_Referral_WordPress();
							$r->add_referrals( array( $aff_id ), null, $description, null, null, $amount, $currency_id);
						} else {
							affiliates_add_referral( $aff_id, null, $description, null, $amount, $currency_id);
						}
					}
					
					// Payment has been successful
					$customer_order->add_order_note( __( 'Payment completed with commissions.', WOO_AFFILIATES_GATEWAY_DOMAIN ) );
					
					// Mark order as Paid
					$customer_order->payment_complete();
					
					// Empty the cart (Very important step)
					$woocommerce->cart->empty_cart();
					
					// Redirect to thank you page
					return array(
							'result'   => 'success',
							'redirect' => $this->get_return_url( $customer_order ),
					);
					
				} else {
					// Transaction was not succesful
					// Add notice to the cart
					wc_add_notice( __( "Error, not closed correctly !!", WOO_AFFILIATES_GATEWAY_DOMAIN ), 'error' );
					// Add note to the order for your reference
					$customer_order->add_order_note( __( "Error, not closed correctly !!", WOO_AFFILIATES_GATEWAY_DOMAIN ) );
				}
			} else {
				// Transaction was not succesful
				// Add notice to the cart
				wc_add_notice( __( "Error, you don't have enough credit.", WOO_AFFILIATES_GATEWAY_DOMAIN ), 'error' );
				// Add note to the order for your reference
				$customer_order->add_order_note( __( "Error, you don't have enough credit.", WOO_AFFILIATES_GATEWAY_DOMAIN ) );
			}
		}
	}

	public static function woocommerce_order_status_refunded( $order_id ) {
		global $woocommerce;
		global $wpdb;

		$gateway = new WoocommerceAffiliatesGateway();
		
		if ( isset( $gateway->settings['refund'] ) && ( $gateway->settings['refund'] == 'yes' ) ) {
		
		$order = new WC_Order( $order_id );

		$payment_method = get_post_meta( $order_id, '_payment_method', true );
		if ( $payment_method == WOO_AFFILIATES_GATEWAY_ID ) {

			$user_id = $order->get_user_id();
			if ( $user_id )  {

				$aff_id = Affiliates_Affiliate_WordPress::get_user_affiliate_id( $user_id );
				if ( $aff_id ) {
					$currency_id = $order->order_currency;
					$amount = $order->get_total_refunded(); //$order->order_total;

					$description = sprintf( __( "Woocommerce order refunded. Order id %s", WOO_AFFILIATES_GATEWAY_DOMAIN ), $order_id );

					if ( class_exists( 'Affiliates_Referral_WordPress' ) ) {
						$r = new Affiliates_Referral_WordPress();
						$r->add_referrals( array( $aff_id ), null, $description, null, null, $amount, $currency_id);
					} else {
						affiliates_add_referral( $aff_id, null, $description, null, $amount, $currency_id);
					}
				}
			}
		}
		
		}
	}

	// Validate fields
	public function validate_fields() {
		return true;
	}

} 