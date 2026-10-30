// Checks for assets/doc-header.js (the Doc header reader). Run: node tests/doc-header.test.js
const h = require( '../assets/doc-header.js' );
let failed = 0;
function is( name, got, want ) {
    const ok = JSON.stringify( got ) === JSON.stringify( want );
    if ( ! ok ) { failed++; console.log( 'FAIL ' + name + '\n  got:  ' + JSON.stringify( got ) + '\n  want: ' + JSON.stringify( want ) ); }
}

const english = h.parse( [ 'Author Name / Fact Check By: Usha Manoj Category: International', 'Fact Check Rating: Misleading', 'Tags: France, protest, looting',
    'Claim: Protesters looted stores.', 'Fact: This video is from Madrid.', 'URL Slug: This video does not show goods.', '_______________', 'Title: This video does not show goods.', 'Body line' ] );
is( 'english count', english.count, 8 );
is( 'english writer + category on one line', [ english.fields.writer, english.fields.category ], [ 'Usha Manoj', 'International' ] );
is( 'english rating', english.rating, 'misleading' );
is( 'english tags', english.fields.tags, [ 'France', 'protest', 'looting' ] );

const tamil = h.parse( [ 'Synopsis: Viral videos...', 'Claim:  crowd buying food Writer – S.G.Prabu', 'Rating – False', 'Category: x', 'Title – y', 'body' ] );
is( 'tamil claim stops at the writer label', tamil.fields.claim, 'crowd buying food' );
is( 'tamil writer', tamil.fields.writer, 'S.G.Prabu' );
is( 'no Fact line: Synopsis is used', [ tamil.fields.fact, tamil.factFromSynopsis ], [ 'Viral videos...', true ] );

const mal = h.parse( [ 'Synopsis:', 'Claim: a', 'Fact: b', 'Evidence: Disclaimer', 'Result: False', 'Tags: a, b URL Slug: s', 'Title: t', 'പ്രചരണം(Claim): c', 'https://docs.google.com' ] );
is( 'malayalam: empty Synopsis, Result = rating', [ mal.rating, mal.fields.fact, mal.fields.slug ], [ 'false', 'b', 's' ] );
is( 'malayalam: stops at the first non-header line', mal.count, 8 );

is( 'sinhala labels in capitals', h.parse( [ 'SYNOPSIS: s', 'WRITER : w', 'RATING : False', 'Tags / Meta Data : a, b', 'SLUG : x', 'Title : t', 'body' ] ).count, 6 );

is( 'ratings', [ 'Partly False', 'Partially false', 'Missing Context', 'Satire', 'Altered', 'False', 'True' ].map( r => h.parse( [ 'Claim: c', 'Rating: ' + r ] ).rating ),
    [ 'partly-false', 'partly-false', 'missing-context', 'satire', 'altered', 'false', '' ] );

is( 'an article starting with text is never a header', h.parse( [ 'Hello, this is an article', 'Claim: x', 'Fact: y' ] ), null );
is( 'one label alone is not enough', h.parse( [ 'Claim: only this', 'Then the article' ] ), null );
is( 'a sentence that starts with a label word is not a label', h.parse( [ 'Production notes are kept by the team.', 'Claim: x', 'Fact: y' ] ), null );
is( 'a word that merely starts like a label is not a label', h.parse( [ 'Claims were made online, he said', 'Title page of the report', 'Tagsomething: x' ] ), null );

const guj = h.parse( [ 'Author Name / Fact Check By: Vikas Vyas', 'Category: National', 'Fact Check Rating: Misleading', 'Tags: Odisha, Tornado', 'Claim: c', 'Fact: f',
    'URL Slug: the video', '_____', '', 'Title:', '', 'Gujarati title line', 'Body starts' ] );
is( 'label with the value on the next line', [ guj.fields.title, guj.count ], [ 'Gujarati title line', 12 ] );
is( 'a rule line before the header is skipped', h.parse( [ '_____', 'Claim: a', 'Fact: b', 'Body' ] ).count, 3 );
is( 'an empty label followed by a label stays empty', h.parse( [ 'Synopsis:', 'Claim: a', 'Title:', 'Rating: False', 'Body' ] ).fields.title, undefined );

const ta = h.parse( [ 'Author Name: Chendur Pandian', 'Category: India, Political', 'Synopsis: s', 'Claim: c', 'Fact: f', 'Evidence: we have relevant links', 'URL Slug: a slug',
    'RATING TEXT \u2013 False', 'Tags: India, China', 'Title: t', '\u0B95\u0BBF\u0BB3\u0BC6\u0baf\u0BCD\u0BAE\u0BCD (Claim): second claim in Tamil', '\u0B83\u0BAA\u0BC7\u0B95\u0BCD\u0B9F\u0BCD\u0B9A\u0BC6\u0B95\u0BCD (FactCheck): second fact in Tamil',
    'Social Media Caption): a caption', '', '-------------------------------', 'The title again', 'Body' ] );
is( 'long header: "RATING TEXT", Tamil labels with English in brackets, caption', [ ta.count, ta.rating, ta.fields.claim, ta.fields.fact ], [ 15, 'false', 'c', 'f' ] );

console.log( failed ? failed + ' failed' : 'doc-header: all checks passed' );
process.exit( failed ? 1 : 0 );
