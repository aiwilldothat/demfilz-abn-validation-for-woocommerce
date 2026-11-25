<?php
/**
 * WooCommerce > Settings > ABN Validation.
 *
 * @package DemfilzAbnValidation
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings tab built on WooCommerce's settings API (one array option, dabn_settings), plus the lookup tool.
 */
class DABN_Settings_Page extends WC_Settings_Page {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id    = 'dabn';
		$this->label = __( 'ABN Validation', 'demfilz-abn-validation-for-woocommerce' );
		parent::__construct();
	}

	/**
	 * Sections.
	 *
	 * @return array
	 */
	protected function get_own_sections() {
		return array(
			''     => __( 'Settings', 'demfilz-abn-validation-for-woocommerce' ),
			'tool' => __( 'Lookup tool', 'demfilz-abn-validation-for-woocommerce' ),
		);
	}

	/**
	 * Field id inside the settings array.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function id( $key ) {
		return DABN_Settings::OPTION . '[' . $key . ']';
	}

	/**
	 * An inline notice (kept beside the settings it is about, never in the admin notices area).
	 *
	 * @param string $type success, info, warning or error.
	 * @param string $text Plain text.
	 * @return string
	 */
	private static function notice( $type, $text ) {
		return '<div class="notice notice-' . esc_attr( $type ) . ' inline dabn-notice"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Whether the register accepts the saved GUID, as an inline notice.
	 *
	 * @return string HTML.
	 */
	private static function connection_notice() {
		$status = DABN_ABR::connection();
		if ( null === $status['ok'] ) {
			return self::notice( 'info', __( 'No GUID is saved. ABNs are checked for a valid format only: a mistyped or made-up number is refused, but a cancelled ABN is not noticed.', 'demfilz-abn-validation-for-woocommerce' ) );
		}
		if ( true === $status['ok'] ) {
			if ( ! DABN_Settings::lookups_on() ) {
				return self::notice( 'info', __( 'Your GUID works, but lookups are switched off: "When an ABN is entered" is set to check the format only.', 'demfilz-abn-validation-for-woocommerce' ) );
			}
			return self::notice( 'success', __( 'Connected to the Australian Business Register. ABNs are looked up as customers enter them.', 'demfilz-abn-validation-for-woocommerce' ) );
		}
		if ( 'dabn_bad_guid' === $status['code'] ) {
			$text = __( 'The Australian Business Register did not accept this GUID. Check that it matches the one in your registration email. Until it is fixed, ABNs are checked for a valid format only and orders are not held up.', 'demfilz-abn-validation-for-woocommerce' );
			if ( '' !== $status['message'] ) {
				/* translators: %s: message from the Australian Business Register. */
				$text .= ' ' . sprintf( __( 'The register said: %s', 'demfilz-abn-validation-for-woocommerce' ), $status['message'] );
			}
			return self::notice( 'error', $text );
		}
		return self::notice( 'warning', __( 'The Australian Business Register could not be reached just now. The checkout keeps working: ABNs are checked for a valid format and orders are marked "not checked" until the register answers again.', 'demfilz-abn-validation-for-woocommerce' ) );
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	protected function get_settings_for_default_section() {
		$d = DABN_Settings::defaults();

		$register = sprintf(
			/* translators: %s: link to the ABN Lookup web services registration page. */
			esc_html__( 'To look ABNs up, the Australian Business Register needs to know who is asking. Registration is free: %s, accept the web services agreement, and the register emails you a GUID (a code like 1a2b3c4d-5e6f-7a8b-9c0d-1e2f3a4b5c6d). Paste it below.', 'demfilz-abn-validation-for-woocommerce' ),
			'<a href="' . esc_url( DABN_ABR::REGISTER_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'register for ABN Lookup web services', 'demfilz-abn-validation-for-woocommerce' ) . '</a>'
		);

		return array(
			array(
				'title' => __( 'Checkout field', 'demfilz-abn-validation-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'The ABN field appears in the checkout block under the email address, and in the classic checkout after Company. Customers can also change their ABN in My Account > Account details.', 'demfilz-abn-validation-for-woocommerce' ),
				'id'    => 'dabn_field',
			),
			array(
				'title'   => __( 'ABN field', 'demfilz-abn-validation-for-woocommerce' ),
				'id'      => self::id( 'field' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => $d['field'],
				'options' => array(
					'optional' => __( 'Optional', 'demfilz-abn-validation-for-woocommerce' ),
					'company'  => __( 'Required when a company name is given', 'demfilz-abn-validation-for-woocommerce' ),
					'required' => __( 'Required', 'demfilz-abn-validation-for-woocommerce' ),
					'off'      => __( 'Do not ask for an ABN', 'demfilz-abn-validation-for-woocommerce' ),
				),
				'desc'    => __( '"Required when a company name is given" suits stores with both trade and retail customers: people who fill in Company must give an ABN, everyone else can leave it empty.', 'demfilz-abn-validation-for-woocommerce' ),
			),
			array(
				'title'   => __( 'Ask', 'demfilz-abn-validation-for-woocommerce' ),
				'id'      => self::id( 'scope' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => $d['scope'],
				'options' => array(
					'au'  => __( 'Customers with an Australian billing address', 'demfilz-abn-validation-for-woocommerce' ),
					'all' => __( 'All customers', 'demfilz-abn-validation-for-woocommerce' ),
				),
				'desc'    => __( 'For other billing countries the field is hidden and nothing is checked.', 'demfilz-abn-validation-for-woocommerce' ),
			),
			array(
				'title'       => __( 'Label', 'demfilz-abn-validation-for-woocommerce' ),
				'id'          => self::id( 'label' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => __( 'ABN', 'demfilz-abn-validation-for-woocommerce' ),
			),
			array(
				'title'   => __( 'Company name', 'demfilz-abn-validation-for-woocommerce' ),
				'desc'    => __( 'Fill an empty Company field with the business name the register returns (needs a GUID)', 'demfilz-abn-validation-for-woocommerce' ),
				'id'      => self::id( 'autofill' ),
				'type'    => 'checkbox',
				'default' => $d['autofill'],
			),
			array(
				'title'    => __( 'Billing address', 'demfilz-abn-validation-for-woocommerce' ),
				'desc'     => __( 'Add the ABN as the last line of the order\'s billing address', 'demfilz-abn-validation-for-woocommerce' ),
				'id'       => self::id( 'address_line' ),
				'type'     => 'checkbox',
				'default'  => $d['address_line'],
				'desc_tip' => __( 'The address is what order emails and most PDF invoice and packing slip plugins print, so this puts the buyer\'s ABN on those documents.', 'demfilz-abn-validation-for-woocommerce' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'dabn_field',
			),
			array(
				'title' => __( 'Australian Business Register', 'demfilz-abn-validation-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => $register . '</p>' . self::connection_notice() . '<p>',
				'id'    => 'dabn_register',
			),
			array(
				'title'             => __( 'ABN Lookup GUID', 'demfilz-abn-validation-for-woocommerce' ),
				'id'                => self::id( 'guid' ),
				'type'              => 'text',
				'default'           => '',
				'css'               => 'min-width:340px;',
				'desc'              => __( 'Kept on your server. It is never sent to your customers\' browsers.', 'demfilz-abn-validation-for-woocommerce' ),
				'custom_attributes' => array(
					'autocomplete' => 'off',
					'spellcheck'   => 'false',
				),
			),
			array(
				'title'   => __( 'When an ABN is entered', 'demfilz-abn-validation-for-woocommerce' ),
				'id'      => self::id( 'verify' ),
				'type'    => 'radio',
				'default' => $d['verify'],
				'options' => array(
					'active' => __( 'Look it up, and refuse ABNs that are cancelled or not on the register', 'demfilz-abn-validation-for-woocommerce' ),
					'record' => __( 'Look it up and keep the answer with the order, but refuse nothing', 'demfilz-abn-validation-for-woocommerce' ),
					'format' => __( 'Check the format only (no lookup)', 'demfilz-abn-validation-for-woocommerce' ),
				),
				'desc'    => __( 'An ABN with the wrong check digits is always refused. If the register cannot be reached, the order goes through and is marked "not checked".', 'demfilz-abn-validation-for-woocommerce' ),
			),
			array(
				'title'   => __( 'GST', 'demfilz-abn-validation-for-woocommerce' ),
				'desc'    => __( 'Treat an ABN that is not registered for GST like a cancelled one', 'demfilz-abn-validation-for-woocommerce' ),
				'id'      => self::id( 'require_gst' ),
				'type'    => 'checkbox',
				'default' => $d['require_gst'],
			),
			array(
				'title'             => __( 'Keep answers for', 'demfilz-abn-validation-for-woocommerce' ),
				'desc'              => __( 'hours, before the same ABN is looked up again', 'demfilz-abn-validation-for-woocommerce' ),
				'id'                => self::id( 'cache_hours' ),
				'type'              => 'number',
				'default'           => $d['cache_hours'],
				'css'               => 'width:80px;',
				'custom_attributes' => array(
					'min'  => 1,
					'max'  => 720,
					'step' => 1,
				),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'dabn_register',
			),
			array(
				'title' => __( 'Data', 'demfilz-abn-validation-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'dabn_data',
			),
			array(
				'title'   => __( 'Uninstall', 'demfilz-abn-validation-for-woocommerce' ),
				'desc'    => __( 'Delete these settings when the plugin is deleted (ABNs on orders and customer accounts are always kept)', 'demfilz-abn-validation-for-woocommerce' ),
				'id'      => self::id( 'delete_data' ),
				'type'    => 'checkbox',
				'default' => $d['delete_data'],
			),
			array(
				'type' => 'sectionend',
				'id'   => 'dabn_data',
			),
		);
	}

	/**
	 * Print the page: the settings, or the lookup tool.
	 *
	 * @return void
	 */
	public function output() {
		global $current_section;
		DABN_Admin::enqueue_style();
		if ( 'tool' === $current_section ) {
			$this->tool();
			return;
		}
		parent::output();
	}

	/**
	 * The lookup tool. It is not a form of its own (the settings screen is one big form already):
	 * a button and a small script send the request.
	 *
	 * @return void
	 */
	private function tool() {
		wp_enqueue_script( 'dabn-admin', DABN_URL . 'admin/js/admin.js', array(), DABN_VERSION, true );
		wp_localize_script(
			'dabn-admin',
			'dabnAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'dabn_admin_lookup' ),
				'i18n'    => array(
					'looking'  => __( 'Asking the Australian Business Register...', 'demfilz-abn-validation-for-woocommerce' ),
					'failed'   => __( 'The lookup could not be completed. Please try again.', 'demfilz-abn-validation-for-woocommerce' ),
					'noNames'  => __( 'No names like that were found.', 'demfilz-abn-validation-for-woocommerce' ),
					'matches'  => __( 'Closest matches. Choose one to see its record.', 'demfilz-abn-validation-for-woocommerce' ),
					'name'     => __( 'Name', 'demfilz-abn-validation-for-woocommerce' ),
					'abn'      => __( 'ABN', 'demfilz-abn-validation-for-woocommerce' ),
					'place'    => __( 'Location', 'demfilz-abn-validation-for-woocommerce' ),
					'match'    => __( 'Match', 'demfilz-abn-validation-for-woocommerce' ),
					'details'  => __( 'Details', 'demfilz-abn-validation-for-woocommerce' ),
					'view'     => __( 'View on ABN Lookup', 'demfilz-abn-validation-for-woocommerce' ),
					'notFound' => __( 'The register holds no ABN with this number.', 'demfilz-abn-validation-for-woocommerce' ),
				),
			)
		);

		echo '<div class="dabn-tool" id="dabn-tool">';
		echo '<h2>' . esc_html__( 'Look up an ABN', 'demfilz-abn-validation-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Type an ABN (11 digits), an ACN (9 digits) or part of a business name. The answer comes straight from the Australian Business Register and is not saved anywhere.', 'demfilz-abn-validation-for-woocommerce' ) . '</p>';

		if ( '' === DABN_Settings::guid() ) {
			echo '<div class="notice notice-info inline dabn-notice"><p>';
			echo esc_html__( 'No GUID is saved yet, so only the format of an ABN or ACN can be checked here.', 'demfilz-abn-validation-for-woocommerce' );
			echo ' <a href="' . esc_url( DABN_Admin::settings_url() ) . '">' . esc_html__( 'Add your GUID in the settings', 'demfilz-abn-validation-for-woocommerce' ) . '</a>';
			echo '</p></div>';
		}

		echo '<p class="dabn-tool__row">';
		echo '<label class="screen-reader-text" for="dabn-tool-q">' . esc_html__( 'ABN, ACN or business name', 'demfilz-abn-validation-for-woocommerce' ) . '</label>';
		echo '<input type="text" id="dabn-tool-q" class="regular-text" autocomplete="off" spellcheck="false" placeholder="' . esc_attr__( '51 824 753 556', 'demfilz-abn-validation-for-woocommerce' ) . '" /> ';
		echo '<button type="button" class="button button-primary" id="dabn-tool-go">' . esc_html__( 'Look up', 'demfilz-abn-validation-for-woocommerce' ) . '</button>';
		echo '<span class="spinner" id="dabn-tool-spinner"></span>';
		echo '</p>';
		echo '<div id="dabn-tool-result" class="dabn-tool__result" role="status" aria-live="polite"></div>';
		echo '</div>';
	}

	/**
	 * Save, then normalise the stored array.
	 *
	 * @return void
	 */
	public function save() {
		global $current_section;
		if ( 'tool' === $current_section ) {
			return;
		}

		DABN_Settings::flush();
		$previous = DABN_Settings::guid();

		parent::save();
		DABN_Settings::flush();

		$stored = get_option( DABN_Settings::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$typed  = isset( $stored['guid'] ) && is_scalar( $stored['guid'] ) ? trim( (string) $stored['guid'] ) : '';
		$clean  = DABN_Settings::sanitize( array( 'guid' => $typed ) );
		$wrong  = '' !== $typed && '' === $clean['guid'];

		// A mistyped GUID must not cost the store the one that works.
		if ( $wrong ) {
			$stored['guid'] = $previous;
		}

		DABN_Settings::save( $stored );
		DABN_ABR::reset();

		if ( $wrong ) {
			$message = __( 'That does not look like an ABN Lookup GUID, so it was not saved. A GUID has 32 letters and digits in five groups, like 1a2b3c4d-5e6f-7a8b-9c0d-1e2f3a4b5c6d.', 'demfilz-abn-validation-for-woocommerce' );
			if ( '' !== $previous ) {
				$message .= ' ' . __( 'The GUID you had before is still in place.', 'demfilz-abn-validation-for-woocommerce' );
			}
			WC_Admin_Settings::add_error( $message );
		}
	}
}
