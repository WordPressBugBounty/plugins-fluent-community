<?php

namespace FluentCommunity\Modules\Course\Services;

use FluentCommunity\App\Services\Search\SearchQuery;
use FluentCommunity\Modules\Course\Model\Course;
use FluentCommunity\Modules\Course\Model\CourseLesson;

/**
 * Global search results for courses and lessons.
 *
 * Courses follow the same visibility rule as the course list (CourseController::getCourses)
 * and match on title and description only.
 *
 * Lessons follow the course page (CourseHelper::canSeeLessonList): a user who cannot see a
 * course's lesson list gets only the lessons they can open from it. A lesson the
 * user cannot open (CourseHelper::canAccessLesson: enrollment, drip date, public lesson view
 * and the fluent_community/course/can_view_lesson filter) can match by title only and comes
 * back with no snippet, so search never reveals locked lesson text.
 */
class CourseSearchProvider
{
    // Every match is ranked; these only bound one request on a very large catalogue
    const COURSE_SCAN_LIMIT = 1000;
    const LESSON_SCAN_LIMIT = 2000;

    // Lesson bodies are loaded this many at a time, only for lessons on the requested page
    const TEXT_CHUNK = 50;

    public function register()
    {
        add_filter('fluent_community/search/providers', [ $this, 'addProvider' ]);
    }

    public function addProvider($providers)
    {
        $providers['courses'] = [ $this, 'search' ];

        return $providers;
    }

    public function search(SearchQuery $query, $user, array $args)
    {
        $limit = max(1, (int) ($args['limit'] ?? 6));
        $page = max(1, (int) ($args['page'] ?? 1));
        $userId = $user ? (int) $user->ID : 0;
        $enrolledIds = array_map('intval', CourseHelper::getEnrolledCourseIds($userId ? $userId : null));
        $permissions = $user ? $user->getPermissions() : [];

        return [
            'courses' => $this->searchCourses($query, $user, $enrolledIds, $limit, $page),
            'lessons' => $this->searchLessons($query, $user, $enrolledIds, $permissions, $limit, $page),
        ];
    }

    private function searchCourses(SearchQuery $query, $user, array $enrolledIds, $limit, $page)
    {
        $isCourseCreator = $user && $user->hasCourseCreatorAccess();

        $courseQuery = Course::query()
            ->select([ 'id', 'title', 'slug', 'description', 'privacy', 'status', 'created_by' ])
            ->where(function ($q) use ($isCourseCreator, $user) {
                $q->where('status', 'published');
                if ($isCourseCreator) {
                    $q->orWhere('created_by', $user->ID);
                }
            })
            ->where(function ($q) use ($enrolledIds) {
                $q->whereIn('privacy', [ 'public', 'private' ]);
                if ($enrolledIds) {
                    $q->orWhereIn('id', $enrolledIds);
                }
            });

        $courses = $query->whereAllTerms($courseQuery, [ 'title', 'description' ])
            ->orderBy('id', 'DESC')
            ->limit(self::COURSE_SCAN_LIMIT)
            ->get();

        $ranked = [];
        foreach ($courses as $course) {
            $isEnrolled = in_array((int) $course->id, $enrolledIds, true);
            $ranked[] = [
                'rank'        => ($query->matchesAll($course->title) ? 2 : 0) + ($isEnrolled ? 1 : 0),
                'course'      => $course,
                'is_enrolled' => $isEnrolled,
            ];
        }

        usort($ranked, function ($a, $b) {
            $byRank = $b['rank'] - $a['rank'];

            return $byRank !== 0 ? $byRank : (int) $b['course']->id - (int) $a['course']->id;
        });

        $hasMore = count($ranked) > $limit * $page;
        $ranked = array_slice($ranked, ($page - 1) * $limit, $limit);

        $lessonCounts = $this->publishedLessonCounts(array_map(function ($row) {
            return (int) $row['course']->id;
        }, $ranked));

        $items = [];
        foreach ($ranked as $row) {
            $course = $row['course'];
            $items[] = [
                'id'            => (int) $course->id,
                'title'         => $course->title,
                'slug'          => $course->slug,
                'snippet'       => $query->snippet(SearchQuery::plainText($course->description)),
                'lessons_count' => (int) ($lessonCounts[$course->id] ?? 0),
                'is_enrolled'   => $row['is_enrolled'],
                'progress'      => $row['is_enrolled'] ? (int) CourseHelper::getCourseProgress($course->id) : null,
            ];
        }

        return [ 'items' => $items, 'has_more' => $hasMore ];
    }

    private function searchLessons(SearchQuery $query, $user, array $enrolledIds, $permissions, $limit, $page)
    {
        $canManageAllCourses = !empty($permissions['course_admin']);
        $isCourseCreator = !empty($permissions['course_creator']);

        // Light columns only: the lesson body is loaded later, and only for lessons on the page
        $lessonQuery = CourseLesson::query()
            ->select([ 'id', 'title', 'slug', 'space_id', 'parent_id', 'status', 'meta', 'created_at' ])
            ->where('status', 'published')
            ->whereHas('course', function ($q) use ($canManageAllCourses, $isCourseCreator, $enrolledIds, $user) {
                if ($canManageAllCourses) {
                    return;
                }
                // A first cut only; every candidate is checked in PHP below
                $q->where(function ($q) use ($isCourseCreator, $enrolledIds, $user) {
                    $q->where(function ($q) {
                        $q->where('status', 'published')->whereIn('privacy', [ 'public', 'private' ]);
                    });
                    if ($enrolledIds) {
                        $q->orWhereIn('id', $enrolledIds);
                    }
                    if ($isCourseCreator) {
                        $q->orWhere('created_by', $user->ID);
                    }
                });
            })
            ->with([ 'course', 'topic' ]);

        $query->whereAllTerms($lessonQuery, [ 'title', 'message' ]);

        // Title matches first, so locked lessons that match only in their text cannot fill the
        // scan window and push out a lesson whose title matches
        $query->orderByAllTermsIn($lessonQuery, 'title');

        $lessons = $lessonQuery->orderBy('id', 'DESC')
            ->limit(self::LESSON_SCAN_LIMIT)
            ->get();

        $ranked = [];
        $listVisible = [];
        $courseAccess = [];
        foreach ($lessons as $lesson) {
            $course = $lesson->course;
            if (!$course || !$lesson->topic) {
                continue;
            }

            $isEnrolled = in_array((int) $course->id, $enrolledIds, true);
            if (!isset($courseAccess[$course->id])) {
                $courseAccess[$course->id] = [
                    'enrollment' => $isEnrolled ? CourseHelper::getCourseEnrollment($course->id, $user->ID) : null,
                    'is_admin'   => $course->isCourseAdmin($user),
                ];
            }
            $enrollment = $courseAccess[$course->id]['enrollment'];
            $isCourseAdmin = $courseAccess[$course->id]['is_admin'];

            // Same rule as the course page (CourseController::processCourse)
            if ($course->privacy === 'secret' && !$enrollment && !$isCourseAdmin) {
                continue;
            }

            $canView = CourseHelper::canAccessLesson($lesson, $course, $user, $enrollment);

            if (!$canView) {
                // The lock screen settings are read once per course, not once per lesson
                if (!isset($listVisible[$course->id])) {
                    $listVisible[$course->id] = CourseHelper::canSeeLessonList($course, $enrollment, $isCourseAdmin);
                }
                if (!$listVisible[$course->id]) {
                    continue;
                }
            }

            $titleMatch = $query->matchesAll($lesson->title);

            // A locked lesson may match on its title only
            if (!$canView && !$titleMatch) {
                continue;
            }

            $ranked[] = [
                'rank'        => ($titleMatch ? 4 : 0) + ($canView ? 2 : 0) + ($isEnrolled ? 1 : 0),
                'lesson'      => $lesson,
                'course'      => $course,
                'can_view'    => $canView,
                'title_match' => $titleMatch,
                'is_enrolled' => $isEnrolled,
            ];
        }

        usort($ranked, function ($a, $b) {
            $byRank = $b['rank'] - $a['rank'];

            return $byRank !== 0 ? $byRank : (int) $b['lesson']->id - (int) $a['lesson']->id;
        });

        // Walk the ranked list until the page (plus one row, for has_more) is full. The body of
        // a lesson the user can open is loaded here, in chunks: it gives the snippet, and it
        // drops a lesson whose only "match" was in block markup (a search for "wp", say).
        $offset = ($page - 1) * $limit;
        $needed = $offset + $limit + 1;
        $accepted = [];

        foreach (array_chunk($ranked, self::TEXT_CHUNK) as $chunk) {
            $texts = $this->lessonTexts(array_map(function ($row) {
                return (int) $row['lesson']->id;
            }, array_filter($chunk, function ($row) {
                return $row['can_view'];
            })));

            foreach ($chunk as $row) {
                $lessonId = (int) $row['lesson']->id;
                $text = $row['can_view'] ? ($texts[$lessonId] ?? '') : '';

                if (!$row['title_match'] && !$query->matchesAll($row['lesson']->title . ' ' . $text)) {
                    continue;
                }

                $accepted[] = $this->lessonItem($row, $row['can_view'] ? $query->snippet($text) : '');

                if (count($accepted) >= $needed) {
                    break 2;
                }
            }
        }

        return [
            'items'    => array_slice($accepted, $offset, $limit),
            'has_more' => count($accepted) > $offset + $limit,
        ];
    }

    private function lessonItem(array $row, $snippet)
    {
        $lesson = $row['lesson'];
        $course = $row['course'];

        return [
            'id'          => (int) $lesson->id,
            'title'       => $lesson->title,
            'slug'        => $lesson->slug,
            'snippet'     => $snippet,
            'is_locked'   => !$row['can_view'],
            'is_enrolled' => $row['is_enrolled'],
            'match'       => $row['title_match'] ? 'title' : 'content',
            'course'      => [
                'id'    => (int) $course->id,
                'title' => $course->title,
                'slug'  => $course->slug,
            ],
        ];
    }

    /**
     * Plain text of lessons without rendering them. Block lessons often keep an empty
     * message_rendered (CourseHelper::formatLessonData renders them on view), and running
     * blocks or shortcodes for every search result would be slow and could have side effects.
     *
     * @return array<int, string> Lesson id => plain text
     */
    private function lessonTexts(array $lessonIds)
    {
        if (!$lessonIds) {
            return [];
        }

        $texts = [];
        $rows = CourseLesson::query()
            ->select([ 'id', 'message', 'message_rendered' ])
            ->whereIn('id', $lessonIds)
            ->get();

        foreach ($rows as $row) {
            $source = $row->message_rendered;
            if (!$source || has_blocks($source)) {
                $source = $row->message;
            }
            $texts[(int) $row->id] = SearchQuery::plainText(strip_shortcodes((string) $source));
        }

        return $texts;
    }

    private function publishedLessonCounts(array $courseIds)
    {
        if (!$courseIds) {
            return [];
        }

        return CourseLesson::query()
            ->whereIn('space_id', $courseIds)
            ->where('status', 'published')
            ->groupBy('space_id')
            ->selectRaw('space_id, COUNT(*) as total')
            ->pluck('total', 'space_id')
            ->toArray();
    }
}
