/**
 * Doc import: puts what doc-header.js read from a pasted Doc into the post, and shows
 * the writer a report of what was filled in and what still needs them.
 *
 *   fcDocImport.fill( header )               fills the Fact Check box, title, slug, tags,
 *                                            categories and author, then shows the report
 *   fcDocImport.finishFeatured( header, r )  once the pasted pictures are uploaded, makes
 *                                            the picture after the title the featured image
 *
 * Never overwrites something already filled in and never creates categories. The report
 * is a plain card (no framework): large, high-contrast text and a status word on every line.
 * Plain JavaScript, no build step.
 */
( function ( wp ) {
    'use strict';
    if ( ! wp || ! wp.data || ! wp.apiFetch ) return;

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
    function show( v ) { v = String( v ); return v.length > 110 ? v.slice( 0, 107 ) + '…' : v; }

    /** Looks at the editor as it is right now. null = this item can't be re-checked. */
    function filledNow( key ) {
        var ed = wp.data.select( 'core/editor' ), meta = ed.getEditedPostAttribute( 'meta' ) || {};
        switch ( key ) {
            case 'rating': return hasText( meta.fc_rating );
            case 'claim':  return hasText( meta.fc_claim );
            case 'fact':   return hasText( meta.fc_fact );
            case 'title':  return hasText( ed.getEditedPostAttribute( 'title' ) );
            case 'slug':   return hasText( ed.getEditedPostAttribute( 'slug' ) );
            case 'excerpt': return hasText( ed.getEditedPostAttribute( 'excerpt' ) );
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
        '.fcr{position:fixed;top:72px;left:16px;z-index:100001;width:min(380px,calc(100vw - 32px));max-height:calc(100vh - 96px);overflow:auto;box-sizing:border-box;background:#fff;color:#111;border:1px solid #dcdcde;border-radius:8px;box-shadow:0 2px 14px rgba(0,0,0,.12);font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}' +
        '.fcr *{box-sizing:border-box}' +
        '.fcr-top{display:flex;align-items:center;gap:8px;padding:12px 14px;background:#fff;border-bottom:1px solid #dcdcde;color:#111}' +
        '.fcr-top h2{flex:1;margin:0;padding:0;font-size:18px;line-height:1.3;color:#111;font-weight:700}' +
        '.fcr button{font:inherit;cursor:pointer}' +
        '.fcr-x{min-width:44px;min-height:40px;border:1px solid #8c8f94;border-radius:6px;background:#fff;color:#111;font-size:15px;font-weight:600;padding:0 12px}' +
        '.fcr-x:hover,.fcr-x:focus{background:#f0f0f1}' +
        '.fcr-sum{margin:0;padding:12px 14px 6px;font-size:18px;font-weight:700;line-height:1.35;color:#111;background:#fff}' +
        '.fcr-sum b{margin-right:6px}.fcr-sum.bad b{color:#b32d2e}.fcr-sum.good b{color:#1a7f3c}' +
        '.fcr h3{margin:0;padding:12px 14px 2px;font-size:13px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#50575e}' +
        '.fcr ul{list-style:none;margin:0;padding:0 14px 8px}' +
        '.fcr li{display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-top:1px solid #ececec}' +
        '.fcr li:first-child{border-top:0}' +
        '.fcr-ic{flex:none;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;color:#fff;margin-top:1px}' +
        '.fcr-ic.ok{background:#1a7f3c}.fcr-ic.missing{background:#b32d2e}.fcr-ic.check{background:#8a5a00}.fcr-ic.pending{background:#6c7075}' +
        '.fcr-b{flex:1;min-width:0}' +
        '.fcr-l{font-weight:700;font-size:16px;color:#111}' +
        '.fcr-s{display:inline-block;margin-left:8px;font-size:13px;font-weight:700;padding:0 8px;border-radius:99px;border:1.5px solid currentColor;background:#fff}' +
        '.fcr-s.ok{color:#146c32}.fcr-s.missing{color:#a02020}.fcr-s.check{color:#7a4f00}.fcr-s.pending{color:#50575e}' +
        '.fcr-d{margin:2px 0 0;color:#111;overflow-wrap:anywhere}' +
        '.fcr-act{display:flex;flex-wrap:wrap;gap:8px;padding:12px 14px 14px;border-top:1px solid #dcdcde}' +
        '.fcr-btn{min-height:42px;padding:0 14px;border:1px solid #8c8f94;border-radius:6px;background:#fff;color:#111;font-weight:600}' +
        '.fcr-btn:hover{background:#f0f0f1}' +
        '.fcr-btn:focus,.fcr-x:focus,.fcr-mini button:focus{outline:2px solid #2271b1;outline-offset:2px}' +
        '.fcr-note{margin:0;padding:4px 14px 12px;color:#111;font-size:14px}' +
        '.fcr-mini{position:fixed;top:72px;left:16px;z-index:100001}' +
        '.fcr-mini button{min-height:44px;padding:0 16px;border:1px solid #8c8f94;border-radius:8px;background:#fff;color:#111;font-weight:600;font-size:15px;box-shadow:0 2px 10px rgba(0,0,0,.12)}' +
        '.fcr-mini button:hover{background:#f0f0f1}';

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
            if ( now === null || it.state === 'check' ) return;
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
            var open = el( 'button', '', 'Doc import: ' + sumText + ' — show' );
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

            var sum = el( 'p', 'fcr-sum ' + ( bad ? 'bad' : 'good' ) );
            sum.appendChild( el( 'b', '', bad ? '\u2716' : '\u2714' ) );
            sum.appendChild( document.createTextNode( sumText ) );
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
            var side = el( 'button', 'fcr-btn', 'Open the settings sidebar' );
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
        if ( filledNow( 'featured' ) ) {
            item.state = 'ok';
            item.detail = res.featuredFromLink ? 'The Doc’s Feature Image link was used.' : 'The picture right after the title was used, and moved out of the article.';
        } else {
            item.state = 'missing';
            item.detail = res.featuredFromLink ? 'The Feature Image link could not be loaded: ' + show( res.fields.image ) + ' — set it in the sidebar.'
                                               : 'The picture after the title could not be used. Set the featured image in the sidebar.';
        }
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
        else {
            post.title = f.title;
            if ( res.titleGuessed ) add( 'title', 'Title', 'check', 'No \u201cTitle\u201d label, so the line above the picture was used: ' + show( f.title ) + ' \u2014 please check it.' );
            else add( 'title', 'Title', 'ok', show( f.title ) );
        }

        if ( hasText( ed.getEditedPostAttribute( 'slug' ) ) ) add( 'slug', 'Slug (web address)', 'ok', 'Kept what you already had.' );
        else if ( ! f.slug ) add( 'slug', 'Slug (web address)', 'missing', 'Not found in the Doc. WordPress will make one from the title, or set it in the sidebar.' );
        else { post.slug = f.slug; add( 'slug', 'Slug (web address)', 'ok', show( f.slug ) ); }

        // A "Byline" / "Subtitle" line: only when the Doc has one, into the Excerpt box.
        if ( f.excerpt ) {
            if ( hasText( ed.getEditedPostAttribute( 'excerpt' ) ) ) add( 'excerpt', 'Excerpt (Byline)', 'ok', 'Kept what you already had.' );
            else { post.excerpt = f.excerpt; add( 'excerpt', 'Excerpt (Byline)', 'ok', show( f.excerpt ) ); }
        }
        if ( Object.keys( post ).length ) dp.editPost( post );

        // Featured image: the picture right after the title, or the Doc's "Feature Image" link.
        if ( ed.getEditedPostAttribute( 'featured_media' ) ) add( 'featured', 'Featured image', 'ok', 'Kept what you already had.' );
        else if ( res.featuredFromLink ) add( 'featured', 'Featured image', 'pending', 'Using the Doc’s Feature Image link. Uploading it…' );
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

        // Categories: each one must already exist on this site. A Doc may list several ("India, Political", "National/Social").
        var names = f.category ? f.category.split( /[,\u060C;|\/]+/ ).map( function ( n ) { return n.trim(); } ).filter( Boolean ) : [];
        if ( ! names.length ) add( 'category', 'Category', 'missing', 'Not found in the Doc. Choose it in the sidebar under Categories.' );
        else {
            add( 'category', 'Category', 'pending', 'Looking…' );
            var catItem = items[ items.length - 1 ];
            jobs.push( Promise.all( names.map( function ( n ) { return findByName( '/wp/v2/categories', n ); } ) ).then( function ( hits ) {
                var ids = [], unknown = [];
                hits.forEach( function ( hit, i ) { if ( hit.length ) ids.push( hit[ 0 ].id ); else unknown.push( names[ i ] ); } );
                if ( ! ids.length ) { catItem.state = 'missing'; catItem.detail = '“' + names.join( ', ' ) + '” is not a category on this site. Choose one in the sidebar under Categories.'; return; }
                var have = ed.getEditedPostAttribute( 'categories' ) || [];
                if ( have.length && ! ( have.length === 1 && have[ 0 ] === 1 ) ) { catItem.state = 'ok'; catItem.detail = 'Kept what you already had.'; return; }
                dp.editPost( { categories: ids } );
                catItem.state = unknown.length ? 'check' : 'ok';
                catItem.detail = unknown.length ? 'Not categories on this site: ' + unknown.join( ', ' ) + '. Added the others.' : names.join( ', ' );
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
        var order = [ 'rating', 'claim', 'fact', 'title', 'slug', 'excerpt', 'featured', 'category', 'tags', 'author' ];
        items.sort( function ( a, b ) { return order.indexOf( a.key ) - order.indexOf( b.key ); } );

        var note = 'The Doc’s header lines were taken out of the article.';
        showReport( items, note );
        return Promise.all( jobs ).then( function () { renderReport(); } );
    }

    window.fcDocImport = { fill: applyHeader, finishFeatured: finishFeatured };
} )( window.wp );
