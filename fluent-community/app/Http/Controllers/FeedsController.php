<?php

namespace FluentCommunity\App\Http\Controllers;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Models\Media;
use FluentCommunity\App\Models\NotificationSubscriber;
use FluentCommunity\App\Models\Space;
use FluentCommunity\App\Models\User;
use FluentCommunity\App\Services\CustomSanitizer;
use FluentCommunity\App\Services\FeedsHelper;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\App\Services\Libs\FileSystem;
use FluentCommunity\App\Services\ProfileHelper;
use FluentCommunity\App\Services\RemoteUrlParser;
use FluentCommunity\Framework\Http\Request\Request;
use FluentCommunity\App\Models\Feed;
use FluentCommunity\App\Models\BaseSpace;
use FluentCommunity\Framework\Support\Arr;

class FeedsController extends Controller
{
    public function get(Request $request)
    {
        $start = microtime(true);
        $space = null;
        $bySpace = $request->get('space');
        $userId = $request->getSafe('user_id', 'intval', '');
        $selectedTopic = $request->getSafe('topic_slug', 'sanitize_text_field', '');
        $search = $request->getSafe('search', 'sanitize_text_field', '');
        if ($bySpace) {
            // just for validation
            $space = BaseSpace::where('slug', $bySpace)->first();
            if (!$space) {
                return $this->sendError('Invalid space slug');
            }
        }

        $feedsQuery = Feed::where('status', 'published')
            ->select(Feed::$publicColumns)
            ->with([
                    'xprofile'  => function ($q) {
                        $q->select(ProfileHelper::getXProfilePublicFields());
                    },
                    'comments'  => function ($q) use ($space) {
                        $q->where('status', 'published')
                            ->pendingCommentsByUser($this->getUserId(), $space)
                            ->with(['xprofile' => function ($q) {
                                $q->select(ProfileHelper::getXProfilePublicFields());
                            }]);
                    },
                    'space',
                    'reactions' => function ($q) {
                        $q->with([
                            'xprofile' => function ($query) {
                                $query->select(['user_id', 'avatar', 'display_name']);
                            }
                        ])
                            ->where('type', 'like')
                            ->limit(3);
                    },
                    'terms'     => function ($q) {
                        $q->select(['title', 'slug'])
                            ->where('taxonomy_name', 'post_topic');
                    }
                ]
            )
            ->searchBy($search, (array)$request->get('search_in', ['post_content']))
            ->byTopicSlug($selectedTopic)
            ->customOrderBy($request->get('type', ''));

        $stickyFeed = null;

        $disableSticky = $request->get('disable_sticky', '') == 'yes' || !!$search || !!$selectedTopic;

        if ($bySpace) {
            $feedsQuery = $feedsQuery->filterBySpaceSlug($bySpace);
        }

        if ($bySpace && !$disableSticky) {
            $feedsQuery = $feedsQuery->where('is_sticky', 0);
            if ($request->page == 1) {
                $stickyFeed = Feed::where('space_id', $space->id)
                    ->where('is_sticky', 1)
                    ->with([
                            'xprofile' => function ($q) {
                                $q->select(ProfileHelper::getXProfilePublicFields());
                            },
                            'comments' => function ($q) use ($space) {
                                $q->where('status', 'published')
                                    ->pendingCommentsByUser($this->getUserId(), $space)
                                    ->with(['xprofile' => function ($q) {
                                        $q->select(ProfileHelper::getXProfilePublicFields());
                                    }]);
                            },
                            'space'
                        ]
                    )
                    ->first();
            }
        }

        $currentUserId = get_current_user_id();

        if ($userId) {
            $feedsQuery = $feedsQuery->where('user_id', $userId);
            if ($userId != $currentUserId) {
                $feedsQuery = $feedsQuery->byUserAccess($currentUserId);
            }
        } else {
            $feedsQuery->byUserAccess($currentUserId);
        }

        $feedsQuery->pendingFeedsByUser($currentUserId, $space);

        do_action_ref_array('fluent_community/feeds_query', [&$feedsQuery, $request->all()]);

        $feeds = $feedsQuery->paginate();

        // add $stickyFeed to the first page
        if ($stickyFeed) {
            $stickyFeed = FeedsHelper::transformFeed($stickyFeed);
        }

        $feeds->getCollection()->each(function ($feed) {
            FeedsHelper::transformFeed($feed);
        });

        $data = [
            'feeds'  => $feeds,
            'sticky' => $stickyFeed
        ];

        $isMainFeed = $request->get('page') == 1 && !$search && !$userId;
        if ($isMainFeed && $currentUserId) {
            $data['last_fetched_timestamp'] = current_time('timestamp');
        }

        $data['execution_time'] = microtime(true) - $start;

        return $data;
    }

    public function getFeedBySlug(Request $request, $feed_slug)
    {
        $start = microtime(true);

        if ($request->get('context') == 'edit') {
            $feed = Feed::where('slug', $feed_slug)->first();

            if (!$feed || !$feed->hasEditAccess(get_current_user_id())) {
                return $this->sendError([
                    'message' => 'You do not have permission to edit this feed'
                ]);
            }

            return [
                'feed' => FeedsHelper::transformForEdit($feed)
            ];
        }

        $feed = Feed::where('slug', $feed_slug)
            ->where('status', 'published')
            ->select(Feed::$publicColumns)
            ->with([
                'xprofile'  => function ($q) {
                    $q->select(ProfileHelper::getXProfilePublicFields());
                },
                'space',
                'comments'  => function ($q) {
                    $q->where('status', 'published')
                        ->pendingCommentsByUser($this->getUserId())
                        ->with(['xprofile' => function ($q) {
                            $q->select(ProfileHelper::getXProfilePublicFields());
                        }]);
                },
                'reactions' => function ($q) {
                    $q->with([
                        'xprofile' => function ($query) {
                            $query->select(['user_id', 'avatar']);
                        }
                    ])
                        ->where('type', 'like')
                        ->limit(3);
                },
                'terms'     => function ($q) {
                    $q->select(['title', 'slug'])
                        ->where('taxonomy_name', 'post_topic');
                }
            ])
            ->byUserAccess($this->getUserId())
            ->pendingFeedsByUser($this->getUserId())
            ->first();

        if (!$feed) {
            return $this->sendError([
                'message' => __('The feed could not be found', 'fluent-commuity')
            ], 404);
        }

        $feed = FeedsHelper::transformFeed($feed);

        return [
            'feed'           => $feed,
            'execution_time' => microtime(true) - $start
        ];
    }

    public function getFeedById(Request $request, $feedId)
    {
        $feed = Feed::findOrFail($feedId);
        return $this->getFeedBySlug($request, $feed->slug);
    }

    public function getBookmarks(Request $request)
    {
        $userId = get_current_user_id();

        $feedsQuery = Feed::where('status', 'published')
            ->select(Feed::$publicColumns)
            ->with([
                    'xprofile' => function ($q) {
                        $q->select(ProfileHelper::getXProfilePublicFields());
                    },
                    'comments' => function ($q) use ($userId) {
                        $q->where('status', 'published')
                            ->pendingCommentsByUser($userId)
                            ->with(['xprofile' => function ($q) {
                                $q->select(ProfileHelper::getXProfilePublicFields());
                            }]);
                    },
                    'space'
                ]
            )
            ->byBookMarked($userId)
            ->byUserAccess($userId)
            ->searchBy($request->get('search'));


        if ($type = $request->get('type')) {
            $feedsQuery = $feedsQuery->where('type', $type);
        }

        $feeds = $feedsQuery->orderBy('id', 'DESC')
            ->paginate();

        $feeds->getCollection()->each(function ($feed) {
            FeedsHelper::transformFeed($feed);
        });

        $data = [
            'feeds' => $feeds
        ];

        if ($request->get('page') == 1) {
            $lastItem = FeedsHelper::getLastFeedId();
            if ($lastItem) {
                $data['last_id'] = $lastItem;
            }
        }

        return $data;
    }

    public function store(Request $request)
    {
        $user = $this->getUser(true);
        do_action('fluent_community/check_rate_limit/create_post', $user);
        $requestData = $request->all();

        $data = $this->sanitizeAndValidateData($requestData);
        $data['user_id'] = $user->ID;

        $feed = new Feed();
        $feed->user_id = $user->ID;
        $space = null;

        if ($spaceSlug = $request->get('space')) {
            $data['space_id'] = $this->validateAndSetSpace($spaceSlug, $user);
            if ($data['space_id']) {
                $space = Space::where('id', $data['space_id'])->first();
                if (!$space) {
                    return $this->sendError([
                        'message' => __('Please select a valid space to post in.', 'fluent-community')
                    ]);
                }
            }

            if (Arr::get($space->settings, 'topic_required') == 'yes') {
                $topicIds = (array)$request->get('topic_ids', []);
                $spaceTopics = Utility::getTopicsBySpaceId($space->id);
                $spaceTopicsIds = [];

                foreach ($spaceTopics as $topic) {
                    $spaceTopicsIds[] = $topic['id'];
                }

                $validTopicIds = array_intersect($topicIds, $spaceTopicsIds);

                if (!$validTopicIds) {
                    return $this->sendError([
                        'message' => __('Please select at least one topic to post in this space.', 'fluent-community'),
                        'shakes'  => [
                            'topic_ids' => true
                        ]
                    ]);
                }
            }

        } else if (!Helper::hasGlobalPost()) {
            return $this->sendError([
                'message' => __('Please select a valid space to post in.', 'fluent-community')
            ]);
        }

        $spaceId = Arr::get($data, 'space_id');
        $message = Arr::get($data, 'message');

        if ($isDulicate = $this->checkForDuplicatePost($user->ID, $message, $spaceId)) {
            return $isDulicate;
        }

        $mentions = FeedsHelper::getMentions($data['message'], Arr::get($data, 'space_id'));
        if ($mentions) {
            $data['message'] = $message;
            $message = $mentions['text'];
        }

        // replace new line with br
        $data['message_rendered'] = wp_kses_post(FeedsHelper::mdToHtml($message));

        $requestData['is_admin'] = $user->hasSpacePermission('community_moderator', $space);

        [$data, $mediaItems] = FeedsHelper::processFeedMetaData($data, $requestData);

        if (Arr::get($requestData, 'send_announcement_email') == 'yes' && $requestData['is_admin']) {
            $data['meta']['send_announcement_email'] = 'yes';
        } else if (isset($data['meta']['send_announcement_email'])) {
            $data['meta']['send_announcement_email'] = 'no';
        }

        $data = apply_filters('fluent_community/feed/new_feed_data', $data, $requestData);

        $formContentType = (string)Arr::get($requestData, 'content_type', '');

        if ($formContentType) {
            $data = apply_filters('fluent_community/feed/new_feed_data_type_' . $formContentType, $data, $requestData);
        }

        if (is_wp_error($data)) {
            return $this->sendError([
                'message' => $data->get_error_message(),
                'errors'  => $data->get_error_data()
            ]);
        }

        $feed->fill($data);

        $feed->save();

        if ($formContentType) {
            do_action('fluent_community/feed/just_created_type_' . $formContentType, $feed, $requestData);
        }

        if ($mediaItems) {
            $this->saveMediaItems($feed, $mediaItems);
        }

        $this->handleMentions($feed, $mentions ?? []);

        $feed->load(['xprofile', 'comments.xprofile']);
        if ($feed->space_id) {
            $feed->load(['space']);
            $topicIds = (array)$request->get('topic_ids', []);
            // take only max topics per post
            if ($topicIds) {
                $topicsConfig = Helper::getTopicsConfig();
                $topicIds = array_slice($topicIds, 0, $topicsConfig['max_topics_per_post']);
                $feed->attachTopics($topicIds, false);
            }
        }

        do_action('fluent_community/feed/created', $feed);

        if ($feed->space_id) {
            do_action('fluent_community/space_feed/created', $feed);
        }

        return [
            'feed'                   => FeedsHelper::transformFeed($feed),
            'message'                => __('Your post has been published', 'fluent-community'),
            'last_fetched_timestamp' => current_time('timestamp')
        ];
    }

    public function update(Request $request, $feedId)
    {
        $requestData = $request->all();

        $data = $this->sanitizeAndValidateData($requestData);
        $user = $this->getUser(true);
        $existingFeed = Feed::findOrFail($feedId);
        $user->canEditFeed($existingFeed, true);

        $message = $data['message'];
        $mentions = FeedsHelper::getMentions($data['message'], Arr::get($data, 'space_id'));
        if ($mentions) {
            $data['message'] = $message;
            $message = $mentions['text'];
        }

        // replace new line with br
        $data['message_rendered'] = wp_kses_post(FeedsHelper::mdToHtml($message));

        [$data, $mediaItems] = FeedsHelper::processFeedMetaData($data, $requestData, $existingFeed);

        if (Arr::get($requestData, 'send_announcement_email') == 'yes' && $user->hasSpacePermission('community_moderator', $existingFeed->space)) {
            $data['meta']['send_announcement_email'] = 'yes';
        } else if (Arr::get($existingFeed->meta, 'send_announcement_email')) {
            $data['meta']['send_announcement_email'] = Arr::get($existingFeed->meta, 'send_announcement_email');
        }

        $data = apply_filters('fluent_community/feed/update_feed_data', $data, $requestData);

        if (is_wp_error($data)) {
            return $this->sendError([
                'message' => $data->get_error_message(),
                'errors'  => $data->get_error_data()
            ]);
        }

        $newContentType = Arr::get($requestData, 'content_type', '');
        $exisitngContentType = $existingFeed->content_type;

        if ($newContentType != $exisitngContentType) {
            // Content Type Changed
            do_action('fluent_community/feed/updating_content_type_old_' . $exisitngContentType, $existingFeed, $newContentType, $requestData);
        }

        if ($newContentType != 'text') {
            $data = apply_filters('fluent_community/feed/update_feed_data_type_' . $newContentType, $data, $requestData, $existingFeed);
            if (is_wp_error($data)) {
                return $this->sendError([
                    'message' => $data->get_error_message(),
                    'errors'  => $data->get_error_data()
                ]);
            }
        }

        if ($message != $existingFeed->message) {
            $data['meta']['last_edited'] = [
                'user_id' => $user->ID,
                'time'    => current_time('mysql')
            ];
        }


        if ($newSpaceId = $request->get('new_space_id')) {
            if (!Helper::isUserInSpace($existingFeed->user_id, $newSpaceId)) {
                return $this->sendError([
                    'message' => __('The author is not a member of the selected space', 'fluent-community')
                ]);
            }

            $newSpace = Space::findOrFail($newSpaceId);

            // check if the current user is admin
            if (!$user->hasPermissionOrInCurrentSpace('community_admin', $newSpace)) {
                return $this->sendError([
                    'message' => __('Sorry, you do not have permission to change the space for this post', 'fluent-community')
                ]);
            }

            $data['space_id'] = $newSpaceId;
        }

        $data = apply_filters('fluent_community/feed/update_data', $data, $existingFeed);
        $existingFeed->fill($data);
        $dirty = $existingFeed->getDirty();

        $existingFeed->fill($data);
        $existingFeed->save();

        if ($message != $existingFeed->message) {
            $editHistory = $existingFeed->getCustomMeta('_edit_history', []);
            if (!$editHistory) {
                $editHistory = [];
            }

            $editHistory[] = array_filter([
                'user_id'      => $user->ID,
                'time'         => current_time('mysql'),
                'prev_message' => $existingFeed->message,
                'prev_title'   => $existingFeed->title
            ]);

            // get last 5 edit history
            $editHistory = array_slice($editHistory, -5);
            $existingFeed->updateCustomMeta('_edit_history', $editHistory);
        }

        if ($mediaItems) {
            $this->saveMediaItems($existingFeed, $mediaItems);
        }

        $existingFeed->load(['xprofile', 'comments.xprofile']);

        if ($existingFeed->space_id) {
            $existingFeed->load(['space']);
            $topicIds = (array)Arr::get($requestData, 'topic_ids', []);
            // take only max topics per post
            if ($topicIds) {
                $topicsConfig = Helper::getTopicsConfig();
                $topicIds = array_slice($topicIds, 0, $topicsConfig['max_topics_per_post']);
                $existingFeed->attachTopics($topicIds, true);
            }
        }

        if ($dirty) {
            do_action('fluent_community/feed/updated', $existingFeed, $dirty);
            if ($existingFeed->space_id) {
                do_action('fluent_community/space_feed/updated', $existingFeed);
            }
        }

        return [
            'feed'    => FeedsHelper::transformFeed($existingFeed),
            'message' => __('Your post has been updated', 'fluent-community')
        ];
    }

    public function patchFeed(Request $request, $feedId)
    {
        $feed = Feed::findOrFail($feedId);
        $user = $this->getUser(true);

        $isAuthor = $feed->user_id == $user->ID;
        $isMod = $user->hasPermissionOrInCurrentSpace('community_moderator', $feed->space);
        $isAdmin = $user->hasPermissionOrInCurrentSpace('community_admin', $feed->space);

        if (!$isMod && !$isAuthor && !$isAdmin) {
            return $this->sendError([
                'message' => __('You do not have permission to perform this action', 'fluent-community')
            ]);
        }

        $allData = $request->all();
        $validKeys = ['is_sticky', 'priority', 'comments_disabled'];

        if (!$isMod) {
            $validKeys = ['comments_disabled'];
        }

        $data = Arr::only($allData, $validKeys);

        $data = array_map('intval', $data);

        if (isset($data['is_sticky'])) {
            $data['is_sticky'] = $data['is_sticky'] ? 1 : 0;
            if ($data['is_sticky'] && $feed->space_id) {
                // remove all the sticky posts from the space
                Feed::where('space_id', $feed->space_id)->update(['is_sticky' => 0]);
            }
        }

        if (isset($data['comments_disabled'])) {
            $meta = $feed->meta;
            $meta['comments_disabled'] = $data['comments_disabled'] ? 'yes' : 'no';
            $data['meta'] = $meta;
        }

        if ($data) {
            $feed->fill($data);
            $dirty = $feed->getDirty();
            if ($dirty) {
                $feed->save();
                do_action('fluent_community/feed/updated', $feed, $dirty);
            }
        }

        return [
            'feed'    => $feed,
            'message' => __('Feed updated', 'fluent-community')
        ];
    }

    public function getLinks(Request $request)
    {
        return [
            'links' => Helper::getFeedLinks()
        ];
    }

    public function updateLinks(Request $request)
    {
        $links = $request->get('links', []);

        $links = array_map(function ($link) {
            return CustomSanitizer::santizeLinkItem($link);
        }, $links);

        Helper::updateFeedLinks($links);

        return [
            'message' => __('Links have been updated.', 'fluent-community'),
            'links'   => $links
        ];
    }

    private function saveMediaItems($feed, $mediaItems)
    {
        foreach ($mediaItems as $media) {
            $media->feed_id = $feed->id;
            $media->is_active = 1;
            $media->object_source = 'feed';
            $media->save();
        }
    }

    private function handleMentions($feed, $mentions)
    {
        if ($mentions) {
            do_action('fluent_community/feed_mentioned', $feed, $mentions['users']);
        }
    }

    private function sanitizeAndValidateData($data)
    {
        $data['type'] = 'text';

        $this->validate($data, [
            'message' => 'required'
        ], [
            'message.required' => __('Message is required', 'fluent-community'),
        ]);

        return FeedsHelper::sanitizeAndValidateData($data);
    }

    private function checkForDuplicatePost($userId, $message, $spaceId = null)
    {
        $message = trim($message);

        $exist = Feed::where('user_id', $userId)
            ->where('message', $message)
            ->where('created_at', '>', gmdate('Y-m-d H:i:s', current_time('timestamp') - 7 * 24 * 60 * 60))
            ->when($spaceId, function ($query) use ($spaceId) {
                $query->where('space_id', $spaceId);
            })
            ->first();

        if ($exist) {
            return $this->sendError(['message' => 'No duplicate post please!']);
        }

        return false;
    }

    private function validateAndSetSpace($spaceSlug, $user)
    {
        if ($spaceSlug == '__self__post__') {
            if (!Helper::hasGlobalPost()) {
                throw new \Exception(__('Please select a valid space to post in', 'fluent-community'));
            }

            return null;
        }

        $space = Space::where('slug', $spaceSlug)->first();

        if (!$space) {
            throw new \Exception(__('Please select a valid space to post in', 'fluent-community'));
        }

        $user->verifySpacePermission('can_create_post', $space);

        return $space->id;
    }

    public function deleteFeed(Request $request, $feed_id)
    {
        $feed = Feed::findOrFail($feed_id);

        $user = User::find(get_current_user_id());
        $user->canDeleteFeed($feed, true);
        do_action('fluent_community/feed/before_deleted', $feed);
        $feed->delete();

        do_action('fluent_community/feed/deleted', $feed_id);

        return [
            'message' => 'Feed has been deleted successfully'
        ];
    }

    public function deleteMediaPreview(Request $request, $feed_id)
    {
        $feed = Feed::findOrFail($feed_id);
        $user = User::find(get_current_user_id());
        $user->canDeleteFeed($feed, true);

        do_action('fluent_community/feed/media_deleted', $feed->media);

        $meta = $feed->meta;
        $meta['media_preview'] = null;

        $feed->meta = $meta;
        $feed->save();

        return [
            'message' => __('Media preview image has been removed successfully.', 'fluent-community')
        ];
    }

    public function handleMediaUpload(Request $request)
    {
        $allowedTypes = implode(
            ',',
            apply_filters('fluent_community/support_attachment_types', [
                'image/jpeg',
                'image/pjpeg',
                'image/jpeg',
                'image/pjpeg',
                'image/png',
                'image/gif',
                'image/webp'
            ])
        );

        $files = $this->validate($this->request->files(), [
            'file' => 'mimetypes:' . $allowedTypes,
        ], [
            'file.mimetypes' => __('The file must be an image type.', 'fluent-community')
        ]);

        add_filter('wp_handle_upload', [$this, 'fixImageOrientation']);
        $uploadedFiles = FileSystem::put($files);
        remove_filter('wp_handle_upload', [$this, 'fixImageOrientation']);

        $file = $uploadedFiles[0];

        $upload_dir = wp_upload_dir();

        $originalUrl = $file['url'];
        $orginalPath = $upload_dir['basedir'] . '/fluent-community/' . $file['file'];
        $originalFileType = $file['type'];
        $originalFileName = $file['file'];

        $willWebPConvert = $request->get('disable_convert') != 'yes';

        $willWebPConvert = apply_filters('fluent_community/convert_image_to_webp', $willWebPConvert, $file);
        $willResize = $request->get('resize');
        $maxWidth = $request->get('max_width');

        $willResize = apply_filters('fluent_community/media_upload_resize', $willResize, $file);

        if ($context = $request->get('context')) {
            $maxWidth = apply_filters('fluent_community/media_upload_max_width_' . $context, $maxWidth, $file);
        }

        if ($willResize && $maxWidth) {
            $upload_dir = wp_upload_dir();
            $fileUrl = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $file['url']);

            $editor = wp_get_image_editor($fileUrl);

            if (!is_wp_error($editor) && $editor->get_size()['width'] > $maxWidth) {
                // Current file extension
                $ext = pathinfo($file['url'], PATHINFO_EXTENSION);
                $imageExtensions = ['jpg', 'jpeg', 'png', 'gif'];
                $willConvert = in_array($ext, $imageExtensions) && $willWebPConvert;

                if ($willConvert) {
                    $imageExtensions = array_map(function ($ext) {
                        return '.' . $ext;
                    }, $imageExtensions);

                    $fileUrl = str_replace($imageExtensions, '.webp', $fileUrl);
                    $file['file'] = str_replace($imageExtensions, '.webp', $file['file']);
                    $file['url'] = str_replace($imageExtensions, '.webp', $file['url']);
                    $file['type'] = 'image/webp';
                }

                // resize the image
                $editor->resize($maxWidth, null, false);
                $editor->set_quality(90);
                if ($willConvert) {
                    $result = $editor->save($fileUrl, 'image/webp');
                    if ($result['mime-type'] == 'image/webp') {
                        // remove original file now
                        wp_delete_file(str_replace('.webp', '.' . $ext, $fileUrl));
                    }
                    $file['is_converted'] = true;
                } else {
                    $result = $editor->save($fileUrl);
                }

                if ($result['mime-type'] != 'image/webp') {
                    $file['file'] = $originalFileName;
                    $file['url'] = $originalUrl;
                    $file['type'] = $result['mime-type'];
                }

                $file['meta'] = [
                    'width'  => $editor->get_size()['width'],
                    'height' => $editor->get_size()['height']
                ];
            }
            $file['path'] = $upload_dir['basedir'] . '/fluent-community/' . $file['file'];
        } else {
            $upload_dir = wp_upload_dir();
            $file['path'] = $upload_dir['basedir'] . '/fluent-community/' . $file['file'];
        }

        if ($willWebPConvert && empty($file['is_converted']) && !$request->get('skip_convert')) {
            $path = $file['path'];
            $extension = pathinfo($path, PATHINFO_EXTENSION);

            $convertFromExtensions = ['png', 'jpg', 'jpeg', 'gif'];
            if ($extension != 'webp' && in_array($extension, $convertFromExtensions)) {
                // Let's convert to webp
                $editor = wp_get_image_editor($file['path']);
                if (!is_wp_error($editor)) {
                    $file['path'] = str_replace('.' . $extension, '.webp', $file['path']);
                    $file['url'] = str_replace('.' . $extension, '.webp', $file['url']);
                    $file['type'] = 'image/webp';
                    $result = $editor->save($file['path'], 'image/webp');

                    if ($result['mime-type'] != 'image/webp') {
                        $file['path'] = $orginalPath;
                        $file['url'] = $originalUrl;
                        $file['type'] = $result['mime-type'];
                    } else {
                        wp_delete_file($orginalPath);
                    }

                    $file['meta'] = [
                        'width'  => $editor->get_size()['width'],
                        'height' => $editor->get_size()['height']
                    ];
                }
            }
        }

        $mediaData = [
            'media_type' => $file['type'],
            'driver'     => 'local',
            'media_path' => $file['path'],
            'media_url'  => $file['url'],
            'settings'   => Arr::get($file, 'meta', [])
        ];

        $mediaData = apply_filters('fluent_community/media_upload_data', $mediaData, $file);

        if (is_wp_error($mediaData)) {
            return $this->sendError([
                'message' => $mediaData->get_error_message(),
                'errors'  => $mediaData->get_error_data()
            ]);
        }

        if (!$mediaData) {
            return $this->sendError([
                'message' => 'Error while uploading the media'
            ]);
        }

        // Let's create the media now
        $media = Media::create($mediaData);

        $mediaUrl = $media->public_url;

        $mediaUrl = add_query_arg([
            'media_key' => $media->media_key,
        ], $mediaUrl);

        return [
            'media' => [
                'url'       => $mediaUrl,
                'media_key' => $media->media_key,
                'type'      => $media->media_type,
                'width'     => Arr::get($media->settings, 'width'),
                'height'    => Arr::get($media->settings, 'height')
            ]
        ];
    }

    public function fixImageOrientation($file)
    {
        // Only process JPEG images (since they typically have EXIF data)
        $image_types = array('image/jpeg', 'image/jpg');
        if (!in_array($file['type'], $image_types)) {
            return $file;
        }

        // Check if the EXIF extension is available
        if (!function_exists('exif_read_data')) {
            return $file;
        }

        // Read EXIF data from the uploaded image
        $exif = @exif_read_data($file['file']);

        if (!$exif || !isset($exif['Orientation'])) {
            return $file;
        }

        $orientation = $exif['Orientation'];

        // Load the image based on the available library (Imagick or GD)
        if (extension_loaded('imagick') && class_exists('Imagick')) {
            // Use Imagick if available
            try {
                $image = new \Imagick($file['file']);
                switch ($orientation) {
                    case 3: // 180°
                        $image->rotateImage(new \ImagickPixel(), 180);
                        break;
                    case 6: // 90° clockwise
                        $image->rotateImage(new \ImagickPixel(), 90);
                        break;
                    case 8: // 90° counter-clockwise
                        $image->rotateImage(new \ImagickPixel(), -90);
                        break;
                }
                // Strip EXIF data to prevent further issues
                $image->stripImage();
                // Save the rotated image
                $image->writeImage($file['file']);
                $image->destroy();
            } catch (\Exception $e) {

            }
        } elseif (function_exists('imagecreatefromjpeg')) {
            // Use GD if Imagick is not available
            $image = @imagecreatefromjpeg($file['file']);
            if ($image === false) {
                return $file;
            }

            switch ($orientation) {
                case 3: // 180°
                    $image = imagerotate($image, 180, 0);
                    break;
                case 6: // 90° clockwise
                    $image = imagerotate($image, -90, 0);
                    break;
                case 8: // 90° counter-clockwise
                    $image = imagerotate($image, 90, 0);
                    break;
            }

            // Save the rotated image
            imagejpeg($image, $file['file'], 100);
            imagedestroy($image);
        }

        return $file;
    }

    public function getTicker(Request $request)
    {
        $start = microtime(true);

        do_action('fluent_communit/track_activity');
        $lastLoadedTimeStamp = $request->get('last_fetched_timestamp');

        //check if $lastLoadedTimeStamp is valid date
        if (!$lastLoadedTimeStamp || (current_time('timestamp') - $lastLoadedTimeStamp) > HOUR_IN_SECONDS) {
            return [
                'last_fetched_timestamp' => current_time('timestamp'),
                'error'                  => 'Invalid timestamp',
                'given'                  => $lastLoadedTimeStamp
            ];
        }

        $userId = get_current_user_id();
        if (!$userId) {
            return [
                'last_fetched_timestamp' => current_time('timestamp'),
                'error'                  => 'Invalid user'
            ];
        }

        $newItemsCount = Feed::where('created_at', '>', date('Y-m-d H:i:s', $lastLoadedTimeStamp))
            ->where('status', 'published')
            ->byUserAccess(get_current_user_id())
            ->count();

        $notificationCount = NotificationSubscriber::unread()->where('user_id', $userId)->count();

        return apply_filters('fluent_community/feed_ticker', [
            'last_fetched_timestamp'    => current_time('timestamp'),
            'new_items_count'           => $newItemsCount > 10 ? 10 : $newItemsCount,
            'unread_notification_count' => $notificationCount,
            'execution_time'            => microtime(true) - $start
        ]);
    }

    public function getOembed(Request $request)
    {
        $url = $request->get('url');
        // check if the url is valid
        $metaData = RemoteUrlParser::parse($url);

        if ($metaData && !is_wp_error($metaData)) {
            return [
                'oembed' => $metaData
            ];
        }

        return $this->send([
            'message' => 'No oembed data found',
            'url'     => $url
        ]);
    }

    public function markdownToHtml(Request $request)
    {
        $message = trim(sanitize_textarea_field($request->get('text', '')));

        $html = FeedsHelper::mdToHtml($message);

        return [
            'html' => $html
        ];
    }
}
