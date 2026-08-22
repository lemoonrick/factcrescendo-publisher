<?php
/**
 * ACF Field Group — registered programmatically. Zero manual setup per site.
 *
 * Fields live on every post but stay hidden until "Mark as Fact Check"
 * is switched on, via ACF conditional logic. No category dependency.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'acf/init', 'fc_register_acf_fields' );

function fc_register_acf_fields() {
    if ( ! function_exists( 'acf_add_local_field_group' ) ) return;

    $show_if_fact_check = [
        [ [ 'field' => 'field_fc_is_fact_check', 'operator' => '==', 'value' => '1' ] ],
    ];

    acf_add_local_field_group( [
        'key'    => 'group_fc_metadata',
        'title'  => 'Fact Check Details',
        'fields' => [

            [
                'key'           => 'field_fc_is_fact_check',
                'label'         => 'Mark as Fact Check',
                'name'          => 'fc_is_fact_check',
                'type'          => 'true_false',
                'default_value' => 0,
                'ui'            => 1,
                'ui_on_text'    => 'Yes',
                'ui_off_text'   => 'No',
                'instructions'  => 'Reveals the fields below and enables auto-generated content on the front end.',
            ],

            [
                'key'               => 'field_fc_rating',
                'label'             => 'Fact Check Rating',
                'name'              => 'fc_rating',
                'type'              => 'select',
                'required'          => 0,
                'choices'           => [
                    'false'           => 'False',
                    'partly-false'    => 'Partly False',
                    'misleading'      => 'Misleading',
                    'missing-context' => 'Missing Context',
                    'satire'          => 'Satire',
                    'altered'         => 'Altered',
                    'insight'         => 'Insight',
                    'news'            => 'News',
                ],
                'default_value'     => '',
                'allow_null'        => 1,
                'placeholder'       => 'Select a rating...',
                'return_format'     => 'value',
                'conditional_logic' => $show_if_fact_check,
            ],

            [
                'key'               => 'field_fc_claim',
                'label'             => 'Claim',
                'name'              => 'fc_claim',
                'type'              => 'textarea',
                'required'          => 0,
                'rows'              => 3,
                'placeholder'       => 'What is the claim being fact-checked?',
                'conditional_logic' => $show_if_fact_check,
            ],

            [
                'key'               => 'field_fc_fact',
                'label'             => 'Fact',
                'name'              => 'fc_fact',
                'type'              => 'textarea',
                'required'          => 0,
                'rows'              => 3,
                'placeholder'       => 'What is the verified fact / correction?',
                'conditional_logic' => $show_if_fact_check,
            ],

            [
                'key'               => 'field_fc_claim_source_url',
                'label'             => 'Where the claim appeared (link)',
                'name'              => 'fc_claim_source_url',
                'type'              => 'url',
                'required'          => 0,
                'placeholder'       => 'https://...',
                'instructions'      => 'Optional. Link to the post, video or article where the claim was made. Sent to Google so the claim can be traced back to its source.',
                'conditional_logic' => $show_if_fact_check,
            ],

            [
                'key'               => 'field_fc_claim_author',
                'label'             => 'Who made the claim',
                'name'              => 'fc_claim_author',
                'type'              => 'text',
                'required'          => 0,
                'placeholder'       => 'e.g. Viral WhatsApp forward, @username, a news channel',
                'instructions'      => 'Optional. The person, account or outlet the claim came from.',
                'conditional_logic' => $show_if_fact_check,
            ],

            [
                'key'               => 'field_fc_claim_author_type',
                'label'             => 'That source is a',
                'name'              => 'fc_claim_author_type',
                'type'              => 'select',
                'required'          => 0,
                'choices'           => [
                    'Person'       => 'Person or social media account',
                    'Organization' => 'Organisation, channel or publication',
                ],
                'default_value'     => 'Person',
                'return_format'     => 'value',
                'instructions'      => 'Google records these differently. Only shown once you name a source above.',
                'conditional_logic' => [
                    [
                        [ 'field' => 'field_fc_is_fact_check', 'operator' => '==', 'value' => '1' ],
                        [ 'field' => 'field_fc_claim_author', 'operator' => '!=empty' ],
                    ],
                ],
            ],

            [
                'key'               => 'field_fc_generate_audio',
                'label'             => 'Generate Audio Narration',
                'name'              => 'fc_generate_audio',
                'type'              => 'true_false',
                'default_value'     => 0,
                'ui'                => 1,
                'ui_on_text'        => 'Yes',
                'ui_off_text'       => 'No',
                'instructions'      => 'Generates a premium AI voiceover of this article on save. Uses your ElevenLabs quota — see FC Publisher settings.',
                'conditional_logic' => $show_if_fact_check,
            ],

            [
                'key'               => 'field_fc_audio_status',
                'label'             => 'Audio Status',
                'name'              => 'fc_audio_status_display',
                'type'              => 'message',
                'message'           => 'Save the post after enabling narration above. Status appears here once generated.',
                'conditional_logic' => [
                    [
                        [ 'field' => 'field_fc_is_fact_check', 'operator' => '==', 'value' => '1' ],
                        [ 'field' => 'field_fc_generate_audio', 'operator' => '==', 'value' => '1' ],
                    ],
                ],
            ],

        ],

        'location' => [
            [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ] ],
        ],

        'menu_order'            => 0,
        'position'              => 'normal',
        'style'                 => 'default',
        'label_placement'       => 'top',
        'instruction_placement' => 'label',
        'active'                => true,
        'show_in_rest'          => true,
    ] );
}
