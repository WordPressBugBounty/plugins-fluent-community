<?php

namespace FluentCommunity\Modules\Integrations\FluentCRM;

use FluentCommunity\App\Models\BaseSpace;
use FluentCommunity\App\Models\Space;
use FluentCommunity\App\Models\XProfile;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\Framework\Support\Arr;
use FluentCommunity\Modules\Course\Services\CourseHelper;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Services\Html\TableBuilder;

class ProfileSection
{
    public function register()
    {
        add_filter('fluentcrm_profile_sections', [$this, 'addProfileSection'], 10, 1);
        add_filter('fluencrm_profile_section_fluent_community', [$this, 'getProfileSection'], 10, 2);
        add_filter('fluencrm_profile_section_save_fluent_community', [$this, 'saveProfileSection'], 10, 3);
    }

    public function addProfileSection($sections)
    {
        $sections['fluent_community'] = [
            'name'    => 'fluentcrm_profile_extended',
            'title'   => __('Community', 'fluent-communtiy'),
            'handler' => 'route',
            'query'   => [
                'handler' => 'fluent_community'
            ],
        ];

        return $sections;
    }

    public function getProfileSection($sections, Subscriber $contact)
    {
        $userId = $contact->getWpUserId();
        $sections['heading'] = 'FluentCommunity Profile';
        if (!$userId) {
            $sections['content_html'] = '<p>' . __('Sorry! the contact does not have a FluentCommunity profile or not a registered site user.', 'fluent-community') . '</p>';
            return $sections;
        }

        $content = '';
        $xprofile = XProfile::where('user_id', $userId)->first();

        if ($xprofile) {
            $content .= '<a href="' . $xprofile->getPermalink() . '" target="_blank" rel="noopener">' . __('View Community Profile', 'fluent-community') . '</a>';
        }

        $courseEnabled = Helper::isFeatureEnabled('course_module');

        $dateFormat = get_option('date_format') . ' ' . get_option('time_format');

        $userSpaces = Space::with('space_pivot')
            ->whereHas('space_pivot', function ($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->orderBy('title', 'ASC')
            ->get();

        $content .= '<h3>' . __('Spaces', 'fluent-community') . '</h3>';

        if ($userSpaces && !$userSpaces->isEmpty()) {
            $tableBuilder = new TableBuilder();
            foreach ($userSpaces as $space) {
                $tableBuilder->addRow([
                    'id'         => $space->id,
                    'title'      => '<a target="_blank" rel="noopener" href="' . $space->getPermalink() . '">' . $space->title . '</a>',
                    'created_at' => date($dateFormat, strtotime($space->space_pivot->created_at)),
                    'status'     => $space->space_pivot->status,
                    'role'       => $space->space_pivot->role
                ]);
            }
            $tableBuilder->setHeader([
                'id'         => __('ID', 'fluent-community'),
                'title'      => __('Space Name', 'fluent-community'),
                'status'     => __('Status', 'fluent-community'),
                'role'       => __('Type', 'fluent-community'),
                'created_at' => __('Member Since', 'fluent-community'),
            ]);
            $content .= $tableBuilder->getHtml();
        } else {
            $content .= '<p>' . __('No spaces found for this contact.', 'fluent-community') . '</p>';
        }

        if ($courseEnabled) {
            $tableBuilder = new TableBuilder();
            $userCourses = \FluentCommunity\Modules\Course\Services\CourseHelper::getUserCourses($userId); // this will return Collection of Course objects
            $content .= '<h3>' . __('Enrolled Courses', 'fluent-community') . '</h3>';
            if ($userCourses && !$userCourses->isEmpty()) {
                foreach ($userCourses as $course) {
                    $tableBuilder->addRow([
                        'id'              => $course->id,
                        'title'           => '<a target="_blank" rel="noopener" href="' . $course->getPermalink() . '">' . $course->title . '</a>',
                        'enrollment_date' => date($dateFormat, strtotime($course->enrollment->created_at)),
                        'status'          => $course->enrollment->status,
                        'progress'        => CourseHelper::getCourseProgress($course->id, $userId) . '%'
                    ]);
                }
                $tableBuilder->setHeader([
                    'id'              => __('ID', 'fluent-community'),
                    'title'           => __('Course Name', 'fluent-community'),
                    'status'          => __('Status', 'fluent-community'),
                    'progress'        => __('Progress', 'fluent-community'),
                    'enrollment_date' => __('Enrolled At', 'fluent-community'),
                ]);
                $content .= $tableBuilder->getHtml();
            } else {
                $content .= '<p>' . __('No courses found for this contact.', 'fluent-community') . '</p>';
            }
        }

        $spaceCourses = \FluentCommunity\App\Models\BaseSpace::withoutGlobalScopes()
            ->select('title', 'id', 'type', 'slug')
            ->whereIn('type', ['course', 'community'])
            ->whereDoesntHave('space_pivot', function ($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->orderBy('title', 'ASC')
            ->get();

        $options = [];

        foreach ($spaceCourses as $spaceCourse) {
            $type = $spaceCourse->type;
            if ($type == 'course') {
                $type = 'Course';
            } else if ($type == 'community') {
                $type = 'Space';
            }

            $options[] = [
                'id'    => $spaceCourse->id,
                'label' => $spaceCourse->title . ' (' . $type . ') - ' . $spaceCourse->slug
            ];
        }
        $sections['crud'] = [
            'btn_label'    => __('Add to Space or Course', 'fluent-community'),
            'form_heading' => __('Add to Space or Course', 'fluent-community'),
            'fields'       => [
                'base_space_ids' => [
                    'type'        => 'input-option',
                    'multiple'    => true,
                    'placeholder' => __('Select Space or Course', 'fluent-community'),
                    'label'       => __('Select Space or Course', 'fluent-community'),
                    'options'     => $options
                ],
            ]
        ];

        $sections['content_html'] = $content;

        return $sections;
    }

    public function saveProfileSection($response, $data, Subscriber $subscriber)
    {
        $spaceIds = Arr::get($data, 'base_space_ids', []);

        if (!$spaceIds) {
            throw new \Exception(__('No Space or Course selected', 'fluent-community'));
        }
        
        $userId = $subscriber->getWpUserId();

        if (!$userId) {
            throw new \Exception(__('No user found for this contact', 'fluent-community'));
        }

        foreach ($spaceIds as $spaceId) {
            $space = BaseSpace::withoutGlobalScopes()->find($spaceId);
            if ($space) {
                Helper::addToSpace($space, $userId);
            }
        }

        return true;
    }

}
