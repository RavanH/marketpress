<?php

class MP_Products_Screen {

	/**
	 * Refers to a single instance of the class
	 *
	 * @since 3.0
	 * @access private
	 * @var object
	 */
	private static $_instance = null;

	/**
	 * Gets the single instance of the class
	 *
	 * @since 3.0
	 * @access public
	 * @return object
	 */
	public static function get_instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new MP_Products_Screen();
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
		// Remove add-new submenu item from store admin menu
		add_action( 'admin_menu', array( &$this, 'remove_menu_items' ), 999 );
		// Hide featured image for variable products
		add_action( 'wpmudev_field/print_scripts/has_variations', array( &$this, 'maybe_hide_core_metaboxes' ) );
		// Product variations save/get value
		add_filter( 'wpmudev_field/save_value/variations', array( &$this, 'save_product_variations' ), 10, 3 );
		add_filter( 'wpmudev_field/before_get_value/variations', array( &$this, 'get_product_variations' ), 10, 4 );
		// Custom product columns
		add_filter( 'manage_product_posts_columns', array( &$this, 'product_columns_head' ) );
		add_filter( 'manage_mp_product_posts_columns', array( &$this, 'product_columns_head' ) );
		add_action( 'manage_product_posts_custom_column', array( &$this, 'product_columns_content' ), 10, 2 );
		add_action( 'manage_mp_product_posts_custom_column', array( &$this, 'product_columns_content' ), 10, 2 );
		// Add metaboxes
		add_action( 'init', array( &$this, 'init_metaboxes' ) );
		// Add quick/bulk edit capability for product fields
		add_action( 'quick_edit_custom_box', array( &$this, 'quick_edit_custom_box' ), 10, 2 );
		add_action( 'bulk_edit_custom_box', array( &$this, 'bulk_edit_custom_box' ), 10, 2 );
		add_action( 'admin_print_scripts-edit.php', array( &$this, 'enqueue_bulk_quick_edit_js' ) );
		add_action( 'save_post', array( &$this, 'save_quick_edit' ), 10, 2 );
		// Product screen scripts
		add_action( 'in_admin_footer', array( &$this, 'toggle_product_attributes_js' ) );
		// Product attributes save/get value
		$mp_product_atts = MP_Product_Attributes::get_instance();
		$atts			 = $mp_product_atts->get();
		foreach ( $atts as $att ) {
			add_filter( 'wpmudev_field/save_value/' . $mp_product_atts->generate_slug( $att->attribute_id ), array( &$this, 'save_product_attribute' ), 10, 3 );
		}

		add_filter( 'enter_title_here', array( &$this, 'custom_placeholder_title' ), 10, 2 );

	}

	/**
	 * Print js related to displaying/hiding of product attributes
	 *
	 * @since 3.0
	 * @access public
	 * @action in_admin_footer
	 */
	public function toggle_product_attributes_js() {
		if ( get_current_screen()->id != MP_Product::get_post_type() ) {
			// not product screen - bail
			return;
		}
		?>
		<script type="text/javascript">
			( function( $ ) {
				var $inputs = $( 'input[name="tax_input[product_category][]"]' );

				var toggleProductAttributes = function() {
					var selectors = [ ];
					var $subfield = $( '.wpmudev-subfield' );

					if ( $inputs.filter( ':checked' ).length == 0 ) {
						// no categories checked - reset all product attributes to visible
						$subfield.has( '[name*="product_attr_"]' ).removeClass( 'wpmudev-field-hidden' );
						return;
					}

					// hide all product attributes
					$subfield.has( '[name*="product_attr_"]' ).addClass( 'wpmudev-field-hidden' );

					// loop through checked input and show associated attributes
					$inputs.filter( ':checked' ).each( function() {
						$subfield.has( '[data-product-category-' + $( this ).val() + ']' ).removeClass( 'wpmudev-field-hidden' );
					} );
				};

				$( document ).ready( function() {
					toggleProductAttributes();
					$inputs.on( 'change', toggleProductAttributes );
				} );
			}( jQuery ) );
		</script>
		<?php
	}

	/**
	 * Maybe hide some core metaboxes
	 *
	 * @since 3.0
	 * @access public
	 * @action wpmudev_field/print_scripts/has_variations
	 */
	public function maybe_hide_core_metaboxes( $field ) {
		?>
		<script type="text/javascript">
			jQuery( document ).ready( function( $ ) {
				$( '[name="has_variations"]' ).change( function() {
					var $elms = $( '#postimagediv, #postdivrich, #postexcerpt' );

					if ( $( this ).prop( 'checked' ) ) {
						$elms.hide();
					} else {
						$elms.show();
						/* This is required to fix a bug in webkit with the WYSIWYG showing up all
						 garbled after unhiding */
						$( window ).trigger( 'scroll' );
					}
				} ).trigger( 'change' );
			} );
		</script>
		<?php
	}

	/**
	 * Save the custom quick edit form fields
	 *
	 * @since 3.0
	 * @access public
	 * @action save_post
	 */
	public function save_quick_edit( $post_id, $post ) {
		if ( empty( $_POST ) ) {
			return $post_id;
		}

		if ( ($nonce = mp_get_post_value( 'quick_edit_product_nonce' )) && !wp_verify_nonce( $nonce, 'quick_edit_product' ) ) {
			return $post_id;
		}

		if ( mp_doing_autosave() ) {
			return $post_id;
		}

		if ( wp_is_post_revision( $post ) ) {
			return $post_id;
		}

		if ( $post->post_type != MP_Product::get_post_type() ) {
			return $post_id;
		}

		$price		 = mp_get_post_value( 'product_price', '' );
		$sale_price	 = mp_get_post_value( 'product_sale_price', '' );

		update_post_meta( $post_id, 'regular_price', $price );
		update_post_meta( $post_id, 'sale_price_amount', $sale_price );
	}

	/**
	 * Enqueue quick/bulk edit script
	 *
	 * @since 3.0
	 * @access public
	 * @action admin_print_scripts-edit.php
	 */
	public function enqueue_bulk_quick_edit_js() {
		if ( get_current_screen()->post_type != MP_Product::get_post_type() ) {
			return;
		}

		wp_enqueue_script( 'mp-bulk-quick-edit-product', mp_plugin_url( 'includes/admin/ui/js/bulk-quick-edit-product.js' ), array( 'jquery', 'inline-edit-post' ), MP_VERSION, true );
	}

	/**
	 * Display the custom quick edit box
	 *
	 * @since 3.0
	 * @access public
	 * @action quick_edit_custom_box
	 */
	public function quick_edit_custom_box( $column_name, $post_type ) {
		if ( $post_type != MP_Product::get_post_type() || $column_name != 'product_price' ) {
			return;
		}
		?>
		<fieldset id="quick-edit-col-product-price" class="inline-edit-col-left" style="clear:left">
			<div class="inline-edit-col"><!-- content inserted via js here --></div>
		</fieldset>
		<?php
	}

	/**
	 * Display the custom bulk edit box
	 *
	 * @since 3.0
	 * @access public
	 * @action bulk_edit_custom_box
	 */
	public function bulk_edit_custom_box( $column_name, $post_type ) {
		if ( $post_type != MP_Product::get_post_type() || $column_name != 'product_price' ) {
			return;
		}
		?>
		<fieldset id="bulk-edit-col-product-price" class="inline-edit-col-left" style="clear:left">
			<div class="inline-edit-col clearfix">
				<label class="alignleft"><span class="title"><?php _e( 'Price', 'mp' ); ?></span><span class="input-text-wrap"><input type="text" name="product_price" style="width:100px" /></span></label>
				<label class="alignleft" style="margin-left:15px"><span class="title"><?php _e( 'Sale Price', 'mp' ); ?></span><span class="input-text-wrap"><input type="text" name="product_sale_price" style="width:100px" /></span></label>
				<input type="hidden" name="bulk_edit_products_nonce" value="<?php echo wp_create_nonce( 'bulk_edit_products' ); ?>" />		
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Filter the product admin columns
	 *
	 * @since 3.0
	 * @access public
	 * @filter manage_product_posts_columns, manage_mp_product_posts_columns
	 * @return array
	 */
	public function product_columns_head( $columns ) {
		return array(
			'cb'						 => '<input type="checkbox" />',
			'title'						 => __( 'Product Name', 'mp' ),
			'product_variations'		 => __( 'Variations', 'mp' ),
			'product_sku'				 => __( 'SKU', 'mp' ),
			'product_price'				 => __( 'Price', 'mp' ),
			'product_stock'				 => __( 'Stock', 'mp' ),
			'product_sales'				 => __( 'Sales', 'mp' ),
			'taxonomy-product_category'	 => __( 'Categories', 'mp' ),
			'taxonomy-product_tag'		 => __( 'Tags', 'mp' ),
		);
	}

	/**
	 * Display data for each product admin column
	 *
	 * @since 3.0
	 * @access public
	 * @action manage_product_posts_custom_column, manage_mp_product_posts_custom_column
	 */
	public function product_columns_content( $column, $post_id ) {
		$product	 = new MP_Product( $post_id );
		$variations	 = $product->get_variations();

		switch ( $column ) {
			case 'product_variations' :
				if ( $product->has_variations() ) {
					$names = array();
					foreach ( $variations as $variation ) {
						$names[] = $variation->get_meta( 'name' );
					}
				} else {
					$names = array( '&mdash;' );
				}

				echo implode( '<br />', $names );
				break;

			case 'product_sku' :
				if ( $product->has_variations() ) {
					$skus = array();
					foreach ( $variations as $variation ) {
						$skus[] = $variation->get_meta( 'sku' );
					}
				} else {
					$skus = array( $product->get_meta( 'sku', '&mdash;' ) );
				}

				echo implode( '<br />', $skus );
				break;

			case 'product_price' :
				if ( $product->has_variations() ) {
					$prices = array();
					foreach ( $variations as $variation ) {
						$price = $variation->get_price();
						if ( $variation->on_sale() ) {
							$prices[] = '<strike>' . mp_format_currency( '', $price[ 'regular' ] ) . '</strike> ' . mp_format_currency( '', $price[ 'sale' ][ 'amount' ] );
						} else {
							$prices[] = mp_format_currency( '', $price[ 'regular' ] );
						}
					}
				} else {
					$price = $product->get_price();
					if ( $product->on_sale() ) {
						$prices = array( '<strike>' . mp_format_currency( '', $price[ 'regular' ] ) . '</strike> ' . mp_format_currency( '', $price[ 'sale' ][ 'amount' ] ) );
					} else {
						$prices = array( mp_format_currency( '', $price[ 'regular' ] ) );
					}
				}

				echo implode( '<br />', $prices );
				echo '
					<div style="display:none">
						<div id="quick-edit-product-content-' . $post_id . '">
							<label class="alignleft"><span class="title">' . __( 'Price', 'mp' ) . '</span><span class="input-text-wrap"><input type="text" name="product_price" style="width:100px" value="' . $price[ 'regular' ] . '" /></span></label>
							<label class="alignleft" style="margin-left:15px"><span class="title">' . __( 'Sale Price', 'mp' ) . '</span><span class="input-text-wrap"><input type="text" name="product_sale_price" style="width:100px" value="' . $price[ 'sale' ][ 'amount' ] . '" /></span></label>
							<input type="hidden" name="quick_edit_product_nonce" value="' . wp_create_nonce( 'quick_edit_product' ) . '" />
						</div>
					</div>';
				break;

			case 'product_stock' :
				if ( $product->has_variations() ) {
					$stock = array();
					foreach ( $variations as $variation ) {
						$stock[] = $variation->get_meta( 'inventory', '&mdash;' );
					}
				} else {
					$stock = array( $product->get_meta( 'inventory', '&mdash;' ) );
				}

				echo implode( '<br />', $stock );
				break;

			case 'product_sales' :
				if ( $product->has_variations() ) {
					$sales = array();
					foreach ( $variations as $variation ) {
						$sales[] = $variation->get_meta( 'sales_count', 0 );
					}
				} else {
					$sales = array( $product->get_meta( 'sales_count', 0 ) );
				}

				echo implode( '<br />', $sales );
				break;
		}
	}

	/**
	 * Initialize metaboxes
	 *
	 * @since 3.0
	 * @access public
	 */
	public function init_metaboxes() {
		$this->init_product_type_metabox();
		$this->init_product_details_metabox();
		$this->init_variations_metabox();
		$this->init_related_products_metabox();
	}

	/**
	 * Remove add-new submenu item from store admin menu
	 *
	 * @since 3.0
	 * @access public
	 */
	public function remove_menu_items() {
		remove_submenu_page( 'edit.php?post_type=' . MP_Product::get_post_type(), 'post-new.php?post_type=' . MP_Product::get_post_type() );
		remove_submenu_page( 'edit.php?post_type=' . MP_Product::get_post_type(), 'edit-tags.php?taxonomy=product_category&amp;post_type=' . MP_Product::get_post_type() );
		remove_submenu_page( 'edit.php?post_type=' . MP_Product::get_post_type(), 'edit-tags.php?taxonomy=product_tag&amp;post_type=' . MP_Product::get_post_type() );
	}

	/**
	 * Saves the product attributes to the database
	 *
	 * @since 3.0
	 * @access public
	 * @filter wpmudev_field/save_value/product_attr_*
	 */
	public function save_product_attribute( $value, $post_id, $field ) {
		$slug = $field->args[ 'name' ];
		wp_set_post_terms( $post_id, $value, $slug );
		return $value;
	}

	/**
	 * Gets the product variations from the database and formats for repeater field
	 *
	 * @since 3.0
	 * @access public
	 * @filter wpmudev_field/get_value/variations
	 */
	public function get_product_variations( $value, $post_id, $raw, $field ) {
		$product	 = new MP_Product( $post_id );
		$variations	 = $product->get_variations();
		$data		 = array();

		foreach ( $variations as $variation ) {
			$meta = array();

			foreach ( $field->subfields as $subfield ) {
				switch ( $subfield->args[ 'original_name' ] ) {
					case 'description' :
						$meta[ $subfield->args[ 'original_name' ] ] = $subfield->format_value( $variation->post_content, $variation->ID );
						break;

					case 'image' :
						$meta[ $subfield->args[ 'original_name' ] ] = get_post_thumbnail_id( $variation->ID );
						break;

					default :
						if ( false !== (strpos( $subfield->args[ 'original_name' ], 'product_attr_' )) ) {
							$terms	 = get_the_terms( $variation->ID, $subfield->args[ 'original_name' ] );
							$term	 = false;

							if ( is_array( $terms ) ) {
								$term_obj	 = array_shift( $terms );
								$term		 = $term_obj->term_id;
							}

							$meta[ $subfield->args[ 'original_name' ] ] = $term;
						} else {
							$meta[ $subfield->args[ 'original_name' ] ] = $subfield->get_value( $variation->ID, $subfield->args[ 'original_name' ] );
						}
						break;
				}
			}

			$data[] = array_merge( array( 'ID' => $variation->ID ), $meta );
		}

		return $data;
	}

	/**
	 * Saves the product variations to the database
	 *
	 * @since 3.0
	 * @access public
	 * @filter wpmudev_field/save_value/variations
	 * @uses $wpdb
	 */
	public function save_product_variations( $value, $post_id, $field ) {
		global $wpdb;

		$variations		 = mp_get_post_value( 'variations', array() );
		$sorted			 = $field->sort_subfields( $variations );
		$ids			 = array();
		$delete_where	 = "{$wpdb->posts}.ID = {$wpdb->postmeta}.post_id AND {$wpdb->posts}.post_parent = $post_id AND {$wpdb->posts}.post_type = 'mp_product_variation'";


		if ( mp_get_post_value( 'has_variations', false ) ) {
			foreach ( $sorted as $order => $array ) {
				$variation_id	 = key( $array );
				$fields			 = current( $array );

				if ( false === strpos( $variation_id, '_' ) ) {
					$variation_id	 = $ids[]			 = wp_insert_post( array(
						'post_content'	 => mp_arr_get_value( 'description', $fields, '' ),
						'post_title'	 => 'Product Variation of ' . $post_id,
						'post_status'	 => 'publish',
						'post_type'		 => 'mp_product_variation',
						'post_parent'	 => $post_id,
						'menu_order'	 => $order,
					) );
				} else {
					$ids[]			 = $variation_id	 = substr( $variation_id, 1 );
					wp_update_post( array(
						'ID'			 => $variation_id,
						'post_content'	 => mp_arr_get_value( 'description', $fields, '' ),
						'post_status'	 => 'publish',
						'menu_order'	 => $order,
					) );
				}

				// Update post thumbnail
				if ( empty( $fields[ 'image' ] ) ) {
					delete_post_thumbnail( $variation_id );
				} else {
					set_post_thumbnail( $variation_id, $fields[ 'image' ] );
				}

				// Unset the fields that shouldn't be saved as post meta
				$fields[ 'description' ] = $fields[ 'image' ]		 = null;

				$index = 0;
				foreach ( $fields as $name => $value ) {
					if ( is_null( $value ) ) {
						$index ++;
						continue;
					}

					$subfield = $field->subfields[ $index ];

					if ( false !== strpos( $name, 'product_attr_' ) ) {
						wp_set_post_terms( $variation_id, $subfield->sanitize_for_db( $value, $variation_id ), $name );
					} else {
						$subfield->save_value( $variation_id, $name, $value, true );
					}

					$index ++;
				}
			}

			$delete_where .= " AND {$wpdb->posts}.ID NOT IN (" . implode( ',', $ids ) . ")";
		}

		// Delete variations that no longer exist
		$wpdb->query( "
			DELETE FROM $wpdb->posts
			USING $wpdb->posts
			INNER JOIN $wpdb->postmeta
			WHERE $delete_where"
		);

		return null; // Returning null will bypass internal save mechanism
	}

	/**
	 * Initializes the related products metabox
	 *
	 * @since 3.0
	 * @access public
	 */
	public function init_related_products_metabox() {
		$metabox = new WPMUDEV_Metabox( array(
			'id'		 => 'mp-related-products-metabox',
			'title'		 => __( 'Related Products', 'mp' ),
			'post_type'	 => MP_Product::get_post_type(),
			'context'	 => 'side',
			'desc'		 => __( 'If you would like, you can choose specific related products instead of using the ones generated by MarketPress', 'mp' ),
		) );
		$metabox->add_field( 'post_select', array(
			'name'			 => 'related_products',
			'multiple'		 => true,
			'placeholder'	 => __( 'Choose Products', 'mp' ),
			'query'			 => array(
				'post__not_in'	 => array( get_the_ID() ),
				'post_type'		 => MP_Product::get_post_type(),
				'posts_per_page' => -1,
			),
		) );
	}

	/**
	 * Initializes the product type metabox
	 *
	 * @since 3.0
	 * @access public
	 */
	public function init_product_type_metabox() {
		$metabox = new WPMUDEV_Metabox( array(
			'id'		 => 'mp-product-type-metabox',
			'title'		 => __( 'Product Type', 'mp' ),
			'post_type'	 => MP_Product::get_post_type(),
			'context'	 => 'below_title',
		) );
		$metabox->add_field( 'radio_group', array(
			'name'			 => 'product_type',
			'default_value'	 => 'physical',
			'options'		 => array(
				'physical'	 => __( 'Physical/Tangible Product', 'mp' ),
				'digital'	 => __( 'Digital Download', 'mp' ),
				'external'	 => __( 'External/Affiliate Link', 'mp' ),
			),
		) );
		$metabox->add_field( 'checkbox', array(
			'name'		 => 'has_variations',
			'message'	 => __( 'Does this product have variations such as color, size, etc?', 'mp' ),
		) );
	}

	/**
	 * Initializes the product details metabox
	 *
	 * @since 3.0
	 * @access public
	 */
	public function init_product_details_metabox() {
		$metabox = new WPMUDEV_Metabox( array(
			'id'			 => 'mp-product-details-metabox',
			'title'			 => __( 'Product Details', 'mp' ),
			'post_type'		 => MP_Product::get_post_type(),
			'context'		 => 'normal',
			'conditional'	 => array(
				'name'	 => 'has_variations',
				'value'	 => 1,
				'action' => 'hide',
			),
		) );

		$metabox->add_field( 'tab_labels', array(
			'name'	 => 'product_tabs',
			'tabs'	 => array(
				array(
					'label'	 => __( 'General', 'mp' ),
					'slug'	 => 'general',
					'active' => true,
				),
				array(
					'label'	 => __( 'Price', 'mp' ),
					'slug'	 => 'price',
				),
				array(
					'label'	 => __( 'Taxes', 'mp' ),
					'slug'	 => 'taxes',
				),
				array(
					'label'	 => __( 'Shipping', 'mp' ),
					'slug'	 => 'shipping',
				),
			),
		) );

		// General Tab
		$metabox->add_field( 'tab', array(
			'name'	 => 'product_tab_general',
			'slug'	 => 'general'
		) );
		$metabox->add_field( 'text', array(
			'name'	 => 'sku',
			'label'	 => array( 'text' => __( 'SKU', 'mp' ) ),
		) );
		$metabox->add_field( 'checkbox', array(
			'name'			 => 'track_inventory',
			'label'			 => array( 'text' => __( 'Track Inventory?', 'mp' ) ),
			'conditional'	 => array(
				'name'	 => 'product_type',
				'value'	 => 'physical',
				'action' => 'show',
			),
		) );
		$metabox->add_field( 'text', array(
			'name'			 => 'inventory',
			'label'			 => array( 'text' => __( 'Inventory Count', 'mp' ) ),
			'desc'			 => __( 'Enter the quantity that you have available to sell.', 'mp' ),
			'conditional'	 => array(
				'action'	 => 'show',
				'operator'	 => 'AND',
				array(
					'name'	 => 'product_type',
					'value'	 => 'physical',
				),
				array(
					'name'	 => 'track_inventory',
					'value'	 => 1,
				),
			),
			'validation'	 => array(
				'required'	 => true,
				'digits'	 => true,
				'min'		 => 0,
			),
		) );
		$metabox->add_field( 'file', array(
			'name'			 => 'file_url',
			'label'			 => array( 'text' => __( 'File URL', 'mp' ) ),
			'conditional'	 => array(
				'name'	 => 'product_type',
				'value'	 => 'digital',
				'action' => 'show',
			),
			'validation'	 => array(
				'url' => true,
			),
		) );
		$metabox->add_field( 'text', array(
			'name'			 => 'external_url',
			'label'			 => array( 'text' => __( 'External URL', 'mp' ) ),
			'conditional'	 => array(
				'name'	 => 'product_type',
				'value'	 => 'external',
				'action' => 'show',
			),
			'validation'	 => array(
				'url' => true,
			),
		) );

		// Price Tab
		$metabox->add_field( 'tab', array(
			'name'	 => 'product_tab_price',
			'slug'	 => 'price'
		) );
		$metabox->add_field( 'text', array(
			'name'		 => 'regular_price',
			'label'		 => array( 'text' => __( 'Regular Price', 'mp' ) ),
			'validation' => array(
				'required'	 => true,
				'number'	 => true,
				'min'		 => 0,
			),
		) );
		$sale_price = $metabox->add_field( 'complex', array(
			'name'	 => 'sale_price',
			'label'	 => array( 'text' => __( 'Sale Price', 'mp' ) ),
		) );

		if ( $sale_price instanceof WPMUDEV_Field ) {
			$sale_price->add_field( 'text', array(
				'name'		 => 'amount',
				'label'		 => array( 'text' => __( 'Price', 'mp' ) ),
				'custom'	 => array(
					'data-msg-lessthan' => __( 'Value must be less than regular price', 'mp' ),
				),
				'validation' => array(
					'number'	 => true,
					'min'		 => 0,
					'lessthan'	 => '[name*="regular_price"]'
				),
			) );
			$sale_price->add_field( 'datepicker', array(
				'name'	 => 'start_date',
				'label'	 => array( 'text' => __( 'Start Date (if applicable)', 'mp' ) ),
			) );
			$sale_price->add_field( 'datepicker', array(
				'name'	 => 'end_date',
				'label'	 => array( 'text' => __( 'End Date (if applicable)', 'mp' ) ),
			) );
		}


		// Tax Tab
		$metabox->add_field( 'tab', array(
			'name'	 => 'product_tab_taxes',
			'slug'	 => 'taxes'
		) );
		$metabox->add_field( 'text', array(
			'name'			 => 'special_tax_rate',
			'label'			 => array( 'text' => __( 'Special Tax Rate', 'mp' ) ),
			'default_value'	 => '0.00',
			'conditional'	 => array(
				'name'	 => 'product_type',
				'value'	 => array( 'physical', 'digital' ),
				'action' => 'show',
			),
			'validation'	 => array(
				'number' => true,
				'min'	 => 0,
			),
		) );

		// Shipping Tab
		$metabox->add_field( 'tab', array(
			'name'	 => 'product_tab_shipping',
			'slug'	 => 'shipping'
		) );
		$weight = $metabox->add_field( 'complex', array(
			'name'			 => 'weight',
			'label'			 => array( 'text' => __( 'Weight', 'mp' ) ),
			'conditional'	 => array(
				'name'	 => 'product_type',
				'value'	 => 'physical',
				'action' => 'show',
			),
		) );

		if ( $weight instanceof WPMUDEV_Field ) {
			$weight->add_field( 'text', array(
				'name'		 => 'pounds',
				'label'		 => array( 'text' => __( 'Pounds', 'mp' ) ),
				'validation' => array(
					'digits' => true,
				),
			) );
			$weight->add_field( 'text', array(
				'name'		 => 'ounces',
				'label'		 => array( 'text' => __( 'Ounces', 'mp' ) ),
				'validation' => array(
					'digits' => true,
				),
			) );
		}

		$metabox->add_field( 'text', array(
			'name'			 => 'extra_shipping_cost',
			'label'			 => array( 'text' => __( 'Extra Shipping Cost', 'mp' ) ),
			'default_value'	 => '0.00',
			'conditional'	 => array(
				'name'	 => 'product_type',
				'value'	 => array( 'physical', 'digital' ),
				'action' => 'show',
			),
			'validation'	 => array(
				'number' => true,
				'min'	 => 0,
			),
		) );
	}

	/**
	 * Initializes the product variation metabox
	 *
	 * @since 3.0
	 * @access public
	 */
	public function init_variations_metabox() {
		$metabox	 = new WPMUDEV_Metabox( array(
			'id'			 => 'mp-product-variations-metabox',
			'title'			 => __( 'Variations', 'mp' ),
			'post_type'		 => MP_Product::get_post_type(),
			'context'		 => 'normal',
			'desc'			 => __( 'Create your product variations here. You can reorder variations by using the number to left of each variation, or delete one by clicking the "x" to the right of each variation. <strong>NOTE: The variation that shows up first in this list will be considered the "main variation". The details from this variation will be used in product listings.</strong>', 'mp' ),
			'conditional'	 => array(
				'name'	 => 'has_variations',
				'value'	 => 1,
				'action' => 'show',
			),
		) );
		$repeater	 = $metabox->add_field( 'repeater', array(
			'name'			 => 'variations',
			'layout'		 => 'rows',
			'add_row_label'	 => __( 'Add Variation', 'mp' ),
		) );

		if ( $repeater instanceof WPMUDEV_Field ) {
			$repeater->add_sub_field( 'tab_labels', array(
				'name'	 => 'tabs',
				'tabs'	 => array(
					array(
						'label'	 => __( 'General', 'mp' ),
						'slug'	 => 'general',
						'active' => true,
					),
					array(
						'label'	 => __( 'Price', 'mp' ),
						'slug'	 => 'price',
					),
					array(
						'label'	 => __( 'Taxes', 'mp' ),
						'slug'	 => 'taxes',
					),
					array(
						'label'	 => __( 'Shipping', 'mp' ),
						'slug'	 => 'shipping',
					),
					array(
						'label'	 => __( 'Attributes', 'mp' ),
						'slug'	 => 'attributes',
					),
				),
			) );

			// General Tab
			$repeater->add_sub_field( 'tab', array(
				'name'	 => 'tab_general',
				'slug'	 => 'general'
			) );
			$repeater->add_sub_field( 'text', array(
				'name'		 => 'name',
				'label'		 => array( 'text' => __( 'Name', 'mp' ) ),
				'validation' => array(
					'required' => true,
				),
			) );
			$repeater->add_sub_field( 'text', array(
				'name'	 => 'sku',
				'label'	 => array( 'text' => __( 'SKU', 'mp' ) ),
			) );
			$repeater->add_sub_field( 'image', array(
				'name'	 => 'image',
				'label'	 => array( 'text' => __( 'Image', 'mp' ) ),
			) );
			$repeater->add_sub_field( 'checkbox', array(
				'name'			 => 'track_inventory',
				'label'			 => array( 'text' => __( 'Track Inventory?', 'mp' ) ),
				'conditional'	 => array(
					'name'	 => 'product_type',
					'value'	 => 'physical',
					'action' => 'show',
				),
			) );
			$repeater->add_sub_field( 'text', array(
				'name'			 => 'inventory',
				'label'			 => array( 'text' => __( 'Inventory Count', 'mp' ) ),
				'desc'			 => __( 'Enter the quantity that you have available to sell.', 'mp' ),
				'conditional'	 => array(
					'action'	 => 'show',
					'operator'	 => 'AND',
					array(
						'name'	 => 'product_type',
						'value'	 => 'physical',
					),
					array(
						'name'	 => 'variations[track_inventory]',
						'value'	 => 1,
					),
				),
				'validation'	 => array(
					'required'	 => true,
					'digits'	 => true,
					'min'		 => 0,
				),
			) );
			$repeater->add_sub_field( 'file', array(
				'name'			 => 'file_url',
				'label'			 => array( 'text' => __( 'File URL', 'mp' ) ),
				'conditional'	 => array(
					'name'	 => 'product_type',
					'value'	 => 'digital',
					'action' => 'show',
				),
				'validation'	 => array(
					'url' => true,
				),
			) );
			$repeater->add_sub_field( 'wysiwyg', array(
				'name'	 => 'description',
				'label'	 => array( 'text' => __( 'Description', 'mp' ) ),
				'desc'	 => __( 'If you would like the description to be different than the main product enter it here.', 'mp' ),
			) );
			$repeater->add_sub_field( 'text', array(
				'name'			 => 'external_url',
				'label'			 => array( 'text' => __( 'External URL', 'mp' ) ),
				'conditional'	 => array(
					'name'	 => 'product_type',
					'value'	 => 'external',
					'action' => 'show',
				),
				'validation'	 => array(
					'url' => true,
				),
			) );

			// Price Tab
			$repeater->add_sub_field( 'tab', array(
				'name'	 => 'tab_price',
				'slug'	 => 'price',
			) );
			$repeater->add_sub_field( 'text', array(
				'name'			 => 'regular_price',
				'label'			 => array( 'text' => __( 'Regular Price', 'mp' ) ),
				'conditional'	 => array(
					'name'	 => 'product_type',
					'value'	 => array( 'physical', 'digital' ),
					'action' => 'show',
				),
				'validation'	 => array(
					'required'	 => true,
					'number'	 => true,
				),
			) );
			$sale_price = $repeater->add_sub_field( 'complex', array(
				'name'			 => 'sale_price',
				'label'			 => array( 'text' => __( 'Sale Price (if applicable)', 'mp' ) ),
				'conditional'	 => array(
					'name'	 => 'product_type',
					'value'	 => array( 'physical', 'digital' ),
					'action' => 'show',
				),
			) );
			$sale_price->add_field( 'text', array(
				'name'		 => 'amount',
				'label'		 => array( 'text' => __( 'Price', 'mp' ) ),
				'custom'	 => array(
					'data-msg-lessthan' => __( 'Value must be less than regular price', 'mp' ),
				),
				'validation' => array(
					'number'	 => true,
					'min'		 => 0,
					'lessthan'	 => '[name*="regular_price"]'
				),
			) );
			$sale_price->add_field( 'datepicker', array(
				'name'	 => 'start_date',
				'label'	 => array( 'text' => __( 'Start Date', 'mp' ) ),
			) );
			$sale_price->add_field( 'datepicker', array(
				'name'	 => 'end_date',
				'label'	 => array( 'text' => __( 'End Date (if applicable)', 'mp' ) ),
			) );

			// Shipping Tab
			$repeater->add_sub_field( 'tab', array(
				'name'	 => 'tab_shipping',
				'slug'	 => 'shipping'
			) );
			$weight = $repeater->add_sub_field( 'complex', array(
				'name'			 => 'weight',
				'label'			 => array( 'text' => __( 'Weight', 'mp' ) ),
				'conditional'	 => array(
					'name'	 => 'product_type',
					'value'	 => 'physical',
					'action' => 'show',
				),
			) );
			$weight->add_field( 'text', array(
				'name'		 => 'pounds',
				'label'		 => array( 'text' => __( 'Pounds', 'mp' ) ),
				'validation' => array(
					'digits' => true,
					'min'	 => 0,
				),
			) );
			$weight->add_field( 'text', array(
				'name'		 => 'ounces',
				'label'		 => array( 'text' => __( 'Ounces', 'mp' ) ),
				'validation' => array(
					'digits' => true,
					'min'	 => 0,
				),
			) );
			$repeater->add_sub_field( 'text', array(
				'name'			 => 'extra_shipping_cost',
				'label'			 => array( 'text' => __( 'Extra Shipping Cost', 'mp' ) ),
				'default_value'	 => '0.00',
				'conditional'	 => array(
					'name'	 => 'product_type',
					'value'	 => array( 'physical', 'digital' ),
					'action' => 'show',
				),
				'validation'	 => array(
					'number' => true,
					'min'	 => 0,
				),
			) );

			// Taxes Tab
			$repeater->add_sub_field( 'tab', array(
				'name'	 => 'tab_taxes',
				'slug'	 => 'taxes'
			) );
			$repeater->add_sub_field( 'text', array(
				'name'			 => 'special_tax_rate',
				'label'			 => array( 'text' => __( 'Special Tax Rate', 'mp' ) ),
				'default_value'	 => '0.00',
				'conditional'	 => array(
					'name'	 => 'product_type',
					'value'	 => array( 'physical', 'digital' ),
					'action' => 'show',
				),
				'validation'	 => array(
					'number' => true,
					'min'	 => 0,
				),
			) );

			// Attributes Tab
			$repeater->add_sub_field( 'tab', array(
				'name'	 => 'tab_attributes',
				'slug'	 => 'attributes',
				'desc'	 => __( 'Each product variation needs to have product attributes assigned to it so the system knows how to differentiate one product variation from the other. It is <strong>important</strong> that you assign a category to this product before choosing any attributes.', 'mp' ),
			) );
			$mp_product_atts = MP_Product_Attributes::get_instance();
			$atts			 = $mp_product_atts->get();
			foreach ( $atts as $att ) {
				$slug	 = $mp_product_atts->generate_slug( $att->attribute_id );
				$terms	 = get_terms( $slug, 'hide_empty=0' );
				$terms	 = $mp_product_atts->sort( $terms, false );
				$args	 = array(
					'name'			 => $slug,
					'label'			 => array( 'text' => $att->attribute_name ),
					'multiple'		 => false,
					'placeholder'	 => sprintf( __( 'Select a %s', 'mp' ), $att->attribute_name ),
					'conditional'	 => array(
						'name'	 => 'product_type',
						'value'	 => array( 'physical', 'digital' ),
						'action' => 'show',
					),
					'validation'	 => array(
						'required' => true,
					),
				);

				// Set options
				$options = array( '' );
				foreach ( $terms as $term ) {
					$args[ 'options' ][ $term->term_id ] = $term->name;
				}

				// Set associated product categories
				$cats	 = $mp_product_atts->get_associated_categories( $att->attribute_id );
				$custom	 = array();
				foreach ( $cats as $cat_id ) {
					$key						 = 'data-product-category-' . $cat_id;
					$args[ 'custom' ][ $key ]	 = 'true';
				}

				$repeater->add_sub_field( 'advanced_select', $args );
			}
		}
	}

	function custom_placeholder_title( $placeholder, $post ) {
		if ( $post->post_type == MP_Product::get_post_type() ) {
			$placeholder = __( 'Enter your product name here', 'mp' );
		}
		return $placeholder;
	}

}

MP_Products_Screen::get_instance();
