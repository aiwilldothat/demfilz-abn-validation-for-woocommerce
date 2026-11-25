/**
 * Demfilz ABN Validation: live feedback under the ABN field, in the checkout block and the classic checkout.
 *
 * The check digit test runs here, so a mistyped ABN is flagged without a request. A well-formed ABN is
 * sent to the store (never straight to the register: the store's GUID stays on the server), which answers
 * with the outcome of its policy and, for an active ABN, the business name.
 *
 * Nothing here decides whether an order is accepted. The server checks the ABN again when the order is placed.
 */
( function () {
	'use strict';

	var cfg     = window.dabnCheckout || {};
	var i18n    = cfg.i18n || {};
	var WEIGHTS = [ 10, 1, 3, 5, 7, 9, 11, 13, 15, 17, 19 ];
	var STATUS  = 'dabn-status';

	var typingTimer   = null;
	var placeTimer    = null;
	var requestNumber = 0;
	var answers       = {};
	var seenInput     = null;
	var filledCompany = '';

	function digits( value ) {
		return String( value || '' ).replace( /[^0-9]/g, '' );
	}

	function isValidAbn( abn ) {
		var sum = 0;
		var i;
		var digit;
		if ( 11 !== abn.length || '0' === abn.charAt( 0 ) ) {
			return false;
		}
		for ( i = 0; i < 11; i++ ) {
			digit = parseInt( abn.charAt( i ), 10 );
			if ( 0 === i ) {
				digit -= 1;
			}
			sum += digit * WEIGHTS[ i ];
		}
		return 0 === sum % 89;
	}

	function formatAbn( abn ) {
		if ( 11 !== abn.length ) {
			return abn;
		}
		return abn.substr( 0, 2 ) + ' ' + abn.substr( 2, 3 ) + ' ' + abn.substr( 5, 3 ) + ' ' + abn.substr( 8, 3 );
	}

	function findInput() {
		return document.querySelector( 'input[data-dabn="abn"]' ) ||
			document.getElementById( 'billing_abn' ) ||
			document.querySelector( '.wc-block-checkout input[id$="dabn-abn"]' );
	}

	function isAbnInput( node ) {
		return !! node && node === findInput();
	}

	function isClassic( input ) {
		return !! input && 'billing_abn' === input.id;
	}

	/**
	 * Put the status line right under the field, and keep it there when the checkout re-renders.
	 */
	function placeStatus() {
		var input  = findInput();
		var status = document.getElementById( STATUS );
		var anchor;

		if ( ! input ) {
			if ( status && status.parentNode ) {
				status.parentNode.removeChild( status );
			}
			seenInput = null;
			return null;
		}

		if ( ! status ) {
			status           = document.createElement( 'span' );
			status.id        = STATUS;
			status.className = 'dabn-status';
			status.setAttribute( 'role', 'status' );
			status.setAttribute( 'aria-live', 'polite' );
		}

		anchor = input.closest( '.wc-block-components-text-input' ) || input.closest( '.woocommerce-input-wrapper' ) || input;
		if ( anchor.parentNode && anchor.nextSibling !== status ) {
			anchor.parentNode.insertBefore( status, anchor.nextSibling );
		}

		// A field that has just appeared may already hold the customer's saved ABN: check it once.
		if ( seenInput !== input ) {
			seenInput = input;
			if ( '' !== digits( input.value ) ) {
				evaluate( false );
			}
		}
		return status;
	}

	function show( tone, text ) {
		var status = placeStatus();
		if ( ! status ) {
			return;
		}
		status.className   = 'dabn-status' + ( tone ? ' dabn-status--' + tone : '' );
		status.textContent = text || '';
	}

	function toneOf( answer ) {
		if ( 'active' === answer.code ) {
			return 'ok';
		}
		if ( 'unavailable' === answer.code || 'unchecked' === answer.code ) {
			return 'note';
		}
		return 'bad';
	}

	/**
	 * Fill an empty Company field with the business name. A name typed by the customer is never replaced.
	 */
	function fillCompany( name, input ) {
		var field;
		var data;
		var cart;
		var actions;
		var checkout;
		var customer;
		var billing;
		var shipping;
		var same;

		if ( ! cfg.autofill || ! name ) {
			return;
		}

		if ( isClassic( input ) ) {
			field = document.getElementById( 'billing_company' );
			if ( field && ( '' === field.value || field.value === filledCompany ) && field.value !== name ) {
				field.value   = name;
				filledCompany = name;
				field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}
			return;
		}

		// Checkout block: only when a Company field is on screen, through WooCommerce's cart store.
		if ( ! document.getElementById( 'billing-company' ) && ! document.getElementById( 'shipping-company' ) ) {
			return;
		}
		data = window.wp && window.wp.data;
		if ( ! data || 'function' !== typeof data.select || 'function' !== typeof data.dispatch ) {
			return;
		}
		cart    = data.select( 'wc/store/cart' );
		actions = data.dispatch( 'wc/store/cart' );
		if ( ! cart || ! actions || 'function' !== typeof cart.getCustomerData ) {
			return;
		}
		customer = cart.getCustomerData() || {};
		billing  = customer.billingAddress || {};
		shipping = customer.shippingAddress || {};
		checkout = data.select( 'wc/store/checkout' );
		same     = !! ( checkout && 'function' === typeof checkout.getUseShippingAsBilling && checkout.getUseShippingAsBilling() );

		if ( document.getElementById( 'billing-company' ) && 'function' === typeof actions.setBillingAddress && ( ! billing.company || billing.company === filledCompany ) ) {
			actions.setBillingAddress( { company: name } );
		}
		if ( same && document.getElementById( 'shipping-company' ) && 'function' === typeof actions.setShippingAddress && ( ! shipping.company || shipping.company === filledCompany ) ) {
			actions.setShippingAddress( { company: name } );
		}
		filledCompany = name;
	}

	function render( answer, input ) {
		var text = answer.message || '';
		if ( 'unchecked' === answer.code ) {
			text = i18n.format || '';
		}
		show( toneOf( answer ), text );
		if ( 'active' === answer.code ) {
			fillCompany( answer.name, input );
		}
	}

	function ask( abn, input ) {
		var mine = ++requestNumber;
		var body;

		if ( answers[ abn ] ) {
			render( answers[ abn ], input );
			return;
		}
		if ( ! cfg.ajaxUrl || 'function' !== typeof window.fetch || 'function' !== typeof window.FormData ) {
			show( 'note', i18n.format || '' );
			return;
		}

		show( 'busy', i18n.checking || '' );

		body = new window.FormData();
		body.append( 'action', 'dabn_lookup' );
		body.append( 'nonce', cfg.nonce || '' );
		body.append( 'abn', abn );

		window.fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.ok ? response.json() : null;
			} )
			.then( function ( json ) {
				if ( mine !== requestNumber ) {
					return;
				}
				if ( ! json || ! json.success || ! json.data ) {
					// The store could not answer (for example a page left open overnight): say what is known.
					show( 'note', i18n.format || '' );
					return;
				}
				// "Not checked" is not remembered, so the next try asks again.
				if ( 'unavailable' !== json.data.code ) {
					answers[ abn ] = json.data;
				}
				render( json.data, findInput() );
			} )
			.catch( function () {
				if ( mine === requestNumber ) {
					show( 'note', i18n.format || '' );
				}
			} );
	}

	/**
	 * Look at what is in the field and say something about it.
	 *
	 * @param {boolean} leaving True when the customer has left the field (an unfinished ABN is then pointed out).
	 */
	function evaluate( leaving ) {
		var input = findInput();
		var abn;

		if ( ! input ) {
			return;
		}
		abn = digits( input.value );
		requestNumber++;

		if ( '' === abn ) {
			show( '', '' );
			return;
		}
		if ( abn.length < 11 ) {
			show( leaving ? 'bad' : '', leaving ? ( i18n.short || '' ) : '' );
			return;
		}
		if ( ! isValidAbn( abn ) ) {
			show( 'bad', i18n.invalid || '' );
			return;
		}
		if ( ! cfg.lookups ) {
			show( 'note', i18n.format || '' );
			return;
		}
		ask( abn, input );
	}

	/**
	 * Classic checkout, Australian addresses only: hide the field for other billing countries.
	 */
	function applyCountry() {
		var field   = document.getElementById( 'billing_abn_field' );
		var country = document.getElementById( 'billing_country' );
		var showIt;

		if ( ! field || ! cfg.auOnly ) {
			return;
		}
		showIt              = ! country || '' === country.value || 'AU' === country.value;
		field.style.display = showIt ? '' : 'none';
	}

	document.addEventListener(
		'input',
		function ( event ) {
			if ( ! isAbnInput( event.target ) ) {
				return;
			}
			window.clearTimeout( typingTimer );
			typingTimer = window.setTimeout( function () {
				evaluate( false );
			}, 350 );
		},
		true
	);

	document.addEventListener(
		'focusout',
		function ( event ) {
			var abn;
			if ( ! isAbnInput( event.target ) ) {
				return;
			}
			window.clearTimeout( typingTimer );
			// The classic field is plain HTML, so it can be tidied to 51 824 753 556. The block's field belongs
			// to the checkout app; the server formats that one when it is saved.
			if ( isClassic( event.target ) ) {
				abn = digits( event.target.value );
				if ( isValidAbn( abn ) ) {
					event.target.value = formatAbn( abn );
				}
			}
			evaluate( true );
		},
		true
	);

	function schedulePlace() {
		window.clearTimeout( placeTimer );
		placeTimer = window.setTimeout( function () {
			applyCountry();
			placeStatus();
		}, 60 );
	}

	function start() {
		applyCountry();
		placeStatus();

		// The checkout block draws its fields after the page loads and redraws them later.
		if ( 'function' === typeof window.MutationObserver && document.body ) {
			new window.MutationObserver( schedulePlace ).observe( document.body, { childList: true, subtree: true } );
		}

		// The classic checkout announces country changes through jQuery, which plain listeners do not hear.
		if ( window.jQuery ) {
			window.jQuery( document.body ).on( 'change', '#billing_country', applyCountry );
			window.jQuery( document.body ).on( 'updated_checkout country_to_state_changed', applyCountry );
		}
		document.addEventListener( 'change', function ( event ) {
			if ( event.target && 'billing_country' === event.target.id ) {
				applyCountry();
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
