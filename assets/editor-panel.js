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
        if ( settings.olderPost )        return 'none, older post';
        if ( ! m.fc_rating )             return 'choose a rating first';
        if ( m.fc_rating === 'news' )    return 'none for News';
        if ( m.fc_rating === 'insight' ) return 'Insight badge';
        return 'Fact checked stamp';
    }

    /** Short text under the claim and fact boxes: length, and a tip. */
    function lengthHelp( text, tip ) {
        var n = String( text || '' ).replace( /\*\*/g, '' ).trim().length; // stars aren't shown
        if ( ! n ) return tip;
        return n + ' characters' + ( n > 140 ? ' (long; shorter reads better).' : '.' ) + ' ' + tip;
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

    /** A titled group with a line above it, so each part of the panel stands apart. */
    function Group( title, help, children ) {
        return el( 'div', { className: 'fc-group', style: { borderTop: '1px solid #e0e0e0', paddingTop: '20px' } },
            el( 'h3', { style: { fontSize: '13px', fontWeight: 600, margin: '0 0 4px', color: '#1e1e1e' } }, title ),
            help && el( 'p', { style: { fontSize: '12px', color: '#757575', margin: '0 0 12px', lineHeight: 1.5 } }, help ),
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
        var common  = { __nextHasNoMarginBottom: true };

        function radios( key, options ) {
            return el( C.RadioControl, Object.assign( {
                key: key,
                selected: m[ key ] || '',
                options: options,
                onChange: function ( v ) { f.set( key, v ); }
            }, common ) );
        }

        return el( SidePanel, { name: 'fc-fact-check', title: 'Fact Check', className: 'fc-fact-check-panel' },
            el( C.Flex, { direction: 'column', gap: 6, style: { paddingTop: '4px', paddingBottom: '8px' } },

                el( C.ToggleControl, Object.assign( {
                    label: 'This is a fact check',
                    help: active ? 'The card, stamp, author box and banner are made for you.' : 'Turn on to add the fact card, author box and WhatsApp banner.',
                    checked: active,
                    onChange: function ( v ) { f.set( 'fc_is_fact_check', v ); }
                }, common ) ),

                active && missing.length > 0 && el( C.Notice, { status: 'warning', isDismissible: false },
                    'Still needed: ' + missing.map( function ( x ) { return x.split( ' —' )[ 0 ].toLowerCase(); } ).join( ', ' ) + '.'
                ),

                // ── The facts
                active && el( C.SelectControl, Object.assign( {
                    label: 'Rating',
                    value: m.fc_rating || '',
                    options: [ { label: 'Choose a rating', value: '' } ].concat( ratings ),
                    onChange: function ( v ) { f.set( 'fc_rating', v ); },
                    __next40pxDefaultSize: true
                }, common ) ),

                active && el( C.TextareaControl, Object.assign( {
                    label: 'Claim',
                    placeholder: 'What people are saying',
                    help: lengthHelp( m.fc_claim, '' ),
                    value: m.fc_claim || '',
                    rows: 4,
                    onChange: function ( v ) { f.set( 'fc_claim', v ); }
                }, common ) ),

                active && el( C.TextareaControl, Object.assign( {
                    label: 'Fact',
                    placeholder: 'What is actually true',
                    help: lengthHelp( m.fc_fact, 'Tip: put **two stars** around words to highlight them.' ),
                    value: m.fc_fact || '',
                    rows: 6,
                    onChange: function ( v ) { f.set( 'fc_fact', v ); }
                }, common ) ),

                // ── Pictures
                active && Group( 'Fact card', 'Shown at the top of the article.', [
                    radios( 'fc_card_mode', settings.cardModes || [] ),
                    shown === 'image' && el( ImagePicker, {
                        key: 'image',
                        name: 'fc-card-image',
                        label: 'Your card picture',
                        help: 'Shown as it is.',
                        id: m.fc_card_image || 0,
                        onChange: function ( id ) { f.set( 'fc_card_image', id ); }
                    } ),
                    ( shown === 'card' || shown === 'simple' ) && el( ImagePicker, {
                        key: 'photo',
                        name: 'fc-card-photo',
                        label: 'Card photo (optional)',
                        help: 'Empty = the featured image, without its stamp.',
                        id: m.fc_card_photo || 0,
                        onChange: function ( id ) { f.set( 'fc_card_photo', id ); }
                    } )
                ] ),

                active && Group( 'Featured image stamp', settings.featuredHelp, [
                    radios( 'fc_featured_badge', ( settings.badges || [] ).map( function ( o ) {
                        return o.value === '' ? { value: '', label: 'Automatic (' + autoStamp( m ) + ')' } : o;
                    } ) )
                ] ),

                // ── For Google
                active && Group( 'Claim source', 'Optional. Helps Google show your fact check.', [
                    el( C.TextControl, Object.assign( {
                        key: 'url',
                        label: 'Link to the claim',
                        type: 'url',
                        placeholder: 'https://',
                        value: m.fc_claim_source_url || '',
                        onChange: function ( v ) { f.set( 'fc_claim_source_url', v ); },
                        __next40pxDefaultSize: true
                    }, common ) ),
                    el( C.TextControl, Object.assign( {
                        key: 'who',
                        label: 'Who said it',
                        placeholder: 'e.g. a viral WhatsApp post',
                        value: m.fc_claim_author || '',
                        onChange: function ( v ) { f.set( 'fc_claim_author', v ); },
                        __next40pxDefaultSize: true
                    }, common ) ),
                    m.fc_claim_author && el( C.SelectControl, Object.assign( {
                        key: 'type',
                        label: 'They are a',
                        value: m.fc_claim_author_type === 'Organization' ? 'Organization' : 'Person',
                        options: [
                            { label: 'Person or social account', value: 'Person' },
                            { label: 'Organisation or channel', value: 'Organization' }
                        ],
                        onChange: function ( v ) { f.set( 'fc_claim_author_type', v ); },
                        __next40pxDefaultSize: true
                    }, common ) )
                ] )
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

    /**
     * After a save, show what the plugin made of the article.
     *
     * When the post is saved, links and pasted code become Embed, Custom HTML
     * and Image blocks, and author notes like "Embed" are deleted (embeds.php).
     * The editor would otherwise keep showing the article as it was pasted,
     * so the saved version is loaded back in, and saved once more so the
     * editor doesn't think there are unsaved changes.
     */
    ( function () {
        if ( ! wp.blocks || ! wp.blocks.parse ) return;
        var wasSaving = false, resaving = false;

        wp.data.subscribe( function () {
            var ed = wp.data.select( 'core/editor' );
            if ( ! ed ) return;
            var saving = ed.isSavingPost() && ! ed.isAutosavingPost();

            if ( wasSaving && ! saving && ed.didPostSaveRequestSucceed() ) {
                if ( resaving ) { resaving = false; wasSaving = saving; return; }
                var record = wp.data.select( 'core' ).getEntityRecord( 'postType', ed.getCurrentPostType(), ed.getCurrentPostId() );
                var saved  = record && record.content && record.content.raw;
                var mine   = wp.data.select( 'core/block-editor' ).getBlocks().map( function ( b ) { return b.name; } ).join( ',' );
                var theirs = typeof saved === 'string' ? wp.blocks.parse( saved ).map( function ( b ) { return b.name; } ).join( ',' ) : mine;
                // Only when the plugin really changed the article (blocks added, replaced or deleted).
                if ( mine !== theirs ) {
                    wp.data.dispatch( 'core/editor' ).resetEditorBlocks( wp.blocks.parse( saved ), { __unstableShouldCreateUndoLevel: false } );
                    if ( wp.data.select( 'core/notices' ) ) {
                        wp.data.dispatch( 'core/notices' ).createInfoNotice( 'Links turned into embeds and author notes removed.', { type: 'snackbar' } );
                    }
                    if ( wp.data.select( 'core/editor' ).isEditedPostDirty() ) {
                        resaving = true;
                        wp.data.dispatch( 'core/editor' ).savePost();
                    }
                }
            }
            wasSaving = saving;
        } );
    } )();

    wp.plugins.registerPlugin( 'fc-fact-check', {
        render: function () {
            return el( wp.element.Fragment, null, el( FactCheckPanel ), el( ReadyCheck ) );
        }
    } );
} )( window.wp );
