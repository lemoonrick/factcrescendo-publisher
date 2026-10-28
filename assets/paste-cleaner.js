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
    var MARK   = /^@@FCIMG:(\d+):(\d+)@@$/;
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

        // Pictures. First look at all of them while the page is untouched: which are
        // spacers, which repeat the one before, which sit side by side. Then replace.
        var imgs = Array.prototype.slice.call( body.querySelectorAll( 'img' ) );
        var plan = [], prev = null, group = 0, lastKey = null;
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
            if ( ! together ) group++;
            lastKey = normSrc( src );
            prev = img;
            plan.push( { img: img, src: src, alt: img.getAttribute( 'alt' ) || '', group: group } );
        } );
        plan.forEach( function ( it ) {
            var img = it.img;
            if ( it.drop ) { img.parentNode.removeChild( img ); return; }
            var index  = out.images.length;
            out.images.push( { src: it.src, alt: it.alt, group: it.group } );
            var marker = '@@FCIMG:' + index + ':' + it.group + '@@';
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
                if ( m ) { out.push( { marker: { index: +m[ 1 ], group: +m[ 2 ] } } ); return; }
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
            Object.keys( byGroup ).forEach( function ( g ) {
                var holderIds = byGroup[ g ].filter( function ( id ) { return !! be.getBlock( id ); } );   // the writer may have deleted some
                var good = [];
                holderIds.forEach( function ( id ) {
                    var r = results[ holders[ id ].index ];
                    if ( ! r || r.failed ) {
                        // Never lose a picture silently: leave a visible reminder where it belongs.
                        missing++;
                        dp.replaceBlocks( id, wp.blocks.createBlock( 'core/paragraph', { content: '\u26a0 Picture ' + ( holders[ id ].index + 1 ) + ' did not come across. Add it here from the Media Library.' } ) );
                        return;
                    }
                    if ( r.how === 'linked' ) linked++;
                    good.push( { id: id, r: r } );
                } );
                if ( ! good.length ) return;

                var first = good[ 0 ].id;
                if ( good.length === 1 ) {
                    dp.replaceBlocks( first, imageBlock( good[ 0 ].r ) );
                } else {
                    // Pictures that were side by side in the document stay side by side.
                    var gallery = wp.blocks.createBlock( 'core/gallery', { columns: Math.min( good.length, 3 ), linkTo: 'none' }, good.map( function ( x ) { return imageBlock( x.r ); } ) );
                    dp.replaceBlocks( first, gallery );
                }
                var rest = holderIds.filter( function ( id ) { return id !== first; } );
                if ( rest.length ) dp.removeBlocks( rest, false );
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
        } );
    }

    attachAll();
    new MutationObserver( attachAll ).observe( document.body, { childList: true, subtree: true } );
} )( window.wp );
