/**
 * Reads the "header" lines at the top of a pasted fact-check Doc:
 *
 *     Claim: ...            Rating – False        Tags: a, b, c
 *     Title: ...            URL Slug: ...         Writer: ...
 *
 * Labels differ from site to site (English, Hindi, Marathi, Bengali, Tamil,
 * Malayalam ...), several can share one line, and a separator can be ":" or
 * " – ". Rules only, no AI. Used by paste-cleaner.js, which fills the Fact
 * Check box from the result and removes the header lines it understood.
 *
 * parse( lines ) takes the plain text of the first paragraphs. It returns null
 * unless the very first line is a header line and at least two different
 * things were found. Otherwise:
 *   { count, fields: { claim, fact, rating, title, slug, tags, category, writer, synopsis },
 *     rating, factFromSynopsis }
 * count = how many leading lines were header lines (to be removed); rating =
 * one of our rating names or '' when the words aren't recognised.
 *
 * A line that is not a header line ends the header, so the article itself is
 * never touched. Plain JavaScript, no build step.
 */
( function ( root ) {
    'use strict';

    var LABELS = ( typeof module !== 'undefined' && module.exports ) ? require( './doc-header-labels.js' ) : root.fcDocLabels;

    var SEP    = '(?:\\s*[:\\uFF1A]|\\s+[\\u2013\\u2014-])\\s*';
    var fields = LABELS.fields.map( function ( l ) { return l[ 0 ]; } );
    var FIND   = new RegExp( '(^|\\s)(?:' + LABELS.fields.map( function ( l ) { return '(' + l[ 1 ] + ')'; } ).join( '|' ) + ')' + SEP, 'gi' );

    var RULE = /^[\s_\-=*\u2013\u2014]{3,}$/;

    function rating( text ) {
        for ( var i = 0; i < LABELS.ratings.length; i++ ) if ( LABELS.ratings[ i ][ 1 ].test( text ) ) return LABELS.ratings[ i ][ 0 ];
        return '';
    }

    function clean( s ) {
        return String( s ).replace( /[\s ​]+/g, ' ' ).replace( /^[\s*_]+|[\s*_]+$/g, '' ).trim();
    }

    /** The labelled pieces of one line, or null when the line doesn't start with a label. */
    function pieces( line ) {
        var text = String( line ).replace( / /g, ' ' ).replace( /^[\s*•●-]+/, '' );
        var found = [], m;
        FIND.lastIndex = 0;
        while ( ( m = FIND.exec( text ) ) ) {
            var field = '';
            for ( var g = 2; g < m.length; g++ ) if ( m[ g ] !== undefined ) { field = fields[ g - 2 ]; break; }
            var start = m.index + m[ 1 ].length;
            if ( start === 0 || LABELS.midLine[ field ] ) found.push( { field: field, start: start, end: FIND.lastIndex } );
            if ( m[ 0 ] === '' ) FIND.lastIndex++;
        }
        if ( ! found.length || found[ 0 ].start !== 0 ) return null;
        return found.map( function ( f, i ) {
            return { field: f.field, value: clean( text.slice( f.end, i + 1 < found.length ? found[ i + 1 ].start : text.length ) ) };
        } );
    }

    function split( list ) {
        return String( list ).split( /[,،;\n|]+/ ).map( clean ).filter( Boolean );
    }

    function parse( lines ) {
        var out = {}, count = 0, started = false;
        for ( var i = 0; i < lines.length; i++ ) {
            var line = String( lines[ i ] || '' );
            if ( clean( line ) === '' || RULE.test( line ) ) { if ( started ) count = i + 1; continue; }
            var ps = pieces( line );
            if ( ! ps ) break;
            started = true;
            count = i + 1;

            // "Title:" with nothing after it: the value is the next line that isn't itself a label.
            var last = ps[ ps.length - 1 ];
            if ( last.value === '' && last.field !== 'ignore' && last.field !== 'synopsis' ) {
                for ( var j = i + 1; j < lines.length; j++ ) {
                    var nxt = String( lines[ j ] || '' );
                    if ( clean( nxt ) === '' || RULE.test( nxt ) ) continue;
                    if ( ! pieces( nxt ) ) { last.value = clean( nxt ); count = j + 1; i = j; }
                    break;
                }
            }
            ps.forEach( function ( p ) { if ( p.field !== 'ignore' && p.value !== '' && ! out[ p.field ] ) out[ p.field ] = p.value; } );
        }
        var found = Object.keys( out );
        if ( ! started || found.length < 2 ) return null;

        var result = { count: count, fields: out, rating: '', factFromSynopsis: false };
        if ( out.rating ) result.rating = rating( out.rating );
        if ( ! out.fact && out.synopsis ) { out.fact = out.synopsis; result.factFromSynopsis = true; }
        if ( out.tags ) out.tags = split( out.tags );
        return result;
    }

    var api = { parse: parse };
    if ( typeof module !== 'undefined' && module.exports ) module.exports = api;
    root.fcDocHeader = api;
} )( typeof window !== 'undefined' ? window : this );
