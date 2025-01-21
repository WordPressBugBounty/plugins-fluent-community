<?php

namespace FluentCommunity\App\Hooks\CLI;

use FluentCommunity\App\App;
use FluentCommunity\App\Models\User;
use FluentCommunity\Framework\Support\Arr;
use FluentCommunityPro\App\Modules\LeaderBoard\Services\LeaderBoardHelper;

class Commands
{

    /**
     * usage: wp fluent_community sync_x_profile --force
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

    /**
     * usage: wp fluent_community recalculate_user_points
     */
    public function recalculate_user_points()
    {
        $xProfiles = \FluentCommunity\App\Models\XProfile::all();

        $progress = \WP_CLI\Utils\make_progress_bar('Recalculating Points', count($xProfiles));

        foreach ($xProfiles as $xProfile) {

            $progress->tick();

            $currentPoint = LeaderBoardHelper::recalculateUserPoints($xProfile->user_id);
            if ($currentPoint > $xProfile->total_points) {
                $oldPoints = $xProfile->total_points;
                $xProfile->total_points = $currentPoint;
                $xProfile->save();
                do_action('fluent_community/user_points_updated', $xProfile, $oldPoints);
                \WP_CLI::line(
                    'Recalculated Points for User: ' . $xProfile->display_name . ' - ' . $oldPoints . ' to ' . $currentPoint
                );
            }
        }

        $progress->finish();

        \WP_CLI::success('Points Recalculated Successfully for ' . count($xProfiles) . ' users');
    }

    public function truncate_tables()
    {
        $db = App::make('db');
        $db->table('users')->truncate();
        $db->table('usermeta')->truncate();
        $db->table('fcom_xprofile')->truncate();
        $db->table('fcom_posts')->truncate();
        $db->table('fcom_post_comments')->truncate();
        $db->table('fcom_spaces')->truncate();
        $db->table('fcom_space_user')->truncate();
        $db->table('fcom_terms')->truncate();
        $db->table('fcom_term_feed')->truncate();
        $db->table('fcom_post_reactions')->truncate();
        $db->table('fcom_user_activities')->truncate();
        delete_option('_fcom_bp_migrations_meta');

        $user_id = wp_create_user('admin', 'admin', 'admin@gmail.com');
        $user    = new \WP_User($user_id);
        $user->set_role('administrator');

        \WP_CLI::success('Tables Truncated Successfully');
    }
}
