<?php

namespace FluentCommunity\App\Models;

/**
 *  NotificationPreference Model - DB Model for fcom_notification_prefs table
 *
 *  Stores a user's explicit notification overrides as an (event x channel) matrix.
 *  A missing row means "inherit the site default" - see NotificationPref::isEnabled().
 *  Only explicit overrides are stored, so a member on all defaults costs zero rows.
 *
 *  Unlike the legacy pref rows in fcom_notification_users, the channel is a real
 *  column here. Adding push (or any future channel) is new rows, not new key names.
 *
 * @package FluentCommunity\App\Models
 *
 * @property int         $id
 * @property int         $user_id
 * @property string      $channel
 * @property string      $event_key
 * @property int         $object_id
 * @property int         $value
 *
 * @version 1.0.0
 */
class NotificationPreference extends Model
{
    protected $table = 'fcom_notification_prefs';

    protected $primaryKey = 'id';

    protected $guarded = ['id'];

    protected $fillable = [
        'user_id',
        'channel',
        'event_key',
        'object_id',
        'value'
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function xprofile()
    {
        return $this->belongsTo(XProfile::class, 'user_id', 'user_id');
    }

    public function scopeForChannel($query, $channel)
    {
        return $query->where('channel', $channel);
    }

    public function scopeForEvent($query, $eventKey)
    {
        return $query->where('event_key', $eventKey);
    }

    /**
     * Global prefs (not scoped to a space or other object) use object_id = 0
     * rather than NULL so the unique key can enforce one row per cell.
     */
    public function scopeGlobalScoped($query)
    {
        return $query->where('object_id', 0);
    }

    public function scopeEnabled($query)
    {
        return $query->where('value', 1);
    }
}
