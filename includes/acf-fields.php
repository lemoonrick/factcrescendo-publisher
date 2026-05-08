<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'acf/init', 'fc_register_acf_fields' );

function fc_register_acf_fields() {
    if ( ! function_exists( 'acf_add_local_field_group' ) ) return;

    acf_add_local_field_group( [
        'key'    => 'group_fc_metadata',
        'title'  => '✅ Fact Check Details',
        'fields' => [
            [
                'key'           => 'field_fc_featured_stamp',
                'label'         => 'Overlay Stamp on Featured Image?',
                'name'          => 'fc_featured_stamp',
                'type'          => 'select',
                'instructions'  => 'Automation: Adds a tilted stamp to the main article image.',
                'choices'       => [
                    'none'         => 'No Stamp',
                    'fact-checked' => 'Fact-Checked Stamp',
                    'insight'      => 'Insight Stamp',
                ],
                'default_value' => 'none',
            ],
            [
                'key'           => 'field_fc_is_fact_check',
                'label'         => 'Mark as Fact Check',
                'name'          => 'fc_is_fact_check',
                'type'          => 'true_false',
                'default_value' => 0,
                'ui'            => 1,
                'ui_on_text'    => 'Yes',
                'ui_off_text'   => 'No',
            ],
            [
                'key'               => 'field_fc_rating',
                'label'             => 'Fact Check Rating',
                'name'              => 'fc_rating',
                'type'              => 'select',
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
                'conditional_logic' => [[['field' => 'field_fc_is_fact_check', 'operator' => '==', 'value' => '1']]],
            ],
            [
                'key'               => 'field_fc_claim',
                'label'             => 'Claim',
                'name'              => 'fc_claim',
                'type'              => 'textarea',
                'rows'              => 3,
                'conditional_logic' => [[['field' => 'field_fc_is_fact_check', 'operator' => '==', 'value' => '1']]],
            ],
            [
                'key'               => 'field_fc_fact',
                'label'             => 'Fact',
                'name'              => 'fc_fact',
                'type'              => 'textarea',
                'rows'              => 3,
                'conditional_logic' => [[['field' => 'field_fc_is_fact_check', 'operator' => '==', 'value' => '1']]],
            ],
        ],
        'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
        'menu_order' => 0,
        'position' => 'normal',
        'style' => 'default',
        'label_placement' => 'top',
        'active' => true,
        'show_in_rest' => true,
    ] );
}