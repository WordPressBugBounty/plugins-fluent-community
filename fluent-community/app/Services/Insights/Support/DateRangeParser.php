<?php

namespace FluentCommunity\App\Services\Insights\Support;

use FluentCommunity\App\Services\Insights\Clock;

/**
 * Tolerant parsing for the `from` / `to` query parameters.
 *
 * Ported from the old pro analytics (FluentCommunityPro\App\Services\Analytics\DateRangeParser).
 *
 * These arrive from the request having been through `sanitize_text_field()`,
 * which strips tags and does NOT validate a date. `new \DateTime()` throws on
 * anything it cannot parse (DateMalformedStringException on PHP 8.3+, plain
 * Exception before), and nothing between the controller and the report
 * services catches it — so `?start_date=abc` used to 500 the endpoint.
 *
 * This lives in one place on purpose. The first fix for that bug guarded
 * ReportGenerator only, and `Overview::getPopularDayTime()` — a sibling path
 * with its own `new \DateTime($this->startDate)` and its own defaults — kept
 * throwing. Two copies of the guard was what let the second one be missed, so
 * there is now one copy and every caller uses it.
 *
 * Unlike the original, dates are built in the site timezone (wp_timezone()),
 * because the rows they are compared with store site-local time.
 */
class DateRangeParser
{
    /**
     * Parse, or fall back to the default the caller would have got by omitting
     * the parameter entirely.
     *
     * @param mixed $value
     * @param string $fallback a date string this class controls, not user input
     * @return \DateTimeImmutable
     */
    public static function parse($value, $fallback)
    {
        $date = static::tryParse($value);

        return $date ? $date : new \DateTimeImmutable($fallback, Clock::timezone());
    }

    /**
     * Returns null instead of throwing, so a caller can tell "the user gave me
     * something unusable" apart from "the user gave me a valid date".
     *
     * @param mixed $value
     * @return \DateTimeImmutable|null
     */
    public static function tryParse($value)
    {
        if ((!is_string($value) || trim($value) === '') && !is_numeric($value)) {
            return null;
        }

        try {
            $date = new \DateTimeImmutable((string)$value, Clock::timezone());
        } catch (\Exception $e) {
            return null;
        }

        // PHP rolls 2024-13-45 over into a real date instead of failing; a
        // calendar date that does not exist is garbage, not a range.
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim((string)$value), $parts) && !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
            return null;
        }

        // Relative input ("-7 days", "today") is legitimate; anchor it to the
        // site clock rather than the server's, so it agrees with Range.
        if (!preg_match('/^\s*\d{4}-\d{2}-\d{2}/', (string)$value) && !is_numeric($value)) {
            $relative = Clock::now()->modify((string)$value);
            if ($relative) {
                $date = $relative;
            }
        }

        return $date;
    }
}
