/**
 * Download and Share buttons under the fact card.
 *
 * The picture is made in the visitor's own browser, and only when a button
 * is pressed, so pages stay light. The picture tool (modern-screenshot,
 * shipped with the plugin) loads on the first press.
 */
( function () {
    var cfg = window.fcShare || {};
    var lib = null;

    function loadLib() {
        if ( window.modernScreenshot ) return Promise.resolve( window.modernScreenshot );
        if ( ! lib ) {
            lib = new Promise( function ( ok, fail ) {
                var s = document.createElement( 'script' );
                s.src = cfg.lib;
                s.onload = function () { ok( window.modernScreenshot ); };
                s.onerror = function () { lib = null; fail( new Error( 'load' ) ); };
                document.head.appendChild( s );
            } );
        }
        return lib;
    }

    /** The card as an image file. Own pictures (e.g. Canva) are used as they are. */
    function makeFile( box ) {
        if ( box.fcFile ) return Promise.resolve( box.fcFile );

        var own  = box.querySelector( '[data-fc-own]' );
        var name = box.querySelector( '.fc-share' ).getAttribute( 'data-file' ) || 'fact-check';
        var blob = own
            ? fetch( own.getAttribute( 'data-full' ) || own.currentSrc || own.src ).then( function ( r ) { return r.blob(); } )
            : loadLib().then( function ( ms ) {
                return ms.domToBlob( box.querySelector( '[data-fc-card]' ), { scale: 2, type: 'image/png' } );
            } );

        return blob.then( function ( b ) {
            var ext = b.type === 'image/jpeg' ? '.jpg' : b.type === 'image/webp' ? '.webp' : '.png';
            box.fcFile = new File( [ b ], name + ext, { type: b.type || 'image/png' } );
            return box.fcFile;
        } );
    }

    function download( file ) {
        var url = URL.createObjectURL( file );
        var a   = document.createElement( 'a' );
        a.href = url;
        a.download = file.name;
        document.body.appendChild( a );
        a.click();
        a.remove();
        setTimeout( function () { URL.revokeObjectURL( url ); }, 4000 );
    }

    function share( file, bar ) {
        var title = bar.getAttribute( 'data-title' );
        var url   = bar.getAttribute( 'data-url' );

        if ( navigator.canShare && navigator.canShare( { files: [ file ] } ) ) {
            return navigator.share( { files: [ file ], title: title, text: title + '\n' + url } );
        }
        if ( navigator.share ) return navigator.share( { title: title, url: url } );
        if ( navigator.clipboard ) return navigator.clipboard.writeText( url ).then( function () { return 'copied'; } );
        return Promise.reject( new Error( 'none' ) );
    }

    /** Shows a short message on the button, then puts its label back. */
    function say( btn, text, ms ) {
        var label = btn.querySelector( 'span' );
        if ( ! btn.fcLabel ) btn.fcLabel = label.textContent;
        label.textContent = text;
        if ( ms ) setTimeout( function () { label.textContent = btn.fcLabel; btn.disabled = false; }, ms );
    }

    document.addEventListener( 'click', function ( e ) {
        var btn = e.target.closest && e.target.closest( '.fc-share-btn' );
        if ( ! btn || btn.disabled ) return;

        var bar   = btn.closest( '.fc-share' );
        var box   = btn.closest( '.fc-card-block' );
        var act   = btn.getAttribute( 'data-act' );
        var ready = !! box.fcFile;

        btn.disabled = true;
        if ( ! ready ) say( btn, cfg.wait || 'Preparing…' );

        makeFile( box ).then( function ( file ) {
            if ( act === 'download' ) {
                download( file );
                say( btn, cfg.done || 'Saved', 1500 );
                return;
            }
            return share( file, bar ).then( function ( r ) {
                say( btn, r === 'copied' ? ( cfg.copied || 'Link copied' ) : btn.fcLabel || '', 1500 );
            }, function ( err ) {
                if ( err && err.name === 'AbortError' ) { say( btn, btn.fcLabel, 1 ); return; }
                // Some phones only share straight after a tap. The picture is
                // ready now, so a second tap works.
                if ( err && err.name === 'NotAllowedError' && ! ready ) { say( btn, cfg.again || 'Tap again to share' ); btn.disabled = false; return; }
                say( btn, cfg.fail || 'Could not share', 2000 );
            } );
        } ).catch( function () {
            say( btn, cfg.fail || 'Could not make the picture', 2500 );
        } );
    } );

    // Buttons stay hidden until this script runs, so they never sit there dead.
    document.querySelectorAll( '.fc-share[hidden]' ).forEach( function ( bar ) { bar.hidden = false; } );
} )();
