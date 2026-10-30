/**
 * The words writers use in the header of a fact-check Doc, per field and per language.
 * Data only: to teach the reader a new site's wording, add it here (doc-header.js is the
 * code that uses it). Each label is a regular expression; spaces are written \s*.
 * Writers often give a label in their own language followed by the English word in
 * brackets ("दावा (Claim):"), so a bracketed English word counts as a label too.
 */
( function ( root ) {
    'use strict';

    var inBrackets = function ( word ) { return '[^:\\uFF1A\\n()]{0,40}\\(\\s*(?:' + word + ')\\s*\\)'; };

    var labels = {
        // [ field, label ] in the order they are tried.
        fields: [
            [ 'writer',   'author\\s*name\\s*/\\s*fact\\s*check(?:ed)?\\s*by' ],
            [ 'writer',   'fact\\s*check(?:ed)?\\s*by|written\\s*by|author\\s*name|author|writer|reporter|लेखक' ],
            [ 'rating',   'fact\\s*check\\s*rating|rating\\s*text|rating|रेटिंग|রেটিং|மதிப்பீடு' ],
            // "Result: False" is a rating (Malayalam), "Result: The video is old…" is the fact (Assamese).
            [ 'result',   'result|verdict|ফলাফল' ],
            [ 'tags',     'tags?\\s*/\\s*meta\\s*data|tags?|keywords?|meta\\s*data' ],
            [ 'category', 'categor(?:y|ies)|श्रेणी|বিভাগ|വിഭാഗം|பிரிவு' ],
            [ 'slug',     'url\\s*slug|slug|permalink' ],
            [ 'title',    'title|headline|शीर्षक|শিরোনাম|தலைப்பு' ],
            [ 'claim',    'claim|दावा|দাবি|দাবী|દાવો|പ്രചരണം|പ്രചാരണം|கூற்று|' + inBrackets( 'claim' ) ],
            [ 'fact',     'fact\\s*checked|facts?|सत्य|तथ्य|তথ্য\\s*যাচাই|সত্য|വസ്തുത|உண்மை|' + inBrackets( 'fact\\s*check(?:ed)?|fact' ) ],
            [ 'synopsis', 'synopsis|summary' ],
            // Production lines that are recognised so they leave the article, but are not used.
            [ 'ignore',   'evidence|meta\\s*description|(?:social\\s*media|sm)\\s*caption\\)?' ]
        ],

        // Fields that may also start in the middle of a line ("Claim: ... Writer – Name").
        midLine: { writer: 1, rating: 1, tags: 1, category: 1, slug: 1, title: 1 },

        // Words that mean one of our ratings. Checked in this order ("Partly False" before "False").
        ratings: [
            [ 'partly-false',    /partly|partially|half[\s-]*true|आंशिक|अंशत|আংশিক/i ],
            [ 'missing-context', /missing\s*context|\bcontext\b/i ],
            [ 'misleading',      /mislead|भ्रामक|ভ্রান্তিকর|বিভ্ৰান্তিকৰ/i ],
            [ 'satire',          /satire|व्यंग्य/i ],
            [ 'altered',         /altered|manipulated|doctored/i ],
            [ 'insight',         /insight/i ],
            [ 'news',            /^\W*news\b/i ],
            [ 'false',           /false|fake|fabricated|झूठ|गलत|फर्जी|खोट|असत्य|মিথ্যা|মিছা|பொய்|தவறு/i ]
        ]
    };

    if ( typeof module !== 'undefined' && module.exports ) module.exports = labels;
    root.fcDocLabels = labels;
} )( typeof window !== 'undefined' ? window : this );
