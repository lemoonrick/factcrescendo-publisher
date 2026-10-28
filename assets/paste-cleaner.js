/**
 * Clean paste: text and pictures copied from Word, Google Docs or LibreOffice
 * arrive in the block editor tidy, instead of with their hidden formatting.
 *
 * What goes wrong without it, and what this does about it:
 *   - A picture and the words next to it end up in one paragraph and run
 *     together                         → every picture becomes its own block.
 *   - The same picture appears twice   → a picture that repeats right after
 *                                        itself is kept once.
 *   - Pictures are missing             → each picture is copied into the Media
 *                                        Library (so it can't expire or point at
 *                                        the writer's computer). Pictures that
 *                                        can't be read are reported, never
 *                                        silently lost.
 *   - Pictures put side by side in a table
 *                                      → the same rows as Columns blocks
 *                                        (empty cells stay empty, 4+ in a row
 *                                        go 2 to a row), nothing cropped. A
 *                                        table with words in it is left alone.
 *                                        Pictures anywhere else stay one under
 *                                        the other; a "side by side" note
 *                                        above them is handled on save.
 *   - Lines glued together, blank lines, no-break spaces and other leftovers
 *                                      → one line becomes one paragraph; empty
 *                                        paragraphs and stray spaces are removed.
 *
 * Only pastes that look like they came from Word, Google Docs or LibreOffice
 * (or that carry pictures) are handled. Anything else, including text copied
 * inside the editor, is left to WordPress. Plain JavaScript, no build step.
 *
 * Careful: zero-width joiners (U+200C, U+200D) are never removed. Hindi,
 * Marathi, Tamil, Sinhala and the other scripts need them to display correctly.
 */
( function ( wp ) {
    'use strict';
    if ( ! wp || ! wp.data || ! wp.blocks || ! wp.blocks.pasteHandler || ! wp.apiFetch ) return;

    var cfg    = window.fcPaste || {};
    var MARK   = /^@@FCIMG:(\d+):(\d+):(\d+):(\d+)@@$/;   // picture, row, place in the row, places in the row
    var MAX_UP = 3;          // pictures uploaded at the same time
    var TIMEOUT = 25000;     // milliseconds to wait for one picture

    // ── What is worth cleaning ──────────────────────────────────────────────

    function looksMessy( html ) {
        return /docs-internal-guid|class="?Mso|urn:schemas-microsoft-com|<o:p|mso-|LibreOffice|<!--StartFragment/i.test( html );
    }

    // ── Step 1: the copied HTML, before WordPress turns it into blocks ──────

    function unwrap( el ) {
        var p = el.parentNode;
        if ( ! p ) return;
        while ( el.firstChild ) p.insertBefore( el.firstChild, el );
        p.removeChild( el );
    }

    function normSrc( src ) {
        return String( src || '' ).split( '#' )[ 0 ].split( '?' )[ 0 ].toLowerCase();
    }

    function onlySpace( text ) {
        return text.replace( /[\s ​]+/g, '' ) === '';
    }

    /** Splits the paragraph around the picture, so the picture stands alone. */
    function splitAround( p, img, markerText ) {
        var doc = p.ownerDocument;
        var r1  = doc.createRange();
        r1.setStart( p, 0 );
        r1.setEndBefore( img );
        var beforeFrag = r1.extractContents();
        var r2 = doc.createRange();
        r2.setStartAfter( img );
        r2.setEnd( p, p.childNodes.length );
        var afterFrag = r2.extractContents();

        var before = p.cloneNode( false );
        before.appendChild( beforeFrag );
        var after = p.cloneNode( false );
        after.appendChild( afterFrag );
        var mark = doc.createElement( 'p' );
        mark.textContent = markerText;

        p.parentNode.insertBefore( before, p );
        p.parentNode.insertBefore( mark, p );
        p.parentNode.insertBefore( after, p );
        p.parentNode.removeChild( p );
    }

    function preprocess( html ) {
        var doc  = new DOMParser().parseFromString( html, 'text/html' );
        var body = doc.body;
        var out  = { images: [], stats: { duplicates: 0, tiny: 0 } };

        // Hidden parts: styles, scripts, Word's picture duplicates (VML), comments.
        Array.prototype.slice.call( body.querySelectorAll( 'script,style,meta,link,title,xml' ) ).forEach( function ( n ) { n.parentNode.removeChild( n ); } );
        var walker = doc.createTreeWalker( body, NodeFilter.SHOW_COMMENT, null );
        var comments = [];
        while ( walker.nextNode() ) comments.push( walker.currentNode );
        comments.forEach( function ( c ) { c.parentNode.removeChild( c ); } );
        Array.prototype.slice.call( body.getElementsByTagName( '*' ) ).forEach( function ( el ) {
            var n = el.tagName.toLowerCase();
            if ( n.indexOf( ':' ) === -1 ) return;
            if ( /^(v|w|x|st1):/.test( n ) ) el.parentNode.removeChild( el ); else unwrap( el );
        } );

        // Tables. Writers put pictures side by side in a table. A table that holds only
        // pictures becomes rows of pictures; any table with words in it is left exactly as
        // it is, pictures included.
        var group = 0;
        Array.prototype.slice.call( body.querySelectorAll( 'table' ) ).forEach( function ( table ) {
            if ( ! table.querySelector( 'img' ) || ! table.parentNode ) return;
            if ( ! onlySpace( table.textContent ) ) {
                Array.prototype.forEach.call( table.querySelectorAll( 'img' ), function ( img ) { img.setAttribute( 'data-fc-skip', '1' ); } );
                return;
            }
            // Copied row by row: a picture keeps its place in the row, an empty cell stays an empty place.
            var marks = [];
            Array.prototype.forEach.call( table.querySelectorAll( 'tr' ), function ( tr ) {
                var places = [];
                Array.prototype.forEach.call( tr.children, function ( cell ) {
                    var pics = Array.prototype.filter.call( cell.querySelectorAll( 'img' ), function ( img ) {
                        var w = parseInt( img.getAttribute( 'width' ) || '0', 10 ), h = parseInt( img.getAttribute( 'height' ) || '0', 10 );
                        return img.getAttribute( 'src' ) && ! ( ( w && w <= 2 ) || ( h && h <= 2 ) );
                    } );
                    if ( ! pics.length ) places.push( null ); else pics.forEach( function ( img ) { places.push( img ); } );
                } );
                if ( ! places.some( Boolean ) ) return;
                group++;
                places.forEach( function ( img, slot ) {
                    if ( ! img ) return;
                    var index = out.images.length;
                    out.images.push( { src: img.getAttribute( 'src' ), alt: img.getAttribute( 'alt' ) || '', group: group } );
                    var mk = doc.createElement( 'p' );
                    mk.textContent = '@@FCIMG:' + index + ':' + group + ':' + slot + ':' + places.length + '@@';
                    marks.push( mk );
                } );
            } );
            marks.forEach( function ( mk ) { table.parentNode.insertBefore( mk, table ); } );
            table.parentNode.removeChild( table );
        } );

        // Pictures. First look at all of them while the page is untouched: which are
        // spacers, which repeat the one before, which sit side by side. Then replace.
        var imgs = Array.prototype.slice.call( body.querySelectorAll( 'img:not([data-fc-skip])' ) );
        var plan = [], prev = null, lastKey = null;
        imgs.forEach( function ( img ) {
            var src = img.getAttribute( 'src' ) || '';
            var w = parseInt( img.getAttribute( 'width' ) || '0', 10 );
            var h = parseInt( img.getAttribute( 'height' ) || '0', 10 );
            if ( ! src || ( w && w <= 2 ) || ( h && h <= 2 ) ) { out.stats.tiny++; plan.push( { img: img, drop: true } ); return; }

            var together = false;
            if ( prev ) {
                var r = doc.createRange();
                r.setStartAfter( prev );
                r.setEndBefore( img );
                together = onlySpace( r.toString() );
            }
            if ( together && normSrc( src ) === lastKey ) { out.stats.duplicates++; plan.push( { img: img, drop: true } ); prev = img; return; }
            group++;   // each picture on its own: pictures go side by side only for a table or a "side by side" note (on save)
            lastKey = normSrc( src );
            prev = img;
            plan.push( { img: img, src: src, alt: img.getAttribute( 'alt' ) || '', group: group } );
        } );
        plan.forEach( function ( it ) {
            var img = it.img;
            if ( it.drop ) { img.parentNode.removeChild( img ); return; }
            var index  = out.images.length;
            out.images.push( { src: it.src, alt: it.alt, group: it.group } );
            var marker = '@@FCIMG:' + index + ':' + it.group + ':0:1@@';
            var p = img.closest( 'p' );
            if ( p ) {
                splitAround( p, img, marker );
            } else {
                var mk = doc.createElement( 'p' );
                mk.textContent = marker;
                img.parentNode.replaceChild( mk, img );
            }
        } );

        // Paragraphs left with nothing in them.
        Array.prototype.slice.call( body.querySelectorAll( 'p' ) ).forEach( function ( p ) {
            if ( onlySpace( p.textContent ) && ! p.querySelector( 'img,iframe' ) ) p.parentNode.removeChild( p );
        } );

        Array.prototype.forEach.call( body.querySelectorAll( '[data-fc-skip]' ), function ( img ) { img.removeAttribute( 'data-fc-skip' ); } );
        out.html = body.innerHTML;
        return out;
    }

    // ── Step 2: the blocks WordPress made, tidied ────────────────────────────

    function cleanInline( c ) {
        return String( c || '' )
            .replace( /&nbsp;| /g, ' ' )
            .replace( /[​­﻿]/g, '' )                   // never ‌ or ‍
            .replace( /<(strong|em|span|s|sub|sup|a)\b[^>]*>\s*<\/\1>/gi, '' )
            .replace( / {2,}/g, ' ' );
    }

    function plain( html ) {
        return String( html || '' ).replace( /<[^>]*>/g, '' ).replace( /&nbsp;/g, ' ' ).replace( /[\s ​]+/g, ' ' ).trim();
    }

    /** One line = one paragraph. */
    function splitLines( html ) {
        return html.split( /(?:\s*<br\b[^>]*>\s*)+/i ).map( function ( s ) { return s.trim(); } ).filter( function ( s ) { return plain( s ) !== ''; } );
    }

    function tidy( blocks, stats ) {
        var out = [];
        blocks.forEach( function ( b ) {
            if ( b.name === 'core/paragraph' ) {
                var content = cleanInline( b.attributes.content );
                var m = MARK.exec( plain( content ) );
                if ( m ) { out.push( { marker: { index: +m[ 1 ], group: +m[ 2 ], slot: +m[ 3 ], places: +m[ 4 ] } } ); return; }
                var lines = splitLines( content );
                if ( lines.length !== 1 || lines[ 0 ] !== b.attributes.content ) stats.tidied++;
                lines.forEach( function ( line ) {
                    out.push( { block: wp.blocks.createBlock( 'core/paragraph', Object.assign( {}, b.attributes, { content: line } ) ) } );
                } );
            } else if ( b.name === 'core/heading' ) {
                var h = cleanInline( b.attributes.content );
                if ( plain( h ) !== '' ) out.push( { block: wp.blocks.createBlock( 'core/heading', Object.assign( {}, b.attributes, { content: h } ) ) } );
            } else {
                out.push( { block: b } );
            }
        } );
        return out;
    }

    // ── Step 3: getting the pictures into the Media Library ──────────────────

    function isLocal( image ) {
        return ! /^(data:|blob:|https?:)/i.test( image.src );
    }

    /** A picture written into the address itself (data:image/png;base64,...). Decoded by hand: strict sites forbid fetching data: addresses. */
    function dataUrlToBlob( url ) {
        var comma = url.indexOf( ',' );
        var head = url.slice( 5, comma ), body = url.slice( comma + 1 );
        var type = head.split( ';' )[ 0 ] || 'image/png';
        var raw = /;base64/i.test( head ) ? atob( body ) : decodeURIComponent( body );
        var bytes = new Uint8Array( raw.length );
        for ( var i = 0; i < raw.length; i++ ) bytes[ i ] = raw.charCodeAt( i );
        return new Blob( [ bytes ], { type: type } );
    }

    function getBlob( image ) {
        var src = image.src;
        if ( /^data:/i.test( src ) ) return Promise.resolve( dataUrlToBlob( src ) );
        if ( /^blob:/i.test( src ) ) return fetch( src ).then( function ( r ) { return r.blob(); } );
        if ( /^https?:/i.test( src ) ) {
            return fetch( src, { credentials: 'omit' } ).then( function ( r ) {
                if ( ! r.ok ) throw new Error( 'http ' + r.status );
                return r.blob();
            } );
        }
        // A file on the writer's own computer (file:///...): only the clipboard can supply it.
        return image.file ? Promise.resolve( image.file ) : Promise.reject( new Error( 'local' ) );
    }

    function extension( type ) {
        return { 'image/png': 'png', 'image/jpeg': 'jpg', 'image/gif': 'gif', 'image/webp': 'webp' }[ type ] || 'png';
    }

    function withTimeout( promise, ms ) {
        return new Promise( function ( resolve, reject ) {
            var t = setTimeout( function () { reject( new Error( 'timeout' ) ); }, ms );
            promise.then( function ( v ) { clearTimeout( t ); resolve( v ); }, function ( e ) { clearTimeout( t ); reject( e ); } );
        } );
    }

    function upload( blob, image, n ) {
        var fd = new FormData();
        fd.append( 'file', blob, 'pasted-image-' + ( n + 1 ) + '.' + extension( blob.type ) );
        if ( image.alt ) fd.append( 'alt_text', image.alt );
        return wp.apiFetch( { path: '/wp/v2/media', method: 'POST', body: fd } ).then( function ( r ) {
            return { id: r.id, url: r.source_url, alt: image.alt };
        } );
    }

    /** Fetches and uploads every picture, a few at a time. Resolves to results by index. */
    function transfer( images, files ) {
        var results = new Array( images.length );
        var cache = {}, next = 0;

        // Pictures that only the clipboard can supply are matched to its files in order,
        // and only when the counts agree. (Some programs put one snapshot of the whole
        // selection on the clipboard; using that would give the wrong picture.)
        var local = images.filter( isLocal );
        if ( local.length && local.length === files.length ) local.forEach( function ( image, k ) { image.file = files[ k ]; } );

        function worker() {
            if ( next >= images.length ) return Promise.resolve();
            var i = next++, image = images[ i ], key = image.src;
            var cacheable = key.length < 400;
            if ( cacheable && cache[ key ] ) return cache[ key ].then( function ( r ) { results[ i ] = r; } ).then( worker );

            var job = withTimeout( getBlob( image ).then( function ( blob ) {
                if ( ! blob || ! /^image\//.test( blob.type || '' ) ) throw new Error( 'not a picture' );
                return upload( blob, image, i );
            } ), TIMEOUT ).then( function ( r ) { r.how = 'uploaded'; return r; }, function ( e ) {
                // Could not copy it in. A web address can still be shown as it is; anything else is lost.
                if ( /^https?:/i.test( image.src ) ) return { id: 0, url: image.src, alt: image.alt, how: 'linked' };
                return { failed: true, reason: e && e.message };
            } );
            if ( cacheable ) cache[ key ] = job;
            return job.then( function ( r ) { results[ i ] = r; } ).then( worker );
        }

        var pool = [];
        for ( var k = 0; k < Math.min( MAX_UP, images.length ); k++ ) pool.push( worker() );
        return Promise.all( pool ).then( function () { return results; } );
    }

    // ── Putting it all in the editor ─────────────────────────────────────────

    function notice( text ) {
        var n = wp.data.dispatch( 'core/notices' );
        if ( n && n.createNotice ) n.createNotice( 'info', text, { type: 'snackbar', isDismissible: true } );
    }

    /** Stays on screen until dismissed: used when something did not come across. */
    function warn( text ) {
        var n = wp.data.dispatch( 'core/notices' );
        if ( n && n.createNotice ) n.createNotice( 'warning', text, { isDismissible: true } );
    }

    /** Places in a row: up to 3 stay in one row; 4 or more go 2 to a row, the last one padded so all are the same size. */
    function rows( places ) {
        if ( places.length <= 3 ) return [ places ];
        var out = [];
        for ( var i = 0; i < places.length; i += 2 ) {
            var row = places.slice( i, i + 2 );
            if ( row.length === 1 ) row.push( null );
            out.push( row );
        }
        return out;
    }

    function imageBlock( r ) {
        return wp.blocks.createBlock( 'core/image', { id: r.id || undefined, url: r.url, alt: r.alt || '', sizeSlug: 'large', linkDestination: 'none' } );
    }

    function insertAtCursor( blocks ) {
        var be = wp.data.select( 'core/block-editor' ), dp = wp.data.dispatch( 'core/block-editor' );
        var sel = be.getSelectedBlockClientId();
        var blk = sel ? be.getBlock( sel ) : null;
        var emptyParagraph = blk && blk.name === 'core/paragraph' && plain( blk.attributes.content ) === '';
        if ( sel && emptyParagraph ) dp.replaceBlocks( sel, blocks );
        else if ( sel ) dp.insertBlocks( blocks, be.getBlockIndex( sel ) + 1, be.getBlockRootClientId( sel ) );
        else dp.insertBlocks( blocks );
    }

    /** Works out what the paste should become, without touching the editor. null = leave it to WordPress. */
    function prepare( html, plainText ) {
        var pre    = preprocess( html );
        var blocks = wp.blocks.pasteHandler( { HTML: pre.html, plainText: plainText, mode: 'BLOCKS' } );
        var stats  = { tidied: 0, duplicates: pre.stats.duplicates, tiny: pre.stats.tiny };
        var items  = tidy( blocks, stats );

        // A plain sentence or two with nothing to fix: WordPress handles those fine.
        if ( ! items.length ) return null;
        if ( ! pre.images.length && items.length === 1 && items[ 0 ].block && items[ 0 ].block.name === 'core/paragraph' && ! stats.tidied ) return null;

        // Safety: every picture place-holder must have become its own paragraph. If one ended
        // up anywhere else (inside a table, a list, a quote...), the picture would be lost, so
        // the whole paste is left to WordPress instead.
        var markers = items.filter( function ( it ) { return it.marker; } ).length;
        var leaked  = wp.blocks.serialize( items.filter( function ( it ) { return it.block; } ).map( function ( it ) { return it.block; } ) ).indexOf( '@@FCIMG' ) !== -1;
        if ( leaked || markers !== pre.images.length ) return null;

        return { pre: pre, items: items, stats: stats };
    }

    function execute( plan, files ) {
        var pre = plan.pre, items = plan.items, stats = plan.stats;

        // Place-holders where the pictures go; the rest of the article appears at once.
        var holders = {};
        var list = items.map( function ( it ) {
            if ( it.marker ) {
                var ph = wp.blocks.createBlock( 'core/paragraph', { content: '⏳ Adding picture…' } );
                holders[ ph.clientId ] = it.marker;
                return ph;
            }
            return it.block;
        } );
        insertAtCursor( list );

        if ( ! pre.images.length ) {
            notice( 'Cleaned the pasted text.' );
            return;
        }

        notice( 'Pasted. Adding ' + pre.images.length + ' picture' + ( pre.images.length > 1 ? 's' : '' ) + ' to the Media Library…' );
        transfer( pre.images, files ).then( function ( results ) {
            var dp = wp.data.dispatch( 'core/block-editor' ), be = wp.data.select( 'core/block-editor' );
            var ids = Object.keys( holders );
            var byGroup = {};
            ids.forEach( function ( id ) { ( byGroup[ holders[ id ].group ] = byGroup[ holders[ id ].group ] || [] ).push( id ); } );

            var missing = 0, linked = 0;

            function warning( index ) {
                missing++;
                // Never lose a picture silently: leave a visible reminder where it belongs.
                return wp.blocks.createBlock( 'core/paragraph', { content: '\u26a0 Picture ' + ( index + 1 ) + ' did not come across. Add it here from the Media Library.' } );
            }
            function result( index ) {
                var r = results[ index ];
                if ( ! r || r.failed ) return null;
                if ( r.how === 'linked' ) linked++;
                return r;
            }

            Object.keys( byGroup ).forEach( function ( g ) {
                var holderIds = byGroup[ g ].filter( function ( id ) { return !! be.getBlock( id ); } );   // the writer may have deleted some
                if ( ! holderIds.length ) return;
                var first = holders[ holderIds[ 0 ] ];

                if ( first.places <= 1 ) {
                    // A picture on its own.
                    holderIds.forEach( function ( id ) {
                        var r = result( holders[ id ].index );
                        dp.replaceBlocks( id, r ? imageBlock( r ) : warning( holders[ id ].index ) );
                    } );
                    return;
                }

                // A row from a table of pictures: same places, empty cells stay empty.
                var places = new Array( first.places );
                for ( var k = 0; k < places.length; k++ ) places[ k ] = null;
                holderIds.forEach( function ( id ) { places[ holders[ id ].slot ] = holders[ id ]; } );
                var blocks = rows( places ).map( function ( row ) {
                    return wp.blocks.createBlock( 'core/columns', {}, row.map( function ( h ) {
                        if ( ! h ) return wp.blocks.createBlock( 'core/column', {}, [] );
                        var r = result( h.index );
                        return wp.blocks.createBlock( 'core/column', {}, [ r ? imageBlock( r ) : warning( h.index ) ] );
                    } ) );
                } );
                dp.replaceBlocks( holderIds[ 0 ], blocks );
                if ( holderIds.length > 1 ) dp.removeBlocks( holderIds.slice( 1 ), false );
            } );

            var parts = [], problems = [];
            if ( stats.duplicates ) parts.push( stats.duplicates + ' repeated picture' + ( stats.duplicates > 1 ? 's' : '' ) + ' removed' );
            if ( linked ) problems.push( linked + ' picture' + ( linked > 1 ? 's' : '' ) + ' could not be copied into the Media Library and still link to the original address, which may stop working. Download and add ' + ( linked > 1 ? 'them' : 'it' ) + ' yourself.' );
            if ( missing ) problems.push( missing + ' picture' + ( missing > 1 ? 's' : '' ) + ' did not come across. Look for the \u26a0 line' + ( missing > 1 ? 's' : '' ) + ' in the article and add ' + ( missing > 1 ? 'them' : 'it' ) + ' from the Media Library.' );
            notice( parts.length ? 'Pictures added. ' + parts.join( '; ' ) + '.' : 'Pictures added.' );
            problems.forEach( warn );
        } ).catch( function () {
            warn( 'The pictures could not be added. Look for the \u23f3 lines in the article and add the pictures from the Media Library.' );
        } );
    }

    // ── Listening for pastes ─────────────────────────────────────────────────

    function onPaste( e ) {
        if ( cfg.on === false || window.fcPasteOff ) return;
        var cd = e.clipboardData;
        if ( ! cd ) return;
        var html = cd.getData( 'text/html' );
        if ( ! html || html.indexOf( '<!-- wp:' ) !== -1 ) return;                       // blocks copied inside the editor
        var t = e.target;
        if ( t && t.closest && t.closest( 'textarea,input,[data-type="core/html"],[data-type="core/code"],[data-type="core/preformatted"],.editor-post-title,.wp-block-post-title' ) ) return;
        if ( ! wp.data.select( 'core/block-editor' ).getSelectedBlockClientId() ) return;
        if ( ! looksMessy( html ) ) return;

        var files = Array.prototype.slice.call( cd.files || [] ).filter( function ( f ) { return /^image\//.test( f.type ); } );
        var plan = null;
        try {
            plan = prepare( html, cd.getData( 'text/plain' ) );
        } catch ( err ) {
            plan = null;           // anything unexpected: let WordPress paste it its own way
        }
        if ( ! plan ) return;

        // We take over this paste. (The counter only lets the automatic checks see that.)
        cfg.handled = ( cfg.handled || 0 ) + 1;
        e.preventDefault();
        e.stopPropagation();
        if ( e.stopImmediatePropagation ) e.stopImmediatePropagation();
        try {
            execute( plan, files );
        } catch ( err2 ) {
            notice( 'The paste could not be cleaned. Please try again, or paste as plain text (Ctrl or Cmd + Shift + V).' );
        }
    }

    function attach( doc ) {
        if ( ! doc || doc.__fcPaste ) return;
        doc.__fcPaste = true;
        doc.addEventListener( 'paste', onPaste, true );
    }

    function attachAll() {
        attach( document );
        var frames = document.querySelectorAll( 'iframe[name="editor-canvas"]' );
        Array.prototype.forEach.call( frames, function ( f ) {
            try { attach( f.contentDocument ); } catch ( e ) {}
            // The editor's frame can swap in a fresh page after it appears: listen on that one too.
            if ( ! f.__fcPasteLoad ) {
                f.__fcPasteLoad = true;
                f.addEventListener( 'load', function () { try { attach( f.contentDocument ); } catch ( e ) {} } );
            }
        } );
    }

    attachAll();
    new MutationObserver( attachAll ).observe( document.body, { childList: true, subtree: true } );
} )( window.wp );
