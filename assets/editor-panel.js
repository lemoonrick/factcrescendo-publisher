/**
 * Block editor: the "Fact Check" panel in the Post sidebar, plus a short
 * checklist in the "Are you ready to publish?" panel.
 *
 * The fields are ordinary post meta registered in fields.php, so they save
 * together with the post — no separate save step. The classic editor gets
 * the equivalent box from fields.php instead.
 *
 * Plain JavaScript using WordPress's own globals, so there is no build step.
 */
( function ( wp ) {
    if ( ! wp || ! wp.plugins || ! wp.element || ! wp.data || ! wp.components ) return;

    var el          = wp.element.createElement;
    var useSelect   = wp.data.useSelect;
    var useDispatch = wp.data.useDispatch;
    var C           = wp.components;
    var editorPkg   = wp.editor || {};
    var editPostPkg = wp.editPost || {};
    var SidePanel   = editorPkg.PluginDocumentSettingPanel || editPostPkg.PluginDocumentSettingPanel;
    var PrePublish  = editorPkg.PluginPrePublishPanel || editPostPkg.PluginPrePublishPanel;
    var settings    = window.fcEditor || { ratings: [] };

    if ( ! SidePanel || ! useSelect || ! useDispatch ) return;

    /** The post's fact-check fields, and a way to change one. */
    function useFields() {
        var data = useSelect( function ( select ) {
            var ed = select( 'core/editor' );
            return {
                type:     ed.getCurrentPostType(),
                meta:     ed.getEditedPostAttribute( 'meta' ) || {},
                hasImage: !! ed.getEditedPostAttribute( 'featured_media' )
            };
        }, [] );
        var editPost = useDispatch( 'core/editor' ).editPost;

        function set( key, value ) {
            var change = {};
            change[ key ] = value;
            editPost( { meta: change } );
        }

        return { type: data.type, meta: data.meta, hasImage: data.hasImage, set: set };
    }

    /** True for '1', true or 1 — how the switch may arrive from the server. */
    function on( v ) { return v === true || v === '1' || v === 1; }

    function missingItems( f ) {
        var m = f.meta, out = [];
        if ( ! m.fc_rating )                         out.push( 'Rating — without it, nothing is added to the article' );
        if ( ! String( m.fc_claim || '' ).trim() )   out.push( 'Claim' );
        if ( ! String( m.fc_fact || '' ).trim() )    out.push( 'Fact' );
        if ( ! f.hasImage )                          out.push( 'Featured image — the fact card shows no picture' );
        return out;
    }

    var PANEL = 'fc-fact-check/fc-fact-check';

    /**
     * WordPress starts plugin panels closed and ignores initialOpen for them.
     * Open this one each time the editor loads; it is the panel editors need
     * on almost every post.
     */
    function useOpenOnLoad() {
        var opened = useSelect( function ( select ) {
            var ed = select( 'core/editor' );
            return ed.isEditorPanelOpened ? ed.isEditorPanelOpened( PANEL ) : true;
        }, [] );
        var toggle = useDispatch( 'core/editor' ).toggleEditorPanelOpened;
        var done   = wp.element.useRef( false );

        wp.element.useEffect( function () {
            if ( done.current ) return;
            done.current = true;
            if ( ! opened && toggle ) toggle( PANEL );
        }, [] );
    }

    function FactCheckPanel() {
        var f = useFields();
        useOpenOnLoad();
        if ( f.type !== 'post' ) return null;

        var m       = f.meta;
        var active  = on( m.fc_is_fact_check );
        var missing = active ? missingItems( f ) : [];
        var ratings = [ { label: 'Select a rating…', value: '' } ].concat( settings.ratings );

        return el( SidePanel, { name: 'fc-fact-check', title: 'Fact Check', className: 'fc-fact-check-panel' },

            el( C.ToggleControl, {
                label: 'Mark as Fact Check',
                help: 'Adds the fact card, author box, WhatsApp banner and Google fact-check data.',
                checked: active,
                onChange: function ( v ) { f.set( 'fc_is_fact_check', v ); },
                __nextHasNoMarginBottom: true
            } ),

            active && missing.length > 0 && el( C.Notice, { status: 'warning', isDismissible: false, className: 'fc-missing' },
                'Still needed: ' + missing.map( function ( x ) { return x.split( ' —' )[ 0 ].toLowerCase(); } ).join( ', ' ) + '.'
            ),

            active && el( C.SelectControl, {
                label: 'Rating',
                value: m.fc_rating || '',
                options: ratings,
                onChange: function ( v ) { f.set( 'fc_rating', v ); },
                __next40pxDefaultSize: true,
                __nextHasNoMarginBottom: true
            } ),

            active && el( C.TextareaControl, {
                label: 'Claim',
                placeholder: 'What is the claim being fact-checked?',
                value: m.fc_claim || '',
                rows: 4,
                onChange: function ( v ) { f.set( 'fc_claim', v ); },
                __nextHasNoMarginBottom: true
            } ),

            active && el( C.TextareaControl, {
                label: 'Fact',
                placeholder: 'What is the verified fact / correction?',
                value: m.fc_fact || '',
                rows: 4,
                onChange: function ( v ) { f.set( 'fc_fact', v ); },
                __nextHasNoMarginBottom: true
            } ),

            active && el( C.PanelBody, {
                    title: 'Where the claim came from',
                    initialOpen: !! ( m.fc_claim_source_url || m.fc_claim_author ),
                    className: 'fc-source'
                },
                el( 'p', { className: 'components-base-control__help' }, 'Optional. Sent to Google so the claim can be traced back.' ),
                el( C.TextControl, {
                    label: 'Link where it appeared',
                    type: 'url',
                    placeholder: 'https://...',
                    value: m.fc_claim_source_url || '',
                    onChange: function ( v ) { f.set( 'fc_claim_source_url', v ); },
                    __next40pxDefaultSize: true,
                    __nextHasNoMarginBottom: true
                } ),
                el( C.TextControl, {
                    label: 'Who made the claim',
                    placeholder: 'e.g. Viral WhatsApp forward, @username',
                    value: m.fc_claim_author || '',
                    onChange: function ( v ) { f.set( 'fc_claim_author', v ); },
                    __next40pxDefaultSize: true,
                    __nextHasNoMarginBottom: true
                } ),
                m.fc_claim_author && el( C.SelectControl, {
                    label: 'That source is a',
                    value: m.fc_claim_author_type === 'Organization' ? 'Organization' : 'Person',
                    options: [
                        { label: 'Person or social media account', value: 'Person' },
                        { label: 'Organisation, channel or publication', value: 'Organization' }
                    ],
                    onChange: function ( v ) { f.set( 'fc_claim_author_type', v ); },
                    __next40pxDefaultSize: true,
                    __nextHasNoMarginBottom: true
                } )
            )
        );
    }

    /** Warns before publishing. Never stops anyone publishing. */
    function ReadyCheck() {
        var f = useFields();
        if ( ! PrePublish || f.type !== 'post' || ! on( f.meta.fc_is_fact_check ) ) return null;

        var missing = missingItems( f );
        var ok      = missing.length === 0;

        return el( PrePublish, {
                title: ok ? 'Fact check: ready' : 'Fact check: ' + missing.length + ' missing',
                initialOpen: ! ok,
                className: 'fc-prepublish'
            },
            ok
                ? el( 'p', null, 'Rating, claim, fact and featured image are all filled in.' )
                : el( 'div', null,
                    el( 'p', null, 'You can still publish, but these are empty:' ),
                    el( 'ul', { style: { listStyle: 'disc', paddingLeft: '18px' } },
                        missing.map( function ( x ) { return el( 'li', { key: x }, x ); } )
                    )
                  )
        );
    }

    wp.plugins.registerPlugin( 'fc-fact-check', {
        render: function () {
            return el( wp.element.Fragment, null, el( FactCheckPanel ), el( ReadyCheck ) );
        }
    } );
} )( window.wp );
