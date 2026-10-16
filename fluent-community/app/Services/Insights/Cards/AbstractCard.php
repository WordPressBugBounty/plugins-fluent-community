<?php

namespace FluentCommunity\App\Services\Insights\Cards;

use FluentCommunity\App\Services\Helper;
use FluentCommunity\App\Services\Insights\Bucketer;
use FluentCommunity\App\Services\Insights\CardCache;
use FluentCommunity\App\Services\Insights\Clock;
use FluentCommunity\App\Services\Insights\Comparator;
use FluentCommunity\App\Services\Insights\Contracts\Card;
use FluentCommunity\App\Services\Insights\Range;
use FluentCommunity\App\Services\Insights\Scope;
use FluentCommunity\Database\Migrations\InsightsMigrator;

/**
 * Caching, the response envelope and comparison plumbing shared by every card.
 * A card only has to say what it is and compute its `data`.
 */
abstract class AbstractCard implements Card
{
    public function size()
    {
        return 'full';
    }

    public function order()
    {
        return 100;
    }

    public function isAvailable()
    {
        return true;
    }

    public function isLocked()
    {
        return false;
    }

    public function canView(Scope $scope)
    {
        return Helper::isSiteAdmin();
    }

    public function supports()
    {
        return ['compare', 'space_id', 'export'];
    }

    /**
     * @return array the card definition the page endpoint lists
     */
    public function definition()
    {
        return [
            'key'         => $this->key(),
            'page'        => $this->page(),
            'type'        => $this->type(),
            'title'       => $this->title(),
            'description' => $this->description(),
            'size'        => $this->size(),
            'order'       => $this->order(),
            'supports'    => array_values($this->supports()),
            'locked'      => $this->isLocked(),
        ];
    }

    /**
     * The full API response for this card, cached.
     *
     * @param Range $range
     * @param Scope $scope
     * @param array $options compare, page, per_page, sort
     * @param bool $fresh bypass and rewrite the cache
     * @return array
     */
    public function payload(Range $range, Scope $scope, array $options = [], $fresh = false)
    {
        $options = $this->normaliseOptions($options);

        $cacheKey = implode('|', [
            $this->key(),
            $range->cacheKey(),
            $scope->cacheKey(),
            md5(wp_json_encode($options)),
            get_locale(),
        ]);

        $ttl = $range->includesToday() ? 10 * MINUTE_IN_SECONDS : 12 * HOUR_IN_SECONDS;

        list($payload, $cached) = CardCache::remember($cacheKey, $ttl, function () use ($range, $scope, $options) {
            return [
                'card' => $this->key(),
                'type' => $this->type(),
                'meta' => $this->meta($range, $options),
                'data' => $this->compute($range, $scope, $options),
            ];
        }, $fresh);

        $payload['meta']['cached'] = $cached;

        /**
         * Last-mile change to a card's response.
         *
         * @param array $payload
         * @param Card $card
         * @param Range $range
         * @param Scope $scope
         */
        return apply_filters('fluent_community/insights/card_payload', $payload, $this, $range, $scope);
    }

    protected function meta(Range $range, array $options)
    {
        $comparison = in_array('compare', $this->supports(), true) ? $range->comparison($options['compare']) : null;
        $bucketer = new Bucketer($range);

        return [
            'from'         => $range->fromDate(),
            'to'           => $range->toDate(),
            'granularity'  => $range->granularity(),
            'timezone'     => wp_timezone_string(),
            'week_start'   => $bucketer->weekStart(),
            'compare'      => $comparison ? ['mode' => $options['compare'], 'from' => $comparison->fromDate(), 'to' => $comparison->toDate()] : null,
            'data_since'   => ['visits' => get_option(InsightsMigrator::TRACKING_SINCE_OPTION, null)],
            'generated_at' => Clock::now()->format(DATE_ATOM),
            'cached'       => false,
        ];
    }

    protected function normaliseOptions(array $options)
    {
        $compare = isset($options['compare']) ? $options['compare'] : 'previous';

        return [
            'compare'  => in_array($compare, ['previous', 'year', 'none'], true) ? $compare : 'previous',
            'page'     => max(1, isset($options['page']) ? (int)$options['page'] : 1),
            'per_page' => min(100, max(1, isset($options['per_page']) ? (int)$options['per_page'] : 20)),
            'sort'     => isset($options['sort']) ? sanitize_key($options['sort']) : '',
        ];
    }

    /**
     * @param Range $range
     * @param array $options
     * @return Range|null
     */
    protected function comparisonRange(Range $range, array $options)
    {
        return $range->comparison(isset($options['compare']) ? $options['compare'] : 'previous');
    }

    /**
     * A KPI tile.
     *
     * @param string $key metric key from docs/reporting/03
     * @param string $label
     * @param int|float|null $value
     * @param int|float|null $previous
     * @param string $format number|percent|duration
     * @param string $deltaKind count|rate|duration
     * @param array $spark
     * @return array
     */
    protected function kpiItem($key, $label, $value, $previous, $format = 'number', $deltaKind = 'count', array $spark = [])
    {
        return array_merge([
            'key'      => $key,
            'label'    => $label,
            'value'    => $value,
            'previous' => $previous,
            'format'   => $format,
            'spark'    => array_values($spark),
        ], Comparator::delta($value, $previous, $deltaKind));
    }

    /**
     * @param Bucketer $bucketer
     * @param array $series [ [key, label, values[], previous[]|null] ]
     * @param string $unit
     * @param array $extra
     * @return array
     */
    protected function timeseries(Bucketer $bucketer, array $series, $unit = 'count', array $extra = [])
    {
        return array_merge([
            'buckets'     => $bucketer->keys(),
            'partial'     => $bucketer->partial(),
            'granularity' => $bucketer->granularity(),
            'series'      => $series,
            'unit'        => $unit,
        ], $extra);
    }

    /**
     * Align a comparison series to the current buckets by position, so day 1
     * of the previous period sits under day 1 of this one.
     *
     * @param array $values
     * @param int $length
     * @return array
     */
    protected function alignTo(array $values, $length)
    {
        $values = array_values($values);

        return array_slice(array_pad($values, $length, null), 0, $length);
    }
}
