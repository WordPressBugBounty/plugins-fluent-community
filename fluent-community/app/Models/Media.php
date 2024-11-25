<?php

namespace FluentCommunity\App\Models;

use FluentCommunity\App\Models\XProfile;

/**
 *  Meta Model - DB Model for Notifications table
 *
 *  Database Model
 *
 * @package FluentCommunity\App\Models
 *
 * @version 1.0.0
 */
class Media extends Model
{
    protected $table = 'fcom_media_archive';

    protected $primaryKey = 'id';

    protected $guarded = ['id'];

    protected $appends = ['public_url'];

    protected $fillable = [
        'object_source',
        'user_id',
        'media_key',
        'feed_id',
        'is_active',
        'sub_object_id',
        'media_type',
        'driver',
        'media_path',
        'media_url',
        'settings'
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!isset($model->user_id)) {
                $model->user_id = get_current_user_id();
            }

            $model->media_key = $model->media_key ?: md5($model->media_url . '_' . time());
        });

        static::deleting(function ($media) {
            $media->deleteFile();
        });
    }

    public function scopeBySource($query, $sources = [])
    {
        if (!empty($sources)) {
            $query->whereIn('object_source', $sources);
        }

        return $query;
    }

    public function scopeByMediaKey($query, $key)
    {
        return $query->where('media_key', $key);
    }

    public function scopeByUser($query, $userId)
    {
        if ($userId) {
            return $query->where('user_id', $userId);
        }

        return $query;
    }

    public function setSettingsAttribute($value)
    {
        $this->attributes['settings'] = maybe_serialize($value);
    }

    public function getSettingsAttribute($value)
    {
        $settings = maybe_unserialize($value);

        if (!$settings) {
            $settings = [];
        }

        return $settings;
    }


    public function feed()
    {
        return $this->belongsTo(Feed::class, 'feed_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function xprofile()
    {
        return $this->belongsTo(XProfile::class, 'user_id', 'user_id');
    }

    public function getPublicUrlAttribute()
    {
        return apply_filters('fluent_community/media_public_url_' . $this->driver, $this->media_url, $this);
    }

    public function deleteFile()
    {
        if ($this->driver == 'local') {
            if (file_exists($this->media_path)) {
                wp_delete_file($this->media_path);
            }
        } else {
            do_action('fluent_community/delete_remote_media_' . $this->driver, $this);
        }
    }
}
