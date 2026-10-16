<?php

namespace FluentCommunity\App\Http\Controllers;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\App\Services\Insights\Metrics\Sql;
use FluentCommunity\App\Services\Insights\CardRegistry;
use FluentCommunity\App\Services\Insights\CsvExporter;
use FluentCommunity\App\Services\Insights\Range;
use FluentCommunity\App\Services\Insights\Scope;
use FluentCommunity\Database\Migrations\InsightsMigrator;
use FluentCommunity\Framework\Http\Request\Request;

class InsightsController extends Controller
{
    public function pages(Request $request)
    {
        return [
            'pages'          => CardRegistry::visiblePages(Scope::fromRequest([])),
            'level'          => Utility::insightsLevel(),
            'pro_outdated'   => $this->isProOutdated(),
            'optimising'     => InsightsMigrator::isOptimising(),
            'tracking_since' => get_option(InsightsMigrator::TRACKING_SINCE_OPTION, null),
            'course_options' => $this->courseOptions(),
        ];
    }

    public function page(Request $request, $page)
    {
        $page = sanitize_key($page);
        $pages = CardRegistry::pageDefinitions();

        if (empty($pages[$page]) || !$pages[$page]['available']) {
            return $this->sendError(['message' => __('This Insights page does not exist.', 'fluent-community')], 404);
        }

        $cards = CardRegistry::forPage($page, Scope::fromRequest([]));

        return [
            'page'  => $pages[$page],
            'cards' => array_map(function ($card) {
                return $card->definition();
            }, $cards),
        ];
    }

    public function card(Request $request, $card)
    {
        $resolved = $this->resolveCard($request, $card);

        if (!is_array($resolved)) {
            return $resolved;
        }

        list($cardObject, $range, $scope, $options) = $resolved;

        return $cardObject->payload($range, $scope, $options, (bool)$request->get('fresh'));
    }

    public function export(Request $request, $card)
    {
        $resolved = $this->resolveCard($request, $card);

        if (!is_array($resolved)) {
            return $resolved;
        }

        list($cardObject, $range, $scope, $options) = $resolved;

        if (!in_array('export', $cardObject->supports(), true)) {
            return $this->sendError(['message' => __('This card cannot be exported.', 'fluent-community')], 422);
        }

        $payload = $cardObject->payload($range, $scope, $options);

        return [
            'filename' => sanitize_file_name('insights-' . str_replace('.', '-', $cardObject->key()) . '-' . $range->fromDate() . '-' . $range->toDate() . '.csv'),
            'content'  => CsvExporter::fromPayload($payload),
        ];
    }

    /**
     * @return array|\WP_REST_Response [card, range, scope, options] or an error
     */
    private function resolveCard(Request $request, $cardKey)
    {
        $cardKey = strtolower(sanitize_text_field($cardKey));
        $cardObject = preg_match('/^[a-z0-9_.-]+$/', $cardKey) ? CardRegistry::find($cardKey) : null;

        if (!$cardObject || $cardObject->isLocked()) {
            return $this->sendError(['message' => __('This Insights card does not exist.', 'fluent-community')], 404);
        }

        $params = [
            'from'        => sanitize_text_field((string)$request->get('from', '')),
            'to'          => sanitize_text_field((string)$request->get('to', '')),
            'range'       => sanitize_key((string)$request->get('range', '')),
            'granularity' => sanitize_key((string)$request->get('granularity', '')),
        ];

        $supports = $cardObject->supports();
        $scope = Scope::fromRequest([
            'space_id'  => in_array('space_id', $supports, true) ? $request->get('space_id') : 0,
            'course_id' => in_array('course_id', $supports, true) ? $request->get('course_id') : 0,
        ]);

        if (!$cardObject->canView($scope)) {
            return $this->sendError(['message' => __('You do not have permission to view this card.', 'fluent-community')], 403);
        }

        $options = [
            'compare'  => in_array('compare', $supports, true) ? sanitize_key((string)$request->get('compare', 'previous')) : 'none',
            'page'     => absint($request->get('page', 1)),
            'per_page' => absint($request->get('per_page', 20)),
            'sort'     => sanitize_key((string)$request->get('sort', '')),
        ];

        return [$cardObject, Range::fromRequest($params), $scope, $options];
    }

    private function courseOptions()
    {
        if (!Helper::isFeatureEnabled('course_module')) {
            return [];
        }

        return array_map(function ($course) {
            return ['id' => (int)$course['id'], 'title' => $course['title']];
        }, Sql::rows('SELECT id, title FROM ' . Sql::table('fcom_spaces') . " WHERE type = 'course' ORDER BY title ASC"));
    }

    private function isProOutdated()
    {
        if (!defined('FLUENT_COMMUNITY_PRO_VERSION')) {
            return false;
        }

        return Utility::insightsLevel() !== 'full';
    }
}
