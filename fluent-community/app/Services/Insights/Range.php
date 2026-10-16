<?php

namespace FluentCommunity\App\Services\Insights;

use FluentCommunity\App\Services\Insights\Support\DateRangeParser;
use FluentCommunity\Framework\Support\Arr;

/**
 * An inclusive range of whole local days.
 *
 * SQL always uses `col >= sqlFrom() AND col < sqlToExclusive()`, so a row at
 * 23:59:59 on the last day is in and one at 00:00:00 the next day is out.
 */
class Range
{
    const MAX_DAYS = 731;

    const DEFAULT_DAYS = 30;

    const PRESETS = ['7d' => 7, '30d' => 30, '90d' => 90, '12m' => '12m'];

    const GRANULARITIES = ['day', 'week', 'month'];

    /** @var \DateTimeImmutable */
    private $from;

    /** @var \DateTimeImmutable */
    private $to;

    /** @var string|null */
    private $granularity;

    /**
     * @param \DateTimeImmutable $from any time on the first day
     * @param \DateTimeImmutable $to any time on the last day
     * @param string|null $granularity null = automatic
     */
    public function __construct(\DateTimeImmutable $from, \DateTimeImmutable $to, $granularity = null)
    {
        $timezone = Clock::timezone();
        $from = new \DateTimeImmutable($from->format('Y-m-d'), $timezone);
        $to = new \DateTimeImmutable($to->format('Y-m-d'), $timezone);

        // A backwards range is a range in the wrong order, not an empty one.
        if ($from > $to) {
            list($from, $to) = [$to, $from];
        }

        $today = Clock::today();
        if ($to > $today) {
            $to = $today;
        }

        if ($from > $to) {
            $from = $to;
        }

        if (self::countDays($from, $to) > self::MAX_DAYS) {
            $from = $to->modify('-' . (self::MAX_DAYS - 1) . ' days');
        }

        $this->from = $from;
        $this->to = $to;
        $this->granularity = in_array($granularity, self::GRANULARITIES, true) ? $granularity : null;
    }

    /**
     * Tolerant: an unparseable endpoint falls back to the whole default window
     * as a unit. Mixing the caller's valid end with a default start can produce
     * from > to, and a silently wrong report.
     *
     * @param array $params from, to, range, granularity
     * @return self
     */
    public static function fromRequest(array $params)
    {
        $granularity = Arr::get($params, 'granularity');
        $preset = Arr::get($params, 'range');

        if (!Arr::get($params, 'from') && !Arr::get($params, 'to') && is_string($preset) && isset(self::PRESETS[$preset])) {
            return self::preset($preset, $granularity);
        }

        $from = DateRangeParser::tryParse(Arr::get($params, 'from'));
        $to = DateRangeParser::tryParse(Arr::get($params, 'to'));

        if (!$from || !$to) {
            return self::lastDays(self::DEFAULT_DAYS, $granularity);
        }

        return new self($from, $to, $granularity);
    }

    /**
     * @param string $preset 7d|30d|90d|12m
     * @param string|null $granularity
     * @return self
     */
    public static function preset($preset, $granularity = null)
    {
        if ($preset === '12m') {
            $today = Clock::today();

            return new self($today->modify('-12 months')->modify('+1 day'), $today, $granularity);
        }

        return self::lastDays(isset(self::PRESETS[$preset]) ? self::PRESETS[$preset] : self::DEFAULT_DAYS, $granularity);
    }

    /**
     * @param int $days including today
     * @param string|null $granularity
     * @return self
     */
    public static function lastDays($days, $granularity = null)
    {
        $today = Clock::today();

        return new self($today->modify('-' . (max(1, (int)$days) - 1) . ' days'), $today, $granularity);
    }

    /**
     * @param string $from Y-m-d
     * @param string $to Y-m-d
     * @param string|null $granularity
     * @return self
     */
    public static function fromDates($from, $to, $granularity = null)
    {
        $timezone = Clock::timezone();

        return new self(new \DateTimeImmutable($from, $timezone), new \DateTimeImmutable($to, $timezone), $granularity);
    }

    /** @return \DateTimeImmutable local 00:00:00 of the first day */
    public function from()
    {
        return $this->from;
    }

    /** @return \DateTimeImmutable local 23:59:59 of the last day */
    public function to()
    {
        return $this->to->setTime(23, 59, 59);
    }

    /** @return string */
    public function fromDate()
    {
        return $this->from->format('Y-m-d');
    }

    /** @return string */
    public function toDate()
    {
        return $this->to->format('Y-m-d');
    }

    /** @return string */
    public function sqlFrom()
    {
        return $this->from->format('Y-m-d 00:00:00');
    }

    /** @return string use with `<` */
    public function sqlToExclusive()
    {
        return $this->to->modify('+1 day')->format('Y-m-d 00:00:00');
    }

    /** @return int inclusive day count */
    public function days()
    {
        return self::countDays($this->from, $this->to);
    }

    /**
     * ≤ 62 days daily (kept from the old reports), ≤ 182 weekly, else monthly.
     *
     * @return string day|week|month
     */
    public function granularity()
    {
        if ($this->granularity) {
            return $this->granularity;
        }

        $days = $this->days();

        if ($days <= 62) {
            return 'day';
        }

        return $days <= 182 ? 'week' : 'month';
    }

    /** @return bool */
    public function hasExplicitGranularity()
    {
        return (bool)$this->granularity;
    }

    /**
     * Same number of days, immediately before this range.
     *
     * @return self
     */
    public function previous()
    {
        $previousTo = $this->from->modify('-1 day');
        $previousFrom = $previousTo->modify('-' . ($this->days() - 1) . ' days');

        return new self($previousFrom, $previousTo, $this->granularity);
    }

    /**
     * The same dates one year earlier. Feb 29 maps to Feb 28.
     *
     * @return self
     */
    public function lastYear()
    {
        return new self(self::shiftYear($this->from, -1), self::shiftYear($this->to, -1), $this->granularity);
    }

    /**
     * @param string $mode previous|year
     * @return self|null
     */
    public function comparison($mode)
    {
        if ($mode === 'year') {
            return $this->lastYear();
        }

        return $mode === 'none' ? null : $this->previous();
    }

    /** @return bool */
    public function includesToday()
    {
        return $this->toDate() >= Clock::today()->format('Y-m-d');
    }

    /** @return string */
    public function cacheKey()
    {
        return $this->fromDate() . '_' . $this->toDate() . '_' . $this->granularity();
    }

    /** @return array */
    public function toArray()
    {
        return [
            'from'        => $this->fromDate(),
            'to'          => $this->toDate(),
            'days'        => $this->days(),
            'granularity' => $this->granularity(),
        ];
    }

    private static function countDays(\DateTimeImmutable $from, \DateTimeImmutable $to)
    {
        // Calendar days, not 86400-second units, so a 23 h or 25 h DST day counts once.
        $fromUtc = new \DateTimeImmutable($from->format('Y-m-d'), new \DateTimeZone('UTC'));
        $toUtc = new \DateTimeImmutable($to->format('Y-m-d'), new \DateTimeZone('UTC'));

        return (int)$fromUtc->diff($toUtc)->days + 1;
    }

    private static function shiftYear(\DateTimeImmutable $date, $years)
    {
        $year = (int)$date->format('Y') + $years;
        $month = (int)$date->format('n');
        $day = min((int)$date->format('j'), (int)(new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t'));

        return $date->setDate($year, $month, $day);
    }
}
