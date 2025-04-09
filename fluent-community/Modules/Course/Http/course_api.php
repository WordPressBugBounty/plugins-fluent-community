<?php

/**
 * @var $router FluentCommunity\Framework\Http\Router
 */

$router->prefix('courses')->namespace('\FluentCommunity\Modules\Course\Http\Controllers')->withPolicy(\FluentCommunity\App\Http\Policies\PortalPolicy::class)->group(function ($router) {
    $router->get('/', 'CourseController@getCourses');
    $router->get('/{course_id}', 'CourseController@getCourse')->int('course_id');
    $router->get('/{course_slug}/by-slug', 'CourseController@getCourseBySlug')->alphaNumDash('course_slug');
    $router->post('/{course_id}/enroll', 'CourseController@enrollCourse')->int('course_id');
    $router->put('/{course_id}/lessons/{lesson_id}/completion', 'CourseController@updateCompletionLesson')->int('course_id')->int('lesson_id');
});

$router->prefix('admin/courses')->namespace('\FluentCommunity\Modules\Course\Http\Controllers')->withPolicy(\FluentCommunity\Modules\Course\Http\Policies\CourseAdminPolicy::class)->group(function ($router) {
    $router->get('/', 'CourseAdminController@getCourses');
    $router->post('/', 'CourseAdminController@createCourse');
    $router->get('/{cousre_id}', 'CourseAdminController@findCourse')->int('cousre_id');
    $router->put('/{cousre_id}', 'CourseAdminController@updateCourse')->int('cousre_id');
    $router->delete('/{cousre_id}', 'CourseAdminController@deleteCourse')->int('cousre_id');
    $router->get('/{cousre_id}/comments', 'CourseAdminController@getCourseComments')->int('cousre_id');
    $router->get('/{cousre_id}/students', 'CourseAdminController@getCourseStudents')->int('cousre_id');
    $router->post('/{cousre_id}/students', 'CourseAdminController@addStudent')->int('cousre_id');
    $router->delete('/{cousre_id}/students/{student_id}', 'CourseAdminController@removeStudent')->int('cousre_id')->int('student_id');

    $router->get('/{cousre_id}/users/search', 'CourseAdminController@getOtherUsers')->int('course_id');
    $router->post('/{cousre_id}/links', 'CourseAdminController@updateLinks')->int('cousre_id');

    $router->get('/{cousre_id}/sections', 'CourseAdminController@getSections')->int('cousre_id');
    $router->post('/{cousre_id}/sections', 'CourseAdminController@createSection')->int('cousre_id');
    $router->patch('/{cousre_id}/sections/indexes', 'CourseAdminController@resetSectionIndexes')->int('cousre_id');
    $router->get('/{cousre_id}/sections/{section_id}', 'CourseAdminController@getSection')->int('cousre_id')->int('section_id');
    $router->put('/{cousre_id}/sections/{section_id}', 'CourseAdminController@updateSection')->int('cousre_id')->int('section_id');
    $router->patch('/{cousre_id}/sections/{section_id}', 'CourseAdminController@patchSection')->int('cousre_id')->int('section_id');
    $router->delete('/{cousre_id}/sections/{section_id}', 'CourseAdminController@deleteSection')->int('cousre_id')->int('section_id');
    $router->patch('/{cousre_id}/sections/{section_id}/indexes', 'CourseAdminController@resetLessonIndexes')->int('cousre_id')->int('section_id');

    $router->get('/{cousre_id}/lessons', 'CourseAdminController@getLessons')->int('cousre_id');
    $router->post('/{cousre_id}/lessons', 'CourseAdminController@createLesson')->int('cousre_id');
    $router->put('/{cousre_id}/move-lesson', 'CourseAdminController@moveLesson')->int('cousre_id');
    $router->get('/{cousre_id}/lessons/{lesson_id}', 'CourseAdminController@getLesson')->int('cousre_id')->int('lesson_id');
    $router->put('/{cousre_id}/lessons/{lesson_id}', 'CourseAdminController@updateLesson')->int('cousre_id')->int('lesson_id');
    $router->patch('/{cousre_id}/lessons/{lesson_id}', 'CourseAdminController@patchLesson')->int('cousre_id')->int('lesson_id');
    $router->delete('/{cousre_id}/lessons/{lesson_id}', 'CourseAdminController@deleteLesson')->int('cousre_id')->int('lesson_id');
});

