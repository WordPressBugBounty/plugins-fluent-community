<?php

namespace FluentCommunity\App\Services\Insights\Contracts;

use FluentCommunity\App\Services\Insights\Range;
use FluentCommunity\App\Services\Insights\Scope;

/**
 * One Insights card. Register through the `fluent_community/insights/cards`
 * filter; extend AbstractCard rather than implementing this from scratch.
 */
interface Card
{
    /** @return string unique, e.g. 'overview.kpis' ([a-z0-9_.-]) */
    public function key();

    /** @return string page key, e.g. 'overview' */
    public function page();

    /** @return string kpi|timeseries|heatmap|table|distribution|cohort|funnel|attention */
    public function type();

    /** @return string translated */
    public function title();

    /** @return string translated tooltip; names the metric keys it shows */
    public function description();

    /** @return string full|two_thirds|half|third */
    public function size();

    /** @return int */
    public function order();

    /** @return bool false when the feature it reports on is off */
    public function isAvailable();

    /** @return bool */
    public function isLocked();

    /** @return bool */
    public function canView(Scope $scope);

    /** @return string[] subset of compare, space_id, course_id, export */
    public function supports();

    /**
     * @param Range $range
     * @param Scope $scope
     * @param array $options compare (previous|year|none), page, per_page, sort
     * @return array the type-specific `data` payload
     */
    public function compute(Range $range, Scope $scope, array $options);
}
