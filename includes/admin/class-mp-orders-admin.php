<?php

class MP_Orders_Admin {

	/**
	 * Refers to a single instance of the class
	 *
	 * @since 3.0
	 * @access private
	 * @var object
	 */
	private static $_instance = null;

	/**
	 * Refers to the order's IPN history
	 *
	 * @since 3.0
	 * @access protected
	 * @var array
	 */
	protected $_ipn_history = null;

	/**
	 * Gets the single instance of the class
	 *
	 * @since 3.0
	 * @access public
	 * @return object
	 */
	public static function get_instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new MP_Orders_Admin();
		}
		return self::$_instance;
	}

	/**
	 * Constructor function
	 *
	 * @since 3.0
	 * @access private
	 */
	private function __construct() {
		//meta boxes
		add_action( 'add_meta_boxes_mp_order', array( &$this, 'add_meta_boxes' ) );
		add_action( 'save_post', array( &$this, 'save_meta_boxes' ) );
		//perform some actions when order status changes
		add_action( 'transition_post_status', array( &$this, 'change_order_status' ), 10, 3 );
		//add menu items
		add_action( 'admin_menu', array( &$this, 'add_menu_items' ), 9 );
		//change the "enter title here" text
		add_filter( 'enter_title_here', array( &$this, 'enter_title_here' ) );
		//modify coupon list table columns/data
		add_filter( 'manage_mp_order_posts_columns', array( &$this, 'orders_column_headers' ) );
		add_action( 'manage_mp_order_posts_custom_column', array( &$this, 'orders_column_data' ), 10, 2 );
		add_filter( 'manage_edit-mp_order_sortable_columns', array( &$this, 'orders_sortable_columns' ) );
		add_action( 'pre_get_posts', array( &$this, 'modify_query' ) );
		//custom css/javascript
		add_action( 'admin_enqueue_scripts', array( &$this, 'enqueue_css_js' ) );
		//process custom bulk actions
		add_action( 'load-edit.php', array( &$this, 'process_bulk_actions' ) );
		//bulk update admin notice
		add_action( 'admin_notices', array( &$this, 'admin_notices' ) );
		//remove submit div
		add_action( 'admin_menu', create_function( '', 'remove_meta_box( "submitdiv", "mp_order", "side" ); remove_meta_box( "titlediv", "mp_order", "core" );' ) );
		//add export form
		add_action( 'mp_render_settings/store-settings_page_store-settings-exporters', array( &$this, 'export_order_form' ) );
	}

	/**
	 * Save customer info metabox
	 *
	 * @since 3.0
	 * @access protected
	 * @param MP_Order $order
	 */
	protected function _save_customer_info_metabox( $order ) {
		if ( !wp_verify_nonce( mp_get_post_value( 'mp_save_customer_info_nonce' ), 'mp_save_customer_info' ) ) {
			return;
		}

		$order->update_meta( 'mp_billing_info', mp_get_post_value( 'mp->billing_info' ) );
		$order->update_meta( 'mp_shipping_info', mp_get_post_value( 'mp->shipping_info' ) );
	}

	/**
	 * Save order notes metabox
	 *
	 * @since 3.0
	 * @access protected
	 * @param MP_Order $order
	 */
	protected function _save_order_notes_metabox( $order ) {
		if ( !wp_verify_nonce( mp_get_post_value( 'mp_save_order_notes_nonce' ), 'mp_save_order_notes' ) ) {
			return;
		}

		$order_notes = sanitize_text_field( trim( mp_get_post_value( 'mp->order_notes', '' ) ) );
		if ( !empty( $order_notes ) ) {
			$order->update_meta( 'mp_order_notes', $order_notes );
		} else {
			$order->delete_meta( 'mp_order_notes' );
		}
	}

	/**
	 * Save shipping info metabox
	 *
	 * @since 3.0
	 * @access protected
	 * @param MP_Order $order
	 */
	protected function _save_shipping_info_metabox( $order ) {
		if ( !wp_verify_nonce( mp_get_post_value( 'mp_save_shipping_info_nonce' ), 'mp_save_shipping_info' ) ) {
			return;
		}

		$tracking_num	 = trim( mp_get_post_value( 'mp->tracking_info->tracking_num', '' ) );
		$shipment_method = trim( mp_get_post_value( 'mp->tracking_info->shipping_method', '' ) );
		$custom_method	 = trim( mp_get_post_value( 'mp->tracking_info->custom_method' ) );
		//check for the custom shipping method here
		if ( $shipment_method == 'other' && !empty( $custom_method ) ) {
			//so if shippin method = custom, & user provided a new method name, we will use tht method name
			$method_name	 = trim( mp_get_post_value( 'mp->tracking_info->custom_method' ) );
			$shipment_method = sanitize_title( $method_name );
		}

		if ( !empty( $tracking_num ) && !empty( $shipment_method ) ) {
			// update tracking info
			$order->update_meta( 'mp_shipping_info->tracking_num', $tracking_num );
			$order->update_meta( 'mp_shipping_info->method', $shipment_method );

			//case shipping method is other, & method name provided
			if ( isset( $method_name ) ) {
				$custom_shipping_method = mp_get_setting( 'shipping->custom_method', array() );
				//if method not exist
				if ( !isset( $custom_shipping_method[ $shipment_method ] ) ) {
					$custom_shipping_method[ $shipment_method ] = $method_name;
					mp_update_setting( 'shipping->custom_method', $custom_shipping_method );
				}
			}

			// update status to shipped?
			if ( 'shipped' != $order->post_status && 'shipped' == mp_get_post_value( 'post_status' ) ) {
				remove_action( 'save_post', array( &$this, 'save_meta_boxes' ) );
				$order->change_status( 'order_shipped', true );
				add_action( 'save_post', array( &$this, 'save_meta_boxes' ) );
			}
		}
	}

	/**
	 * Display the export order form
	 *
	 * @since 3.0
	 * @access public
	 * @uses $wpdb, $wp_locale
	 * @action in_admin_footer
	 */
	public function export_order_form() {
		require_once mp_plugin_dir( 'includes/admin/class-mp-exporter-orders.php' );
		MP_Exporter_Orders::export_form();
	}

	/**
	 * Get IPN history
	 *
	 * @since 3.0
	 * @access public
	 * @return array False, if no IPN history exists.
	 */
	public function get_ipn_history() {
		if ( !is_null( $this->_ipn_history ) ) {
			return $this->_ipn_history;
		}

		$this->_ipn_history = get_comments( array(
			'status'	 => 'approve',
			'post_id'	 => $post->ID,
		) );

		if ( count( $this->_ipn_history ) == 0 ) {
			return false;
		}

		return $this->_ipn_history;
	}

	/**
	 * Save meta boxes
	 *
	 * @since 3.0
	 * @access public
	 * @action save_post
	 */
	public function save_meta_boxes( $post_id ) {
		if ( mp_doing_autosave() || mp_doing_ajax() ) {
			return;
		}

		$order = new MP_Order( $post_id );

		$this->_save_order_notes_metabox( $order );
		$this->_save_customer_info_metabox( $order );
		//we need to swap the save shipping info after save customer info,
		//or the new shipping data (shippinng method, number) get overwrited & removed
		$this->_save_shipping_info_metabox( $order );
	}

	/**
	 * Add meta boxes
	 *
	 * @since 3.0
	 * @access public
	 * @action add_meta_boxes_mp_order
	 */
	public function add_meta_boxes() {
		// Normal boxes
		add_meta_box( 'mp-order-details-metabox', __( 'Order Details', 'mp' ), array( &$this, 'meta_box_order_details' ), 'mp_order', 'normal', 'core' );
		add_meta_box( 'mp-order-customer-info-metabox', __( 'Customer Info', 'mp' ), array( &$this, 'meta_box_customer_info' ), 'mp_order', 'normal', 'core' );
		add_meta_box( 'mp-order-notes-metabox', __( 'Order Notes', 'mp' ), array( &$this, 'meta_box_order_notes' ), 'mp_order', 'normal', 'core' );
		add_meta_box( 'mp-order-ipn-history-metabox', __( 'IPN History', 'mp' ), array( &$this, 'meta_box_order_ipn_history' ), 'mp_order', 'normal', 'core' );

		// Side boxes
		add_meta_box( 'mp-order-actions-metabox', __( 'Order Actions', 'mp' ), array( &$this, 'meta_box_order_actions' ), 'mp_order', 'side', 'high' );
		add_meta_box( 'mp-order-history-metabox', __( 'Order History', 'mp' ), array( &$this, 'meta_box_order_history' ), 'mp_order', 'side', 'core' );
		add_meta_box( 'mp-order-payment-info-metabox', __( 'Payment Information', 'mp' ), array( &$this, 'meta_box_payment_info' ), 'mp_order', 'side', 'core' );
		add_meta_box( 'mp-order-shipping-info-metabox', __( 'Shipping Info', 'mp' ), array( &$this, 'meta_box_shipping_info' ), 'mp_order', 'side', 'core' );
	}

	/**
	 * Display the payment info meta box
	 *
	 * @since 3.0
	 * @access public
	 */
	public function meta_box_payment_info( $post ) {
		$order = new MP_Order( $post );
		?>
		<div class="misc-pub-section"><strong><?php _e( 'Gateway', 'mp' ); ?>:</strong><br /><?php echo $order->get_meta( 'mp_payment_info->gateway_private_name' ); ?></div>
		<div class="misc-pub-section"><strong><?php _e( 'Type', 'mp' ); ?>:</strong><br /><?php echo $order->get_meta( 'mp_payment_info->method' ); ?></div>
		<div class="misc-pub-section"><strong><?php _e( 'Transaction ID', 'mp' ); ?>:</strong><br /><?php echo $order->get_meta( 'mp_payment_info->transaction_id' ); ?></div>
		<div class="misc-pub-section" style="background:#f5f5f5;border-top:1px solid #ddd;"><strong><?php _e( 'Payment Total', 'mp' ); ?>:</strong><br /><?php echo mp_format_currency( '', $order->get_meta( 'mp_payment_info->total' ) ); ?></div>
		<?php
	}

	/**
	 * Display the order history meta box
	 *
	 * @since 3.0
	 * @access public
	 */
	public function meta_box_order_history( $post ) {
		$order		 = new MP_Order( $post );
		$meta_keys	 = array(
			'mp_closed_time'	 => __( 'Closed', 'mp' ),
			'mp_shipped_time'	 => __( 'Shipped', 'mp' ),
			'mp_paid_time'		 => __( 'Paid', 'mp' ),
			'mp_received_time'	 => __( 'Received', 'mp' ),
		);

		$index = 1;
		foreach ( $meta_keys as $key => $label ) :
			if ( $timestamp = $order->get_meta( $key ) ) :
				?>
				<div class="misc-pub-section"><strong><?php echo $label; ?>:</strong><br /><?php echo mp_format_date( $timestamp ); ?></div>
				<?php echo ( $index == count( $meta_keys ) ) ? '' : '<hr />'; ?>
				<?php
			endif;

			$index ++;
		endforeach;
	}

	/**
	 * Display the order IPN history meta box
	 *
	 * @since 3.0
	 * @access public
	 */
	public function meta_box_order_ipn_history( $post ) {
		$history = get_comments( array(
			'status'	 => 'approve',
			'post_id'	 => $post->ID,
		) );

		if ( count( $history ) == 0 ) {
			_e( 'There is no IPN history to show at this time', 'mp' );
			return;
		}

		echo '<ul>';
		foreach ( $history as $item ) {
			echo '<strong>' . date( get_option( 'date_format' ), strtotime( $item->comment_date ) ) . ':</strong> ' . $item->comment_content;
		}
		echo '</ul>';
	}

	/**
	 * Display the order actions meta box
	 *
	 * @since 3.0
	 * @access public
	 */
	public function meta_box_order_actions( $post ) {
		$order		 = new MP_Order( $post );
		$statuses	 = get_post_stati( array( 'post_type' => 'mp_order' ), 'objects' );
		?>
		<div id="misc-publishing-actions">
			<div class="misc-pub-section">
				<label for="post_status"><?php _e( 'Order Status', 'mp' ); ?></label>
				<select id="post_status" name="post_status">
					<?php foreach ( $statuses as $key => $status ) : ?>
						<option value="<?php echo $key; ?>" <?php selected( $key, $order->post_status ); ?>><?php echo $status->label; ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>
		<div id="major-publishing-actions">
			<div id="publishing-action">
				<span class="spinner"></span>
				<?php submit_button( __( 'Save Changes', 'mp' ), 'primary', null, false, array( 'id' => 'publish' ) ); ?>
			</div>
			<div class="clear"></div>
		</div>
		<?php
	}

	/**
	 * Display the order notes meta box
	 *
	 * @since 3.0
	 * @access public
	 */
	public function meta_box_order_notes( $post ) {
		$order = new MP_Order( $post );
		wp_nonce_field( 'mp_save_order_notes', 'mp_save_order_notes_nonce' );
		?>
		<textarea class="widefat" name="mp[order_notes]" rows="5"><?php echo $order->get_meta( 'mp_order_notes', '' ); ?></textarea>
		<?php
	}

	/**
	 * Display the shipping info meta box
	 *
	 * @since 3.0
	 * @access public
	 */
	public function meta_box_shipping_info( $post ) {
		$order		 = new MP_Order( $post );
		$carriers	 = array(
			'ups'	 => __( 'UPS', 'mp' ),
			'fedex'	 => __( 'FedEx', 'mp' ),
			'dhl'	 => __( 'DHL', 'mp' ),
			'other'	 => __( 'Other', 'mp' ),
		);

		//get the custom method
		$custom_carriers = mp_get_setting( 'shipping->custom_method', array() );

		/**
		 * Filter shipping carriers
		 *
		 * @since 3.0
		 * @param array $carrier An array of carriers.
		 */
		$carriers = apply_filters( 'mp_shipping_carriers_array', array_merge( $carriers, $custom_carriers ) );

		wp_nonce_field( 'mp_save_shipping_info', 'mp_save_shipping_info_nonce' );
		?>
		<div class="misc-pub-section">
			<strong><?php _e( 'Amount Collected', 'mp' ); ?>:</strong><br />
			<?php echo mp_format_currency( '', $order->get_meta( 'mp_shipping_total', 0 ) ); ?>
		</div>
		<?php if ( $order->get_meta( 'mp_shipping_info->shipping_sub_option' ) ) : ?>
			<div class="misc-pub-section">
				<strong><?php _e( 'Method Paid For', 'mp' ); ?>:</strong><br />
				<?php echo strtoupper( $order->get_meta( 'mp_shipping_info->shipping_option', '' ) . ' ' . $order->get_meta( 'mp_shipping_info->shipping_sub_option', '' ) ); ?>
			</div>
		<?php endif; ?>
		<div class="misc-pub-section">
			<strong><?php _e( 'Actual Shipping Method', 'mp' ); ?>:</strong><br />
			<select name="mp[tracking_info][shipping_method]" style="vertical-align:top;width:100%;">
				<option value=""><?php _e( 'Select One', 'mp' ); ?></option>
				<?php foreach ( $carriers as $val => $label ) : ?>
					<option data-original="<?php echo isset( $custom_carriers[ $val ] ) ? 1 : 0 ?>" value="<?php echo $val; ?>" <?php selected( $val, $order->get_meta( 'mp_shipping_info->method' ) ); ?>><?php echo $label; ?></option>
				<?php endforeach; ?>
			</select>
			<a class="mp-hide mp-remove-custom-carrier" href="#"><?php _e( "Remove", "mp" ) ?></a>
		</div>
		<div class="misc-pub-section">
			<div class="mp-order-custom-shipping-method mp-hide">
				<strong><?php _e( 'Method', 'mp' ); ?>:</strong><br />
				<input type="text" name="mp[tracking_info][custom_method]" placeholder="<?php _e( 'Method Name', 'mp' ); ?>" value="" style="width:100%" />
				<br/>
			</div>
			<strong><?php _e( 'Tracking Number', 'mp' ); ?>:</strong><br/>
			<input type="text" name="mp[tracking_info][tracking_num]"
				   placeholder="<?php _e( 'Tracking Number', 'mp' ); ?>"
				   value="<?php echo $order->get_meta( 'mp_shipping_info->tracking_num' ); ?>" style="width:100%"/>
		</div>
		<?php
	}

	/**
	 * Display the customer info meta box
	 *
	 * @since 3.0
	 * @access public
	 */
	public function meta_box_customer_info( $post ) {
		$order = new MP_Order( $post );
		wp_nonce_field( 'mp_save_customer_info', 'mp_save_customer_info_nonce' );
		echo $order->get_addresses( true );
	}

	/**
	 * Display the order details meta box
	 *
	 * @since 3.0
	 * @access public
	 */
	public function meta_box_order_details( $post ) {
		$order	 = new MP_Order( $post );
		$cart	 = $order->get_cart();
		$cart->display( array(
			'echo'		 => true,
			'view'		 => 'order-status',
			'editable'	 => false,
		) );
	}

	/**
	 * Displays the bulk update notice
	 *
	 * @since 3.0
	 * @access public
	 */
	public function admin_notices() {
		if ( get_current_screen()->id == 'edit-mp_order' ) {
			if ( mp_get_get_value( 'mp_order_status_updated' ) ) {
				echo '<div class="updated"><p>' . __( 'Order statuses successfully updated.', 'mp' ) . '</p></div>';
			}

			if ( $order_id = mp_get_get_value( 'mp_order_status_updated_single' ) ) {
				echo '<div class="updated"><p>' . sprintf( __( 'The order status for order ID <strong>%1$s</strong> was updated successfully.', 'mp' ), $order_id ) . '</p></div>';
			}
		}
	}

	/**
	 * Processes bulk actions
	 *
	 * @since 3.0
	 * @access public
	 */
	public function process_bulk_actions() {
		if ( get_current_screen()->id != 'edit-mp_order' ) {
			return;
		}

		$wp_list_table	 = _get_list_table( 'WP_Posts_List_Table' );
		$action			 = $wp_list_table->current_action();

		$posts			 = mp_get_get_value( 'post', array() );
		$valid_actions	 = array( 'order_received', 'order_paid', 'order_shipped', 'order_closed', 'trash', 'delete_all' );
		$pagenum		 = $wp_list_table->get_pagenum();

		if ( empty( $action ) ) {
			//bail - no action specified
			return;
		}

		check_admin_referer( 'bulk-posts' );

		if ( !in_array( $action, $valid_actions ) ) {
			wp_die( __( 'An invalid bulk action was requested. Please go back and try again.', 'mp' ) );
		}

		$sendback = remove_query_arg( array( 'action', 'action2', 'tags_input', 'post_author', 'comment_status', 'ping_status', 'post_status', '_status', 'post', 'bulk_edit', 'post_view', 'mp_order_status_updated', 'mp_order_status_updated_single' ), wp_get_referer() );

		if ( !$sendback ) {
			$sendback = admin_url( 'edit.php?post_type=mp_order' );
		}

		if ( 'delete_all' == $action ) {
			$posts = get_posts( array(
				'post_type'		 => 'mp_order',
				'post_status'	 => 'trash',
				'numberposts'	 => -1,
			) );

			foreach ( $posts as $post ) {
				wp_delete_post( $post->ID, true );
			}
		} else {
			foreach ( $posts as $post_id ) {
				wp_update_post( array(
					'ID'			 => $post_id,
					'post_status'	 => $action
				) );
			}

			$sendback = add_query_arg( array( 'paged' => $pagenum, 'mp_order_status_updated' => 1 ), $sendback );
		}

		wp_redirect( $sendback );
		exit;
	}

	/**
	 * Changes the given order's status
	 *
	 * @since 3.0
	 * @access public
	 * @action admin_ajax_mp_change_order_status
	 */
	public static function ajax_change_order_status() {
		$post_id		 = mp_get_get_value( 'post_id' );
		$order_id		 = mp_get_get_value( 'order_id' );
		$order_status	 = mp_get_get_value( 'order_status' );
		$msg			 = sprintf( __( 'The order status could not be updated due to unexpected error. Please try again.', 'mp' ), $order_id );

		if ( !check_ajax_referer( 'mp-change-order-status', '_wpnonce', false ) || false === $order_id || false === $order_status ) {
			wp_die( $msg );
		}

		$order_status_old	 = get_post_status( $post_id );
		$result				 = wp_update_post( array(
			'ID'			 => $post_id,
			'post_status'	 => $order_status,
		), true );

		if ( is_wp_error( $result ) ) {
			wp_die( $msg );
		} else {
			$sendback = remove_query_arg( 'mp_order_status_updated', wp_get_referer() );

			if ( empty( $sendback ) ) {
				$sendback = admin_url( 'edit.php?post_type=mp_order' );
			}

			wp_redirect( add_query_arg( 'mp_order_status_updated_single', $order_id, $sendback ) );
		}

		exit;
	}

	/**
	 * Change order status
	 *
	 * @since 3.0
	 * @access public
	 * @action transition_post_status
	 */
	public function change_order_status( $new_status, $old_status, $post ) {
		if ( $new_status == $old_status ) {
			// status hasn't changed - bail
		}

		if ( $post->post_type != 'mp_order' ) {
			// this isn't an order - bail
		}

		$order = new MP_Order( $post );
		$order->change_status( $new_status, false );
	}

	/**
	 * Modifies the query object for orders
	 *
	 * @since 3.0
	 * @access public
	 * @action pre_get_posts
	 * @param object $query
	 */
	public function modify_query( $query ) {
		if ( $query->get( 'post_type' ) != 'mp_order' || get_current_screen()->id != 'edit-mp_order' ) {
			//bail
			return;
		}

		//set post status
		$post_status = mp_get_get_value( 'post_status', array( 'order_received', 'order_paid', 'order_shipped' ) );
		$query->set( 'post_status', $post_status );

		switch ( get_query_var( 'orderby' ) ) {
			case 'product_coupon_discount' :
				$query->set( 'orderby', 'meta_value_num' );
				$query->set( 'meta_key', 'discount_amount' );
				break;

			case 'product_coupon_used' :
				$query->set( 'orderby', 'meta_value_num' );
				$query->set( 'meta_key', 'times_used' );
				break;
		}
	}

	/**
	 * Defines the product coupon sortable columns
	 *
	 * @since 3.0
	 * @access public
	 * @action manage_edit-product_coupon_sortable_columns
	 * @param array $columns
	 * @return array
	 */
	public function orders_sortable_columns( $columns ) {
		return array_merge( $columns, array(
			'discount'	 => 'product_coupon_discount',
			'used'		 => 'product_coupon_used',
		) );
	}

	/**
	 * Changes the "enter title here" text when editing/adding coupons
	 *
	 * @since 3.0
	 * @access public
	 * @action enter_title_here
	 * @param string $title The default title
	 * @return string
	 */
	public function enter_title_here( $title ) {
		if ( get_current_screen()->post_type != 'mp_order' ) {
			return $title;
		}

		return __( 'Enter coupon code here', 'mp' );
	}

	/**
	 * Enqueue CSS and JS
	 *
	 * @since 3.0
	 * @access public
	 * @action admin_enqueue_scripts
	 */
	public function enqueue_css_js() {
		if ( get_current_screen()->post_type != 'mp_order' ) {
			return;
		}

		wp_enqueue_style( 'mp-admin-orders', mp_plugin_url( 'includes/admin/ui/css/admin-orders.css' ), false, MP_VERSION );
		wp_enqueue_script( 'mp-admin-orders', mp_plugin_url( 'includes/admin/ui/js/admin-orders.js' ), false, MP_VERSION );

		wp_localize_script( 'mp-admin-orders', 'mp_admin_orders', array(
			'bulk_actions' => array(
				'-1'			 => __( 'Change Status', 'mp' ),
				'order_received' => __( ' Received', 'mp' ),
				'order_paid'	 => __( 'Paid', 'mp' ),
				'order_shipped'	 => __( 'Shipped', 'mp' ),
				'order_closed'	 => __( 'Closed', 'mp' ),
				'trash'			 => __( 'Move To Trash', 'mp' ),
			),
		) );
	}

	/**
	 * Adds menu items to the admin menu
	 *
	 * @since 3.0
	 * @access public
	 * @action admin_menu
	 */
	public function add_menu_items() {
		/**
		 * Filter the store orders capability
		 *
		 * @since 3.0
		 * @param string $order_cap The current store order capability
		 */
		$order_cap = apply_filters( 'mp_orders_cap', 'edit_store_orders' );

		if ( current_user_can( $order_cap ) && !mp_get_setting( 'disable_cart' ) ) {
			$num_posts	 = wp_count_posts( 'mp_order' ); //get order count
			$count		 = ( isset( $num_posts->order_received ) && isset( $num_posts->order_paid ) ) ? ($num_posts->order_received + $num_posts->order_paid) : 0;

			if ( $count > 0 ) {
				$count_output = '&nbsp;<span class="update-plugins"><span class="updates-count count-' . $count . '">' . $count . '</span></span>';
			} else {
				$count_output = '';
			}

			$orders_page = add_submenu_page( 'edit.php?post_type=' . MP_Product::get_post_type(), __( 'Orders', 'mp' ), __( 'Orders', 'mp' ) . $count_output, $order_cap, 'edit.php?post_type=mp_order' );
		}
	}

	/**
	 * Defines the column headers for the product coupon list table
	 *
	 * @since 3.0
	 * @access public
	 * @action manage_mp_order_posts_columns
	 * @param array $columns The default columns as specified by WP
	 * @return array
	 */
	public function orders_column_headers( $columns ) {
		return array(
			'cb'				 => '<input type="checkbox" />',
			'mp_orders_status'	 => __( 'Status', 'mp' ),
			'mp_orders_id'		 => __( 'Order ID', 'mp' ),
			'mp_orders_date'	 => __( 'Order Date', 'mp' ),
			'mp_orders_name'	 => __( 'From', 'mp' ),
			'mp_orders_items'	 => __( 'Items', 'mp' ),
			'mp_orders_shipping' => __( 'Shipping', 'mp' ),
			'mp_orders_tax'		 => __( 'Tax', 'mp' ),
			'mp_orders_discount' => __( 'Discount', 'mp' ),
			'mp_orders_total'	 => __( 'Total', 'mp' ),
		);
	}

	/**
	 * Defines the list table data for product coupons
	 *
	 * @since 3.0
	 * @access public
	 * @action manage_mp_order_posts_custom_column
	 * @uses $post
	 * @param string $column The current column name
	 * @param int $post_id The current post id
	 */
	public function orders_column_data( $column, $post_id ) {
		global $post;

		$order	 = new MP_Order( $post_id );
		$html	 = '';

		switch ( $column ) {
			//! Order Status
			case 'mp_orders_status' :
				switch ( $post->post_status ) {
					case 'order_received' :
						$text = __( 'Received', 'mp' );
						break;

					case 'order_paid' :
						$text = __( 'Paid', 'mp' );
						break;

					case 'order_shipped' :
						$text = __( 'Shipped', 'mp' );
						break;

					case 'order_closed' :
						$text = __( 'Closed', 'mp' );
						break;

					case 'trash' :
						$text = __( 'Trashed', 'mp' );
						break;
				}

				$actions = array(
					'order_received' => __( 'Received', 'mp' ),
					'order_paid'	 => __( 'Paid', 'mp' ),
					'order_shipped'	 => __( 'Shipped', 'mp' ),
					'order_closed'	 => __( 'Closed', 'mp' ),
				);

				$html .= '<div class="mp_order_status ' . get_post_status() . '">';

				if ( isset( $actions ) ) {
					$html .= '<ul class="mp_order_status_menu">';
					$html .= '<li class="item">' . __( 'Flag as:', 'mp' ) . '</li>';

					foreach ( $actions as $action => $label ) {
						if ( $action == $post->post_status ) {
							$html .= '<li class="item current"><span>' . $label . '</span></li>';
						} else {
							$html .= '<li class="item"><a href="' . wp_nonce_url( add_query_arg( array( 'action' => 'mp_change_order_status', 'order_status' => $action, 'order_id' => get_the_title(), 'post_id' => $post_id ), admin_url( 'admin-ajax.php' ) ), 'mp-change-order-status' ) . '">' . $label . '</a></li>';
						}
					}

					$html .= '</ul>';
				}

				$html .= '<img src="' . mp_plugin_url( 'ui/images/ajax-loader.gif' ) . '" alt="" />';
				$html .= '</div>';
				break;

			//! Order ID
			case 'mp_orders_id' :
				$title = _draft_or_post_title( $post_id );
				$html .= '<strong><a class="row-title" href="' . get_edit_post_link( $post_id ) . '" title="' . esc_attr( sprintf( __( 'View order &#8220;%s&#8221;', 'mp' ), $title ) ) . '">' . $title . '</a></strong>';
				break;

			//! Order Date
			case 'mp_orders_date' :
				$post_date = get_post_time();
				$html .= mp_format_date( $post_date ); //get_the_time( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
				break;

			//! Order From
			case 'mp_orders_name' :
				$html .= '<a href="javascript:;" title="' . __( 'Display billing/shipping info for this customer', 'mp' ) . '">' . $order->get_name() . '</a>';
				$html .= '
					<div style="display:none">
						<div id="mp-customer-info-lb-' . $order->ID . '" class="mp-customer-info-lb" style="padding:10px 30px 30px;">' .
				$order->get_addresses() . '
						</div>
					</div>';
				break;

			//! Order Items
			case 'mp_orders_items' :
				$items = get_post_meta( $post_id, 'mp_order_items', true );
				$html .= number_format_i18n( $items );
				break;

			//! Order Shipping
			case 'mp_orders_shipping' :
				$shipping = get_post_meta( $post_id, 'mp_shipping_total', true );
				$html .= mp_format_currency( '', $shipping );
				break;

			//! Order Tax
			case 'mp_orders_tax' :
				$tax = get_post_meta( $post_id, 'mp_tax_total', true );
				$html .= mp_format_currency( '', $tax );
				break;

			//! Order Discount
			case 'mp_orders_discount' :
				if ( $coupons = $order->get_meta( 'mp_discount_info' ) ) {
					foreach ( (array) $coupons as $key => $val ) {
						if ( $key == 'discount' ) {
							$html .= $val;
						} elseif ( $key == 'code' ) {
							$html .= ' (' . strtoupper( $val ) . ')';
						} else {
							$html .= mp_format_currency( '', $val ) . ' (' . $key . ')<br />';
						}
					}
				} else {
					$html .= __( 'N/A', 'mp' );
				}
				break;

			//! Order Total
			case 'mp_orders_total' :
				$total = get_post_meta( $post_id, 'mp_order_total', true );
				$html .= mp_format_currency( '', $total );
				break;
		}

		/**
		 * Filter the admin column html
		 *
		 * @since 3.0
		 * @param string $html The current admin column $html.
		 * @param string $column The admin column name.
		 * @param MP_Order $order The current order object.
		 */
		$html = apply_filters( 'mp_orders_admin/order_column_data', $html, $column, $order );

		/**
		 * Filter the admin column html
		 *
		 * @since 3.0
		 * @param string $html The current admin column $html.
		 * @param MP_Order $order The current order object.
		 */
		$html = apply_filters( 'mp_orders_admin/order_column_data/' . $column, $html, $order );

		echo $html;
	}

}

MP_Orders_Admin::get_instance();
