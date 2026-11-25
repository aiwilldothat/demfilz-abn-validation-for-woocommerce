<?php
/**
 * Admin: settings tab, order panel, customer profile field, order search.
 *
 * @package DemfilzAbnValidation
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything the shop manager sees.
 */
class DABN_Admin {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_get_settings_pages', array( $this, 'settings_page' ) );
		add_filter( 'plugin_action_links_' . DABN_BASENAME, array( $this, 'action_links' ) );

		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'order_panel' ) );
		add_action( 'admin_post_dabn_recheck', array( $this, 'recheck' ) );
		add_filter( 'woocommerce_admin_billing_fields', array( $this, 'order_fields' ), 20, 2 );

		add_filter( 'woocommerce_customer_meta_fields', array( $this, 'profile_field' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Add the settings tab.
	 *
	 * @param array $pages Settings pages.
	 * @return array
	 */
	public function settings_page( $pages ) {
		if ( class_exists( 'WC_Settings_Page' ) ) {
			require_once DABN_PATH . 'includes/class-dabn-settings-page.php';
			$pages[] = new DABN_Settings_Page();
		}
		return $pages;
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Action links.
	 * @return array
	 */
	public function action_links( $links ) {
		$settings = '<a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Settings', 'demfilz-abn-validation-for-woocommerce' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * URL of the settings tab.
	 *
	 * @param string $section Section id, empty for the main one.
	 * @return string
	 */
	public static function settings_url( $section = '' ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=dabn' );
		return '' !== $section ? add_query_arg( 'section', $section, $url ) : $url;
	}

	/**
	 * Stylesheet for the order panel.
	 *
	 * @return void
	 */
	public function assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		$order_screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$order_screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		if ( in_array( $screen->id, $order_screens, true ) ) {
			self::enqueue_style();
		}
	}

	/**
	 * Load the admin stylesheet (also called while a screen is printing, in which case it lands in the footer).
	 *
	 * @return void
	 */
	public static function enqueue_style() {
		wp_enqueue_style( 'dabn-admin', DABN_URL . 'admin/css/admin.css', array(), DABN_VERSION );
	}

	/**
	 * Print what is known about an ABN.
	 *
	 * @param array $record     Check record.
	 * @param bool  $show_abn   Print the ABN itself as the first line.
	 * @param bool  $with_links Print the link to the public record.
	 * @return void
	 */
	public static function check_panel( $record, $show_abn = true, $with_links = true ) {
		self::enqueue_style();

		$record = array_merge( DABN_Verify::record( '', '' ), (array) $record );
		$result = (string) $record['result'];
		$tone   = 'active' === $result ? 'ok' : ( in_array( $result, array( 'cancelled', 'not_found', 'invalid' ), true ) ? 'bad' : 'idle' );
		$name   = DABN_ABR::display_name( $record );
		$place  = trim( $record['state'] . ' ' . $record['postcode'] );

		echo '<div class="dabn-check dabn-check--' . esc_attr( $tone ) . '">';
		echo '<p class="dabn-check__head"><span class="dabn-check__dot" aria-hidden="true"></span>';
		echo '<strong>' . esc_html( DABN_Display::result_label( $result ) ) . '</strong>';
		if ( '' !== $name ) {
			echo ' <span class="dabn-check__name">' . esc_html( $name ) . '</span>';
		}
		echo '</p>';

		$lines = array();
		if ( $show_abn && '' !== $record['abn'] ) {
			/* translators: %s: ABN, for example 51 824 753 556. */
			$lines[] = sprintf( __( 'ABN %s', 'demfilz-abn-validation-for-woocommerce' ), DABN_ABN::format( $record['abn'] ) );
		}
		if ( in_array( $result, array( 'active', 'cancelled' ), true ) ) {
			if ( '' !== $record['name'] && $record['name'] !== $name ) {
				/* translators: %s: legal entity name. */
				$lines[] = sprintf( __( 'Entity: %s', 'demfilz-abn-validation-for-woocommerce' ), $record['name'] );
			}
			if ( '' !== $record['type'] ) {
				$lines[] = $record['type'];
			}
			$since = DABN_Display::date_text( $record['since'] );
			if ( '' !== $since ) {
				$lines[] = DABN_Display::status_text( $record );
			}
			/* translators: %s: GST registration, for example "Registered since 1 July 2000". */
			$lines[] = sprintf( __( 'GST: %s', 'demfilz-abn-validation-for-woocommerce' ), DABN_Display::gst_text( $record ) );
			if ( '' !== $place ) {
				$lines[] = $place;
			}
		} elseif ( 'unavailable' === $result ) {
			$lines[] = DABN_Display::note_text( $record['note'] );
		} elseif ( 'unchecked' === $result ) {
			$lines[] = '' === DABN_Settings::guid()
				? __( 'Add an ABN Lookup GUID in the settings to look ABNs up on the register.', 'demfilz-abn-validation-for-woocommerce' )
				: __( 'Lookups are switched off in the settings ("Check the format only").', 'demfilz-abn-validation-for-woocommerce' );
		} elseif ( 'invalid' === $result ) {
			$lines[] = __( 'The check digits do not add up, so this number cannot be an ABN.', 'demfilz-abn-validation-for-woocommerce' );
		}

		if ( $lines ) {
			echo '<ul class="dabn-check__list">';
			foreach ( $lines as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul>';
		}

		$meta = array();
		if ( ! empty( $record['checked'] ) ) {
			/* translators: %s: date and time. */
			$meta[] = esc_html( sprintf( __( 'Checked %s', 'demfilz-abn-validation-for-woocommerce' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $record['checked'] ) ) );
		}
		if ( $with_links && DABN_ABN::is_valid( $record['abn'] ) && 'not_found' !== $result ) {
			$meta[] = '<a href="' . esc_url( DABN_ABR::record_url( $record['abn'] ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View on ABN Lookup', 'demfilz-abn-validation-for-woocommerce' ) . '</a>';
		}
		if ( $meta ) {
			echo '<p class="dabn-check__meta">' . wp_kses(
				implode( ' &middot; ', $meta ),
				array(
					'a' => array(
						'href'   => true,
						'target' => true,
						'rel'    => true,
					),
				)
			) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Order screen: the ABN check, under the billing address.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function order_panel( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$abn = DABN_Orders::get_abn( $order );
		if ( '' === $abn ) {
			return;
		}
		$order_id = $order->get_id();
		$check    = DABN_Orders::get_check( $order );
		$url      = wp_nonce_url( admin_url( 'admin-post.php?action=dabn_recheck&order_id=' . $order_id ), 'dabn_recheck_' . $order_id );

		echo '<div class="dabn-order-box" id="dabn-order-box">';
		echo '<p class="dabn-order-box__title"><strong>' . esc_html__( 'ABN check', 'demfilz-abn-validation-for-woocommerce' ) . '</strong></p>';

		// Shown once, after "Check again" sent the browser back here.
		$flag = isset( $_GET['dabn_checked'] ) ? sanitize_text_field( wp_unslash( $_GET['dabn_checked'] ) ) : '';
		if ( '' !== $flag && wp_verify_nonce( $flag, 'dabn_checked_' . $order_id ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'The ABN was checked again.', 'demfilz-abn-validation-for-woocommerce' ) . '</p></div>';
		}

		if ( null === $check ) {
			echo '<p>' . esc_html__( 'This ABN has not been checked yet.', 'demfilz-abn-validation-for-woocommerce' ) . '</p>';
			echo '<p><a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Check now', 'demfilz-abn-validation-for-woocommerce' ) . '</a></p>';
		} else {
			self::check_panel( $check, false );
			// With lookups off there is nothing new to ask, so no button that would do nothing.
			if ( DABN_Settings::lookups_on() ) {
				echo '<p><a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Check again', 'demfilz-abn-validation-for-woocommerce' ) . '</a></p>';
			}
		}
		echo '</div>';
	}

	/**
	 * "Check again" on an order: ask the register afresh and go back to the order.
	 *
	 * @return void
	 */
	public function recheck() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		check_admin_referer( 'dabn_recheck_' . $order_id );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'demfilz-abn-validation-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'That order does not exist.', 'demfilz-abn-validation-for-woocommerce' ), '', array( 'response' => 404 ) );
		}

		DABN_Orders::sync( $order, true );

		wp_safe_redirect( add_query_arg( 'dabn_checked', wp_create_nonce( 'dabn_checked_' . $order_id ), $order->get_edit_order_url() ) . '#dabn-order-box' );
		exit;
	}

	/**
	 * Order screen: one ABN field among the billing fields, for every order.
	 *
	 * WooCommerce lists the checkout block's field here only for orders that came through the block, so
	 * that entry is replaced by a field of our own that reads and saves _billing_abn. After the order is
	 * saved, DABN_Orders::sync() tidies the number and checks it.
	 *
	 * @param array         $fields Billing fields of the order screen.
	 * @param WC_Order|bool $order  Order, when WooCommerce passes one.
	 * @return array
	 */
	public function order_fields( $fields, $order = null ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}
		// WooCommerce adds its entry under the field id. Left in, its input would post the old value on
		// save and put it straight back over an edited ABN.
		unset( $fields[ DABN_Orders::FIELD_ID ], $fields[ DABN_Orders::block_key() ] );

		$abn = $order instanceof WC_Order ? DABN_Orders::get_abn( $order ) : '';
		if ( ! DABN_Settings::field_on() && '' === $abn ) {
			return $fields;
		}

		$field = array(
			'label'         => DABN_Settings::label(),
			'value'         => DABN_ABN::format( $abn ),
			// With the ABN printed in the billing address, it is not listed a second time under it.
			'show'          => ! DABN_Settings::on( 'address_line' ) && '' !== $abn,
			'wrapper_class' => 'form-field-wide',
		);

		// After Company when it is there, otherwise last.
		$out   = array();
		$added = false;
		foreach ( $fields as $key => $value ) {
			$out[ $key ] = $value;
			if ( 'company' === $key ) {
				$out['abn'] = $field;
				$added      = true;
			}
		}
		if ( ! $added ) {
			$out['abn'] = $field;
		}
		return $out;
	}

	/**
	 * Customer profile: an ABN field in WooCommerce's billing section.
	 *
	 * @param array $fields Profile sections.
	 * @return array
	 */
	public function profile_field( $fields ) {
		if ( isset( $fields['billing']['fields'] ) && is_array( $fields['billing']['fields'] ) ) {
			$fields['billing']['fields'][ DABN_Orders::USER_KEY ] = array(
				'label'       => DABN_Settings::label(),
				'description' => __( 'Australian Business Number, 11 digits.', 'demfilz-abn-validation-for-woocommerce' ),
			);
		}
		return $fields;
	}
}
