/**
 * Cleans what is pasted from Word, Google Docs or LibreOffice into the block editor:
 *   - each picture becomes its own block, copied into the Media Library (a picture that
 *     can't be read is reported, never silently lost); a repeated picture is kept once;
 *   - a table of pictures becomes Columns (a table with words stays a table);
 *   - the Doc's header lines (Claim, Rating, Title …) go into the post (doc-header.js
 *     reads them, doc-import.js fills and reports) and leave the article;
 *   - production notes, horizontal lines, empty lines and stray spaces are removed.
 * Other pastes, including text copied inside the editor, are left to WordPress.
 * Zero-width joiners (U+200C, U+200D) are never removed: Indian scripts need them.
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

    /**
     * Writers sometimes put a production note inside the table, under a picture. A line
     * that is only a production note is taken out, so the table is left holding pictures
     * alone and can become Columns. Nothing else in a table is touched.
     */
    function dropNotesInTable( table, out ) {
        var leaves = Array.prototype.slice.call( table.querySelectorAll( 'p,div,li,td,th' ) ).filter( function ( b ) { return ! b.querySelector( 'p,div,li,table,td,th' ); } );
        leaves.forEach( function ( leaf ) {
            // Lines inside the block are separated by <br>; a line with a picture is never looked at.
            var line = [], lines = [ line ];
            Array.prototype.forEach.call( leaf.childNodes, function ( n ) {
                if ( n.nodeType === 1 && n.tagName === 'BR' ) { line = []; lines.push( line ); line.br = n; } else line.push( n );
            } );
            lines.forEach( function ( l ) {
                if ( ! l.length || l.some( function ( n ) { return n.nodeType === 1 && ( n.tagName === 'IMG' || n.querySelector( 'img' ) ); } ) ) return;
                var html = l.map( function ( n ) { return n.nodeType === 1 ? n.outerHTML : ( n.nodeValue || '' ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ); } ).join( '' );
                if ( plain( html ) === '' || stripProductionNote( html ) !== '' ) return;
                l.forEach( function ( n ) { if ( n.parentNode ) n.parentNode.removeChild( n ); } );
                if ( l.br && l.br.parentNode ) l.br.parentNode.removeChild( l.br );
                out.stats.notes = ( out.stats.notes || 0 ) + 1;
            } );
        } );
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
            dropNotesInTable( table, out );
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

    /**
     * Puts back a "?" that went missing in pasted embed code ("video.php" then a line break,
     * a space or nothing, then "height=476&href=..."), so the code reads right in the editor.
     * Only these three known addresses, only when the "?" is really missing. Same rule as on save.
     */
    function mendEmbedCode( html ) {
        var gap = '(?:\\s|&nbsp;|<br\\b[^>]*>|[\\u0000-\\u001f\\u0085\\u2028\\u2029\\uFFFD\\uFF1F])*';
        return String( html || '' )
            .replace( new RegExp( '(facebook\\.com/plugins/(?:video|post)\\.php)' + gap + '(?=(?:height|href|width|show_text|t|appId)=)', 'gi' ), '$1?' )
            .replace( new RegExp( '(awesomescreenshot\\.com/embed)' + gap + '(?=(?:id|shareKey)=)', 'gi' ), '$1?' )
            .replace( new RegExp( '(google\\.com/maps/embed)' + gap + '(?=pb=)', 'gi' ), '$1?' );
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

    var RULE_LINE = /^[_\-=*\u2013\u2014\s]{3,}$/;

    function tidy( blocks, stats ) {
        var out = [];
        blocks.forEach( function ( b ) {
            if ( b.name === 'core/paragraph' ) {
                var content = mendEmbedCode( cleanInline( b.attributes.content ) );
                var m = MARK.exec( plain( content ) );
                if ( m ) { out.push( { marker: { index: +m[ 1 ], group: +m[ 2 ], slot: +m[ 3 ], places: +m[ 4 ] } } ); return; }
                var lines = splitLines( content );
                if ( lines.length !== 1 || lines[ 0 ] !== b.attributes.content ) stats.tidied++;
                lines.forEach( function ( line ) {
                    if ( RULE_LINE.test( plain( line ) ) ) { stats.tidied++; return; }   // a typed line of ____ or ----
                    var pn = stripProductionNote( line );
                    if ( pn === '' ) {
                        stats.notes++; stats.tidied++;
                        if ( saysSideBySide( line ) ) out.push( { sideNote: true } );   // "place the screenshots side by side": acted on below, then dropped
                        return;
                    }
                    if ( pn !== null ) { line = pn; stats.notes++; stats.tidied++; }
                    out.push( { block: wp.blocks.createBlock( 'core/paragraph', Object.assign( {}, b.attributes, { content: line } ) ) } );
                } );
            } else if ( b.name === 'core/separator' ) {
                stats.tidied++;                                    // horizontal lines never belong in our articles
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
     * Shrinks a picture before upload: at most 2560 px (WordPress's own limit), WebP at the
     * Settings quality. GIF, animated PNG, or a result that isn't smaller: the original goes.
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
        if ( ! window.fcDocHeader || ! window.fcDocImport || ! editorIsEmpty() ) return null;

        // Bare links above the header (source posts noted by the writer) stay where they are.
        var lead = 0;
        while ( items[ lead ] && items[ lead ].block && ( items[ lead ].block.name === 'core/embed'
                || ( items[ lead ].block.name === 'core/paragraph' && /^https?:\/\/\S+$/i.test( plain( items[ lead ].block.attributes.content ) ) ) ) ) lead++;

        var lines = [];
        for ( var i = lead; i < items.length && items[ i ].block; i++ ) {
            var nm = items[ i ].block.name;
            if ( nm === 'core/paragraph' || nm === 'core/heading' ) lines.push( plain( items[ i ].block.attributes.content ) );
            else break;
        }
        var res = window.fcDocHeader.parse( lines );
        if ( ! res ) return null;
        var before = items.splice( 0, lead );
        items.splice( 0, res.count );

        // No "Title" label: the line right above the first picture is the title.
        if ( ! res.fields.title && items[ 1 ] && items[ 1 ].marker && items[ 1 ].marker.places === 1 && items[ 0 ].block
             && ( items[ 0 ].block.name === 'core/paragraph' || items[ 0 ].block.name === 'core/heading' ) ) {
            var t = plain( items[ 0 ].block.attributes.content );
            if ( t !== '' && t.length <= 250 ) {
                res.fields.title = t;
                res.titleGuessed = true;
                items.splice( 0, 1 );
            }
        }

        // The article often opens with the title again, word for word: the title field has it now.
        if ( res.fields.title && items[ 0 ] && items[ 0 ].block && ( items[ 0 ].block.name === 'core/paragraph' || items[ 0 ].block.name === 'core/heading' )
             && plain( items[ 0 ].block.attributes.content ).toLowerCase() === plain( res.fields.title ).toLowerCase() ) {
            items.splice( 0, 1 );
        }

        // A picture right after the title, before any text: that is the featured image.
        if ( items[ 0 ] && items[ 0 ].marker && items[ 0 ].marker.places === 1 ) res.featuredIndex = items[ 0 ].marker.index;
        items.unshift.apply( items, before );
        return res;
    }

    /** Works out what the paste should become, without touching the editor. null = leave it to WordPress. */
    function prepare( html, plainText ) {
        var pre    = preprocess( html );
        var blocks = wp.blocks.pasteHandler( { HTML: pre.html, plainText: plainText, mode: 'BLOCKS' } );
        var stats  = { tidied: 0, notes: pre.stats.notes || 0, side: 0, duplicates: pre.stats.duplicates, tiny: pre.stats.tiny };
        if ( stats.notes ) stats.tidied++;
        var items  = tidy( blocks, stats );

        // A "side by side" note next to two or more pictures: they go in a row, the note goes.
        // A note written as a plain line works from above; a production note ("place the
        // screenshots side by side") works from below or above. (The same happens on save.)
        function singles( from, step ) {
            var run = [];
            for ( var j = from; j >= 0 && j < items.length && items[ j ].marker && items[ j ].marker.places === 1; j += step ) run.push( items[ j ] );
            return step < 0 ? run.reverse() : run;
        }
        for ( var i = 0; i < items.length; i++ ) {
            var it = items[ i ], run = [];
            if ( it.sideNote ) {
                run = singles( i - 1, -1 );
                if ( run.length < 2 ) run = singles( i + 1, 1 );
            } else {
                if ( ! it.block || it.block.name !== 'core/paragraph' ) continue;
                var c = String( it.block.attributes.content );
                if ( ! saysSideBySide( c ) || ! isNoteLike( c ) ) continue;
                run = singles( i + 1, 1 );
                if ( run.length < 2 ) continue;
            }
            if ( run.length >= 2 ) {
                var g = 100000 + i;
                run.forEach( function ( r, k ) { r.marker.group = g; r.marker.slot = k; r.marker.places = run.length; } );
                stats.side++;
                stats.tidied++;
            }
            if ( it.sideNote || run.length >= 2 ) { items.splice( i, 1 ); i--; }
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

        // No picture after the title but a "Feature Image: https://…" line: that picture is fetched
        // like a pasted one (it has no place in the article) and becomes the featured image.
        if ( header && header.featuredIndex === undefined && /^https?:\/\/\S+$/i.test( header.fields.image || '' ) ) {
            header.featuredIndex = pre.images.length;
            header.featuredFromLink = true;
            pre.images.push( { src: header.fields.image, alt: '', group: -1 } );
        }

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
        if ( plan.header ) window.fcDocImport.fill( plan.header ).catch( function () { warn( 'The Doc\u2019s header lines could not be read into the fields. Please fill them in yourself.' ); } );

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

            // The picture right after the title becomes the featured image and leaves the article.
            // Only when it really is in the Media Library and no featured image was set already;
            // otherwise it stays where it is.
            var dropIndex = null;
            if ( plan.header && plan.header.featuredIndex !== undefined ) {
                var fr = results[ plan.header.featuredIndex ];
                if ( fr && ! fr.failed && fr.id && fr.how !== 'linked' && ! wp.data.select( 'core/editor' ).getEditedPostAttribute( 'featured_media' ) ) {
                    ed.editPost( { featured_media: fr.id } );
                    dropIndex = plan.header.featuredIndex;
                }
            }

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
                        if ( dropIndex !== null && holders[ id ].index === dropIndex ) { dp.removeBlocks( id, false ); return; }
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
            window.fcDocImport.finishFeatured( plan.header, results );
            notice( ( parts.length ? 'Pictures added. ' + parts.join( '; ' ) + '.' : 'Pictures added.' ) + extra );
            problems.forEach( warn );
        } ).catch( function () {
            window.fcDocImport.finishFeatured( plan.header, [] );
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
