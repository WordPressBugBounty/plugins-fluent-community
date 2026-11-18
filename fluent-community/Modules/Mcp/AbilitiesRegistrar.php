<?php

namespace FluentCommunity\Modules\Mcp;

use FluentCommunity\App\Services\Helper;
use FluentCommunity\Framework\Support\Arr;
use FluentCommunity\Modules\Mcp\Support\McpGate;
use FluentCommunity\Modules\Mcp\Support\McpHelper;
use FluentCommunity\Modules\Mcp\Support\ProTools;
use FluentCommunity\Modules\Mcp\Tools\ContextTools;
use FluentCommunity\Modules\Mcp\Tools\CourseTools;
use FluentCommunity\Modules\Mcp\Tools\MemberTools;
use FluentCommunity\Modules\Mcp\Tools\ModerationTools;
use FluentCommunity\Modules\Mcp\Tools\PostTools;
use FluentCommunity\Modules\Mcp\Tools\SearchTools;

defined('ABSPATH') || exit;

/**
 * Labels, descriptions, schema text and errors are plain English with no
 * translation call: an agent reads them, and a translated description makes the
 * tool worse on a non-English site (Fluent MCP standard, rule 6). Keep each
 * description under 300 characters, with a first sentence that is complete
 * within 160; rules shared by several tools belong in McpModule::serverInstructions().
 *
 * Definition keys beyond the Abilities API ones:
 *   requires  manager|moderator|site_admin|course_creator (see McpGate)
 *   feature   a feature flag that must be on, e.g. course_module
 *   pro       true for a tool FluentCommunity Pro adds (filter fluent_community/mcp/tools);
 *             the settings screen counts them
 *   upsell    a stub for a Pro tool on a site without Pro (Support/ProTools); a call
 *             answers with how to get Pro
 */
class AbilitiesRegistrar
{
    const CATEGORY = 'fluent-community';

    private static $definitions = null;

    public static function getDefinitions()
    {
        if (self::$definitions !== null) {
            return self::$definitions;
        }

        $page = [ 'type' => 'integer', 'default' => 1 ];
        $readOnly = [ 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'openWorldHint' => false ];
        $notifies = [ 'readonly' => false, 'destructive' => false, 'idempotent' => false, 'openWorldHint' => true ];

        $definitions = [
            'fluent-community/get-community-context' => [
                'label'            => 'Get Community Context',
                'description'      => 'Get who you are, the spaces, courses and topics with their ids and slugs, and what needs attention; call it first, once. Also returns what is new since your last visit (activity.last_seen_at), unread mentions and, with Pro, the Insights card keys.',
                // An empty array, not an object: WordPress core's validator indexes properties as an array.
                'input_schema'     => [ 'type' => 'object', 'properties' => [] ],
                'execute_callback' => [ ContextTools::class, 'getContext' ],
                'annotations'      => [ 'readonly' => true, 'destructive' => false, 'idempotent' => false, 'openWorldHint' => false ],
                'requires'         => 'manager',
            ],
            'fluent-community/search-community' => [
                'label'            => 'Search Community',
                'description'      => 'Search posts, comments, people, spaces and courses in one call. Results are grouped by type.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'q'        => [ 'type' => 'string', 'description' => 'At least 2 characters.' ],
                        'types'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'posts, comments, people, spaces, courses. Default: all.' ],
                        'space'    => [ 'type' => 'string', 'description' => 'Space slug.' ],
                        'sort'     => [ 'type' => 'string', 'enum' => [ 'relevance', 'latest', 'popular' ] ],
                        'per_type' => [ 'type' => 'integer', 'default' => 5, 'description' => 'Max 20.' ],
                        'page'     => $page,
                    ],
                    'required'   => [ 'q' ],
                ],
                'execute_callback' => [ SearchTools::class, 'search' ],
                'annotations'      => $readOnly,
                'requires'         => 'manager',
            ],
            'fluent-community/list-posts' => [
                'label'            => 'List Posts',
                'description'      => 'List posts with author, space, counts and an excerpt. view=full adds the body and the newest 3 comments, so one call catches up. since returns posts created or commented on after that time and counts the new comments.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'view'     => [ 'type' => 'string', 'enum' => [ 'compact', 'full' ], 'default' => 'compact', 'description' => 'compact: 200-character excerpt, 20 per page. full: body and comments, 10 per page.' ],
                        'since'    => [ 'type' => 'string', 'description' => 'ISO 8601 time, usually activity.last_seen_at.' ],
                        'filter'   => [ 'type' => 'string', 'enum' => [ 'unanswered', 'mentions_me' ] ],
                        'space'    => [ 'type' => 'string', 'description' => 'Space slug.' ],
                        'user_id'  => [ 'type' => 'integer', 'description' => 'Author.' ],
                        'topic'    => [ 'type' => 'string', 'description' => 'Topic slug.' ],
                        'search'   => [ 'type' => 'string' ],
                        'order_by' => [ 'type' => 'string', 'enum' => [ 'latest', 'new_activity', 'oldest', 'popular', 'likes' ], 'description' => 'Default: new_activity with since, else latest.' ],
                        'page'     => $page,
                        'per_page' => [ 'type' => 'integer' ],
                    ],
                ],
                'execute_callback' => [ PostTools::class, 'listPosts' ],
                'annotations'      => $readOnly,
                'requires'         => 'manager',
            ],
            'fluent-community/get-post' => [
                'label'            => 'Get Post',
                'description'      => 'Read full posts as Markdown with their comments. One post returns 20 comments per page, page 1 the newest. With ids (max 10), each post returns its newest 10 comments.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'id'             => [ 'type' => 'integer' ],
                        'slug'           => [ 'type' => 'string' ],
                        'ids'            => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'maxItems' => 10 ],
                        'comments_page'  => $page,
                        'comments_since' => [ 'type' => 'string', 'description' => 'ISO 8601 time. Only newer comments.' ],
                    ],
                ],
                'execute_callback' => [ PostTools::class, 'getPost' ],
                'annotations'      => $readOnly,
                'requires'         => 'manager',
            ],
            'fluent-community/list-members' => [
                'label'            => 'List Members',
                'description'      => 'List members with status, join date and last activity. With space, lists that space\'s members and their role. inactive_days and has_posted find members to re-engage.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'search'        => [ 'type' => 'string' ],
                        'status'        => [ 'type' => 'string', 'enum' => [ 'active', 'pending', 'blocked', 'deactivated' ], 'default' => 'active' ],
                        'space'         => [ 'type' => 'string', 'description' => 'Space slug.' ],
                        'inactive_days' => [ 'type' => 'integer', 'description' => 'No activity for this many days.' ],
                        'has_posted'    => [ 'type' => 'boolean', 'description' => 'false: members who never posted.' ],
                        'sort_by'       => [ 'type' => 'string', 'enum' => [ 'last_activity', 'display_name', 'created_at' ] ],
                        'sort_dir'      => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ] ],
                        'page'          => $page,
                        'per_page'      => [ 'type' => 'integer', 'default' => 20, 'description' => 'Max 50.' ],
                    ],
                ],
                'execute_callback' => [ MemberTools::class, 'listMembers' ],
                'annotations'      => $readOnly,
                'requires'         => 'manager',
            ],
            'fluent-community/get-member' => [
                'label'            => 'Get Member',
                'description'      => 'Read one member: profile, status, points and badges. include adds their spaces with roles, recent posts or recent comments.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'id'       => [ 'type' => 'integer' ],
                        'username' => [ 'type' => 'string' ],
                        'include'  => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'enum' => [ 'spaces', 'recent_posts', 'recent_comments' ] ] ],
                    ],
                ],
                'execute_callback' => [ MemberTools::class, 'getMember' ],
                'annotations'      => $readOnly,
                'requires'         => 'manager',
            ],
            'fluent-community/save-post' => [
                'label'            => 'Save Post',
                'description'      => 'Create a post, or update one when id is given. You are the author. A new post is published at once and notifies members. On update, only the fields you pass change.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'id'                      => [ 'type' => 'integer', 'description' => 'Post to update.' ],
                        'space'                   => [ 'type' => 'string', 'description' => 'Space slug. New posts only.' ],
                        'title'                   => [ 'type' => 'string' ],
                        'message'                 => [ 'type' => 'string', 'description' => 'Markdown. Mention a member as @username.' ],
                        'topic_ids'               => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                        'send_announcement_email' => [ 'type' => 'boolean', 'description' => 'New posts, moderators only: email every space member.' ],
                        'images'                  => [
                            'type'        => 'array',
                            'items'       => [ 'type' => 'integer' ],
                            'description' => 'Image ids, in the order shown: from upload-image, or ones the post has (get-post lists them). On update the list replaces the images; leave it out to keep them, pass [] to remove them.',
                        ],
                    ],
                ],
                'execute_callback' => [ PostTools::class, 'savePost' ],
                'annotations'      => $notifies,
                'requires'         => 'manager',
            ],
            'fluent-community/save-comment' => [
                'label'            => 'Save Comment',
                'description'      => 'Add a comment to a post, reply to a comment with reply_to, or update a comment when id is given. It is posted at once under your name and notifies the post author and other commenters.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'post_id'  => [ 'type' => 'integer' ],
                        'comment'  => [ 'type' => 'string', 'description' => 'Markdown. Mention a member as @username.' ],
                        'reply_to' => [ 'type' => 'integer', 'description' => 'Comment id to reply to.' ],
                        'id'       => [ 'type' => 'integer', 'description' => 'Comment to update.' ],
                        'images'   => [
                            'type'        => 'array',
                            'items'       => [ 'type' => 'integer' ],
                            'description' => 'Image ids from upload-image, or ones the comment has. On update the list replaces them; leave it out to keep them.',
                        ],
                    ],
                    'required'   => [ 'comment' ],
                ],
                'execute_callback' => [ PostTools::class, 'saveComment' ],
                'annotations'      => $notifies,
                'requires'         => 'manager',
            ],
            'fluent-community/upload-image' => [
                'label'            => 'Upload Image',
                'description'      => 'Upload one image for a post or comment, as the composer does. Pass url (a public http/https image the site downloads) or data (base64, optional filename). JPEG, PNG, GIF or WebP. Returns its id: pass it in images of save-post or save-comment within two hours.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'url'      => [ 'type' => 'string' ],
                        'data'     => [ 'type' => 'string', 'description' => 'Base64 image bytes. A data: URI prefix is allowed.' ],
                        'filename' => [ 'type' => 'string', 'description' => 'With data. The site sets the extension from the image type.' ],
                    ],
                ],
                'execute_callback' => [ PostTools::class, 'uploadImage' ],
                'annotations'      => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false, 'openWorldHint' => true ],
                'requires'         => 'manager',
            ],
            'fluent-community/like-content' => [
                'label'            => 'Like Post or Comment',
                'description'      => 'Like one post or one comment as yourself. remove=true takes the like back. A like notifies the author.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'post_id'    => [ 'type' => 'integer' ],
                        'comment_id' => [ 'type' => 'integer' ],
                        'remove'     => [ 'type' => 'boolean', 'default' => false ],
                    ],
                ],
                'execute_callback' => [ PostTools::class, 'likeContent' ],
                'annotations'      => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true, 'openWorldHint' => true ],
                'requires'         => 'manager',
            ],
            'fluent-community/moderate-content' => [
                'label'            => 'Moderate Content',
                'description'      => 'Pin, unpin, lock or move posts, or pin a comment; every action can be reversed. disable_comments locks a post\'s comments.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'action'     => [ 'type' => 'string', 'enum' => [ 'pin', 'unpin', 'disable_comments', 'enable_comments', 'move' ] ],
                        'post_id'    => [ 'type' => 'integer' ],
                        'comment_id' => [ 'type' => 'integer' ],
                        'to_space'   => [ 'type' => 'string', 'description' => 'Target space slug, for move.' ],
                    ],
                    'required'   => [ 'action' ],
                ],
                'execute_callback' => [ ModerationTools::class, 'moderateContent' ],
                'annotations'      => $notifies,
                'requires'         => 'moderator',
            ],
            'fluent-community/delete-content' => [
                'label'            => 'Delete Post or Comment',
                'description'      => 'Delete one post with its comments, or one comment. This cannot be undone. Call with dry_run=true first to see what would be deleted.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'post_id'    => [ 'type' => 'integer' ],
                        'comment_id' => [ 'type' => 'integer' ],
                        'dry_run'    => [ 'type' => 'boolean', 'default' => false ],
                    ],
                ],
                'execute_callback' => [ ModerationTools::class, 'deleteContent' ],
                'annotations'      => [ 'readonly' => false, 'destructive' => true, 'idempotent' => true, 'openWorldHint' => false ],
                'requires'         => 'manager',
            ],
            'fluent-community/list-courses' => [
                'label'            => 'List Courses',
                'description'      => 'List the courses you manage, with status and the number of students, sections and lessons.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'search'   => [ 'type' => 'string' ],
                        'status'   => [ 'type' => 'string', 'enum' => [ 'published', 'draft' ] ],
                        'page'     => $page,
                        'per_page' => [ 'type' => 'integer', 'default' => 20, 'description' => 'Max 50.' ],
                    ],
                ],
                'execute_callback' => [ CourseTools::class, 'listCourses' ],
                'annotations'      => $readOnly,
                'requires'         => 'course_creator',
                'feature'          => 'course_module',
            ],
            'fluent-community/get-course' => [
                'label'            => 'Get Course',
                'description'      => 'Read a course outline: sections and lessons with their ids and status. With lesson_id, returns that lesson\'s blocks instead, each with its index and exact markup, and a version for update-course-content edits.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'id'        => [ 'type' => 'integer' ],
                        'slug'      => [ 'type' => 'string' ],
                        'lesson_id' => [ 'type' => 'integer' ],
                        'format'    => [ 'type' => 'string', 'enum' => [ 'blocks', 'markdown' ], 'default' => 'blocks', 'description' => 'With lesson_id. markdown returns a read-only summary instead of the blocks.' ],
                    ],
                ],
                'execute_callback' => [ CourseTools::class, 'getCourse' ],
                'annotations'      => $readOnly,
                'requires'         => 'course_creator',
                'feature'          => 'course_module',
            ],
            'fluent-community/get-block-guide' => [
                'label'            => 'Get Block Editor Guide',
                'description'      => 'Get the rules and exact examples for lesson content written as WordPress block editor (Gutenberg) markup, and the official reference links. Call it before you send content_format=blocks. With blocks, also returns the attributes of those blocks on this site.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'blocks' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'maxItems' => 10, 'description' => 'Block names such as core/embed. Omit for every example.' ],
                    ],
                ],
                'execute_callback' => [ CourseTools::class, 'getBlockGuide' ],
                'annotations'      => $readOnly,
                'requires'         => 'course_creator',
                'feature'          => 'course_module',
            ],
            'fluent-community/create-course-content' => [
                'label'            => 'Create Course Content',
                'description'      => 'Create a course with its sections and lessons in one call, or add sections and lessons to a course (course_id). Everything is a draft. Send the outline first; add long lesson bodies with update-course-content. Max 20 sections, 100 lessons.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'course'    => [
                            'type'        => 'object',
                            'description' => 'New course. title, description, privacy (public, private, secret) and course_type (self_paced, structured, scheduled) are required; details is optional Markdown.',
                        ],
                        'course_id' => [ 'type' => 'integer', 'description' => 'Add to this course instead of creating one.' ],
                        'sections'  => [
                            'type'        => 'array',
                            'description' => 'Each: {title} for a new section or {id} for an existing one; optional unlock_at (scheduled course) or unlock_after_days (structured course); lessons: [{title, content, content_format}]; content is Markdown, or block editor markup with content_format=blocks.',
                            'items'       => [ 'type' => 'object' ],
                        ],
                        'dry_run'   => [ 'type' => 'boolean', 'default' => false, 'description' => 'Validate and count only.' ],
                    ],
                ],
                'execute_callback' => [ CourseTools::class, 'createContent' ],
                'annotations'      => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false, 'openWorldHint' => false ],
                'requires'         => 'course_creator',
                'feature'          => 'course_module',
            ],
            'fluent-community/update-course-content' => [
                'label'            => 'Update Course Content',
                'description'      => 'Change one course, section or lesson. Only the fields you pass change. status=published makes it visible to students. A lesson takes edits (change its blocks by index; others stay as they are), content (whole body) and move_to_section.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'type'              => [ 'type' => 'string', 'enum' => [ 'course', 'section', 'lesson' ] ],
                        'id'                => [ 'type' => 'integer' ],
                        'title'             => [ 'type' => 'string' ],
                        'status'            => [ 'type' => 'string', 'enum' => [ 'draft', 'published', 'archived' ] ],
                        'edits'             => [
                            'type'        => 'array',
                            'description' => 'Lesson: changes to its blocks, by the index get-course gave. {index, markup} or {index, markdown} replaces a block, {index, delete: true} removes it, {after, markup|markdown} inserts (after -1 is the start). Needs version.',
                            'items'       => [ 'type' => 'object' ],
                        ],
                        'version'           => [ 'type' => 'string', 'description' => 'Lesson: the version from get-course. A lesson changed since then is not saved.' ],
                        'content'           => [ 'type' => 'string', 'description' => 'Lesson body. Replaces the whole body; prefer edits for an existing lesson. For a quiz lesson: its Markdown intro (questions stay in the course editor).' ],
                        'content_format'    => [ 'type' => 'string', 'enum' => [ 'markdown', 'blocks' ], 'default' => 'markdown', 'description' => 'blocks: content is block editor (Gutenberg) markup, saved as sent.' ],
                        'replace_layout'    => [ 'type' => 'boolean', 'default' => false, 'description' => 'Markdown only: also drop the embeds, columns or buttons the lesson has. Without it such a lesson is not changed.' ],
                        'move_to_section'   => [ 'type' => 'integer', 'description' => 'Lesson: target section id.' ],
                        'description'       => [ 'type' => 'string', 'description' => 'Course only.' ],
                        'privacy'           => [ 'type' => 'string', 'enum' => [ 'public', 'private', 'secret' ], 'description' => 'Course only.' ],
                        'unlock_at'         => [ 'type' => 'string', 'description' => 'Section in a scheduled course: ISO 8601 time.' ],
                        'unlock_after_days' => [ 'type' => 'integer', 'description' => 'Section in a structured course.' ],
                    ],
                    'required'   => [ 'type', 'id' ],
                ],
                'execute_callback' => [ CourseTools::class, 'updateContent' ],
                'annotations'      => $notifies,
                'requires'         => 'course_creator',
                'feature'          => 'course_module',
            ],
        ];

        $definitions = array_merge($definitions, self::addOnDefinitions($definitions));

        // Without Pro, its tools are listed as stubs that answer with how to get Pro.
        self::$definitions = array_merge($definitions, ProTools::stubs($definitions));

        return self::$definitions;
    }

    /**
     * Tools that FluentCommunity Pro (or another add-on) puts on this server. They use the
     * same definition format, so free runs every check for them: the gate, the feature,
     * the role, the parameters, and the per-user tool list. A definition that would replace
     * a free tool, or misses a key, is left out.
     *
     * @param array $core The free plugin's definitions.
     * @return array
     */
    private static function addOnDefinitions(array $core)
    {
        /**
         * Add MCP tools to the fluent-community server.
         *
         * @param array $definitions Ability name (fluent-community/...) => definition: label,
         *                           description, input_schema, execute_callback, annotations,
         *                           requires, and optional feature and pro.
         */
        $added = apply_filters('fluent_community/mcp/tools', []);

        $valid = [];
        foreach ((array) $added as $name => $definition) {
            if (!is_string($name) || strpos($name, self::CATEGORY . '/') !== 0 || isset($core[$name]) || !is_array($definition)) {
                continue;
            }

            if (empty($definition['label']) || empty($definition['description']) || !is_array(Arr::get($definition, 'input_schema'))
                || !is_callable(Arr::get($definition, 'execute_callback')) || !in_array(Arr::get($definition, 'requires'), McpGate::REQUIREMENTS, true)
            ) {
                continue;
            }

            $definition['annotations'] = (array) Arr::get($definition, 'annotations', []);
            $valid[$name] = $definition;
        }

        return $valid;
    }

    public static function register()
    {
        // A public callback: anything can call it. The Abilities API arrives in
        // WordPress 6.9, and the plugin supports 6.0.
        if (!function_exists('wp_register_ability')) {
            return;
        }

        foreach (self::getDefinitions() as $name => $definition) {
            wp_register_ability($name, [
                'label'               => $definition['label'],
                'description'         => $definition['description'],
                'category'            => self::CATEGORY,
                'input_schema'        => $definition['input_schema'],
                'execute_callback'    => function ($params) use ($name) {
                    return self::run($name, $params);
                },
                'permission_callback' => 'is_user_logged_in',
                'meta'                => [
                    'show_in_rest' => true,
                    'mcp'          => [ 'public' => true ],
                    'annotations'  => $definition['annotations'],
                ],
            ]);
        }
    }

    /**
     * Tool names for this site, or for one user. A tool whose feature is off, or
     * that the user cannot run, is left out so it costs no context.
     *
     * @param \FluentCommunity\App\Models\User|null $user Null gives every tool the site has on.
     * @param bool $withStubs False leaves out the Pro stubs, for counting real tools.
     * @return string[]
     */
    public static function exposedNames($user = null, $withStubs = true)
    {
        $names = [];

        foreach (self::getDefinitions() as $name => $definition) {
            if (!$withStubs && !empty($definition['upsell'])) {
                continue;
            }

            if (self::isAvailable($definition) && (!$user || McpGate::allows($definition['requires'], $user))) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Whether the site has this tool: it exists (a Pro tool only while Pro adds it, not as a stub) and its feature is on.
     *
     * @param string $name Ability name, e.g. fluent-community/get-insights.
     * @return bool
     */
    public static function hasTool($name)
    {
        $definitions = self::getDefinitions();

        return isset($definitions[$name]) && empty($definitions[$name]['upsell']) && self::isAvailable($definitions[$name]);
    }

    private static function isAvailable($definition)
    {
        return empty($definition['feature']) || Helper::isFeatureEnabled($definition['feature']);
    }

    /**
     * Runs one tool. Every check an ability needs is here, so a refusal reaches
     * the agent as a structured error instead of the adapter's plain
     * "Permission denied".
     *
     * @param string $name   Ability name, e.g. fluent-community/list-posts.
     * @param mixed  $params
     * @return array|\WP_Error
     */
    public static function run($name, $params)
    {
        $definitions = self::getDefinitions();

        if (!isset($definitions[$name])) {
            return McpHelper::error('not_found', 'Unknown tool.');
        }

        $definition = $definitions[$name];
        McpHelper::resetWarnings();

        $gate = McpGate::transport();
        if (is_wp_error($gate)) {
            return McpHelper::error('forbidden', $gate->get_error_message());
        }

        if (!self::isAvailable($definition)) {
            return McpHelper::error('module_disabled', 'This tool is switched off on this site.');
        }

        if (!McpGate::allows($definition['requires'])) {
            return McpHelper::error('forbidden', 'Your community role cannot use this tool.');
        }

        if (!empty($definition['upsell'])) {
            return ProTools::upgradeAnswer($name);
        }

        $params = McpHelper::validateParams($params, $definition['input_schema']);
        if (is_wp_error($params)) {
            return $params;
        }

        try {
            return call_user_func($definition['execute_callback'], $params);
        } catch (\Throwable $exception) {
            return McpHelper::error('internal_error', 'The tool failed. Try again.', array_filter([
                'retryable' => true,
                'hint'      => defined('WP_DEBUG') && WP_DEBUG ? $exception->getMessage() : null,
            ]));
        }
    }
}
