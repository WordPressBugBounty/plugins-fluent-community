<?php

namespace FluentCommunity\Modules\Mcp\Tools;

use FluentCommunity\Framework\Support\Arr;
use FluentCommunity\Modules\Course\Model\Course;
use FluentCommunity\Modules\Course\Model\CourseLesson;
use FluentCommunity\Modules\Course\Model\CourseTopic;
use FluentCommunity\Modules\Mcp\Support\BlockGuide;
use FluentCommunity\Modules\Mcp\Support\LessonBlocks;
use FluentCommunity\Modules\Mcp\Support\LessonContent;
use FluentCommunity\Modules\Mcp\Support\McpHelper;
use FluentCommunity\Modules\Mcp\Support\RestBridge;

defined('ABSPATH') || exit;

class CourseTools
{
    const MAX_SECTIONS = 20;

    const MAX_LESSONS = 100;

    const MAX_CONTENT_BYTES = 200000;

    const COURSE_TYPES = [ 'self_paced', 'structured', 'scheduled' ];

    const PRIVACY_TYPES = [ 'public', 'private', 'secret' ];

    public static function listCourses($params)
    {
        $paging = McpHelper::pagination($params, 20, 50);

        $result = RestBridge::call('GET', '/admin/courses', array_filter([
            'search'   => sanitize_text_field((string) Arr::get($params, 'search', '')),
            'status'   => Arr::get($params, 'status'),
            'page'     => $paging['page'],
            'per_page' => $paging['per_page'],
        ]));

        if (is_wp_error($result)) {
            return $result;
        }

        $rows = [];
        foreach ((array) Arr::get($result, 'courses.data', []) as $course) {
            $rows[] = [
                'id'       => (int) Arr::get($course, 'id'),
                'slug'     => Arr::get($course, 'slug'),
                'title'    => Arr::get($course, 'title'),
                'status'   => Arr::get($course, 'status'),
                'privacy'  => Arr::get($course, 'privacy'),
                'type'     => Arr::get($course, 'settings.course_type'),
                'students' => (int) Arr::get($course, 'students_count', 0),
                'sections' => (int) Arr::get($course, 'sectionsCount', 0),
                'lessons'  => (int) Arr::get($course, 'lessonsCount', 0),
            ];
        }

        $total = (int) Arr::get($result, 'courses.total', count($rows));

        return McpHelper::envelope(sprintf('%d of %d courses, page %d.', count($rows), $total, $paging['page']), [ 'courses' => $rows ], [
            'paging' => [
                'total'    => $total,
                'page'     => $paging['page'],
                'per_page' => $paging['per_page'],
                'has_more' => $paging['page'] < (int) Arr::get($result, 'courses.last_page', 1),
            ],
        ]);
    }

    public static function getCourse($params)
    {
        $courseId = self::resolveCourseId($params);
        if (is_wp_error($courseId)) {
            return $courseId;
        }

        $lessonId = (int) Arr::get($params, 'lesson_id', 0);

        if ($lessonId) {
            $result = RestBridge::call('GET', '/admin/courses/' . $courseId . '/lessons/' . $lessonId);
            if (is_wp_error($result)) {
                return $result;
            }

            $lesson = (array) Arr::get($result, 'lesson', []);
            $stored = (string) Arr::get($lesson, 'message', '');
            $isQuiz = Arr::get($lesson, 'content_type') === 'quiz';
            // A quiz lesson's intro is Markdown (Pro renders it with mdToHtml), not block markup.
            $format = $isQuiz ? 'markdown' : Arr::get($params, 'format', 'blocks');
            $layoutBlocks = ($isQuiz || $format === 'blocks') ? [] : LessonContent::layoutBlocks($stored);

            $data = [
                'id'             => $lessonId,
                'course_id'      => $courseId,
                'section_id'     => (int) Arr::get($lesson, 'parent_id'),
                'title'          => Arr::get($lesson, 'title'),
                'status'         => Arr::get($lesson, 'status'),
                'type'           => $isQuiz ? 'quiz' : 'lesson',
                'content_format' => $format,
                'url'            => self::lessonUrl($lessonId),
                'version'        => LessonBlocks::version($stored),
            ];

            if ($format === 'blocks') {
                // The stored blocks, each with its exact markup; edit them by index with update-course-content edits.
                $data['blocks'] = LessonBlocks::split($stored);
            } else {
                // A quiz intro is Markdown as stored; any other lesson is summarised as Markdown.
                $data['content'] = $isQuiz ? $stored : LessonContent::toMarkdown($stored);
            }

            // Lessons are block editor (Gutenberg) content. Markdown cannot hold every block.
            if ($layoutBlocks) {
                $data['layout_blocks'] = $layoutBlocks;

                McpHelper::warn('This Markdown leaves out editor blocks or settings: ' . implode(', ', $layoutBlocks) . '. To change the lesson, read it with format=blocks and send edits; blocks you do not touch stay as they are.');
            }

            return McpHelper::envelope(sprintf('Lesson %d: %s.', $lessonId, Arr::get($lesson, 'title')), $data);
        }

        $courseResult = RestBridge::call('GET', '/admin/courses/' . $courseId);
        if (is_wp_error($courseResult)) {
            return $courseResult;
        }

        $sectionResult = RestBridge::call('GET', '/admin/courses/' . $courseId . '/sections');
        if (is_wp_error($sectionResult)) {
            return $sectionResult;
        }

        $course = (array) Arr::get($courseResult, 'course', []);
        $sections = [];
        $lessonCount = 0;

        foreach ((array) Arr::get($sectionResult, 'sections', []) as $section) {
            $lessons = [];
            foreach ((array) Arr::get($section, 'lessons', []) as $lesson) {
                $lessons[] = array_filter([
                    'id'     => (int) Arr::get($lesson, 'id'),
                    'title'  => Arr::get($lesson, 'title'),
                    'status' => Arr::get($lesson, 'status'),
                    'type'   => Arr::get($lesson, 'content_type') === 'quiz' ? 'quiz' : null,
                ]);
                ++$lessonCount;
            }

            $sections[] = array_filter([
                'id'                => (int) Arr::get($section, 'id'),
                'title'             => Arr::get($section, 'title'),
                'status'            => Arr::get($section, 'status'),
                'unlock_at'         => McpHelper::iso(Arr::get($section, 'scheduled_at')),
                'unlock_after_days' => (int) Arr::get($section, 'reactions_count', 0),
                'lessons'           => $lessons,
            ], function ($value) {
                return $value !== null && $value !== 0;
            });
        }

        return McpHelper::envelope(
            sprintf('%s: %d sections, %d lessons, %d students.', Arr::get($course, 'title'), count($sections), $lessonCount, (int) Arr::get($course, 'students_count', 0)),
            [
                'id'          => $courseId,
                'slug'        => Arr::get($course, 'slug'),
                'title'       => Arr::get($course, 'title'),
                'description' => McpHelper::excerpt((string) Arr::get($course, 'description', ''), 600),
                'status'      => Arr::get($course, 'status'),
                'privacy'     => Arr::get($course, 'privacy'),
                'type'        => Arr::get($course, 'course_type'),
                'students'    => (int) Arr::get($course, 'students_count', 0),
                'completed'   => (int) Arr::get($course, 'completed_students', 0),
                'sections'    => $sections,
            ]
        );
    }

    public static function getBlockGuide($params)
    {
        $names = array_values(array_filter(array_map('strval', (array) Arr::get($params, 'blocks', []))));

        if (count($names) > 10) {
            return McpHelper::error('cap_reached', 'Pass at most 10 block names per call.', [ 'fields' => [ 'blocks' ] ]);
        }

        $guide = BlockGuide::guide($names);

        return McpHelper::envelope(
            sprintf('Block editor rules and %d examples. Official reference: %s', count($guide['examples']), BlockGuide::DOCS['core_blocks']),
            $guide
        );
    }

    public static function createContent($params)
    {
        $courseInput = (array) Arr::get($params, 'course', []);
        $courseId = (int) Arr::get($params, 'course_id', 0);
        $sections = array_values((array) Arr::get($params, 'sections', []));
        $dryRun = !empty($params['dry_run']);

        $plan = self::validateTree($courseInput, $courseId, $sections);
        if (is_wp_error($plan)) {
            return $plan;
        }

        // An existing course and its sections are checked before a dry run answers, so a
        // dry run that succeeds means the real call can run.
        $existing = $courseId ? self::checkTarget($courseId, $sections) : null;
        if (is_wp_error($existing)) {
            return $existing;
        }

        if ($dryRun) {
            return McpHelper::envelope(
                sprintf('Dry run: would create %d courses, %d sections and %d lessons, all as drafts.', $plan['courses'], $plan['sections'], $plan['lessons']),
                array_merge([ 'dry_run' => true ], $plan)
            );
        }

        $created = [ 'course' => null, 'sections' => [] ];
        $courseType = null;

        if (!$courseId) {
            $result = RestBridge::call('POST', '/admin/courses', [
                'title'       => (string) $courseInput['title'],
                'description' => (string) $courseInput['description'],
                'privacy'     => $courseInput['privacy'],
                'course_type' => $courseInput['course_type'],
                'status'      => 'draft',
                'settings'    => [ 'course_details' => (string) Arr::get($courseInput, 'details', '') ],
            ]);

            if (is_wp_error($result)) {
                return $result;
            }

            $courseId = (int) Arr::get($result, 'course.id');
            $courseType = $courseInput['course_type'];
            $created['course'] = [
                'id'     => $courseId,
                'slug'   => Arr::get($result, 'course.slug'),
                'title'  => Arr::get($result, 'course.title'),
                'status' => Arr::get($result, 'course.status'),
            ];
        } else {
            $courseType = Arr::get($existing, 'course.course_type');
            $created['course'] = [ 'id' => $courseId, 'existing' => true ];
        }

        $base = '/admin/courses/' . $courseId;

        foreach ($sections as $section) {
            $sectionId = (int) Arr::get($section, 'id', 0);
            $sectionRow = [ 'id' => $sectionId, 'lessons' => [] ];

            if (!$sectionId) {
                $result = RestBridge::call('POST', $base . '/sections', [ 'title' => (string) $section['title'] ]);
                if (is_wp_error($result)) {
                    return self::partial($created, $result);
                }

                $sectionId = (int) Arr::get($result, 'section.id');
                $sectionRow = [ 'id' => $sectionId, 'title' => Arr::get($result, 'section.title'), 'lessons' => [] ];

                // The route creates a section as published; a draft keeps it out of a live course until someone reviews it.
                $patch = array_filter([
                    'status'          => 'draft',
                    'scheduled_at'    => $courseType === 'scheduled' ? McpHelper::toSiteDate(Arr::get($section, 'unlock_at')) : null,
                    'reactions_count' => $courseType === 'structured' ? (int) Arr::get($section, 'unlock_after_days', 0) : null,
                ]);

                $patched = RestBridge::call('PATCH', $base . '/sections/' . $sectionId, $patch);
                if (is_wp_error($patched)) {
                    $created['sections'][] = $sectionRow;
                    return self::partial($created, $patched);
                }
            } else {
                $sectionRow['existing'] = true;
            }

            foreach ((array) Arr::get($section, 'lessons', []) as $lesson) {
                $result = RestBridge::call('POST', $base . '/lessons', [
                    'section_id' => $sectionId,
                    'title'      => (string) $lesson['title'],
                ]);

                if (is_wp_error($result)) {
                    $created['sections'][] = $sectionRow;
                    return self::partial($created, $result);
                }

                $lessonId = (int) Arr::get($result, 'lesson.id');
                $content = trim((string) Arr::get($lesson, 'content', ''));

                if ($content !== '') {
                    $saved = RestBridge::call('PATCH', $base . '/lessons/' . $lessonId, [ 'message' => self::lessonBody($content, Arr::get($lesson, 'content_format', 'markdown')) ]);
                    if (is_wp_error($saved)) {
                        $sectionRow['lessons'][] = [ 'id' => $lessonId, 'title' => (string) $lesson['title'], 'content_saved' => false ];
                        $created['sections'][] = $sectionRow;
                        return self::partial($created, $saved);
                    }
                }

                $sectionRow['lessons'][] = [ 'id' => $lessonId, 'title' => (string) $lesson['title'] ];
            }

            $created['sections'][] = $sectionRow;
        }

        return McpHelper::envelope(
            sprintf('Created %d courses, %d sections and %d lessons as drafts in course %d.', $plan['courses'], $plan['sections'], $plan['lessons'], $courseId),
            $created
        );
    }

    public static function updateContent($params)
    {
        $type = Arr::get($params, 'type');
        $itemId = (int) Arr::get($params, 'id', 0);

        if ($type === 'course') {
            return self::updateCourse($itemId, $params);
        }

        if ($type === 'section') {
            $section = CourseTopic::where('id', $itemId)->first();
            if (!$section) {
                return McpHelper::error('not_found', 'No section has this id.', [ 'fields' => [ 'id' ] ]);
            }

            // Reading the course through its admin route applies the course-admin policy before anything about it is revealed.
            $course = RestBridge::call('GET', '/admin/courses/' . (int) $section->space_id);
            if (is_wp_error($course)) {
                return $course;
            }

            $courseType = Arr::get($course, 'course.course_type');

            $patch = array_filter([
                'title'  => array_key_exists('title', $params) ? (string) $params['title'] : null,
                'status' => Arr::get($params, 'status'),
            ], function ($value) {
                return $value !== null && $value !== '';
            });

            if (array_key_exists('unlock_at', $params)) {
                if ($courseType !== 'scheduled') {
                    return McpHelper::error('invalid_param', 'unlock_at only works in a scheduled course.', [ 'fields' => [ 'unlock_at' ] ]);
                }
                $unlockAt = McpHelper::toSiteDate($params['unlock_at']);
                if ($unlockAt === null) {
                    return McpHelper::error('invalid_param', 'unlock_at must be a date such as 2026-12-01 or 2026-12-01T09:00:00+06:00.', [ 'fields' => [ 'unlock_at' ] ]);
                }
                $patch['scheduled_at'] = $unlockAt;
            }

            if (array_key_exists('unlock_after_days', $params)) {
                if ($courseType !== 'structured') {
                    return McpHelper::error('invalid_param', 'unlock_after_days only works in a structured course.', [ 'fields' => [ 'unlock_after_days' ] ]);
                }
                $patch['reactions_count'] = max(0, (int) $params['unlock_after_days']);
            }

            if (!$patch) {
                return McpHelper::error('invalid_param', 'Nothing to change. Pass title, status, unlock_at or unlock_after_days.');
            }

            $result = RestBridge::call('PATCH', '/admin/courses/' . (int) $section->space_id . '/sections/' . $itemId, $patch);
            if (is_wp_error($result)) {
                return $result;
            }

            return McpHelper::envelope(sprintf('Section %d was updated.', $itemId), [
                'id'        => $itemId,
                'course_id' => (int) $section->space_id,
                'title'     => Arr::get($result, 'topic.title'),
                'status'    => Arr::get($result, 'topic.status'),
            ]);
        }

        $lesson = CourseLesson::where('id', $itemId)->first();
        if (!$lesson) {
            return McpHelper::error('not_found', 'No lesson has this id.', [ 'fields' => [ 'id' ] ]);
        }

        $base = '/admin/courses/' . (int) $lesson->space_id;

        // The lesson route applies the course-admin policy before the stored body is read or described.
        $current = RestBridge::call('GET', $base . '/lessons/' . $itemId);
        if (is_wp_error($current)) {
            return $current;
        }

        $patch = array_filter([
            'title'  => array_key_exists('title', $params) ? (string) $params['title'] : null,
            'status' => Arr::get($params, 'status'),
        ], function ($value) {
            return $value !== null && $value !== '';
        });

        $storedBody = (string) Arr::get($current, 'lesson.message', '');

        if (!empty($params['version']) && (string) $params['version'] !== LessonBlocks::version($storedBody)) {
            return McpHelper::error('conflict', 'The lesson changed after you read it. Nothing was saved.', [
                'fields'    => [ 'version' ],
                'retryable' => true,
                'next_step' => 'Read it again with get-course and apply your change to the new blocks.',
            ]);
        }

        if (array_key_exists('edits', $params)) {
            if ($lesson->content_type === 'quiz') {
                return McpHelper::error('invalid_param', 'A quiz lesson has no blocks to edit: its content is a Markdown intro. Pass content instead.', [ 'fields' => [ 'edits' ] ]);
            }

            if (array_key_exists('content', $params)) {
                return McpHelper::error('invalid_param', 'Pass edits or content, not both.', [ 'fields' => [ 'edits', 'content' ] ]);
            }

            if (empty($params['version'])) {
                return McpHelper::error('invalid_param', 'edits needs version, from the get-course read you edited.', [ 'fields' => [ 'version' ] ]);
            }

            $edited = LessonBlocks::apply($storedBody, $params['edits']);
            if (is_wp_error($edited)) {
                return $edited;
            }

            $patch['message'] = $edited;
        }

        if (array_key_exists('content', $params) && $lesson->content_type === 'quiz') {
            if (Arr::get($params, 'content_format', 'markdown') === 'blocks') {
                return McpHelper::error('invalid_param', 'A quiz lesson\'s content is its intro, in Markdown. Its questions are edited in the course editor.', [ 'fields' => [ 'content_format' ] ]);
            }

            // Stored as Markdown, as the quiz editor does; Pro renders it with mdToHtml.
            $patch['message'] = (string) $params['content'];
        } elseif (array_key_exists('content', $params)) {
            $message = self::lessonBody((string) $params['content'], Arr::get($params, 'content_format', 'markdown'), $storedBody, !empty($params['replace_layout']));
            if (is_wp_error($message)) {
                return $message;
            }

            $patch['message'] = $message;
        }

        $moveTo = (int) Arr::get($params, 'move_to_section', 0);

        if (!$patch && !$moveTo) {
            return McpHelper::error('invalid_param', 'Nothing to change. Pass title, edits, content, status or move_to_section.');
        }

        if ($moveTo) {
            $moved = RestBridge::call('PUT', $base . '/move-lesson', [ 'lesson_id' => $itemId, 'section_id' => $moveTo ]);
            if (is_wp_error($moved)) {
                return $moved;
            }
        }

        $status = $lesson->status;
        $title = $lesson->title;

        if ($patch) {
            $result = RestBridge::call('PATCH', $base . '/lessons/' . $itemId, $patch);
            if (is_wp_error($result)) {
                return $result;
            }
            $status = Arr::get($result, 'lesson.status');
            $title = Arr::get($result, 'lesson.title');
        }

        $savedBody = (string) CourseLesson::where('id', $itemId)->value('message');

        // The lesson route filters HTML for users without unfiltered_html; say so when it changed what was sent.
        if (isset($patch['message']) && trim($savedBody) !== trim((string) $patch['message'])) {
            McpHelper::warn('The site\'s HTML filter changed part of the content you sent. Read the lesson again to see what was saved.');
        }

        return McpHelper::envelope(sprintf('Lesson %d was updated; status %s.', $itemId, $status), [
            'id'         => $itemId,
            'course_id'  => (int) $lesson->space_id,
            'section_id' => $moveTo ? $moveTo : (int) $lesson->parent_id,
            'title'      => $title,
            'status'     => $status,
            'version'    => LessonBlocks::version($savedBody),
        ]);
    }



    /**
     * The lesson body to store. Markdown is converted to blocks. Block markup is
     * stored as sent; the lesson route sanitizes it as it does for the editor.
     *
     * Replacing a lesson that has layout blocks with Markdown would drop them
     * without a word, so that is refused unless the caller asks for it.
     *
     * @param string $content
     * @param string $format        markdown|blocks
     * @param string $stored        The body the lesson has now; '' for a new lesson.
     * @param bool   $replaceLayout The caller accepts that layout blocks are dropped.
     * @return string|\WP_Error
     */
    private static function lessonBody($content, $format, $stored = '', $replaceLayout = false)
    {
        if ($format === 'blocks') {
            if (trim($content) !== '' && !has_blocks($content)) {
                return McpHelper::error('invalid_param', 'content is not block editor markup. Send blocks such as <!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->, or use content_format=markdown.', [
                    'fields'    => [ 'content' ],
                    'next_step' => 'Call get-block-guide for the rules and exact examples.',
                ]);
            }

            $notOffered = BlockGuide::blocksNotOffered($content);
            if ($notOffered) {
                McpHelper::warn('The lesson editor does not offer these blocks: ' . implode(', ', $notOffered) . '. Students still see them, but an author cannot add them in the editor. get-block-guide lists the blocks it offers.');
            }

            // A broken or unclosed block comment leaves its content outside any block.
            foreach (parse_blocks($content) as $block) {
                if (empty($block['blockName']) && trim((string) $block['innerHTML']) !== '') {
                    McpHelper::warn('Some content is outside a block, so the editor will show it as a Classic block. Check that every <!-- wp:NAME --> has its closing <!-- /wp:NAME -->.');
                    break;
                }
            }

            return $content;
        }

        $layoutBlocks = LessonContent::layoutBlocks($stored);

        if ($layoutBlocks && !$replaceLayout) {
            return McpHelper::error('would_lose_layout', 'This lesson has editor blocks or settings that Markdown cannot keep: ' . implode(', ', $layoutBlocks) . '. Nothing was changed.', [
                'fields'    => [ 'content' ],
                'blocks'    => $layoutBlocks,
                'next_step' => 'Read the lesson with get-course (it returns its blocks and a version) and send edits for the blocks you change; the others stay as they are. Or pass replace_layout=true to replace the whole body with your Markdown and drop those blocks; ask the person first.',
            ]);
        }

        return LessonContent::toBlocks($content);
    }

    /**
     * The course route is a full update and resets what the request leaves out
     * (the lock screen, for one), so the current values are sent back with the changes.
     */
    private static function updateCourse($courseId, $params)
    {
        $existing = RestBridge::call('GET', '/admin/courses/' . $courseId);
        if (is_wp_error($existing)) {
            return $existing;
        }

        $course = (array) Arr::get($existing, 'course', []);

        $body = [
            'title'       => array_key_exists('title', $params) ? (string) $params['title'] : Arr::get($course, 'title'),
            'description' => array_key_exists('description', $params) ? (string) $params['description'] : Arr::get($course, 'description'),
            'privacy'     => Arr::get($params, 'privacy', Arr::get($course, 'privacy')),
            'status'      => Arr::get($params, 'status', Arr::get($course, 'status')),
            'course_type' => Arr::get($course, 'course_type'),
            'slug'        => Arr::get($course, 'slug'),
            'settings'    => (array) Arr::get($course, 'settings', []),
        ];

        $result = RestBridge::call('PUT', '/admin/courses/' . $courseId, $body);
        if (is_wp_error($result)) {
            return $result;
        }

        return McpHelper::envelope(sprintf('Course %d was updated; status %s.', $courseId, Arr::get($result, 'course.status')), [
            'id'      => $courseId,
            'title'   => Arr::get($result, 'course.title'),
            'status'  => Arr::get($result, 'course.status'),
            'privacy' => Arr::get($result, 'course.privacy'),
        ]);
    }

    /**
     * The whole tree is checked before the first write, so a bad lesson at the
     * end does not leave half a course behind.
     *
     * @return array|\WP_Error Counts of what would be created.
     */
    private static function lessonUrl($lessonId)
    {
        $lesson = CourseLesson::find($lessonId);

        return $lesson ? $lesson->getPermalink() : null;
    }

    /**
     * Reads the course through its admin route (the course-admin policy applies) and checks
     * that every section id sent belongs to it.
     *
     * @return array|\WP_Error The course route's answer.
     */
    private static function checkTarget($courseId, array $sections)
    {
        $course = RestBridge::call('GET', '/admin/courses/' . (int) $courseId);
        if (is_wp_error($course)) {
            return $course;
        }

        $sectionIds = array_values(array_unique(array_filter(array_map(function ($section) {
            return is_array($section) ? (int) Arr::get($section, 'id', 0) : 0;
        }, $sections))));

        if ($sectionIds) {
            $found = array_map('intval', CourseTopic::where('space_id', (int) $courseId)->whereIn('id', $sectionIds)->pluck('id')->toArray());
            $missing = array_values(array_diff($sectionIds, $found));

            if ($missing) {
                return McpHelper::error('invalid_param', sprintf('Section %s is not a section of course %d. get-course lists its sections.', implode(', ', $missing), $courseId), [ 'fields' => [ 'sections' ] ]);
            }
        }

        return $course;
    }

    private static function validateTree($courseInput, $courseId, $sections)
    {
        if (!$courseId) {
            foreach ([ 'title', 'description', 'privacy', 'course_type' ] as $field) {
                if (empty($courseInput[$field])) {
                    return McpHelper::error('invalid_param', 'A new course needs course.' . $field . '. To add to an existing course, pass course_id.', [ 'fields' => [ 'course.' . $field ] ]);
                }
            }

            if (!in_array($courseInput['privacy'], self::PRIVACY_TYPES, true)) {
                return McpHelper::error('invalid_param', 'course.privacy has an unknown value.', [ 'fields' => [ 'course.privacy' ], 'allowed' => self::PRIVACY_TYPES ]);
            }

            if (!in_array($courseInput['course_type'], self::COURSE_TYPES, true)) {
                return McpHelper::error('invalid_param', 'course.course_type has an unknown value.', [ 'fields' => [ 'course.course_type' ], 'allowed' => self::COURSE_TYPES ]);
            }
        } elseif (!$sections) {
            return McpHelper::error('invalid_param', 'Pass sections to add to this course.', [ 'fields' => [ 'sections' ] ]);
        }

        if (count($sections) > self::MAX_SECTIONS) {
            return McpHelper::error('cap_reached', sprintf('Pass at most %d sections per call.', self::MAX_SECTIONS), [ 'fields' => [ 'sections' ] ]);
        }

        $newSections = 0;
        $lessons = 0;
        $bytes = 0;

        foreach ($sections as $index => $section) {
            if (!is_array($section) || (empty($section['id']) && trim((string) Arr::get($section, 'title', '')) === '')) {
                return McpHelper::error('invalid_param', sprintf('sections[%d] needs a title, or the id of an existing section.', $index), [ 'fields' => [ 'sections' ] ]);
            }

            if (!empty($section['id']) && !$courseId) {
                return McpHelper::error('invalid_param', sprintf('sections[%d].id needs course_id.', $index), [ 'fields' => [ 'course_id' ] ]);
            }

            if (empty($section['id'])) {
                ++$newSections;
            }

            $unlockAt = Arr::get($section, 'unlock_at');
            if ($unlockAt !== null && $unlockAt !== '' && McpHelper::toSiteDate($unlockAt) === null) {
                return McpHelper::error('invalid_param', sprintf('sections[%d].unlock_at must be a date such as 2026-12-01 or 2026-12-01T09:00:00+06:00.', $index), [ 'fields' => [ 'sections' ] ]);
            }

            foreach ((array) Arr::get($section, 'lessons', []) as $lessonIndex => $lesson) {
                if (!is_array($lesson) || trim((string) Arr::get($lesson, 'title', '')) === '') {
                    return McpHelper::error('invalid_param', sprintf('sections[%d].lessons[%d] needs a title.', $index, $lessonIndex), [ 'fields' => [ 'sections' ] ]);
                }

                $format = Arr::get($lesson, 'content_format', 'markdown');
                if ($format !== 'markdown' && $format !== 'blocks') {
                    return McpHelper::error('invalid_param', sprintf('sections[%d].lessons[%d].content_format must be one of: markdown, blocks.', $index, $lessonIndex), [ 'fields' => [ 'sections' ], 'allowed' => [ 'markdown', 'blocks' ] ]);
                }

                if ($format === 'blocks' && trim((string) Arr::get($lesson, 'content', '')) !== '' && !has_blocks((string) $lesson['content'])) {
                    return McpHelper::error('invalid_param', sprintf('sections[%d].lessons[%d].content is not block editor markup.', $index, $lessonIndex), [ 'fields' => [ 'sections' ] ]);
                }

                ++$lessons;
                $bytes += strlen((string) Arr::get($lesson, 'content', ''));
            }
        }

        if ($lessons > self::MAX_LESSONS) {
            return McpHelper::error('cap_reached', sprintf('Pass at most %d lessons per call.', self::MAX_LESSONS), [ 'fields' => [ 'sections' ] ]);
        }

        if ($bytes > self::MAX_CONTENT_BYTES) {
            return McpHelper::error('cap_reached', 'The lesson content is too large for one call. Create the outline first, then add each lesson body with update-course-content.', [ 'fields' => [ 'sections' ] ]);
        }

        return [
            'courses'  => $courseId ? 0 : 1,
            'sections' => $newSections,
            'lessons'  => $lessons,
        ];
    }

    /**
     * A failure after the first write reports what exists, so the agent adds
     * the rest instead of creating the course again.
     */
    private static function partial($created, \WP_Error $error)
    {
        $payload = json_decode($error->get_error_message(), true);

        return McpHelper::error(Arr::get($payload, 'error.code', 'validation_failed'), Arr::get($payload, 'error.message', 'A step failed.'), [
            'created'   => $created,
            'next_step' => 'The items under created exist. Do not create the course again; add the missing sections and lessons with course_id.',
        ]);
    }

    /**
     * @return int|\WP_Error
     */
    private static function resolveCourseId($params)
    {
        if (!empty($params['id'])) {
            return (int) $params['id'];
        }

        $slug = sanitize_text_field((string) Arr::get($params, 'slug', ''));
        $courseId = $slug ? (int) Course::where('slug', $slug)->value('id') : 0;

        if (!$courseId) {
            return McpHelper::error('not_found', 'No course matches. Pass id or slug.', [ 'fields' => [ 'id' ] ]);
        }

        return $courseId;
    }
}
