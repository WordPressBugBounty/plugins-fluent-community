<?php

namespace FluentCommunity\Modules\PushNotification;

use FluentCommunity\App\Models\Model;
use FluentCommunity\App\Models\SpaceUserPivot;

/**
 *  This is a placeholder model for Push Notification Subscriptions
 *
 *  Database Model
 *
 * @package FluentCommunity\Modules\PushNotification
 *
 * @version 2.1.10
 */
class FnSubscriptionModel extends Model
{
    protected $table = 'fn_subscriptions';

    protected $primaryKey = 'id';

    protected $guarded = ['id'];

    protected $fillable = [
        'user_id',
        'fcm_token',
        'browser',
        'device',
        'ip_address',
        'other_info',
        'user_agent',
        'platform',
        'status'
    ];

    /**
     * Relationship: User of this subscription
     */
    public function user()
    {
        return $this->belongsTo('FluentCommunity\App\Models\User', 'user_id');
    }

    public function space_pivot()
    {
        return $this->belongsTo(SpaceUserPivot::class, 'user_id', 'user_id')->withoutGlobalScopes();
    }

    public function xprofile()
    {
        return $this->belongsTo('FluentCommunity\App\Models\XProfile', 'user_id', 'user_id');
    }

}
