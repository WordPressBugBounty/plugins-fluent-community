<?php

namespace FluentCommunity\App\Models;

use FluentCommunity\App\App;
use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Services\CustomSanitizer;
use FluentCommunity\App\Services\LockscreenService;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\Framework\Support\Arr;

class BaseSpace extends Model
{
    protected $table = 'fcom_spaces';

    protected static $type = 'community';

    protected $guarded = ['id'];

    protected $fillable = [
        'created_by',
        'parent_id',
        'title',
        'slug',
        'description',
        'logo',
        'cover_photo',
        'type',
        'privacy',
        'status',
        'serial',
        'settings'
    ];

    protected $searchable = [
        'title',
        'description'
    ];

    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->created_by)) {
                $model->created_by = get_current_user_id();
            }

            if (empty($model->slug)) {
                $slug = sanitize_title($model->tilte, time());
                $model->slug = $slug;
            }
            $model->type = static::$type;
        });

        static::addGlobalScope('type', function ($query) {
            if (static::$type) {
                $query->where('type', static::$type);
            }

            return $query;
        });

        static::deleting(function ($space) {
            Media::where('sub_object_id', $space->id)
                ->whereIn('object_source', ['space_logo', 'space_cover_photo'])
                ->update([
                    'is_active' => 0
                ]);
        });
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'created_by', 'ID');
    }

    public function space_pivot()
    {
        return $this->belongsTo(SpaceUserPivot::class, 'id', 'space_id');
    }

    public function scopeSearchBy($query, $search)
    {
        if ($search) {
            $fields = $this->searchable;
            $query->where(function ($query) use ($fields, $search) {
                $query->where(array_shift($fields), 'LIKE', "%$search%");
                foreach ($fields as $field) {
                    $query->orWhere($field, 'LIKE', "$search%");
                }
            });
        }

        return $query;
    }

    public function scopeFilterByUserId($query, $userId)
    {
        if (!$userId) {
            return $query->where('privacy', 'public');
        }

        $ids = get_user_meta($userId, '_fcom_space_ids', true);

        if (!$ids) {
            return $query->where('privacy', 'public');
        }

        return $query->whereIn('id', $ids);
    }

    public function scopeByUserAccess($query, $userId)
    {
        if (!$userId) {
            return $query->where('privacy', 'public');
        }

        return $this->where(function ($query) use ($userId) {
            return $query->where('privacy', 'public')
                ->orWhereHas('members', function ($query) use ($userId) {
                    return $query->where('user_id', $userId);
                });
        });
    }

    public function posts()
    {
        return $this->hasMany(Feed::class, 'space_id', 'id');
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'fcom_space_user', 'space_id', 'user_id')
            ->withPivot(['role', 'created_at', 'status']);
    }

    public function x_members()
    {
        return $this->belongsToMany(XProfile::class, 'fcom_space_user', 'space_id', 'user_id', 'id', 'user_id')
            ->withPivot(['role', 'created_at', 'status']);
    }

    public function group()
    {
        return $this->belongsTo(SpaceGroup::class, 'parent_id', 'id');
    }

    public function getMembership($userId)
    {
        if (!$userId) {
            return null;
        }

        return $this->members()->where('user_id', $userId)->first();
    }

    public function isCourseSpace()
    {
        return $this->type == 'course';
    }

    public function isAdmin($userId, $checkModerator = false)
    {
        if (Helper::isSiteAdmin($userId)) {
            return true;
        }

        $membership = $this->getMembership($userId);
        if (!$membership) {
            return false;
        }

        $roles = ['admin'];

        if ($checkModerator) {
            $roles[] = 'moderator';
        }

        return in_array($membership->pivot->role, $roles);
    }

    public function updateCustomData($data, $removeSrc = false)
    {
        $deletePhotos = [];
        if (isset($data['logo'])) {
            $deletePhotos[] = $this->logo;
            $this->logo = sanitize_url($data['logo']);
        }

        if (isset($data['cover_photo'])) {
            $deletePhotos[] = $this->cover_photo;
            $this->cover_photo = sanitize_url($data['cover_photo']);
        }

        if (isset($data['description'])) {
            $this->description = wp_kses_post($data['description']);
        }

        if (isset($data['title'])) {
            $this->title = sanitize_text_field($data['title']);
        }

        if (isset($data['privacy'])) {
            $this->privacy = sanitize_text_field($data['privacy']);
        }

        if (isset($data['parent_id'])) {
            if (!empty($data['parent_id'])) {
                $group = SpaceGroup::find($data['parent_id']);
                if (!$group) {
                    throw new \Exception('Invalid group id', 400);
                }
                $this->parent_id = $group->id;
            } else {
                $this->parent_id = NULL;
            }
        }
        
        if (isset($data['settings'])) {
            $shapSvg = '';
            if (!empty($data['settings']['shape_svg'])) {
                $shapSvg = CustomSanitizer::sanitizeSvg($data['settings']['shape_svg']);
            }

            $exisitingSetting = $this->settings;
            $settings = Arr::only(fluentCommunitySanitizeArray($data['settings']), array_keys($this->defaultSettings()));

            if ($shapSvg) {
                $settings['shape_svg'] = $shapSvg;
            } else if (!empty($settings['emoji'])) {
                $settings['emoji'] = CustomSanitizer::sanitizeEmoji($settings['emoji']);
            }

            $settings['links'] = Arr::get($exisitingSetting, 'links', []);

            if (isset($settings['og_image'])) {
                $ogImageUrl = Arr::get($settings, 'og_image');
                if ($ogImageUrl) {
                    $ogImageMedia = Helper::getMediaFromUrl($ogImageUrl);
                    if ($ogImageMedia && $ogImageMedia->is_active) {
                        unset($settings['og_image']);
                    } else if ($ogImageMedia) {
                        $ogImageMedia->update([
                            'is_active'     => true,
                            'user_id'       => get_current_user_id(),
                            'sub_object_id' => $this->id,
                            'object_source' => 'space_og_media'
                        ]);
                        $settings['og_image'] = $ogImageMedia->public_url;
                    } else {
                        $settings['og_image'] = sanitize_url($ogImageUrl);
                    }
                }
            } else {
                $deletePhotos[] = Arr::get($exisitingSetting, 'og_image');
            }

            $settings = wp_parse_args($settings, $exisitingSetting);
            $this->settings = $settings;
        }

        if (!empty($data['slug']) && $data['slug'] !== $this->slug) {
            $newSlug = sanitize_title($data['slug']);

            if (empty($newSlug)) {
                throw new \Exception('Invalid slug', 400);
            }

            $exist = App::getInstance('db')->table('fcom_spaces')->where('slug', $newSlug)
                ->where('id', '!=', $this->id)
                ->exists();

            if ($exist) {
                throw new \Exception(__('Slug already exist. Please use a different slug', 'fluent-community'), 400);
            }

            $this->slug = $newSlug;
        }

        $deletePhotos = array_filter($deletePhotos);
        if ($removeSrc && $deletePhotos) {
            $deletePhotos = array_filter($deletePhotos);
            do_action('fluent_community/remove_medias_by_url', $deletePhotos, [
                'sub_object_id' => $this->id,
            ]);
        }

        $dirty = $this->getDirty();

        if ($dirty) {
            $this->save();
            if ($this->type == 'community') {
                do_action('fluent_community/space/updated', $this, $dirty);
            }
        }

        return $this;
    }

    public function getSettingsAttribute($value)
    {
        $settings = maybe_unserialize($value);

        if (!$settings) {
            $settings = [];
        }

        return wp_parse_args($settings, $this->defaultSettings());
    }

    public function defaultSettings()
    {
        return [
            'restricted_post_only' => 'no',
            'emoji'                => '',
            'shape_svg'            => '',
            'custom_lock_screen'   => 'no',
            'can_request_join'     => 'no',
            'layout_style'         => 'timeline',
            'show_sidebar'         => 'yes',
            'og_image'             => '',
            'links'                => [],
            'topic_required'       => 'no',
            'hide_members_count'   => 'no', // yes / no
            'members_page_status'  => 'members_only', // members_only, everybody, logged_in, admin_only
        ];
    }

    public function setSettingsAttribute($value)
    {
        $this->attributes['settings'] = maybe_serialize($value);
    }

    public function getPublicPermissions()
    {
        if ($this->privacy == 'public') {
            return [
                'can_view_info'    => true,
                'can_view_posts'   => true,
                'can_view_members' => $this->canViewMembers(null),
                'can_create_post'  => false
            ];
        }

        return [
            'can_view_info'  => false,
            'can_view_posts' => false,
        ];
    }

    public function getUserPermissions($user = null)
    {
        if (!$user) {
            return $this->getPublicPermissions();
        }

        return $user->getSpacePermissions($this);
    }

    public function canViewMembers($user)
    {
        $viewStatus = Arr::get($this->settings, 'members_page_status');
        if ($viewStatus === 'everybody') {
            return true;
        }

        if (!$user) {
            return false;
        }

        if ($viewStatus === 'logged_in') {
            return true;
        }

        if ($viewStatus === 'members_only') {
            return Helper::isUserInSpace($user->ID, $this->id);
        }

        return $this->isAdmin($user->ID, true);
    }

    public function verifyUserPermisson($user, $permission, $exception = true)
    {
        $permissions = $this->getUserPermissions($user);

        $hasPermission = $permissions[$permission] ?? false;

        if (!$hasPermission && $exception) {
            throw new \Exception('Sorry you do not have permission ' . esc_html($permission), 403);
        }

        return $hasPermission;
    }

    public function setLockscreen($settingFields)
    {
        return $this->updateCustomMeta('lockscreen_settings', $settingFields);
    }

    public function getPermalink()
    {
        if ($this->type == 'community') {
            return Helper::baseUrl('space/' . $this->slug . '/home');
        }

        if ($this->type == 'course') {
            return Helper::baseUrl('course/' . $this->slug . '/lessons');
        }

        return Helper::baseUrl('/');
    }

    public function getLockscreen()
    {
        return LockscreenService::getLockscreenSettings($this);
    }

    public function getCustomMeta($key, $default = null)
    {
        return Helper::getSpaceMeta($this->id, $key, $default);
    }

    public function updateCustomMeta($key, $value)
    {
        return Helper::updateSpaceMeta($this->id, $key, $value);
    }

    public function getIconMark($isHtml = true)
    {
        if (!$isHtml) {
            return '';
        }

        if ($this->logo) {
            return '<img alt="" src="' . $this->logo . '"/>';
        }

        if ($imoji = Arr::get($this->settings, 'emoji')) {
            return '<span class="fcom_emoji">' . $imoji . '</span>';
        }

        if ($svg = Arr::get($this->settings, 'shape_svg')) {
            return '<span class="fcom_shape"><i class="el-icon">' . $svg . '</i></span>';
        }

        return '';
    }

    public function syncTopics($topicIds)
    {
        $topics = Term::where('taxonomy_name', 'post_topic')->whereIn('id', $topicIds)->get();
        $topicIds = $topics->pluck('id')->toArray();
        $existIds = [];

        foreach ($topicIds as $topicId) {
            $relation = Meta::where('object_id', $topicId)
                ->where('object_type', 'term_space_relation')
                ->where('meta_key', $this->id)
                ->first();

            if ($relation) {
                $existIds[] = $relation->id;
                continue;
            }

            // We have to create one
            $new = Meta::create([
                'object_id'   => $topicId,
                'meta_key'    => $this->id,
                'object_type' => 'term_space_relation'
            ]);

            $existIds[] = $new->id;
        }

        // Remove other relations
        Meta::whereNotIn('id', $existIds)
            ->where('object_type', 'term_space_relation')
            ->where('meta_key', $this->id)
            ->delete();

        Utility::forgetCache('fluent_community_post_topics');

        return $this;
    }

}
