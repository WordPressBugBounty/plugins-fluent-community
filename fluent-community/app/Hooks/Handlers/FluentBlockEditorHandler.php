<?php

namespace FluentCommunity\App\Hooks\Handlers;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\Framework\Support\Arr;
use FluentCommunity\Modules\Course\Model\CourseLesson;

class FluentBlockEditorHandler
{

    public function register()
    {
        add_action('init', function () {
            register_post_type('fcom-dummy', [
                'label'        => 'Lesson',
                'public'       => false,
                'show_in_rest' => true,
                'supports'     => ['title', 'editor', 'thumbnail'],
            ]);

            if (isset($_REQUEST['fluent_community_block_editor'])) {
                if (!defined('IFRAME_REQUEST')) {
                    define('IFRAME_REQUEST', true);
                }
                add_filter('wp_theme_json_data_theme', function (\WP_Theme_JSON_Data $theme_json) {
                    $defaults = [
                        'version'  => 3,
                        'settings' => [
                            'spacing'    => [
                                'blockGap' => 1,
                                'margin'   => 1,
                                'padding'  => 1,
                                'units'    => ['px', 'em', 'rem', '%', 'vh', 'vw'],
                            ],
                            'layout'     => [
                                'contentSize' => 'var(--theme-block-max-width)',
                                'wideSize'    => 'var(--theme-block-wide-max-width)',
                            ],
                            'typography' => [
                                'customFontSize'   => 1,
                                'fluid'            => 0,
                                'fontSizes'        => [
                                    'theme' => [
                                        [
                                            'name' => 'Small',
                                            'slug' => 'small',
                                            'size' => 'var(--fcom-font-size-small)',
                                        ],
                                        [
                                            'name' => 'Medium',
                                            'slug' => 'medium',
                                            'size' => 'var(--fcom-font-size-medium)',
                                        ],
                                        [
                                            'name' => 'Large',
                                            'slug' => 'large',
                                            'size' => 'var(--fcom-font-size-large)',
                                        ],
                                        [
                                            'name' => 'Larger',
                                            'slug' => 'larger',
                                            'size' => 'var(--fcom-font-size-larger)',
                                        ],
                                        [
                                            'name' => 'XX-Large',
                                            'slug' => 'xxlarge',
                                            'size' => 'var(--fcom-font-size-xxlarge)',
                                        ],
                                    ],
                                ],
                                'lineHeight'       => 1,
                                'defaultFontSizes' => null,
                            ],
                            'background' => [
                                'backgroundImage' => 1,
                                'backgroundSize'  => 1
                            ],
                            'border'     => [
                                'color'  => 1,
                                'radius' => 1,
                                'style'  => 1,
                                'width'  => 1,
                            ],
                            'color'      => [
                                'custom'         => 1,
                                'defaultDuotone' => 0,
                                'duotone'        => [],
                                'customDuotone'  => 0,
                                'defaultPalette' => [],
                                'link'           => 1,
                                'palette'        => [
                                    'theme'   => $this->getColorPallets(),
                                    'default' => []
                                ],
                                'heading'        => 1,
                                'button'         => 1,
                                'caption'        => 1
                            ],
                            'dimensions' => [
                                'aspectRatio' => 1,
                                'minHeight'   => 1
                            ],
                            'position'   => [
                                'sticky' => 0
                            ],
                        ],
                        'styles'   => [
                            'elements' => [
                                'link'   => [
                                    'typography' => [
                                        'textDecoration' => null,
                                    ],
                                ],
                                'button' => [
                                    'spacing'    => [
                                        'padding' => null,
                                    ],
                                    'border'     => [
                                        'width' => null,
                                    ],
                                    'color'      => [
                                        'background' => null,
                                        'text'       => null,
                                    ],
                                    'typography' => [
                                        'fontFamily'     => null,
                                        'fontSize'       => null,
                                        'lineHeight'     => null,
                                        'textDecoration' => null,
                                    ],
                                ],
                            ],
                            'spacing'  => [
                                'blockGap' => 'var(--theme-content-spacing)',
                            ],
                        ],
                    ];

                    return $theme_json->update_with($defaults);
                }, 9999);
                remove_action('enqueue_block_editor_assets', 'wp_enqueue_editor_block_directory_assets');
                add_action('fluent_community/block_editor_head', function () {
                    $url = FLUENT_COMMUNITY_PLUGIN_URL . 'Modules/Gutenberg/editor/index.css';
                    ?>
                    <link rel="stylesheet"
                          href="<?php echo esc_url($url); ?>?version=<?php echo esc_attr(FLUENT_COMMUNITY_PLUGIN_VERSION); ?>"
                          media="screen"/>
                    <?php
                });
                add_filter('should_load_separate_core_block_assets', '__return_false', 20);
                $this->renderLessonEditor($_REQUEST);

                add_action('template_redirect', function () {
                    $this->renderPage();
                    exit(200);
                }, -1000);

            }
        }, 2);
    }

    public function getColorPallets()
    {
        return [
            [
                'name'  => 'Accent',
                'slug'  => 'theme-palette-color-1',
                'color' => 'var(--theme-palette-color-1)',
            ],
            [
                'name'  => 'Accent - alt',
                'slug'  => 'theme-palette-color-2',
                'color' => 'var(--theme-palette-color-2)',
            ],
            [
                'name'  => 'Strongest text',
                'slug'  => 'theme-palette-color-3',
                'color' => 'var(--theme-palette-color-3)',
            ],
            [
                'name'  => 'Strong Text',
                'slug'  => 'theme-palette-color-4',
                'color' => 'var(--theme-palette-color-4)',
            ],
            [
                'name'  => 'Medium text',
                'slug'  => 'theme-palette-color-5',
                'color' => 'var(--theme-palette-color-5)',
            ],
            [
                'name'  => 'Subtle Text',
                'slug'  => 'theme-palette-color-6',
                'color' => 'var(--theme-palette-color-6)',
            ],
            [
                'name'  => 'Subtle Background',
                'slug'  => 'theme-palette-color-7',
                'color' => 'var(--theme-palette-color-7)',
            ],
            [
                'name'  => 'Lighter Background',
                'slug'  => 'theme-palette-color-8',
                'color' => 'var(--theme-palette-color-8)',
            ]
        ];
    }

    public function renderLessonEditor($data = [])
    {
        do_action('litespeed_control_set_nocache', 'fluentcommunity api request');
        // set no cache headers
        nocache_headers();

        $context = Arr::get($data, 'context');
        $hasAccess = false;
        if ($context == 'course_lesson') {
            $lessonId = Arr::get($data, 'lesson_id');
            if ($lessonId) {
                $lesson = CourseLesson::find($lessonId);
                $hasAccess = $lesson && $lesson->course && $lesson->course->isCourseAdmin();
            }
        }

        if (!$hasAccess) {
            echo '<h3 style="padding: 100px; text-align: center;">Sorry, you do not have access to this page.</h3>';
            exit(200);
        }

        add_filter('should_load_separate_core_block_assets', '__return_false', 20);
        show_admin_bar(false);

        $firstPost = Utility::getApp('db')->table('posts')
            ->where('post_type', 'fcom-dummy')
            ->first();

        if ($firstPost) {
            $simulatedPost = get_post($firstPost->ID);
        } else {
            $newPostId = wp_insert_post(array(
                'post_title'   => 'Demo Lesson Title',
                'post_content' => '<!-- wp:paragraph --><p>Edit me....</p><!-- /wp:paragraph -->',
                'post_type'    => 'fcom-dummy',
                'post_status'  => 'draft',
            ));

            $simulatedPost = get_post($newPostId);
        }

        global $post;
        $post = $simulatedPost;

        add_action('wp_enqueue_scripts', function () use ($post) {
            wp_enqueue_script('postbox', admin_url('js/postbox.min.js'), array('jquery-ui-sortable'), false, 1);
            wp_enqueue_style('dashicons');
            wp_enqueue_style('media');
            wp_enqueue_style('admin-menu');
            wp_enqueue_style('admin-bar');
            wp_enqueue_style('l10n');

            wp_add_inline_script(
                'wp-api-fetch',
                \sprintf(
                    'wp.apiFetch.use( wp.apiFetch.createPreloadingMiddleware( %s ) );',
                    wp_json_encode(
                        array(
                            '/wp/v2/fcom-dummy/' . $post->ID . '?context=edit' => array(
                                'body' => array(
                                    'id'             => $post->ID,
                                    'title'          => array( 'raw' => $post->post_title ),
                                    'content'        => array(
                                        'block_format' => 1,
                                        'raw'          => $post->post_content,
                                    ),
                                    'excerpt'        => array( 'raw' => '' ),
                                    'date'           => '',
                                    'date_gmt'       => '',
                                    'modified'       => '',
                                    'modified_gmt'   => '',
                                    'link'           => home_url( '/' ),
                                    'guid'           => array(),
                                    'parent'         => 0,
                                    'menu_order'     => 0,
                                    'author'         => 0,
                                    'featured_media' => 0,
                                    'comment_status' => 'closed',
                                    'ping_status'    => 'closed',
                                    'template'       => '',
                                    'meta'           => array(),
                                    '_links'         => array(),
                                    'type'           => 'fcom-dummy',
                                    'status'         => 'pending', // pending is the best state to remove draft saving possibilities.
                                    'slug'           => '',
                                    'generated_slug' => '',
                                    'permalink_template' => home_url( '/' ),
                                ),
                            ),
                        )
                    )
                ),
                'after'
            );

        }, 11);

        add_action('wp_enqueue_scripts', function ($hook) use ($post) {
            // Gutenberg requires the post-locking functions defined within:
            // See `show_post_locked_dialog` and `get_post_metadata` filters below.
            include_once ABSPATH . 'wp-admin/includes/post.php';
            $this->gutenberg_editor_scripts_and_styles($hook, $post);
        });

        add_action('enqueue_block_editor_assets', function () {
            wp_enqueue_script('fcom_editor_custom', FLUENT_COMMUNITY_PLUGIN_URL . 'Modules/Gutenberg/editor/index.js', ['react', 'wp-components', 'wp-compose', 'wp-data', 'wp-edit-post', 'wp-i18n', 'wp-plugins'], FLUENT_COMMUNITY_PLUGIN_VERSION . time(), true);
        });

        // Disable post locking dialogue.
        add_filter('show_post_locked_dialog', '__return_false');

        // Everyone can richedit! This avoids a case where a page can be cached where a user can't richedit.
        $GLOBALS['wp_rich_edit'] = true;
        add_filter('user_can_richedit', '__return_true', 1000);

        // Homepage is always locked by @wordpressdotorg
        // This prevents other logged-in users taking a lock of the post on the front-end.
        add_filter('get_post_metadata', function ($value, $post_id, $meta_key) {
            if ($meta_key !== '_edit_lock') {
                return $value;
            }
            return time() . ':' . get_current_user_id(); // WordPressdotorg user ID
        }, 10, 3);

        // Disable Jetpack Blocks for now.
        add_filter('jetpack_gutenberg', '__return_false');
    }

    function gutenberg_editor_scripts_and_styles($hook, $post)
    {
        // Set the post type name.
        $post_type = get_post_type($post);

        $initial_edits = array(
            'title'   => $post->post_title,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
        );


        // Get all available templates for the post/page attributes meta-box.
        // The "Default template" array element should only be added if the array is
        // not empty so we do not trigger the template select element without any options
        // besides the default value.
        $available_templates = wp_get_theme()->get_page_templates(get_post($post->ID));
        $available_templates = !empty($available_templates) ? array_merge(
            array(
                '' => apply_filters('default_page_template_title', __('Default template', 'gutenberg'), 'rest-api'),
            ),
            $available_templates
        ) : $available_templates;

        // Media settings.
        $max_upload_size = wp_max_upload_size();
        if (!$max_upload_size) {
            $max_upload_size = 0;
        }

        $lock_details = array(
            'isLocked' => false,
            'user'     => '',
        );

        $editor_settings = array(
            'availableTemplates'     => $available_templates,
            'allowedBlockTypes'      => [
                'core/audio',
                'core/block',
                'core/buttons',
                'core/button',
                'core/code',
                'core/columns',
                'core/column',
                'core/cover',
                'core/embed',
                'core/footnotes',
                'core/freeform',
                'core/gallery',
                'core/group',
                'core/heading',
                'core/html',
                'core/image',
                'core/latest-posts',
                'core/list',
                'core/list-item',
                'core/media-text',
                'core/missing',
                'core/paragraph',
                'core/preformatted',
                'core/pullquote',
                'core/quote',
                'core/rss',
                'core/separator',
                'core/social-link',
                'core/social-links',
                'core/spacer',
                'core/table',
                'core/text-columns',
                'core/verse',
                'core/freeform'
            ],
            'disableCustomColors'    => true,
            'disableCustomFontSizes' => true,
            'disablePostFormats'     => true,
            'titlePlaceholder'       => __('Add Lesson title', 'fluent-community'),
            'bodyPlaceholder'        => __('Start writing or type / to choose a block for your lesson content', 'fluent-community'),
            'isRTL'                  => is_rtl(),
            'autosaveInterval'       => 999,
            'maxUploadFileSize'      => $max_upload_size,
            'allowedMimeTypes'       => get_allowed_mime_types(),
            'styles'                 => [],
            'richEditingEnabled'     => true,
            'fullscreenMode'         => true,

            // Ideally, we'd remove this and rely on a REST API endpoint.
            'postLock'               => $lock_details,
            'postLockUtils'          => array(
                'nonce'       => wp_create_nonce('lock-post_' . $post->ID),
                'unlockNonce' => wp_create_nonce('update-post_' . $post->ID),
                'ajaxUrl'     => admin_url('admin-ajax.php'),
            ),

            // Whether or not to load the 'postcustom' meta box is stored as a user meta
            // field so that we're not always loading its assets.
            'enableCustomFields'     => false
        );

        $editor_context = new \WP_Block_Editor_Context(array('name' => 'core/edit-widgets'));
        $editor_settings = get_block_editor_settings($editor_settings, $editor_context);

        $editor_settings['styles'][] = [
            'css'            => file_get_contents(FLUENT_COMMUNITY_PLUGIN_DIR . 'Modules/Gutenberg/editor/editor.css'),
            '__unstableType' => 'user'
        ];

        Arr::set($editor_settings, '__experimentalFeatures.color.duotone', []);
        Arr::set($editor_settings, '__experimentalFeatures.color.defaultGradients', 0);
        Arr::set($editor_settings, '__experimentalFeatures.color.gradients', []);
        Arr::set($editor_settings, 'disableCustomGradients', 1);
        Arr::set($editor_settings, 'gradients', []);
        $editor_settings = apply_filters('fluent_community/block_editor_settings', $editor_settings);

        //dd($editor_settings);

        $init_script = <<<JS
			( function() {
				window._wpLoadBlockEditor = new Promise( function( resolve ) {
					wp.domReady( function() {
						resolve( wp.editPost.initializeEditor( 'editor', "%s", %d, %s, %s ) );
					} );
				} );
			} )();
			JS;

        $script = sprintf(
            $init_script,
            $post->post_type,
            $post->ID,
            wp_json_encode($editor_settings),
            wp_json_encode($initial_edits)
        );
        wp_add_inline_script('wp-edit-post', $script);

        /**
         * Scripts
         */
        wp_enqueue_media(
            array(
                'post' => null
            )
        );

        add_filter('user_can_richedit', '__return_true');
        wp_tinymce_inline_scripts();
        wp_enqueue_editor();


        /**
         * Styles
         */
        wp_enqueue_style('wp-edit-post');

        /*
        These styles are usually registered by Gutenberg and register properly when the user is signed in.
        However, if the use is not registered they are not added. For now, include them, but this isn't a good long term strategy

        See: https://github.com/WordPress/wporg-gutenberg/issues/26
        */
        wp_enqueue_style('global-styles');
        wp_enqueue_style('wp-block-library');
        wp_enqueue_style('wp-block-image');
        wp_enqueue_style('wp-block-group');
        wp_enqueue_style('wp-block-heading');
        wp_enqueue_style('wp-block-button');
        wp_enqueue_style('wp-block-paragraph');
        wp_enqueue_style('wp-block-separator');
        wp_enqueue_style('wp-block-columns');
        wp_enqueue_style('wp-block-cover');
        wp_enqueue_style('global-styles-css-custom-properties');
        wp_enqueue_style('wp-block-spacer');

        wp_register_style('fluent_com_editor_styles', FLUENT_COMMUNITY_PLUGIN_URL . 'Modules/Gutenberg/editor/style.css', false, FLUENT_COMMUNITY_PLUGIN_VERSION, 'all');

        //   add_editor_style('fluent_com_editor_styles.css');

        // wp_enqueue_style('fluent_com_editor_styles', FLUENT_COMMUNITY_PLUGIN_URL . 'Modules/Gutenberg/editor/style-editor.css', false, FLUENT_COMMUNITY_PLUGIN_VERSION, 'all');


        //    wp_dequeue_style('global-styles');

        /**
         * Fires after block assets have been enqueued for the editing interface.
         *
         * Call `add_action` on any hook before 'admin_enqueue_scripts'.
         *
         * In the function call you supply, simply use `wp_enqueue_script` and
         * `wp_enqueue_style` to add your functionality to the Gutenberg editor.
         *
         * @since 0.4.0
         */
        do_action('enqueue_block_editor_assets');

        // Remove Emoji fallback support
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
    }

    function gutenberg_get_available_image_sizes()
    {
        $size_names = apply_filters(
            'image_size_names_choose',
            array(
                'thumbnail' => __('Thumbnail', 'gutenberg'),
                'medium'    => __('Medium', 'gutenberg'),
                'large'     => __('Large', 'gutenberg'),
                'full'      => __('Full Size', 'gutenberg'),
            )
        );
        $all_sizes = array();
        foreach ($size_names as $size_slug => $size_name) {
            $all_sizes[] = array(
                'slug' => $size_slug,
                'name' => $size_name,
            );
        }
        return $all_sizes;
    }

    protected function renderPage()
    {
        $this->unloadOtherScripts();

        add_filter('body_class', function ($classes) {
            $classes .= ' feed_md_content';
            return $classes;
        });

        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <title>FluentCommuynity Block Editor</title>
            <meta charset='utf-8'>
            <meta name="viewport"
                  content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=0,viewport-fit=cover"/>
            <meta name="mobile-web-app-capable" content="yes">
            <meta name="robots" content="noindex">
            <?php wp_head(); ?>
            <?php do_action('fluent_community/block_editor_head'); ?>
        </head>
        <body class="fcom_custom_editor">
        <div class="wp-site-blocks">
            <div id="editor" class="gutenberg__editor"></div>
        </div>
        <?php wp_footer(); ?>
        </body>
        </html>
        <?php
    }

    private function unloadOtherScripts()
    {
        $isSkip = apply_filters('fluent_com_editor/skip_no_conflict', false);
        if ($isSkip) {
            return;
        }

        /**
         * Define the list of approved slugs for FluentCRM assets.
         *
         * This filter allows modification of the list of slugs that are approved for FluentCRM assets.
         *
         * @param array $approvedSlugs An array of approved slugs for FluentCRM assets.
         */
        $approvedSlugs = apply_filters('fluent_com_editor/asset_listed_slugs', [
            '\/gutenberg\/',
        ]);
        $approvedSlugs[] = 'fluent-community';
        $approvedSlugs = array_unique($approvedSlugs);
        $approvedSlugs = implode('|', $approvedSlugs);

        $pluginUrl = str_replace(['http:', 'https:'], '', plugins_url());

        $themesUrl = str_replace(['http:', 'https:'], '', get_theme_root_uri());

        add_filter('script_loader_src', function ($src, $handle) use ($approvedSlugs, $pluginUrl, $themesUrl) {
            if (!$src) {
                return $src;
            }

            $willSkip = (strpos($src, $pluginUrl) !== false) && !preg_match('/' . $approvedSlugs . '/', $src);
            if ($willSkip) {
                return false;
            }

            $willSkip = (strpos($src, $themesUrl) !== false) && !preg_match('/' . $approvedSlugs . '/', $src);

            if ($willSkip) {
                return false;
            }

            return $src;
        }, 1, 2);

        add_action('wp_print_scripts', function () use ($approvedSlugs, $pluginUrl, $themesUrl) {
            global $wp_scripts;
            if (!$wp_scripts) {
                return;
            }

            foreach ($wp_scripts->queue as $script) {
                if (empty($wp_scripts->registered[$script]) || empty($wp_scripts->registered[$script]->src)) {
                    continue;
                }

                $src = $wp_scripts->registered[$script]->src;
                $isMatched = (strpos($src, $pluginUrl) !== false) && !preg_match('/' . $approvedSlugs . '/', $src);
                if (!$isMatched) {
                    continue;
                }

                $isMatched = (strpos($src, $themesUrl) !== false) && !preg_match('/' . $approvedSlugs . '/', $src);
                if (!$isMatched) {
                    continue;
                }

                wp_dequeue_script($wp_scripts->registered[$script]->handle);
            }
        }, 1);

        add_action('wp_print_styles', function () {
            $isSkip = apply_filters('fluent_community/skip_no_conflict', false, 'styles');

            if ($isSkip) {
                return;
            }

            global $wp_styles;
            if (!$wp_styles) {
                return;
            }

            //    dd($wp_styles);

            $approvedSlugs = apply_filters('fluent_community/asset_listed_slugs', [
                '\/gutenberg\/',
            ]);

            $approvedSlugs[] = '\/fluent-community\/';

            $approvedSlugs = array_unique($approvedSlugs);
            $approvedSlugs = implode('|', $approvedSlugs);

            $pluginUrl = plugins_url();
            $themeUrl = get_theme_root_uri();

            $pluginUrl = str_replace(['http:', 'https:'], '', $pluginUrl);
            $themeUrl = str_replace(['http:', 'https:'], '', $themeUrl);

            foreach ($wp_styles->queue as $script) {

                if (empty($wp_styles->registered[$script]) || empty($wp_styles->registered[$script]->src)) {
                    continue;
                }

                $src = $wp_styles->registered[$script]->src;
                $pluginMatched = (strpos($src, $pluginUrl) !== false) && !preg_match('/' . $approvedSlugs . '/', $src);
                $themeMatched = (strpos($src, $themeUrl) !== false) && !preg_match('/' . $approvedSlugs . '/', $src);

                if (!$pluginMatched && !$themeMatched) {
                    continue;
                }

                wp_dequeue_style($wp_styles->registered[$script]->handle);
            }
        }, 999999);
    }
}
