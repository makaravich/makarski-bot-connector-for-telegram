# wptOptions
The class allows to easy adding options page to the WordPress plugins, themes, etc

Example of usage:

<pre>
global $wpt_options;
add_action( 'init', 'wpt_activate_options' );
add_action( 'admin_init', 'wpt_activate_options' );

function wpt_activate_options()
{
    $wpt_settings_model = [
        'id'          => 'wpt_options',
        'page_title'  => __( 'WP Tricks Options', 'wpt' ), //It is the title, will appear as header of the options page
        'menu_title'  => __( 'Custom site settings', 'wpt' ), //It will appear in the admin menu
        'save_button' => __( 'Save', 'wpt' ), //It is the caption for the "Save" button
        'groups'      => [
            'trick_page' => [
                'sections' => [
                    'author' => [
                        'title'  => __( 'Author options', 'wpt' ),
                        'fields' => [
                            'display_author_block' => [
                                'title' => __( 'Display author block', 'wpt' ),
                                'type'  => 'checkbox',
                            ],//Can add other fields here
                        ],
                    ],//Can add other sections here
                ],
            ],//Can add other groups here
        ],
    ];

    global $wpt_options;
    $wpt_options = new wptSettings( $wpt_settings_model );
}
</pre>