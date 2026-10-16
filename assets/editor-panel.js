/**
 * Block editor: the "Fact Check" panel in the Post sidebar, plus a short
 * checklist in the "Are you ready to publish?" panel.
 *
 * The fields are ordinary post meta registered in fields.php, so they save
 * with the post. The classic editor gets the same fields from fields.php.
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
    var blockEditor = wp.blockEditor || {};
    var SidePanel   = editorPkg.PluginDocumentSettingPanel || editPostPkg.PluginDocumentSettingPanel;
    var PrePublish  = editorPkg.PluginPrePublishPanel || editPostPkg.PluginPrePublishPanel;
    var settings    = window.fcEditor || {};
    var ratings     = settings.ratings || [];

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

    /** True for '1', true or 1, however the switch arrives from the server. */
    function on( v ) { return v === true || v === '1' || v === 1; }

    function missingItems( f ) {
        var m = f.meta, out = [];
        if ( ! m.fc_rating )                       out.push( 'Rating — without it, nothing is added to the article' );
        if ( ! String( m.fc_claim || '' ).trim() ) out.push( 'Claim' );
        if ( ! String( m.fc_fact || '' ).trim() )  out.push( 'Fact' );
        if ( ! f.hasImage )                        out.push( 'Featured image — the card has no photo' );
        if ( m.fc_card_mode === 'image' && ! m.fc_card_image ) out.push( 'Your card picture — the built-in card shows instead' );
        return out;
    }

    /** What "Automatic" will do for this post's rating. */
    function autoStamp( m ) {
        if ( settings.olderPost )       return 'no stamp (older post)';
        if ( ! m.fc_rating )            return 'pick a rating first';
        if ( m.fc_rating === 'news' )    return 'no stamp';
        if ( m.fc_rating === 'insight' ) return 'Insight badge';
        return 'Fact checked stamp';
    }

    /** Short text under the claim and fact boxes: length, and a tip. */
    function lengthHelp( text, tip ) {
        var n = String( text || '' ).trim().length;
        if ( ! n ) return tip;
        return n + ' characters' + ( n > 140 ? ' — long; shorter reads better on the card.' : '.' ) + ' ' + tip;
    }

    /** Pick an image from the media library; keeps only its ID. */
    function ImagePicker( props ) {
        var MediaUpload = blockEditor.MediaUpload;
        var Check       = blockEditor.MediaUploadCheck || wp.element.Fragment;
        var media = useSelect( function ( select ) {
            return props.id ? select( 'core' ).getMedia( props.id ) : null;
        }, [ props.id ] );
        if ( ! MediaUpload ) return null;

        var sizes = media && media.media_details && media.media_details.sizes;
        var src   = media ? ( sizes && sizes.medium ? sizes.medium.source_url : media.source_url ) : '';

        return el( C.BaseControl, { id: props.name, label: props.label, help: props.help, __nextHasNoMarginBottom: true },
            el( Check, null,
                el( MediaUpload, {
                    allowedTypes: [ 'image' ],
                    value: props.id,
                    onSelect: function ( m ) { props.onChange( m.id ); },
                    render: function ( o ) {
                        return el( 'div', { className: 'fc-pick' },
                            src && el( 'img', { src: src, alt: '', style: { display: 'block', width: '100%', borderRadius: '4px', marginBottom: '8px' } } ),
                            el( C.Button, { variant: 'secondary', onClick: o.open }, props.id ? 'Replace' : 'Choose image' ),
                            props.id ? el( C.Button, { variant: 'link', isDestructive: true, style: { marginLeft: '12px' }, onClick: function () { props.onChange( 0 ); } }, 'Remove' ) : null
                        );
                    }
                } )
            )
        );
    }

    /** A small labelled group inside the panel, with a line between groups. */
    function Group( title, children ) {
        return el( 'div', { className: 'fc-group', style: { borderTop: '1px solid #e0e0e0', paddingTop: '16px', marginTop: '4px' } },
            el( 'h3', { style: { fontSize: '11px', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '.04em', margin: '0 0 12px', color: '#1e1e1e' } }, title ),
            el( C.Flex, { direction: 'column', gap: 4 }, children )
        );
    }

    var PANEL = 'fc-fact-check/fc-fact-check';

    /**
     * WordPress starts plugin panels closed and ignores initialOpen for them,
     * so open this one each time the editor loads.
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
        var mode    = m.fc_card_mode || '';
        var shown   = mode || settings.siteCard; // what the card will actually be

        var common = { __nextHasNoMarginBottom: true };
        function select( props ) { return el( C.SelectControl, Object.assign( { __next40pxDefaultSize: true }, common, props ) ); }

        return el( SidePanel, { name: 'fc-fact-check', title: 'Fact Check', className: 'fc-fact-check-panel' },
            el( C.Flex, { direction: 'column', gap: 4 },

                el( C.ToggleControl, Object.assign( {
                    label: 'This is a fact check',
                    help: active ? null : 'Turn on to add the fact card, author box and WhatsApp banner.',
                    checked: active,
                    onChange: function ( v ) { f.set( 'fc_is_fact_check', v ); }
                }, common ) ),

                active && missing.length > 0 && el( C.Notice, { status: 'warning', isDismissible: false },
                    'Still needed: ' + missing.map( function ( x ) { return x.split( ' —' )[ 0 ].toLowerCase(); } ).join( ', ' ) + '.'
                ),

                // ── The facts
                active && select( {
                    label: 'Rating',
                    value: m.fc_rating || '',
                    options: [ { label: 'Choose a rating…', value: '' } ].concat( ratings ),
                    onChange: function ( v ) { f.set( 'fc_rating', v ); }
                } ),

                active && el( C.TextareaControl, Object.assign( {
                    label: 'Claim',
                    placeholder: 'What is being said',
                    help: lengthHelp( m.fc_claim, '' ),
                    value: m.fc_claim || '',
                    rows: 3,
                    onChange: function ( v ) { f.set( 'fc_claim', v ); }
                }, common ) ),

                active && el( C.TextareaControl, Object.assign( {
                    label: 'Fact',
                    placeholder: 'What is actually true',
                    help: lengthHelp( m.fc_fact, 'Put **two stars** around words to highlight them.' ),
                    value: m.fc_fact || '',
                    rows: 4,
                    onChange: function ( v ) { f.set( 'fc_fact', v ); }
                }, common ) ),

                // ── Pictures
                active && Group( 'Fact card', [
                    select( {
                        key: 'mode',
                        label: 'Show',
                        hideLabelFromVision: true,
                        value: mode,
                        options: settings.cardModes,
                        onChange: function ( v ) { f.set( 'fc_card_mode', v ); }
                    } ),
                    shown === 'image' && el( ImagePicker, {
                        key: 'image',
                        name: 'fc-card-image',
                        label: 'Your card picture',
                        help: 'Shown as it is, in place of the built-in card.',
                        id: m.fc_card_image || 0,
                        onChange: function ( id ) { f.set( 'fc_card_image', id ); }
                    } ),
                    ( shown === 'card' || shown === 'simple' ) && el( ImagePicker, {
                        key: 'photo',
                        name: 'fc-card-photo',
                        label: 'Card photo (optional)',
                        help: 'Leave empty to use the featured image without its stamp.',
                        id: m.fc_card_photo || 0,
                        onChange: function ( id ) { f.set( 'fc_card_photo', id ); }
                    } )
                ] ),

                active && Group( 'Featured image stamp', [
                    select( {
                        key: 'badge',
                        label: 'Stamp',
                        hideLabelFromVision: true,
                        value: m.fc_featured_badge || '',
                        options: ( settings.badges || [] ).map( function ( o ) {
                            return o.value === '' ? { value: '', label: 'Automatic: ' + autoStamp( m ) } : o;
                        } ),
                        help: settings.featuredHelp,
                        onChange: function ( v ) { f.set( 'fc_featured_badge', v ); }
                    } )
                ] ),

                // ── For Google
                active && el( C.PanelBody, {
                        title: 'Claim source (for Google)',
                        initialOpen: !! ( m.fc_claim_source_url || m.fc_claim_author ),
                        className: 'fc-source'
                    },
                    el( C.Flex, { direction: 'column', gap: 4 },
                        el( C.TextControl, Object.assign( {
                            label: 'Link where it appeared',
                            type: 'url',
                            placeholder: 'https://',
                            value: m.fc_claim_source_url || '',
                            onChange: function ( v ) { f.set( 'fc_claim_source_url', v ); },
                            __next40pxDefaultSize: true
                        }, common ) ),
                        el( C.TextControl, Object.assign( {
                            label: 'Who said it',
                            placeholder: 'e.g. viral WhatsApp forward, @username',
                            value: m.fc_claim_author || '',
                            onChange: function ( v ) { f.set( 'fc_claim_author', v ); },
                            __next40pxDefaultSize: true
                        }, common ) ),
                        m.fc_claim_author && select( {
                            label: 'That is a',
                            value: m.fc_claim_author_type === 'Organization' ? 'Organization' : 'Person',
                            options: [
                                { label: 'Person or social media account', value: 'Person' },
                                { label: 'Organisation, channel or publication', value: 'Organization' }
                            ],
                            onChange: function ( v ) { f.set( 'fc_claim_author_type', v ); }
                        } )
                    )
                )
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
