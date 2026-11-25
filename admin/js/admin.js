/**
 * Demfilz ABN Validation: the lookup tool in WooCommerce > Settings > ABN Validation.
 *
 * Everything is written to the page as text nodes, so nothing the register returns is treated as HTML.
 */
( function () {
	'use strict';

	var cfg     = window.dabnAdmin || {};
	var i18n    = cfg.i18n || {};
	var input   = document.getElementById( 'dabn-tool-q' );
	var button  = document.getElementById( 'dabn-tool-go' );
	var spinner = document.getElementById( 'dabn-tool-spinner' );
	var out     = document.getElementById( 'dabn-tool-result' );
	var running = 0;

	if ( ! input || ! button || ! out ) {
		return;
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( undefined !== text && null !== text ) {
			node.textContent = String( text );
		}
		return node;
	}

	function clear() {
		while ( out.firstChild ) {
			out.removeChild( out.firstChild );
		}
	}

	function busy( on ) {
		button.disabled = !! on;
		if ( spinner ) {
			spinner.classList.toggle( 'is-active', !! on );
		}
	}

	function message( type, text, note ) {
		var box = el( 'div', 'notice notice-' + type + ' inline dabn-notice' );
		box.appendChild( el( 'p', '', text ) );
		if ( note ) {
			box.appendChild( el( 'p', '', note ) );
		}
		clear();
		out.appendChild( box );
	}

	function showRecord( record ) {
		var tone  = 'active' === record.result ? 'ok' : 'bad';
		var card  = el( 'div', 'dabn-check dabn-check--' + tone + ' dabn-tool__card' );
		var head  = el( 'p', 'dabn-check__head' );
		var table = el( 'table', 'dabn-tool__table' );
		var body  = el( 'tbody' );
		var link;
		var meta;

		head.appendChild( el( 'span', 'dabn-check__dot' ) );
		head.appendChild( el( 'strong', '', record.title || record.abn ) );
		card.appendChild( head );

		if ( 'not_found' === record.result ) {
			card.appendChild( el( 'p', '', ( i18n.notFound || '' ) + ' ' + record.abn ) );
		}

		( record.rows || [] ).forEach( function ( row ) {
			var tr = el( 'tr' );
			tr.appendChild( el( 'th', '', row[ 0 ] ) );
			tr.appendChild( el( 'td', '', row[ 1 ] ) );
			body.appendChild( tr );
		} );
		if ( body.firstChild ) {
			table.appendChild( body );
			card.appendChild( table );
		}

		if ( record.url ) {
			meta        = el( 'p', 'dabn-check__meta' );
			link        = el( 'a', '', i18n.view || '' );
			link.href   = record.url;
			link.target = '_blank';
			link.rel    = 'noopener noreferrer';
			meta.appendChild( link );
			card.appendChild( meta );
		}

		clear();
		out.appendChild( card );
	}

	function showNames( names ) {
		var table;
		var head;
		var body;
		var row;

		if ( ! names.length ) {
			message( 'info', i18n.noNames || '' );
			return;
		}

		table = el( 'table', 'widefat striped dabn-tool__names' );
		head  = el( 'thead' );
		body  = el( 'tbody' );
		row   = el( 'tr' );
		[ i18n.name, i18n.abn, i18n.place, i18n.match, '' ].forEach( function ( label ) {
			var th = el( 'th', '', label || '' );
			th.scope = 'col';
			row.appendChild( th );
		} );
		head.appendChild( row );

		names.forEach( function ( item ) {
			var tr     = el( 'tr' );
			var action = el( 'td' );
			var more;

			tr.appendChild( el( 'td', '', item.name ) );
			tr.appendChild( el( 'td', '', item.abn ) );
			tr.appendChild( el( 'td', '', item.place ) );
			tr.appendChild( el( 'td', '', item.score ? item.score + '%' : '' ) );
			if ( item.validAbn ) {
				more      = el( 'button', 'button button-small', i18n.details || '' );
				more.type = 'button';
				more.addEventListener( 'click', function () {
					input.value = item.abn;
					lookup();
				} );
				action.appendChild( more );
			}
			tr.appendChild( action );
			body.appendChild( tr );
		} );

		table.appendChild( head );
		table.appendChild( body );
		clear();
		out.appendChild( el( 'p', 'description', i18n.matches || '' ) );
		out.appendChild( table );
	}

	function lookup() {
		var query = input.value.replace( /^\s+|\s+$/g, '' );
		var mine  = ++running;
		var body;

		if ( '' === query ) {
			input.focus();
			return;
		}
		if ( 'function' !== typeof window.fetch || 'function' !== typeof window.FormData ) {
			message( 'error', i18n.failed || '' );
			return;
		}

		busy( true );
		clear();
		out.appendChild( el( 'p', 'description', i18n.looking || '' ) );

		body = new window.FormData();
		body.append( 'action', 'dabn_admin_lookup' );
		body.append( 'nonce', cfg.nonce || '' );
		body.append( 'q', query );

		window.fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( mine !== running ) {
					return;
				}
				busy( false );
				if ( ! json || ! json.data ) {
					message( 'error', i18n.failed || '' );
					return;
				}
				if ( ! json.success ) {
					message( 'warning', json.data.message || i18n.failed || '', json.data.note || '' );
					return;
				}
				if ( 'names' === json.data.kind ) {
					showNames( json.data.names || [] );
					return;
				}
				showRecord( json.data.record || {} );
			} )
			.catch( function () {
				if ( mine === running ) {
					busy( false );
					message( 'error', i18n.failed || '' );
				}
			} );
	}

	button.addEventListener( 'click', lookup );

	// The settings screen is one form: Enter in this field must look up, not save the settings.
	input.addEventListener( 'keydown', function ( event ) {
		if ( 'Enter' === event.key ) {
			event.preventDefault();
			lookup();
		}
	} );
}() );
