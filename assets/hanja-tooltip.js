( function () {
	'use strict';

	var cache = new Map();
	var popup = null;
	var current = null;
	var config = window.HanjaTooltipConfig || {};
	var notFound = config.notFound || 'Not found';

	function closest( node, selector ) {
		if ( ! node ) {
			return null;
		}
		if ( node.nodeType !== 1 ) {
			node = node.parentElement;
		}
		if ( ! node || ! node.closest ) {
			return null;
		}
		return node.closest( selector );
	}

	function ensurePopup() {
		if ( ! popup ) {
			popup = document.createElement( 'div' );
			popup.className = 'hanja-tooltip';
			popup.setAttribute( 'role', 'tooltip' );
			popup.hidden = true;
			document.body.appendChild( popup );
		}
		return popup;
	}

	function hide() {
		if ( popup ) {
			popup.hidden = true;
			popup.textContent = '';
		}
		current = null;
	}

	function position( popupEl, target ) {
		var rect = target.getBoundingClientRect();
		var top = rect.bottom + window.scrollY + 4;
		var left = rect.left + window.scrollX;
		popupEl.hidden = false;
		var width = popupEl.offsetWidth;
		var maxLeft = window.scrollX + document.documentElement.clientWidth - width - 8;
		if ( left > maxLeft ) {
			left = Math.max( window.scrollX + 8, maxLeft );
		}
		var height = popupEl.offsetHeight;
		var viewportBottom = window.scrollY + window.innerHeight;
		if ( top + height > viewportBottom - 8 ) {
			var above = rect.top + window.scrollY - height - 4;
			if ( above >= window.scrollY + 8 ) {
				top = above;
			}
		}
		popupEl.style.top = top + 'px';
		popupEl.style.left = left + 'px';
	}

	function show( target, text ) {
		var el = ensurePopup();
		el.textContent = text;
		position( el, target );
	}

	function textFor( character, info ) {
		if ( info && info.found ) {
			return character + ( info.definition ? '\n' + info.definition : '' );
		}
		return character + '\n' + notFound;
	}

	function lookup( character ) {
		if ( cache.has( character ) ) {
			return Promise.resolve( cache.get( character ) );
		}
		if ( ! config.apiUrl ) {
			return Promise.resolve( character + '\n' + notFound );
		}
		var url;
		try {
			url = new URL( config.apiUrl, window.location.href );
		} catch ( error ) {
			return Promise.resolve( character + '\n' + notFound );
		}
		url.searchParams.set( 'char', character );
		return fetch( url.toString(), {
			headers: { Accept: 'application/json' }
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'lookup failed' );
			}
			return response.json();
		} ).then( function ( info ) {
			var text = textFor( character, info );
			cache.set( character, text );
			return text;
		} ).catch( function () {
			return character + '\n' + notFound;
		} );
	}

	function onClick( event, target ) {
		if ( ! event.detail || event.detail < 1 ) {
			return;
		}
		if ( closest( target, 'a' ) ) {
			return;
		}
		var character = target.getAttribute( 'data-char' );
		if ( ! character ) {
			return;
		}
		event.preventDefault();
		event.stopPropagation();
		if ( current === target && popup && ! popup.hidden ) {
			hide();
			return;
		}
		current = target;
		show( target, character );
		lookup( character ).then( function ( text ) {
			if ( current === target ) {
				show( target, text );
			}
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var word = closest( event.target, '.hanja-word' );
		if ( word ) {
			onClick( event, word );
			return;
		}
		if ( ! popup || popup.hidden ) {
			return;
		}
		if ( closest( event.target, '.hanja-tooltip' ) ) {
			return;
		}
		hide();
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key === 'Escape' ) {
			hide();
		}
	} );
}() );
