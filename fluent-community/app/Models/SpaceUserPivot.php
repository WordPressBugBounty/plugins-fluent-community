<?php

namespace FluentCommunity\App\Models;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Models\Model;
use FluentCommunity\App\Models\XProfile;

/**
 * A member's row in a space or course (fcom_space_user).
 *
 * @property int         $id
 * @property int         $space_id
 * @property int         $user_id
 * @property string      $status     active | pending | scheduled (a course enrollment waiting for starts_at)
 * @property string      $role
 * @property array       $meta
 * @property string|null $expire_at
 * @property string|null $starts_at  site time the member's access starts (course drip anchor)
 * @property string|null $created_at
 */
class SpaceUserPivot extends Model
{
    /**
     * `updated_at` holds the member's last visit to the space (see Helper::markSpaceSeen),
     * so membership saves must not move it.
     */
    const UPDATED_AT = null;

    /**
     * Option holding when `updated_at` started meaning "last visit".
     */
    const SEEN_SINCE_OPTION = 'fluent_community_space_seen_since';

    /**
     * A course enrollment that waits for `starts_at`. It gives no access until it turns
     * 'active' (CourseHelper::activateScheduledEnrollment).
     */
    const STATUS_SCHEDULED = 'scheduled';

    protected $table = 'fcom_space_user';

    protected $guarded = [ 'id' ];

    // The last visit is private: the members API returns these rows to other members.
    protected $hidden = [ 'updated_at' ];

    protected $fillable = [
        'space_id',
        'user_id',
        'status',
        'role',
        'meta',
        'starts_at',
    ];

    public function scopeBySpace($query, $spaceId)
    {
        if (!$spaceId) {
            return $query;
        }

        $query->where('space_id', $spaceId);

        return $query;
    }


    public function scopeByUser($query, $userId)
    {
        if (!$userId) {
            return $query;
        }

        $query->where('user_id', $userId);

        return $query;
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'ID');
    }

    public function xprofile()
    {
        return $this->belongsTo(XProfile::class, 'user_id', 'user_id');
    }

    public function space()
    {
        return $this->belongsTo(BaseSpace::class, 'space_id', 'id')->withoutGlobalScopes();
    }

    public function setMetaAttribute($value)
    {
        $this->attributes['meta'] = maybe_serialize($value);
    }

    public function getMetaAttribute($value)
    {
        if (!$value) {
            return [];
        }

        if (is_string($value)) {
            $decodedMeta = json_decode($value, true);
            if (is_array($decodedMeta)) {
                $value = $decodedMeta;
            }
        }

        $value = Utility::safeUnserialize($value);

        if (!is_array($value)) {
            return [];
        }

        return $value;
    }

    /**
     * Active member counts keyed by space id, in one query. Pending, blocked and
     * deactivated members are not counted.
     */
    public static function getActiveMemberCounts($spaceIds)
    {
        if (!$spaceIds) {
            return [];
        }

        return static::whereIn('space_id', $spaceIds)
            ->where('status', 'active')
            ->whereHas('xprofile', function ($q) {
                $q->where('status', 'active');
            })
            ->whereHas('user')
            ->selectRaw('space_id, COUNT(*) as total')
            ->groupBy('space_id')
            ->pluck('total', 'space_id')
            ->toArray();
    }
}
