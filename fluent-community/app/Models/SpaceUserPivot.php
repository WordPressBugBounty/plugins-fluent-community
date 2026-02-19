<?php

namespace FluentCommunity\App\Models;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Models\Model;
use FluentCommunity\App\Models\XProfile;

class SpaceUserPivot extends Model
{
    protected $table = 'fcom_space_user';

    protected $guarded = ['id'];

    protected $fillable = [
        'space_id',
        'user_id',
        'status',
        'role',
        'meta'
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

}
