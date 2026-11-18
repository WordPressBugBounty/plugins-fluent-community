<?php

namespace FluentCommunity\App\Hooks\CLI;

use FluentCommunity\App\Models\XProfile;
use FluentCommunity\Modules\Migrations\Helpers\BPMigratorHelper;
use FluentCommunity\Modules\Migrations\Helpers\PostMigrator;
use FluentCommunity\App\Models\User;
use FluentCommunity\Framework\Support\Arr;

class Commands
{

    /**
     * Deletes all FluentCommunity data, then imports the BuddyPress data.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Skip the confirmation prompt.
     */
    public function migrate_from_bb($args, $assoc_args = [])
    {
        \WP_CLI::confirm('This deletes all FluentCommunity data before it imports BuddyPress data. Continue?', $assoc_args);

        $migrator = new BuddyPressMigrator();

        \WP_CLI::line('Starting BuddyPress Data Migration...');
        BPMigratorHelper::deleteCurrentData();
        \WP_CLI::line('Deleted existing Fluent Community data.');
        $migrator->migrateGroups();
        \WP_CLI::line('Migrated BuddyPress Groups to Fluent Community Spaces.');
        $migrator->syncGroupMembers();
        \WP_CLI::line('Synchronized Group Members to Space Members.');
        $migrator->migratePosts();
        \WP_CLI::line('Migrated BuddyPress Posts to Fluent Community Feeds.');
        $migrator->syncUsers();
        \WP_CLI::line('Synchronized Users.');

        \WP_CLI::line('Calculating User points.');
        $this->recalculate_user_points();

        \WP_CLI::success('BuddyPress Data Migration Completed Successfully.');

        die();
    }

    /**
     * usage: wp fluent_community sync_x_profile --force
     */
    public function sync_x_profile($args, $assoc_args = [])
    {
        $isForced = (int) Arr::get($assoc_args, 'force', false) === 1;

        $users = User::orderBy('ID', 'ASC')->get();

        foreach ($users as $user) {
            $result = $user->syncXProfile($isForced);
            \WP_CLI::line('Synced XProfile for UserID: ' . $user->ID . ' - ' . $result->id);
        }

        \WP_CLI::success('XProfile Synced Successfully');
    }

    /**
     * Lists uploads whose local file is missing or empty. Read-only.
     *
     * On sites that use WordPress's GD image editor, an image upload could end as an empty
     * file (a GIF or palette PNG converted to WebP). Such images cannot be repaired; ask
     * the member to upload them again.
     *
     * ## OPTIONS
     *
     * [--include-drafts]
     * : Also check uploads that were never attached to a post or comment.
     *
     * [--format=<format>]
     * : table, csv, json or count.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp fluent_community find_empty_media
     *     wp fluent_community find_empty_media --format=csv > empty-media.csv
     */
    public function find_empty_media($args, $assoc_args = [])
    {
        $rows = \FluentCommunity\App\Services\Libs\EmptyMediaFinder::find((bool) Arr::get($assoc_args, 'include-drafts', false));
        $format = Arr::get($assoc_args, 'format', 'table');

        if ($format === 'count') {
            \WP_CLI::line((string) count($rows));
            return;
        }

        if (!$rows) {
            \WP_CLI::success('No missing or empty media files found.');
            return;
        }

        \WP_CLI\Utils\format_items($format, $rows, [ 'id', 'problem', 'media_type', 'object_source', 'feed_id', 'comment_id', 'user_id', 'created_at', 'url' ]);

        if ($format === 'table') {
            \WP_CLI::warning(sprintf('%d media files are missing or empty. They cannot be repaired; the post or comment needs the image uploaded again.', count($rows)));
        }
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

            $currentPoint = BPMigratorHelper::recalculateUserPoints($xProfile->user_id);
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
}
