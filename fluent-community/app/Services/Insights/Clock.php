<?php

namespace FluentCommunity\App\Services\Insights;

/**
 * "Now" for every Insights calculation, always in the site timezone.
 *
 * Source rows store site-local wall-clock time (current_time('mysql')), so the
 * dates compared against them must be local too. gmdate() here would shift
 * every boundary by the UTC offset.
 */
class Clock
{
    /** @var \DateTimeImmutable|null */
    private static $frozen = null;

    /**
     * Tests only: pin "now". Pass null to release it.
     *
     * @param string|null $dateTime local date/time
     */
    public static function freeze($dateTime)
    {
        self::$frozen = $dateTime ? new \DateTimeImmutable($dateTime, self::timezone()) : null;
    }

    /**
     * @return \DateTimeImmutable
     */
    public static function now()
    {
        if (self::$frozen) {
            return self::$frozen;
        }

        return new \DateTimeImmutable('now', self::timezone());
    }

    /**
     * @return \DateTimeImmutable local midnight today
     */
    public static function today()
    {
        return self::now()->setTime(0, 0, 0);
    }

    /**
     * @return \DateTimeZone
     */
    public static function timezone()
    {
        return wp_timezone();
    }
}
