<?php
// phpcs:disable
namespace FluentCommunity\Modules\CLI;


use FluentCommunity\App\Models\User;
use FluentCommunity\Framework\Support\Arr;
use FluentCommunityPro\App\Modules\LeaderBoard\Services\LeaderBoardHelper;

class Commands
{

    /*
     * usage: wp fluent_community sync_x_profile --all --force
     */
    public function sync_x_profile($args, $assoc_args = [])
    {
        $isForced = Arr::get($assoc_args, 'force', false) == 1;

        $users = User::orderBy('ID', 'ASC')->get();

        foreach ($users as $user) {
            $result = $user->syncXProfile($isForced);

            \WP_CLI::line('Synced XProfile for UserID: ' . $user->ID . ' - ' . $result->id);
        }

        \WP_CLI::success('XProfile Synced Successfully');

    }

    public function recalculate_user_points()
    {
        if (!defined('FLUENT_COMMUNITY_PRO')) {
            \WP_CLI::error('This command is only available in Fluent Community Pro');
        }

        $xProfiles = \FluentCommunity\App\Models\XProfile::all();

        foreach ($xProfiles as $xProfile) {
            $currentPoint = LeaderBoardHelper::recalculateUserPoints($xProfile->user_id);
            if ($currentPoint > $xProfile->total_points) {
                $oldPoints = $xProfile->total_points;
                $xProfile->total_points = $currentPoint;
                $xProfile->save();

                do_action('fluent_community/user_points_updated', $xProfile, $oldPoints);

                \WP_CLI::line('Recalculated Points for User: ' . $xProfile->display_name . ' - ' . $oldPoints . ' to ' . $currentPoint);
            }
        }

        \WP_CLI::success('Points Recalculated Successfully for ' . count($xProfiles) . ' users');
    }
}
