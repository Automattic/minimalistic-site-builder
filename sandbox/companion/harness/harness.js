/**
 * msb-companion harness — the Tree IR compiler.
 *
 * Vanilla ES5-ish, no build step, no bundler. Exposes exactly four globals:
 * __version, __ready, __registry(), __compile(). Everything else is private.
 */
( function ( window ) {
	'use strict';

	window.__version = '1';

	function message( e ) {
		return ( e && e.message ) ? String( e.message ) : String( e );
	}

	/** wp-block-library ships core blocks but does not self-register them; the editor calls this. */
	function registerCoreBlocks() {
		var wp = window.wp;
		if ( ! wp || ! wp.blocks || ! wp.blockLibrary ) { return; }
		if ( wp.blocks.getBlockType( 'core/paragraph' ) ) { return; }
		if ( typeof wp.blockLibrary.registerCoreBlocks === 'function' ) {
			wp.blockLibrary.registerCoreBlocks();
		}
	}

	window.__ready = new Promise( function ( resolve ) {
		function settle() {
			try { registerCoreBlocks(); } catch ( e ) { window.__ready_error = message( e ); }
			// One tick, so block scripts that register from their own domReady have run.
			window.setTimeout( resolve, 0 );
		}
		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', settle );
		} else {
			settle();
		}
	} );

	window.__registry = function () {
		if ( ! window.wp || ! window.wp.blocks ) { return []; }
		return window.wp.blocks.getBlockTypes().map( function ( type ) { return type.name; } );
	};

	/** createBlock applies defaults and sanitises attributes; recurse depth-first. */
	function create( node ) {
		if ( ! node || 'string' !== typeof node.name ) {
			throw new Error( 'every BlockNode needs a string "name"' );
		}
		// An unregistered name is not an error inside wp.blocks: createBlock
		// accepts it and serialize() then emits NOTHING for that subtree, so the
		// caller would get all_valid:true and silently missing content. Refuse.
		if ( ! window.wp.blocks.getBlockType( node.name ) ) {
			throw new Error( 'block "' + node.name + '" is not registered in this harness; it would serialize to nothing' );
		}
		var inner = ( node.innerBlocks || [] ).map( create );
		return window.wp.blocks.createBlock( node.name, node.attributes || {}, inner );
	}

	/** validationIssues carry a logger function; flatten to JSON-safe strings. */
	function issues( raw ) {
		if ( ! Array.isArray( raw ) ) { return []; }
		return raw.map( function ( issue ) {
			var args = ( issue && Array.isArray( issue.args ) ) ? issue.args : [];
			return args.map( function ( arg ) {
				if ( 'string' === typeof arg ) { return arg; }
				try { return JSON.stringify( arg ); } catch ( e ) { return String( arg ); }
			} );
		} );
	}

	/** RFC 6901 pointer rooted at the array passed to __compile: /0/innerBlocks/2 */
	function walk( blocks, prefix, out ) {
		blocks.forEach( function ( block, index ) {
			var path = prefix + '/' + index;
			if ( false === block.isValid ) {
				out.push( { path: path, name: block.name, validation_issues: issues( block.validationIssues ) } );
			}
			if ( block.innerBlocks && block.innerBlocks.length ) {
				walk( block.innerBlocks, path + '/innerBlocks', out );
			}
		} );
	}

	/**
	 * Content parity — the second silent-loss guard, one layer down from the
	 * unregistered-name refusal in create(). A sourced attribute (source html /
	 * text / rich-text / children / query, or role "content") lives in the saved
	 * markup, not the comment delimiter, so when the CURRENT save() does not
	 * render it — block.json keeps such attributes for migrating old markup,
	 * e.g. core/quote's `value` — the authored content exists NOWHERE in the
	 * output, and the round-trip parse is still perfectly valid. Only comparing
	 * the parse-back against the INPUT tree can see it. Same for inner blocks a
	 * save() never renders. "Lost" only — never equality, so save()'s own
	 * normalisation (entities, whitespace) can not produce false positives.
	 */
	function isSourcedContent( def ) {
		if ( ! def ) { return false; }
		if ( 'content' === def.role || 'content' === def.__experimentalRole ) { return true; }
		return -1 !== [ 'html', 'text', 'rich-text', 'children', 'query' ].indexOf( def.source );
	}

	function textOf( value ) {
		if ( null === value || undefined === value ) { return ''; }
		if ( Array.isArray( value ) ) { return value.length ? 'x' : ''; }
		return String( value ).replace( /<[^>]*>/g, ' ' ).trim();
	}

	function parity( nodes, parsed, prefix, out ) {
		nodes.forEach( function ( node, index ) {
			var path = prefix + '/' + index;
			var got = parsed[ index ];
			if ( ! got || got.name !== node.name ) {
				out.push( { path: path, name: node.name, message: 'the block did not survive compile: its serialized markup parses back as ' + ( got ? '"' + got.name + '"' : 'nothing' ) } );
				return;
			}
			var type = window.wp.blocks.getBlockType( node.name );
			var defs = ( type && type.attributes ) || {};
			var attrs = node.attributes || {};
			Object.keys( attrs ).forEach( function ( key ) {
				if ( ! isSourcedContent( defs[ key ] ) ) { return; }
				if ( '' === textOf( attrs[ key ] ) ) { return; }
				if ( '' !== textOf( got.attributes ? got.attributes[ key ] : undefined ) ) { return; }
				out.push( { path: path + '/attributes/' + key, name: node.name, attribute: key, message: 'attribute "' + key + '" carries authored content but this block\'s current save() does not render it (the schema keeps it only to migrate old markup) — the text would silently vanish from the page. Author this block\'s content where its save() reads it: as innerBlocks (core/quote holds core/paragraph children; core/list holds core/list-item children).' } );
			} );
			var inner = node.innerBlocks || [];
			var gotInner = got.innerBlocks || [];
			if ( gotInner.length < inner.length ) {
				out.push( { path: path + '/innerBlocks', name: node.name, message: inner.length + ' inner block(s) authored but only ' + gotInner.length + ' survived compile — this block\'s save() does not render inner blocks; move the content to a block that does' } );
			}
			parity( inner.slice( 0, gotInner.length ), gotInner, path + '/innerBlocks', out );
		} );
	}

	window.__compile = function ( blocks ) {
		try {
			if ( ! window.wp || ! window.wp.blocks ) {
				throw new Error( 'wp.blocks is not available on this page' );
			}
			if ( ! Array.isArray( blocks ) ) {
				throw new Error( '__compile expects TreeIR.blocks (an array of BlockNode), got ' + typeof blocks );
			}
			var markup = window.wp.blocks.serialize( blocks.map( create ) );
			var invalid = [];
			var reparsed = window.wp.blocks.parse( markup );
			walk( reparsed, '', invalid );
			var lost = [];
			parity( blocks, reparsed, '', lost );
			return { markup: markup, all_valid: 0 === invalid.length, invalid: invalid, content_lost: lost };
		} catch ( e ) {
			return { error: message( e ) };
		}
	};
}( window ) );
