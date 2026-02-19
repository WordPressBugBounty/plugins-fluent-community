<?php

namespace FluentCommunity\App\Hooks\Handlers;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Models\BaseSpace;
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

            register_post_type('fcom-lockscreen', [
                'label'        => 'Lockscreen',
                'public'       => false,
                'show_in_rest' => true,
                'supports'     => ['editor'],
            ]);

            if (!isset($_REQUEST['fluent_community_block_editor'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                return;
            }

            if (!defined('IFRAME_REQUEST')) {
                define('IFRAME_REQUEST', true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
            }

            remove_action('enqueue_block_editor_assets', 'wp_enqueue_editor_block_directory_assets');
            add_action('fluent_community/block_editor_head', function () {
                $url = FLUENT_COMMUNITY_PLUGIN_URL . 'Modules/Gutenberg/editor/index.css';
                ?>
                <link rel="stylesheet"
                      href="<?php echo esc_url($url); ?>?version=<?php echo esc_attr(FLUENT_COMMUNITY_PLUGIN_VERSION); ?>"
                      media="screen"/> <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet ?>
                <link rel="stylesheet"
                      href="<?php echo FLUENT_COMMUNITY_PLUGIN_URL . 'Modules/Gutenberg/editor/content_styling.css'; ?>?version=<?php echo esc_attr(FLUENT_COMMUNITY_PLUGIN_VERSION); ?>"
                      media="screen"/> <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet ?>

                <style>
                    <?php echo $this->getColorSchemaCss(); ?>
                </style>

                <?php
            });
            add_filter('should_load_separate_core_block_assets', '__return_false', 20);
            $this->renderCustomEditor($_REQUEST); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

            add_action('template_redirect', function () {
                $this->renderPage();
                exit(200);
            }, -1000);
        }, 2);
    }

    public function renderCustomEditor($data = [])
    {
        do_action('litespeed_control_set_nocache', 'fluentcommunity api request'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
        // set no cache headers
        nocache_headers();

        $hasAccess = false;
        $postType = 'fcom-dummy';
        $context = Arr::get($data, 'context');

        $lesson = null;

        if ($context === 'course_lesson') {
            $lessonId = Arr::get($data, 'lesson_id');
            if ($lessonId) {
                $lesson = CourseLesson::find($lessonId);
                $hasAccess = $lesson && $lesson->course && $lesson->course->isCourseAdmin();
            }
        }

        if ($context === 'lockscreen') {
            $postType = 'fcom-lockscreen';
            $spaceId = Arr::get($data, 'space_id');
            if ($spaceId) {
                $space = BaseSpace::query()->onlyMain()->find($spaceId);
                $hasAccess = $space && $space->isAdmin(get_current_user_id(), true);
            }
        }

        if (!$hasAccess) {
            echo '<h3 style="padding: 100px; text-align: center;">Sorry, you do not have access to this page.</h3>';
            exit(200);
        }

        add_filter('should_load_separate_core_block_assets', '__return_false', 20);
        show_admin_bar(false);

        $firstPost = Utility::getApp('db')->table('posts')
            ->where('post_type', $postType)
            ->first();

        if ($firstPost) {
            $simulatedPost = get_post($firstPost->ID);
            $simulatedPost->post_content = '<!-- wp:paragraph --><p> </p><!-- /wp:paragraph -->';
        } else {
            $newPostId = wp_insert_post(array(
                'post_title'   => $context === 'course_lesson' ? 'Demo Lesson Title' : '',
                'post_content' => '<!-- wp:paragraph --><p> </p><!-- /wp:paragraph -->',
                'post_type'    => $postType,
                'post_status'  => 'draft',
            ));

            $simulatedPost = get_post($newPostId);
        }

        global $post;
        $post = $simulatedPost;

        if ($lesson) {
            $post->post_title = $lesson->title;
            $post->post_content = $lesson->message ?: '<!-- wp:paragraph --><p> </p><!-- /wp:paragraph -->';
        }

        add_action('wp_enqueue_scripts', function () use ($post) {
            wp_enqueue_script('postbox', admin_url('js/postbox.min.js'), array('jquery-ui-sortable'), FLUENT_COMMUNITY_PLUGIN_VERSION, true);
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
                            '/wp/v2/' . $post->post_type . '/' . $post->ID . '?context=edit' => array(
                                'body' => array(
                                    'id'                 => $post->ID,
                                    'title'              => array('raw' => $post->post_title),
                                    'content'            => array(
                                        'block_format' => 1,
                                        'raw'          => $post->post_content,
                                    ),
                                    'excerpt'            => array('raw' => ''),
                                    'date'               => '',
                                    'date_gmt'           => '',
                                    'modified'           => '',
                                    'modified_gmt'       => '',
                                    'link'               => home_url('/'),
                                    'guid'               => array(),
                                    'parent'             => 0,
                                    'menu_order'         => 0,
                                    'author'             => 0,
                                    'featured_media'     => 0,
                                    'comment_status'     => 'closed',
                                    'ping_status'        => 'closed',
                                    'template'           => '',
                                    'meta'               => array(),
                                    '_links'             => array(),
                                    'type'               => $post->post_type,
                                    'status'             => 'pending', // pending is the best state to remove draft saving possibilities.
                                    'slug'               => '',
                                    'generated_slug'     => '',
                                    'permalink_template' => home_url('/'),
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

    private function gutenberg_editor_scripts_and_styles($hook, $post)
    {
        $initial_edits = array(
            'title'   => $post->post_title,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
        );

        $editor_settings = $this->getEditorSettings($post);

        $init_script =
            "(function() {
                window._wpLoadBlockEditor = new Promise(function(resolve) {
                    wp.domReady(function() {
                        resolve(wp.editPost.initializeEditor('editor', \"%s\", %d, %s, %s));
                    });
                });
            })();";

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

        // Include block styles needed when user is not signed in. See: https://github.com/WordPress/wporg-gutenberg/issues/26
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

        add_action('fluent_enqueue_block_editor_assets', 'wp_enqueue_editor_format_library_assets');

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
        do_action('fluent_enqueue_block_editor_assets');

        wp_enqueue_script('fcom_editor_custom', FLUENT_COMMUNITY_PLUGIN_URL . 'Modules/Gutenberg/editor/index.js', ['react', 'wp-components', 'wp-compose', 'wp-data', 'wp-edit-post', 'wp-i18n', 'wp-plugins'], FLUENT_COMMUNITY_PLUGIN_VERSION . time(), true);
        wp_localize_script('fcom_editor_custom', 'fcomEditorI18n', $this->getEditorI18nStrings());
    }

    private function getEditorI18nStrings()
    {
        $strings = [
            'Enable Video Embed'                                                    => __('Enable Video Embed', 'fluent-community'),
            'Enable Comments'                                                       => __('Enable Comments', 'fluent-community'),
            'Free Preview Lesson'                                                   => __('Free Preview Lesson', 'fluent-community'),
            'If enabled, public users can view this lesson without enrolling the course.' => __('If enabled, public users can view this lesson without enrolling the course.', 'fluent-community'),
            'Media Embed'                                                           => __('Media Embed', 'fluent-community'),
            'Documents & Files'                                                     => __('Documents & Files', 'fluent-community'),
            'Smartcodes'                                                            => __('Smartcodes', 'fluent-community'),
            'You may use following smartcode in your lesson:'                        => __('You may use following smartcode in your lesson:', 'fluent-community'),
            "User's Name:"                                                          => __("User's Name:", 'fluent-community'),
            "User's Email:"                                                         => __("User's Email:", 'fluent-community'),
            "User's photo HTML:"                                                    => __("User's photo HTML:", 'fluent-community'),
            "User's Profile Link:"                                                  => __("User's Profile Link:", 'fluent-community'),
            'Failed to fetch embed. Please check the URL.'                          => __('Failed to fetch embed. Please check the URL.', 'fluent-community'),
            'Video thumbnail'                                                       => __('Video thumbnail', 'fluent-community'),
            'Media embedded successfully'                                           => __('Media embedded successfully', 'fluent-community'),
            'Edit media'                                                            => __('Edit media', 'fluent-community'),
            'Oembed'                                                                => __('Oembed', 'fluent-community'),
            'Custom HTML'                                                           => __('Custom HTML', 'fluent-community'),
            'Custom HTML Code'                                                      => __('Custom HTML Code', 'fluent-community'),
            'Paste an iframe code'                                                  => __('Paste an iframe code', 'fluent-community'),
            'Embed'                                                                 => __('Embed', 'fluent-community'),
            'Paste a URL to embed'                                                  => __('Paste a URL to embed', 'fluent-community'),
            'Embed from Vimeo, YouTube, Wistia and more'                            => __('Embed from Vimeo, YouTube, Wistia and more', 'fluent-community'),
            'Lesson Duration'                                                       => __('Lesson Duration', 'fluent-community'),
            'Minutes'                                                               => __('Minutes', 'fluent-community'),
            'Seconds'                                                               => __('Seconds', 'fluent-community'),
            'View'                                                                  => __('View', 'fluent-community'),
            'No documents attached yet.'                                            => __('No documents attached yet.', 'fluent-community'),
            'Manage Documents & Files'                                              => __('Manage Documents & Files', 'fluent-community'),
            "User's Name"                                                           => __("User's Name", 'fluent-community'),
            "User's Email"                                                          => __("User's Email", 'fluent-community'),
            "User's photo HTML"                                                     => __("User's photo HTML", 'fluent-community'),
            'Profile Link'                                                          => __('Profile Link', 'fluent-community'),
        ];

        return apply_filters('fluent_community/editor_i18n_strings', $strings);
    }

    private function gutenberg_get_available_image_sizes()
    {
        $size_names = apply_filters(
            'fluent_community/image_size_names_choose',
            array(
                'thumbnail' => __('Thumbnail', 'fluent-community'),
                'medium'    => __('Medium', 'fluent-community'),
                'large'     => __('Large', 'fluent-community'),
                'full'      => __('Full Size', 'fluent-community'),
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
        add_action('fluent_community/block_editor_footer', function () {
            wp_underscore_playlist_templates();
            wp_print_footer_scripts();
            wp_print_media_templates();
            wp_enqueue_global_styles();
            wp_enqueue_stored_styles();
            wp_maybe_inline_styles();
        });

        add_action('fluent_block_editor/head', 'wp_enqueue_scripts', 1);
        add_action('fluent_block_editor/head', 'wp_resource_hints', 2);
        add_action('fluent_block_editor/head', 'wp_preload_resources', 1);
        add_action('fluent_block_editor/head', 'wp_print_styles', 8);
        add_action('fluent_block_editor/head', 'wp_print_head_scripts', 9);
        add_action('fluent_block_editor/head', 'wp_custom_css_cb', 101);

        $this->unloadOtherScripts();
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <title>FluentCommunity Block Editor</title>
            <meta charset='utf-8'>
            <meta name="viewport"
                  content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=0,viewport-fit=cover"/>
            <meta name="mobile-web-app-capable" content="yes">
            <meta name="robots" content="noindex">
            <?php do_action('fluent_block_editor/head'); ?>
            <?php do_action('fluent_community/block_editor_head'); ?>
        </head>
        <body class="fcom_custom_editor">
        <div class="wp-site-blocks">
            <div id="editor" class="gutenberg__editor"></div>
        </div>
        <?php
        do_action('fluent_community/block_editor_footer');
        ?>
        </body>
        </html>
        <?php
    }

    private function shouldBlockAsset(string $src, string $pluginUrl, string $themesUrl, string $approvedPattern): bool
    {
        $isPlugin = strpos($src, $pluginUrl) !== false;
        $isTheme = strpos($src, $themesUrl) !== false;

        if (!$isPlugin && !$isTheme) {
            return false;
        }

        return !preg_match('#' . $approvedPattern . '#', $src);
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
            '\/gutenberg\/'
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

            if ($this->shouldBlockAsset($src, $pluginUrl, $themesUrl, $approvedSlugs)) {
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
                if ($this->shouldBlockAsset($src, $pluginUrl, $themesUrl, $approvedSlugs)) {
                    wp_dequeue_script($wp_scripts->registered[$script]->handle);
                }
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
                $pluginMatched = (strpos($src, $pluginUrl) !== false) && !preg_match('#' . $approvedSlugs . '#', $src);
                $themeMatched = (strpos($src, $themeUrl) !== false) && !preg_match('#' . $approvedSlugs . '#', $src);

                if (!$pluginMatched && !$themeMatched) {
                    continue;
                }

                wp_dequeue_style($wp_styles->registered[$script]->handle);
            }
        }, 999999);
    }

    private function getEditorSettings($post)
    {
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
            'maxUploadFileSize'                => $max_upload_size,
            'allowedMimeTypes'                 => get_allowed_mime_types(),
            'postLock'                         => $lock_details,
            'postLockUtils'                    => array(
                'nonce'       => wp_create_nonce('lock-post_' . $post->ID),
                'unlockNonce' => wp_create_nonce('update-post_' . $post->ID),
                'ajaxUrl'     => admin_url('admin-ajax.php'),
            ),
            '__experimentalFeatures'           => $this->getExperimentalFeatures(),
            'colors'                           => $this->getColorPalette(),
            '__experimentalDiscussionSettings' => [
                'avatarURL'            => 'https://secure.gravatar.com/avatar/?s=96&f=y&r=g',
                'commentOrder'         => 'asc',
                'commentsPerPage'      => '50',
                'defaultCommentsPage'  => 'newest',
                'defaultCommentStatus' => 'open',
                'pageComments'         => '',
                'threadComments'       => '1',
                'threadCommentsDepth'  => '5'
            ],
            '__unstableGalleryWithImageBlocks' => false,
            '__unstableIsBlockBasedTheme'      => false,
            'enableCustomUnits'                => [
                'px',
                'em',
                'rem',
                '%',
                'vh',
                'vw'
            ],
            'fontSizes'                        => [
                [
                    'name' => 'Small',
                    'size' => 'var(--fcom-font-size-small)',
                    'slug' => 'small'
                ],
                [
                    'name' => 'Medium',
                    'size' => 'var(--fcom-font-size-medium)',
                    'slug' => 'medium'
                ],
                [
                    'name' => 'Large',
                    'size' => 'var(--fcom-font-size-large)',
                    'slug' => 'large'
                ],
                [
                    'name' => 'Larger',
                    'size' => 'var(--fcom-font-size-larger)',
                    'slug' => 'larger'
                ],
                [
                    'name' => 'XX-Large',
                    'size' => 'var(--fcom-font-size-xxlarge)',
                    'slug' => 'xxlarge'
                ]
            ],
            'fullscreenMode'                   => 1,
            'enableCustomSpacing'              => 1,
            'enableCustomLineHeight'           => 1,
            'enableCustomFields'               => false,
            'disablePostFormats'               => true,
            'disableLayoutStyles'              => false,
            'disableCustomSpacingSizes'        => false,
            'disableCustomGradients'           => 1,
            'alignWide'                        => true,
            'disableCustomFontSizes'           => false,
            'disableCustomColors'              => false,
            'canUpdateBlockBindings'           => false,
            'bodyPlaceholder'                  => __('Start writing or type / to choose a block for your lesson content', 'fluent-community'),
            'allowedBlockTypes'                => apply_filters('fluent_community/allowed_block_types', [
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
            ]),
            'gradients'                        => [],
            'imageDefaultSize'                 => 'large',
            'imageEditing'                     => true,
            'isRTL'                            => Helper::isRtl(),
            'autosaveInterval'                 => 999,
            'localAutosaveInterval'            => 999,
            'richEditingEnabled'               => true,
            'spacingSizes'                     => [
                [
                    'name' => '2X-Small',
                    'size' => '0.44rem',
                    'slug' => '20'
                ],
                [
                    'name' => 'X-Small',
                    'size' => '0.67rem',
                    'slug' => '30'
                ],
                [
                    'name' => 'Small',
                    'size' => '1rem',
                    'slug' => '40'
                ],
                [
                    'name' => 'Medium',
                    'size' => '1.5rem',
                    'slug' => '50'
                ],
                [
                    'name' => 'Large',
                    'size' => '2.25rem',
                    'slug' => '60'
                ],
                [
                    'name' => 'X-Large',
                    'size' => '3.38rem',
                    'slug' => '70'
                ],
                [
                    'name' => '2X-Large',
                    'size' => '5.06rem',
                    'slug' => '80'
                ]
            ],
            'titlePlaceholder'                 => __('Add Lesson title', 'fluent-community')
        );

        $editor_settings['styles'] = $this->getEditorStyles();
        $editor_settings['__unstableResolvedAssets'] = $this->getResolvedAssets();
        $editor_settings['defaultEditorStyles'] = $this->getDefaultEditorStyles();
        $editor_settings['imageSizes'] = $this->gutenberg_get_available_image_sizes();

        $editor_settings = apply_filters('fluent_community/block_editor_settings', $editor_settings);
        return $editor_settings;
    }

    private function getExperimentalFeatures()
    {
        return array(
            'appearanceTools'               => true,
            'useRootPaddingAwareAlignments' => false,
            'border'                        => [
                'color'  => 1,
                'radius' => 1,
                'style'  => 1,
                'width'  => 1,
            ],
            'color'                         => [
                'background'       => true,
                'button'           => 1,
                'caption'          => 1,
                'customDuotone'    => 0,
                'defaultDuotone'   => 0,
                'defaultGradients' => 0,
                'defaultPalette'   => [],
                'duotone'          => [],
                'gradients'        => [],
                'heading'          => 1,
                'link'             => 1,
                'palette'          => [
                    'default' => [],
                    'theme'   => [
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
                    ]
                ],
                'text'             => true,
            ],
            'dimensions'                    => [
                'defaultAspectRatios' => true,
                'aspectRatios'        => [
                    'default' => [
                        [
                            'name'  => 'Square - 1:1',
                            'slug'  => 'square',
                            'ratio' => '1',
                        ],
                        [
                            'name'  => 'Standard - 4:3',
                            'slug'  => '4-3',
                            'ratio' => '4/3',
                        ],
                        [
                            'name'  => 'Portrait - 3:4',
                            'slug'  => '3-4',
                            'ratio' => '3/4',
                        ],
                        [
                            'name'  => 'Classic - 3:2',
                            'slug'  => '3-2',
                            'ratio' => '3/2',
                        ],
                        [
                            'name'  => 'Classic Portrait - 2:3',
                            'slug'  => '2-3',
                            'ratio' => '2/3',
                        ],
                        [
                            'name'  => 'Wide - 16:9',
                            'slug'  => '16-9',
                            'ratio' => '16/9',
                        ],
                        [
                            'name'  => 'Tall - 9:16',
                            'slug'  => '9-16',
                            'ratio' => '9/16',
                        ],
                    ]
                ],
                'aspectRatio'         => 1,
                'minHeight'           => 1,
            ],
            'shadow'                        => [
                'defaultPresets' => true,
                'presets'        => [
                    'default' => [
                        [
                            'name'   => 'Natural',
                            'slug'   => 'natural',
                            'shadow' => '6px 6px 9px rgba(0, 0, 0, 0.2)',
                        ],
                        [
                            'name'   => 'Deep',
                            'slug'   => 'deep',
                            'shadow' => '12px 12px 50px rgba(0, 0, 0, 0.4)',
                        ],
                        [
                            'name'   => 'Sharp',
                            'slug'   => 'sharp',
                            'shadow' => '6px 6px 0px rgba(0, 0, 0, 0.2)',
                        ],
                        [
                            'name'   => 'Outlined',
                            'slug'   => 'outlined',
                            'shadow' => '6px 6px 0px -3px rgba(255, 255, 255, 1), 6px 6px rgba(0, 0, 0, 1)',
                        ],
                        [
                            'name'   => 'Crisp',
                            'slug'   => 'crisp',
                            'shadow' => '6px 6px 0px rgba(0, 0, 0, 1)',
                        ],
                    ],
                ],
            ],
            'spacing'                       => [
                'blockGap'            => 1,
                'margin'              => 1,
                'padding'             => 1,
                'defaultSpacingSizes' => true,
                'spacingScale'        => [
                    'default' => [
                        'operator'   => '*',
                        'increment'  => 1.5,
                        'steps'      => 7,
                        'mediumStep' => 1.5,
                        'unit'       => 'rem',
                    ],
                ],
                'spacingSizes'        => [
                    'default' => [
                        [
                            'name' => '2X-Small',
                            'slug' => '20',
                            'size' => '0.44rem',
                        ],
                        [
                            'name' => 'X-Small',
                            'slug' => '30',
                            'size' => '0.67rem',
                        ],
                        [
                            'name' => 'Small',
                            'slug' => '40',
                            'size' => '1rem',
                        ],
                        [
                            'name' => 'Medium',
                            'slug' => '50',
                            'size' => '1.5rem',
                        ],
                        [
                            'name' => 'Large',
                            'slug' => '60',
                            'size' => '2.25rem',
                        ],
                        [
                            'name' => 'X-Large',
                            'slug' => '70',
                            'size' => '3.38rem',
                        ],
                        [
                            'name' => '2X-Large',
                            'slug' => '80',
                            'size' => '5.06rem',
                        ],
                    ],
                ],
            ],
            'typography'                    => [
                'defaultFontSizes' => NULL,
                'dropCap'          => true,
                'fontSizes'        => [
                    'default' => [
                        [
                            'name' => 'Small',
                            'slug' => 'small',
                            'size' => '13px',
                        ],
                        [
                            'name' => 'Medium',
                            'slug' => 'medium',
                            'size' => '20px',
                        ],
                        [
                            'name' => 'Large',
                            'slug' => 'large',
                            'size' => '36px',
                        ],
                        [
                            'name' => 'Extra Large',
                            'slug' => 'x-large',
                            'size' => '42px',
                        ],
                    ],
                    'theme'   => [
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
                'fontStyle'        => true,
                'fontWeight'       => true,
                'letterSpacing'    => true,
                'textAlign'        => true,
                'textDecoration'   => true,
                'textTransform'    => true,
                'writingMode'      => false,
                'fluid'            => 0,
            ],
            'blocks'                        => [
                'core/button'    => [
                    'border' => [
                        'radius' => true,
                    ]
                ],
                'core/image'     => [
                    'lightbox' => [
                        'allowEditing' => true,
                    ]
                ],
                'core/pullquote' => [
                    'border' => [
                        'color'  => true,
                        'radius' => true,
                        'style'  => true,
                        'width'  => true,
                    ]
                ],
                'core/paragraph' => [
                    'spacing' => [
                        'margin'  => 1,
                        'padding' => 1,
                    ]
                ]
            ],
            'layout'                        => [
                'contentSize' => 'var(--theme-block-max-width)',
                'wideSize'    => 'var(--theme-block-wide-max-width)',
            ],
            'background'                    => [
                'backgroundImage' => 1,
                'backgroundSize'  => 1,
            ],
            'position'                      => [
                'sticky' => 0,
            ]
        );
    }

    private function getColorPalette()
    {
        return [
            [
                'color' => 'var(--fcom-primary-bg, #ffffff)',
                'name'  => 'Accent',
                'slug'  => 'theme-palette-color-1'
            ],
            [
                'color' => 'var(--fcom-secondary-bg, #f0f2f5)',
                'name'  => 'Accent - alt',
                'slug'  => 'theme-palette-color-2'
            ],
            [
                'color' => 'var(--fcom-secondary-text, #525866)',
                'name'  => 'Strongest text',
                'slug'  => 'theme-palette-color-3'
            ],
            [
                'color' => 'var(--fcom-secondary-content-bg, #f0f3f5)',
                'name'  => 'Strong Text',
                'slug'  => 'theme-palette-color-4'
            ],
            [
                'color' => 'var(--fcom-active-bg, #f0f3f5)',
                'name'  => 'Medium text',
                'slug'  => 'theme-palette-color-5'
            ],
            [
                'color' => 'var(--fcom-light-bg, #E1E4EA)',
                'name'  => 'Subtle Text',
                'slug'  => 'theme-palette-color-6'
            ],
            [
                'color' => 'var(--fcom-deep-bg, #E1E4EA)',
                'name'  => 'Subtle Background',
                'slug'  => 'theme-palette-color-7'
            ],
            [
                'color' => 'var(--fcom-primary-text, #19283a)',
                'name'  => 'Lighter Background',
                'slug'  => 'theme-palette-color-8'
            ]
        ];
    }

    private function getEditorStyles()
    {
        $editorDir = FLUENT_COMMUNITY_PLUGIN_DIR . 'Modules/Gutenberg/editor/';

        return [
            [
                '__unstableType' => 'colorSchema',
                'css'            => $this->getColorSchemaCss(),
                'isGlobalStyles' => true
            ],
            [
                '__unstableType' => 'theme',
                'css'            => file_get_contents($editorDir . 'editor-iframe-styles.css') ?: '',
                'isGlobalStyles' => true
            ],
            [
                'css'            => file_get_contents($editorDir . 'editor.css') ?: '',
                '__unstableType' => 'user'
            ]
        ];
    }

    private function getResolvedAssets()
    {
        $resolvedStyles = [
            'wp-components-css'           => includes_url('/css/dist/components/style.min.css'),
            'wp-preferences-css'          => includes_url('/css/dist/preferences/style.min.css'),
            'wp-block-editor-css'         => includes_url('/css/dist/block-editor/style.min.css'),
            'wp-reusable-blocks-css'      => includes_url('/css/dist/reusable-blocks/style.min.css'),
            'wp-patterns-css'             => includes_url('/css/dist/patterns/style.min.css'),
            'wp-editor-css'               => includes_url('/css/dist/editor/style.min.css'),
            'wp-block-library-css'        => includes_url('/css/dist/block-library/style.min.css'),
            'wp-block-editor-content-css' => includes_url('/css/dist/block-editor/content.min.css'),
            'wp-edit-blocks-css'          => includes_url('/css/dist/block-library/editor.min.css'),
            'fcom-content-styling'        => FLUENT_COMMUNITY_PLUGIN_URL . 'Modules/Gutenberg/editor/content_styling.css'
        ];

        global $wp_version;
        $cssFiles = '';
        foreach ($resolvedStyles as $name => $file) {
            $cssFiles .= "<link rel='stylesheet' id='{$name}' href='{$file}?ver={$wp_version}' media='all' />\n"; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
        }

        return [
            'scripts' => '<script src="' . includes_url('/js/dist/vendor/wp-polyfill.min.js?ver=3.15.0') . '" id="wp-polyfill-js"></script>', // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
            'styles'  => $cssFiles
        ];
    }

    private function getDefaultEditorStyles()
    {
        return [
            [
                '__unstableType' => 'user',
                'css'            => ':root{--wp-admin-theme-color:#007cba;--wp-admin-theme-color--rgb:0, 124, 186;--wp-admin-theme-color-darker-10:#006ba1;--wp-admin-theme-color-darker-10--rgb:0, 107, 161;--wp-admin-theme-color-darker-20:#005a87;--wp-admin-theme-color-darker-20--rgb:0, 90, 135;--wp-admin-border-width-focus:2px;--wp-block-synced-color:#7a00df;--wp-block-synced-color--rgb:122, 0, 223;--wp-bound-block-color:var(--wp-block-synced-color);}@media (min-resolution:192dpi){:root{--wp-admin-border-width-focus:1.5px;}}body{font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Oxygen-Sans,Ubuntu,Cantarell,Helvetica Neue,sans-serif;font-size:18px;line-height:1.5;--wp--style--block-gap:2em;}p{line-height:1.8;}.editor-post-title__block{font-size:2.5em;font-weight:800;margin-bottom:1em;margin-top:2em;}'
            ]
        ];
    }

    private function getColorSchemaCss()
    {
        $colorSchema = Utility::getColorSchemaConfig();

        $darkSchemaConfig = Arr::get($colorSchema, 'dark');

        $colorSchemaCss = ':root {';
        foreach (Arr::get($darkSchemaConfig, 'body', []) as $colorKey => $value) {
            if ($value) {
                $cssVar = ' --fcom-' . str_replace('_', '-', $colorKey);
                $colorSchemaCss .= $cssVar . ':' . $value . '; ';
            }
        }
        $colorSchemaCss .= '}';

        return $colorSchemaCss;
    }
}
