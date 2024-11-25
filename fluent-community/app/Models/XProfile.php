<?php

namespace FluentCommunity\App\Models;


use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Models\Contact;
use FluentCommunity\App\Models\Feed;
use FluentCommunity\App\Models\User;
use FluentCommunity\Framework\Support\Arr;
use FluentCommunity\Modules\Course\Model\Course;
use FluentCrm\App\Models\Subscriber;

/**
 *  FluentCommunity XProfile Model
 *
 *  Database Model
 *
 * @package FluentCommunity\App\Models
 *
 * @version 1.1.0
 */
class XProfile extends Model
{
    protected $table = 'fcom_xprofile';

    protected $guarded = ['id'];

    protected $primaryKey = 'user_id';

    protected $casts = [
        'user_id'      => 'integer',
        'total_points' => 'integer',
        'is_verified'  => 'integer',
    ];

    protected $fillable = [
        'user_id',
        'total_points',
        'username',
        'status',
        'is_verified',
        'display_name',
        'avatar',
        'short_description',
        'last_activity',
        'meta',
        'created_at'
    ];

    protected $searchable = [
        'display_name',
        'username'
    ];

    protected $appends = ['badge'];

    public function scopeSearchBy($query, $search)
    {
        if ($search) {
            $fields = $this->searchable;

            $query->where(function ($q) use ($fields, $search) {
                $q->where(array_shift($fields), 'LIKE', "%$search%");
                foreach ($fields as $field) {
                    $q->orWhere($field, 'LIKE', "%$search%");
                }
            });
        }
        
        return $query;
    }

    public function scopeMentionBy($query, $search)
    {
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('display_name', 'LIKE', "$search%")
                    ->orWhere('username', 'LIKE', "$search%");
            });
        }

        return $query;
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'ID');
    }

    public function contact()
    {
        return $this->hasOne(Contact::class, 'user_id', 'user_id');
    }

    public function spaces()
    {
        return $this->belongsToMany(BaseSpace::class, 'fcom_space_user', 'user_id', 'space_id')
            ->withPivot(['role', 'status', 'created_at']);
    }

    public function posts()
    {
        return $this->hasMany(Feed::class, 'user_id', 'user_id');
    }

    public function courses()
    {
        return $this->belongsToMany(Course::class, 'fcom_space_user', 'user_id', 'space_id')
            ->withPivot(['role', 'status', 'created_at']);
    }

    public function space_pivot()
    {
        return $this->belongsTo(SpaceUserPivot::class, 'user_id', 'user_id')->withoutGlobalScopes();
    }

    public function community_role()
    {
        return $this->belongsTo(Meta::class, 'user_id', 'object_id')
            ->where('meta_key', '_user_community_roles');
    }

    /**
     * Accessor to get dynamic photo attribute
     * @return string
     */
    public function getAvatarAttribute()
    {
        if (!empty($this->attributes['avatar'])) {
            return $this->attributes['avatar'];
        }

        $url = Utility::getFromCache('user_avatar_' . $this->user_id, function () {
            return get_avatar_url($this->user_id, [
                'size'    => 128,
                'default' => apply_filters('fluent_community/default_avatar', 'https://ui-avatars.com/api/?name=' . $this->display_name . '/128', $this)
            ]);
        }, WEEK_IN_SECONDS);

        if (!$url) {
            $url = apply_filters('fluent_community/default_avatar', FLUENT_COMMUNITY_PLUGIN_URL . 'assets/images/placeholder.png', $this);
        }

        return $url;
    }

    public function getBadgeAttribute()
    {
        return apply_filters('fluent_community/xprofile/badge', null, $this);
    }

    public function getCrmContact()
    {
        if (!defined('FLUENTCRM')) {
            return null;
        }

        if ($this->user_email) {
            return Subscriber::where('user_id', $this->ID)
                ->orWhere('email', $this->user_email)
                ->first();
        }

        return Subscriber::where('user_id', $this->ID)
            ->first();
    }

    public function getMetaAttribute($value)
    {
        $settings = maybe_unserialize($value);

        if (!$settings) {
            $settings = [
                'cover_photo' => '',
                'website'     => ''
            ];
        }

        return $settings;
    }

    public function setMetaAttribute($value)
    {
        if (!$value) {
            $value = [
                'cover_photo' => '',
                'website'     => ''
            ];
        }

        $this->attributes['meta'] = maybe_serialize($value);
    }

    public function getFirstName()
    {
        if (!$this->display_name) {
            return '';
        }
        $fullName = explode(' ', $this->display_name);
        return $fullName[0];
    }

    public function getLastName()
    {

        if (!$this->display_name) {
            return '';
        }

        $fullName = explode(' ', $this->display_name);

        // remove the first name
        array_shift($fullName);

        if (count($fullName) == 0) {
            return '';
        }
        return implode(' ', $fullName);
    }

    public function getCompletionScore()
    {
        $scores = [
            'first_name'        => 20,
            'last_name'         => 20,
            'website'           => 30,
            'cover_photo'       => 20,
            'avatar'            => 20,
            'short_description' => 30,
            'social_links'      => 20
        ];

        $score = 0;

        if ($this->getFirstName()) {
            $score += $scores['first_name'];
        }

        if ($this->getLastName()) {
            $score += $scores['last_name'];
        }

        $meta = $this->meta;
        if (Arr::get($meta, 'website')) {
            $score += $scores['website'];
        }

        if (Arr::get($meta, 'cover_photo')) {
            $score += $scores['cover_photo'];
        }

        if ($this->short_description) {
            $score += $scores['short_description'];
        }

        if ($score >= 100) {
            return 100;
        }

        if (!empty($meta['social_links']) && array_filter(Arr::get($meta, 'social_links', []))) {
            $score += $scores['social_links'];
        }

        if (!empty($this->attributes['avatar'])) {
            $score += $scores['avatar'];
        }

        return $score > 100 ? 100 : $score;
    }

}
