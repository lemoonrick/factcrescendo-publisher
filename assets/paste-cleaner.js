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

    // ── Writers' notes ───────────────────────────────────────────────────────

    var LABEL = '(?:production|prod\\.?)[\\s\\-_]*notes?|notes?\\s+(?:to|for)\\s+(?:the\\s+)?production';

    /**
     * A production note, however it is written ("[PRODUCTION NOTE: …", "Production
     * Notes -", "(prod. note) …", "Note to production: …"). Same rules as on save
     * (embeds.php): null = not a note, '' = the whole line is the note, otherwise the
     * line without the bracketed note. "Production notes are kept by the team." is
     * a sentence and is never touched.
     */
    function stripProductionNote( html ) {
        var lead = '(?:\\s|&nbsp;|<[^>]+>)*';
        var m = new RegExp( '^' + lead + '([\\[\\(])' + lead + '(?:' + LABEL + ')\\b', 'i' ).exec( html );
        if ( m ) {
            var end = html.indexOf( m[ 1 ] === '[' ? ']' : ')', m[ 0 ].length );
            if ( end === -1 ) return '';
            var rest = html.slice( end + 1 ).replace( /^(?:\s|&nbsp;|<\/[^>]+>)+/, '' );
            return plain( rest ) === '' ? '' : rest;
        }
        var t  = plain( html );
        var mm = new RegExp( '^[\\*\\s]*(' + LABEL + ')\\b(.?)', 'i' ).exec( t );
        if ( ! mm ) return null;
        var afterOk = mm[ 2 ] === '' || /^[:\-\u2013\u2014\]\)\*]/.test( mm[ 2 ] );
        return ( afterOk || /^[\*\s]*(?:PRODUCTION|PROD)/.test( t ) ) ? '' : null;
    }

    /** "Side by side", "SIDE-BY-SIDE", "side_by_side", "sidebyside" ... */
    function saysSideBySide( html ) {
        return plain( html ).toLowerCase().replace( /[^a-z]+/g, '' ).indexOf( 'sidebyside' ) !== -1;
    }

    /** An instruction rather than a sentence: a few words, in brackets, or with the word "note". */
    function isNoteLike( html ) {
        var t = plain( html ).toLowerCase();
        return t !== '' && ( t.split( /\s+/ ).length <= 6 || /^[\[\(\*\{]/.test( t ) || /\bnote\b/.test( t ) );
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
                    var pn = stripProductionNote( line );
                    if ( pn === '' ) { stats.notes++; stats.tidied++; return; }
                    if ( pn !== null ) { line = pn; stats.notes++; stats.tidied++; }
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

    /**
     * Makes a picture lighter before it is sent: at most 2560 pixels on its longest
     * side (the size WordPress itself scales big photos down to) and WebP at the
     * quality chosen in Settings. Uploads are much faster and the server has less
     * to do. GIFs, animated PNGs and anything the browser can't read go as they are.
     * If the result isn't smaller, the original is sent. Never fails: any problem
     * means the original is sent and the server converts it.
     */
    function lighten( blob ) {
        if ( ! cfg.webp || typeof createImageBitmap !== 'function' || ! /^image\/(jpeg|png|webp)$/.test( blob.type ) ) return Promise.resolve( blob );
        if ( blob.size > 25 * 1024 * 1024 ) return Promise.resolve( blob );   // a huge file could exhaust a phone's memory: the server handles it
        var check = blob.type === 'image/png' && blob.slice && blob.slice( 0, 4096 ).arrayBuffer
            ? blob.slice( 0, 4096 ).arrayBuffer().then( function ( buf ) { return new TextDecoder( 'latin1' ).decode( buf ).indexOf( 'acTL' ) === -1; } )
            : Promise.resolve( true );
        return check.then( function ( stillPicture ) {
            if ( ! stillPicture ) return blob;                                   // animated PNG
            return createImageBitmap( blob, { imageOrientation: 'from-image' } ).then( function ( bmp ) {
                var scale = Math.min( 1, 2560 / Math.max( bmp.width, bmp.height ) );
                var w = Math.max( 1, Math.round( bmp.width * scale ) ), h = Math.max( 1, Math.round( bmp.height * scale ) );
                var canvas = document.createElement( 'canvas' );
                canvas.width = w;
                canvas.height = h;
                canvas.getContext( '2d' ).drawImage( bmp, 0, 0, w, h );
                if ( bmp.close ) bmp.close();
                return new Promise( function ( resolve ) { canvas.toBlob( resolve, 'image/webp', ( cfg.quality || 82 ) / 100 ); } ).then( function ( out ) {
                    canvas.width = canvas.height = 0;                              // free the memory at once
                    if ( ! out || out.type !== 'image/webp' ) return blob;        // this browser can't make WebP
                    if ( scale === 1 && out.size >= blob.size ) return blob;      // not lighter: keep the original
                    return out;
                } );
            } );
        } ).catch( function () { return blob; } );
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
                return lighten( blob );
            } ).then( function ( blob ) {
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

    // ── The Doc's header lines → the Fact Check fields ───────────────────────

    /** True when the article is still empty, so a pasted header can't be mistaken for part of an article. */
    function editorIsEmpty() {
        return wp.data.select( 'core/block-editor' ).getBlocks().every( function ( b ) {
            return b.name === 'core/paragraph' && plain( b.attributes.content ) === '';
        } );
    }

    /** Takes the header lines off the top of the paste (items) and returns what they said, or null. */
    function takeHeader( items ) {
        if ( ! window.fcDocHeader || ! editorIsEmpty() ) return null;
        var lines = [];
        for ( var i = 0; i < items.length && items[ i ].block && items[ i ].block.name === 'core/paragraph'; i++ ) {
            lines.push( plain( items[ i ].block.attributes.content ) );
        }
        var res = window.fcDocHeader.parse( lines );
        if ( ! res ) return null;
        items.splice( 0, res.count );
        // A picture right after the title, before any text: that is the featured image.
        if ( items[ 0 ] && items[ 0 ].marker && items[ 0 ].marker.places === 1 ) res.featuredIndex = items[ 0 ].marker.index;
        return res;
    }

    function norm( s ) {
        return String( s || '' ).toLowerCase().replace( /\s+/g, ' ' ).trim();
    }

    /** One list of the site's own tags, categories or users that match a name exactly. */
    function findByName( path, name ) {
        return wp.apiFetch( { path: path + ( path.indexOf( '?' ) === -1 ? '?' : '&' ) + 'search=' + encodeURIComponent( name ) + '&per_page=100&_fields=id,name,slug' } ).then( function ( list ) {
            var want = norm( name );
            return ( list || [] ).filter( function ( x ) { return norm( x.name ) === want || norm( x.slug ) === want; } );
        } );
    }

    // ── The report card: what was filled in and what needs the writer ────────

    var REPORT = null;   // the open report: { items, note, box }

    function hasText( v ) { return String( v || '' ).trim() !== ''; }

    /** Looks at the editor as it is right now. null = this item can't be re-checked. */
    function filledNow( key ) {
        var ed = wp.data.select( 'core/editor' ), meta = ed.getEditedPostAttribute( 'meta' ) || {};
        switch ( key ) {
            case 'rating': return hasText( meta.fc_rating );
            case 'claim':  return hasText( meta.fc_claim );
            case 'fact':   return hasText( meta.fc_fact );
            case 'title':  return hasText( ed.getEditedPostAttribute( 'title' ) );
            case 'slug':   return hasText( ed.getEditedPostAttribute( 'slug' ) );
            case 'tags':   return ( ed.getEditedPostAttribute( 'tags' ) || [] ).length > 0;
            case 'category':
                var c = ed.getEditedPostAttribute( 'categories' ) || [];
                return c.length > 0 && ! ( c.length === 1 && c[ 0 ] === 1 );
            case 'featured': return !! ed.getEditedPostAttribute( 'featured_media' );
        }
        return null;
    }

    function openSidebar() {
        try { wp.data.dispatch( 'core/edit-post' ).openGeneralSidebar( 'edit-post/document' ); } catch ( e ) {}
    }

    function el( tag, cls, text ) {
        var n = document.createElement( tag );
        if ( cls ) n.className = cls;
        if ( text !== undefined ) n.textContent = text;
        return n;
    }

    var REPORT_CSS =
        '.fcr{position:fixed;top:72px;left:16px;z-index:100001;width:min(380px,calc(100vw - 32px));max-height:calc(100vh - 96px);overflow:auto;box-sizing:border-box;background:#fff;color:#1e1e1e;border:3px solid #1e1e1e;border-radius:10px;box-shadow:0 8px 28px rgba(0,0,0,.35);font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}' +
        '.fcr *{box-sizing:border-box}' +
        '.fcr-top{display:flex;align-items:center;gap:8px;padding:12px 14px;background:#1e1e1e;color:#fff}' +
        '.fcr-top h2{flex:1;margin:0;padding:0;font-size:18px;line-height:1.3;color:#fff;font-weight:700}' +
        '.fcr button{font:inherit;cursor:pointer}' +
        '.fcr-x{min-width:44px;min-height:44px;border:2px solid #fff;border-radius:6px;background:transparent;color:#fff;font-size:15px;font-weight:600;padding:0 10px}' +
        '.fcr-x:hover,.fcr-x:focus{background:#fff;color:#1e1e1e}' +
        '.fcr-sum{margin:0;padding:12px 14px;font-size:19px;font-weight:700;line-height:1.35}' +
        '.fcr-sum.bad{background:#fde8e8;color:#7a1212;border-bottom:3px solid #b32d2e}' +
        '.fcr-sum.good{background:#e3f6e8;color:#0b4a22;border-bottom:3px solid #1a7f3c}' +
        '.fcr h3{margin:0;padding:10px 14px 4px;font-size:15px;letter-spacing:.04em;text-transform:uppercase;color:#1e1e1e}' +
        '.fcr ul{list-style:none;margin:0;padding:0 14px 10px}' +
        '.fcr li{display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-top:1px solid #c3c4c7}' +
        '.fcr li:first-child{border-top:0}' +
        '.fcr-ic{flex:none;width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:17px;color:#fff;margin-top:1px}' +
        '.fcr-ic.ok{background:#1a7f3c}.fcr-ic.missing{background:#b32d2e}.fcr-ic.check{background:#8a5a00}.fcr-ic.pending{background:#50575e}' +
        '.fcr-b{flex:1;min-width:0}' +
        '.fcr-l{font-weight:700;font-size:17px}' +
        '.fcr-s{display:inline-block;margin-left:8px;font-size:14px;font-weight:700;padding:1px 8px;border-radius:99px;border:2px solid currentColor}' +
        '.fcr-s.ok{color:#0b4a22}.fcr-s.missing{color:#7a1212}.fcr-s.check{color:#6b4500}.fcr-s.pending{color:#3c434a}' +
        '.fcr-d{margin:2px 0 0;color:#3c434a;overflow-wrap:anywhere}' +
        '.fcr-act{display:flex;flex-wrap:wrap;gap:8px;padding:10px 14px 14px;border-top:1px solid #c3c4c7}' +
        '.fcr-btn{min-height:44px;padding:0 16px;border:2px solid #1e1e1e;border-radius:6px;background:#fff;color:#1e1e1e;font-weight:700}' +
        '.fcr-btn.main{background:#1e1e1e;color:#fff}' +
        '.fcr-btn:hover,.fcr-btn:focus{outline:3px solid #2271b1;outline-offset:2px}' +
        '.fcr-note{margin:0;padding:0 14px 12px;color:#3c434a;font-size:15px}' +
        '.fcr-mini{position:fixed;top:72px;left:16px;z-index:100001}' +
        '.fcr-mini button{min-height:48px;padding:0 16px;border:3px solid #1e1e1e;border-radius:10px;background:#fff;color:#1e1e1e;font-weight:700;font-size:16px;box-shadow:0 4px 14px rgba(0,0,0,.3)}' +
        '.fcr-mini button.bad{background:#fde8e8;color:#7a1212;border-color:#b32d2e}' +
        '.fcr-mini button:focus,.fcr-mini button:hover{outline:3px solid #2271b1;outline-offset:2px}';

    var LABEL_OF = { ok: 'Filled', missing: 'Needs you', check: 'Please check', pending: 'Working…' };
    var ICON_OF  = { ok: '✔', missing: '✖', check: '!', pending: '…' };

    function closeReport() {
        if ( REPORT && REPORT.box && REPORT.box.parentNode ) REPORT.box.parentNode.removeChild( REPORT.box );
        REPORT = null;
    }

    /** Re-reads the editor: anything the writer has filled in since turns green. */
    function recheckReport() {
        if ( ! REPORT ) return;
        REPORT.items.forEach( function ( it ) {
            var now = filledNow( it.key );
            if ( now === null ) return;
            if ( now && it.state !== 'ok' ) { it.state = 'ok'; it.detail = 'Filled in.'; }
            else if ( ! now && it.state === 'ok' ) { it.state = 'missing'; it.detail = 'Empty now. Fill it in.'; }
        } );
        renderReport();
    }

    function renderReport() {
        if ( ! REPORT ) return;
        var old = REPORT.box;
        if ( ! document.getElementById( 'fcr-css' ) ) {
            var css = document.createElement( 'style' );
            css.id = 'fcr-css';
            css.textContent = REPORT_CSS;
            document.head.appendChild( css );
        }

        var need  = REPORT.items.filter( function ( i ) { return i.state === 'missing' || i.state === 'check'; } );
        var pend  = REPORT.items.filter( function ( i ) { return i.state === 'pending'; } );
        var done  = REPORT.items.filter( function ( i ) { return i.state === 'ok'; } );
        var bad   = need.length > 0;
        var sumText = bad ? ( need.length + ( need.length === 1 ? ' thing needs you' : ' things need you' ) ) : ( pend.length ? 'Almost done…' : 'Everything was found' );

        var box;
        if ( REPORT.mini ) {
            box = el( 'div', 'fcr-mini' );
            var open = el( 'button', bad ? 'bad' : '', 'Doc import: ' + sumText + ' — show' );
            open.type = 'button';
            open.addEventListener( 'click', function () { REPORT.mini = false; renderReport(); } );
            box.appendChild( open );
        } else {
            box = el( 'section', 'fcr' );
            box.setAttribute( 'role', 'region' );
            box.setAttribute( 'aria-label', 'Doc import report' );

            var top = el( 'div', 'fcr-top' );
            top.appendChild( el( 'h2', '', 'Doc imported' ) );
            var mini = el( 'button', 'fcr-x', 'Hide' );
            mini.type = 'button';
            mini.setAttribute( 'aria-label', 'Hide the report (keeps a small button)' );
            mini.addEventListener( 'click', function () { REPORT.mini = true; renderReport(); } );
            var x = el( 'button', 'fcr-x', 'Close' );
            x.type = 'button';
            x.setAttribute( 'aria-label', 'Close the report' );
            x.addEventListener( 'click', closeReport );
            top.appendChild( mini );
            top.appendChild( x );
            box.appendChild( top );

            var sum = el( 'p', 'fcr-sum ' + ( bad ? 'bad' : 'good' ), ( bad ? '✖ ' : '✔ ' ) + sumText );
            sum.setAttribute( 'role', 'status' );
            box.appendChild( sum );

            function section( title, list ) {
                if ( ! list.length ) return;
                box.appendChild( el( 'h3', '', title ) );
                var ul = el( 'ul' );
                list.forEach( function ( it ) {
                    var li = el( 'li' );
                    var ic = el( 'span', 'fcr-ic ' + it.state, ICON_OF[ it.state ] );
                    ic.setAttribute( 'aria-hidden', 'true' );
                    var b = el( 'div', 'fcr-b' );
                    var l = el( 'div', 'fcr-l', it.label );
                    l.appendChild( el( 'span', 'fcr-s ' + it.state, LABEL_OF[ it.state ] ) );
                    b.appendChild( l );
                    if ( it.detail ) b.appendChild( el( 'p', 'fcr-d', it.detail ) );
                    li.appendChild( ic );
                    li.appendChild( b );
                    ul.appendChild( li );
                } );
                box.appendChild( ul );
            }
            section( 'Please fill in or check', need );
            section( 'Working on it', pend );
            section( 'Filled in from the Doc', done );

            if ( REPORT.note ) box.appendChild( el( 'p', 'fcr-note', REPORT.note ) );

            var act = el( 'div', 'fcr-act' );
            var side = el( 'button', 'fcr-btn main', 'Open the settings sidebar' );
            side.type = 'button';
            side.addEventListener( 'click', openSidebar );
            var again = el( 'button', 'fcr-btn', 'Check again' );
            again.type = 'button';
            again.addEventListener( 'click', recheckReport );
            act.appendChild( side );
            act.appendChild( again );
            box.appendChild( act );
        }

        if ( old && old.parentNode ) old.parentNode.replaceChild( box, old ); else document.body.appendChild( box );
        REPORT.box = box;
    }

    function showReport( items, note ) {
        var mini = REPORT ? REPORT.mini : false;
        closeReport();
        REPORT = { items: items, note: note, mini: mini, box: null };
        renderReport();
    }

    /** Called when the pictures are in: the picture after the title may now be the featured image. */
    function finishFeatured( res, results ) {
        if ( ! REPORT || ! res || res.featuredIndex === undefined ) return;
        var item = REPORT.items.filter( function ( i ) { return i.key === 'featured'; } )[ 0 ];
        if ( ! item ) return;
        var r = results && results[ res.featuredIndex ];
        if ( r && ! r.failed && r.id && ! wp.data.select( 'core/editor' ).getEditedPostAttribute( 'featured_media' ) ) {
            wp.data.dispatch( 'core/editor' ).editPost( { featured_media: r.id } );
        }
        if ( filledNow( 'featured' ) ) { item.state = 'ok'; item.detail = 'The picture right after the title was used.'; }
        else { item.state = 'missing'; item.detail = 'The picture after the title could not be used. Set the featured image in the sidebar.'; }
        renderReport();
    }

    /**
     * Fills the Fact Check box and the post's title, slug, tags, category and author from the
     * header, then shows what it did and what it could not find. Never overwrites something
     * already filled in; never creates categories.
     */
    function applyHeader( res ) {
        var ed = wp.data.select( 'core/editor' ), dp = wp.data.dispatch( 'core/editor' );
        var f = res.fields, meta = ed.getEditedPostAttribute( 'meta' ) || {};
        var items = [], put = {}, post = {};

        function add( key, label, state, detail ) { items.push( { key: key, label: label, state: state, detail: detail || '' } ); }
        function show( v ) { v = String( v ); return v.length > 110 ? v.slice( 0, 107 ) + '…' : v; }

        function metaField( key, id, label, value, how, shown ) {
            if ( hasText( meta[ key ] ) ) { add( id, label, 'ok', 'Kept what you already had: ' + show( shown || meta[ key ] ) ); return; }
            if ( ! value ) { add( id, label, 'missing', 'Not found in the Doc. Fill it in the Fact Check box in the sidebar.' ); return; }
            put[ key ] = value;
            add( id, label, 'ok', ( how || '' ) + show( shown || value ) );
        }

        if ( f.rating && ! res.rating && ! hasText( meta.fc_rating ) ) {
            add( 'rating', 'Rating', 'missing', 'The Doc says “' + f.rating + '”, which is not one of our ratings. Choose the rating in the Fact Check box.' );
        } else {
            var shown = res.rating;
            ( ( window.fcEditor && window.fcEditor.ratings ) || [] ).forEach( function ( r ) { if ( r.value === res.rating ) shown = r.label; } );
            metaField( 'fc_rating', 'rating', 'Rating', res.rating, '', shown );
        }
        metaField( 'fc_claim', 'claim', 'Claim', f.claim );
        metaField( 'fc_fact', 'fact', res.factFromSynopsis ? 'Fact (taken from the Synopsis)' : 'Fact', f.fact );
        if ( Object.keys( put ).length && String( meta.fc_is_fact_check || '' ) !== '1' ) put.fc_is_fact_check = '1';
        if ( Object.keys( put ).length ) dp.editPost( { meta: put } );

        if ( hasText( ed.getEditedPostAttribute( 'title' ) ) ) add( 'title', 'Title', 'ok', 'Kept what you already had.' );
        else if ( ! f.title ) add( 'title', 'Title', 'missing', 'Not found in the Doc. Type it at the top of the editor.' );
        else { post.title = f.title; add( 'title', 'Title', 'ok', show( f.title ) ); }

        if ( hasText( ed.getEditedPostAttribute( 'slug' ) ) ) add( 'slug', 'Slug (web address)', 'ok', 'Kept what you already had.' );
        else if ( ! f.slug ) add( 'slug', 'Slug (web address)', 'missing', 'Not found in the Doc. WordPress will make one from the title, or set it in the sidebar.' );
        else { post.slug = f.slug; add( 'slug', 'Slug (web address)', 'ok', show( f.slug ) ); }
        if ( Object.keys( post ).length ) dp.editPost( post );

        // Featured image: the picture right after the title, when the Doc has one.
        if ( ed.getEditedPostAttribute( 'featured_media' ) ) add( 'featured', 'Featured image', 'ok', 'Kept what you already had.' );
        else if ( res.featuredIndex !== undefined ) add( 'featured', 'Featured image', 'pending', 'Using the picture right after the title. Uploading it…' );
        else add( 'featured', 'Featured image', 'missing', 'No picture right after the title. Set it in the sidebar under Featured image.' );

        var jobs = [];

        // Tags: the site's own tag if the name exists, otherwise a new one (only where allowed).
        var tagItem = null;
        if ( ! f.tags || ! f.tags.length ) add( 'tags', 'Tags', 'missing', 'Not found in the Doc. Add them in the sidebar under Tags.' );
        else {
            add( 'tags', 'Tags', 'pending', 'Adding…' );
            tagItem = items[ items.length - 1 ];
            jobs.push( ( function () {
                var names = f.tags.slice( 0, 15 ), failed = [];
                return Promise.all( names.map( function ( name ) {
                    return findByName( '/wp/v2/tags', name ).then( function ( hit ) {
                        if ( hit.length ) return hit[ 0 ].id;
                        return wp.apiFetch( { path: '/wp/v2/tags', method: 'POST', data: { name: name } } ).then( function ( t ) { return t.id; } );
                    } ).catch( function ( e ) {
                        // "term_exists" carries the id of the tag that is already there.
                        if ( e && e.code === 'term_exists' && e.data && e.data.term_id ) return e.data.term_id;
                        failed.push( name );
                        return 0;
                    } );
                } ) ).then( function ( ids ) {
                    ids = ids.filter( Boolean );
                    var all = ( ed.getEditedPostAttribute( 'tags' ) || [] ).slice();
                    ids.forEach( function ( id ) { if ( all.indexOf( id ) === -1 ) all.push( id ); } );
                    if ( ids.length ) dp.editPost( { tags: all } );
                    if ( ! ids.length ) { tagItem.state = 'missing'; tagItem.detail = 'The tags could not be added. Add them in the sidebar under Tags.'; }
                    else if ( failed.length ) { tagItem.state = 'check'; tagItem.detail = ids.length + ' added. These could not be added: ' + failed.join( ', ' ) + '.'; }
                    else { tagItem.state = 'ok'; tagItem.detail = ids.length + ' added: ' + show( f.tags.join( ', ' ) ); }
                } );
            } )() );
        }

        // Category: must already exist on this site.
        if ( ! f.category ) add( 'category', 'Category', 'missing', 'Not found in the Doc. Choose it in the sidebar under Categories.' );
        else {
            add( 'category', 'Category', 'pending', 'Looking…' );
            var catItem = items[ items.length - 1 ];
            jobs.push( findByName( '/wp/v2/categories', f.category ).then( function ( hit ) {
                if ( ! hit.length ) { catItem.state = 'missing'; catItem.detail = '“' + f.category + '” is not a category on this site. Choose one in the sidebar under Categories.'; return; }
                var have = ed.getEditedPostAttribute( 'categories' ) || [];
                if ( have.length && ! ( have.length === 1 && have[ 0 ] === 1 ) ) { catItem.state = 'ok'; catItem.detail = 'Kept what you already had.'; return; }
                dp.editPost( { categories: [ hit[ 0 ].id ] } );
                catItem.state = 'ok'; catItem.detail = f.category;
            } ).catch( function () { catItem.state = 'missing'; catItem.detail = 'Could not look it up. Choose it in the sidebar under Categories.'; } ) );
        }

        // Writer: only when exactly one user on this site has that name (and you may see the user list).
        if ( ! f.writer ) add( 'author', 'Author', 'check', 'No writer name in the Doc. The author stays as it is. Change it in the sidebar if needed.' );
        else {
            add( 'author', 'Author', 'pending', 'Looking…' );
            var authItem = items[ items.length - 1 ];
            jobs.push( wp.apiFetch( { path: '/wp/v2/users?context=edit&search=' + encodeURIComponent( f.writer ) + '&per_page=20&_fields=id,name' } ).then( function ( list ) {
                var want = norm( f.writer ), hit = ( list || [] ).filter( function ( u ) { return norm( u.name ) === want; } );
                if ( hit.length !== 1 ) { authItem.state = 'check'; authItem.detail = 'The Doc says “' + f.writer + '”, but no single user has that name. The author was not changed. Set it in the sidebar.'; return; }
                if ( hit[ 0 ].id !== ed.getEditedPostAttribute( 'author' ) ) dp.editPost( { author: hit[ 0 ].id } );
                authItem.state = 'ok'; authItem.detail = f.writer;
            } ).catch( function () { authItem.state = 'check'; authItem.detail = 'The Doc says “' + f.writer + '”. Set the author in the sidebar if it is not you.'; } ) );
        }

        // Order on screen: the things that matter most first.
        var order = [ 'rating', 'claim', 'fact', 'title', 'slug', 'featured', 'category', 'tags', 'author' ];
        items.sort( function ( a, b ) { return order.indexOf( a.key ) - order.indexOf( b.key ); } );

        var note = 'The Doc’s header lines were taken out of the article.';
        showReport( items, note );
        return Promise.all( jobs ).then( function () { renderReport(); } );
    }

    /** Works out what the paste should become, without touching the editor. null = leave it to WordPress. */
    function prepare( html, plainText ) {
        var pre    = preprocess( html );
        var blocks = wp.blocks.pasteHandler( { HTML: pre.html, plainText: plainText, mode: 'BLOCKS' } );
        var stats  = { tidied: 0, notes: 0, side: 0, duplicates: pre.stats.duplicates, tiny: pre.stats.tiny };
        var items  = tidy( blocks, stats );

        // A "side by side" note right above two or more pictures: they go in a row, the note goes.
        // (The same happens on save; doing it here shows it straight away.)
        for ( var i = 0; i < items.length; i++ ) {
            var it = items[ i ];
            if ( ! it.block || it.block.name !== 'core/paragraph' ) continue;
            var c = String( it.block.attributes.content );
            if ( ! saysSideBySide( c ) || ! isNoteLike( c ) ) continue;
            var run = [];
            for ( var j = i + 1; j < items.length && items[ j ].marker && items[ j ].marker.places === 1; j++ ) run.push( items[ j ] );
            if ( run.length < 2 ) continue;
            var g = 100000 + i;
            run.forEach( function ( r, k ) { r.marker.group = g; r.marker.slot = k; r.marker.places = run.length; } );
            items.splice( i, 1 );
            stats.side++;
            stats.tidied++;
            i--;
        }

        // The Doc's header lines (Claim, Rating, Title ...) become the Fact Check fields, and leave the article.
        var header = takeHeader( items );
        if ( header ) stats.tidied++;

        // A plain sentence or two with nothing to fix: WordPress handles those fine.
        if ( ! items.length && ! header ) return null;
        if ( ! header && ! pre.images.length && items.length === 1 && items[ 0 ].block && items[ 0 ].block.name === 'core/paragraph' && ! stats.tidied ) return null;

        // Safety: every picture place-holder must have become its own paragraph. If one ended
        // up anywhere else (inside a table, a list, a quote...), the picture would be lost, so
        // the whole paste is left to WordPress instead.
        var markers = items.filter( function ( it ) { return it.marker; } ).length;
        var leaked  = wp.blocks.serialize( items.filter( function ( it ) { return it.block; } ).map( function ( it ) { return it.block; } ) ).indexOf( '@@FCIMG' ) !== -1;
        if ( leaked || markers !== pre.images.length ) return null;

        return { pre: pre, items: items, stats: stats, header: header };
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
        if ( list.length ) insertAtCursor( list );
        if ( plan.header ) applyHeader( plan.header ).catch( function () { warn( 'The Doc\u2019s header lines could not be read into the fields. Please fill them in yourself.' ); } );

        var extra = ( stats.notes ? ' Removed ' + stats.notes + ' production note' + ( stats.notes > 1 ? 's' : '' ) + '.' : '' )
                  + ( stats.side ? ' Put pictures side by side as the note asked.' : '' );
        if ( ! pre.images.length ) {
            if ( ! plan.header ) notice( 'Cleaned the pasted text.' + extra );
            return;
        }

        notice( 'Pasted. Adding ' + pre.images.length + ' picture' + ( pre.images.length > 1 ? 's' : '' ) + ' to the Media Library\u2026 Saving waits until they are in.' );
        // Save and Publish wait until the pictures are in, so an article is never published with
        // "Adding picture..." lines in it. Never for long: each picture has a time limit, and the
        // lock is lifted after 3 minutes whatever happens.
        var ed = wp.data.dispatch( 'core/editor' ), lockName = 'fc-paste-' + Date.now();
        var locked = !! ( ed && ed.lockPostSaving );
        if ( locked ) ed.lockPostSaving( lockName );
        var unlock = function () { if ( locked ) { locked = false; ed.unlockPostSaving( lockName ); } };
        setTimeout( unlock, 180000 );

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
            finishFeatured( plan.header, results );
            notice( ( parts.length ? 'Pictures added. ' + parts.join( '; ' ) + '.' : 'Pictures added.' ) + extra );
            problems.forEach( warn );
        } ).catch( function () {
            finishFeatured( plan.header, [] );
            warn( 'The pictures could not be added. Look for the \u23f3 lines in the article and add the pictures from the Media Library.' );
        } ).then( function () {
            unlock();
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
