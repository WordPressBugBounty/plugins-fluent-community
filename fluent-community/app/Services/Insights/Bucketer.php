<?php

namespace FluentCommunity\App\Services\Insights;

/**
 * Turns a Range into time buckets. The SQL bucket expression and the PHP key
 * list come from this one class, so they cannot drift apart. They did in the
 * old reports: PHP stepped weeks from the start date while SQL grouped by
 * WEEK(), so every real bucket read 0 and the counts landed on stray keys.
 *
 * Keys are always the ISO date the bucket starts on (2026-08-10), never a
 * week number or a bare month, so August 2025 and August 2026 never merge.
 */
class Bucketer
{
    /** @var Range */
    private $range;

    /** @var string */
    private $granularity;

    /** @var int 0 = Sunday … 6 = Saturday, as WordPress stores start_of_week */
    private $weekStart;

    /**
     * @param Range $range
     * @param string|null $granularity defaults to the range's
     * @param int|null $weekStart defaults to the start_of_week option
     */
    public function __construct(Range $range, $granularity = null, $weekStart = null)
    {
        $this->range = $range;
        $this->granularity = in_array($granularity, Range::GRANULARITIES, true) ? $granularity : $range->granularity();
        $this->weekStart = $weekStart === null ? self::siteWeekStart() : ((int)$weekStart % 7 + 7) % 7;
    }

    /**
     * @return int
     */
    public static function siteWeekStart()
    {
        $weekStart = (int)get_option('start_of_week', 1);

        return ($weekStart % 7 + 7) % 7;
    }

    /** @return string */
    public function granularity()
    {
        return $this->granularity;
    }

    /** @return int */
    public function weekStart()
    {
        return $this->weekStart;
    }

    /**
     * A SQL expression that evaluates to the bucket key (a DATE) for a
     * site-local DATETIME column. No % signs, so it is safe inside prepare().
     *
     * @param string $column a trusted, already-qualified column name
     * @return string
     */
    public function sqlExpression($column)
    {
        if ($this->granularity === 'month') {
            return "DATE_SUB(DATE({$column}), INTERVAL (DAYOFMONTH({$column}) - 1) DAY)";
        }

        if ($this->granularity === 'week') {
            // DAYOFWEEK() is 1 = Sunday, so DAYOFWEEK() - 1 matches start_of_week numbering.
            return "DATE_SUB(DATE({$column}), INTERVAL ((DAYOFWEEK({$column}) - 1 - {$this->weekStart} + 7) MOD 7) DAY)";
        }

        return "DATE({$column})";
    }

    /**
     * The bucket a local date belongs to.
     *
     * @param \DateTimeImmutable $date
     * @return \DateTimeImmutable
     */
    public function bucketStart(\DateTimeImmutable $date)
    {
        $date = $date->setTime(0, 0, 0);

        if ($this->granularity === 'month') {
            return $date->setDate((int)$date->format('Y'), (int)$date->format('n'), 1);
        }

        if ($this->granularity === 'week') {
            $offset = ((int)$date->format('w') - $this->weekStart + 7) % 7;

            return $offset ? $date->modify('-' . $offset . ' days') : $date;
        }

        return $date;
    }

    /**
     * @param \DateTimeImmutable $start
     * @return \DateTimeImmutable the last day of the bucket
     */
    public function bucketEnd(\DateTimeImmutable $start)
    {
        if ($this->granularity === 'month') {
            return $start->modify('last day of this month');
        }

        if ($this->granularity === 'week') {
            return $start->modify('+6 days');
        }

        return $start;
    }

    /**
     * Every bucket key covering the range, in order.
     *
     * @return string[]
     */
    public function keys()
    {
        $keys = [];
        $cursor = $this->bucketStart($this->range->from());
        $last = $this->range->toDate();

        while ($cursor->format('Y-m-d') <= $last) {
            $keys[] = $cursor->format('Y-m-d');
            $cursor = $this->next($cursor);
        }

        return $keys;
    }

    /**
     * True for a bucket that the range covers only part of, which the UI draws
     * differently so a short first week is not read as a dip.
     *
     * @return bool[] aligned with keys()
     */
    public function partial()
    {
        $from = $this->range->fromDate();
        $to = $this->range->toDate();
        $flags = [];

        foreach ($this->keys() as $key) {
            $start = new \DateTimeImmutable($key, Clock::timezone());
            $flags[] = $key < $from || $this->bucketEnd($start)->format('Y-m-d') > $to;
        }

        return $flags;
    }

    /**
     * Zero-fill: a value for every key, in key order.
     *
     * @param array $valuesByKey bucket key => number
     * @return array
     */
    public function fill(array $valuesByKey)
    {
        $values = [];

        foreach ($this->keys() as $key) {
            $values[] = isset($valuesByKey[$key]) ? $valuesByKey[$key] + 0 : 0;
        }

        return $values;
    }

    private function next(\DateTimeImmutable $start)
    {
        if ($this->granularity === 'month') {
            return $start->modify('first day of next month');
        }

        return $start->modify($this->granularity === 'week' ? '+7 days' : '+1 day');
    }
}
