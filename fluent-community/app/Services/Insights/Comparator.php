<?php

namespace FluentCommunity\App\Services\Insights;

/**
 * Change between the current and the comparison value.
 *
 * - count:    relative change, (cur - prev) / prev
 * - rate:     percentage points, cur - prev (both are 0..1 fractions)
 * - duration: relative change, and lower is better
 *
 * A count or duration rising from 0 gives null ("new"), never "+100%";
 * 0 to 0 is no change. Rates compare in points, so a 0% base is fine.
 * `good` reads the change by meaning, not by sign: a shorter reply time is good.
 */
class Comparator
{
    const KINDS = ['count', 'rate', 'duration'];

    /**
     * @param int|float|null $current
     * @param int|float|null $previous
     * @param string $kind count|rate|duration
     * @param bool $higherIsBetter ignored for duration, which is always lower-is-better
     * @return array{delta: float|null, delta_kind: string, good: bool|null}
     */
    public static function delta($current, $previous, $kind = 'count', $higherIsBetter = true)
    {
        $kind = in_array($kind, self::KINDS, true) ? $kind : 'count';

        if ($current === null || $previous === null) {
            return ['delta' => null, 'delta_kind' => $kind, 'good' => null];
        }

        // Percentage points stay meaningful from a 0% base; a ratio does not.
        if ($kind !== 'rate' && (float)$previous === 0.0) {
            return ['delta' => (float)$current === 0.0 ? 0.0 : null, 'delta_kind' => $kind, 'good' => null];
        }

        if ($kind === 'rate') {
            $delta = round((float)$current - (float)$previous, 4);
        } else {
            $delta = round(((float)$current - (float)$previous) / (float)$previous, 4);
        }

        if ($kind === 'duration') {
            $higherIsBetter = false;
        }

        $good = null;
        if ($delta !== 0.0) {
            $good = $higherIsBetter ? $delta > 0 : $delta < 0;
        }

        return ['delta' => $delta, 'delta_kind' => $kind, 'good' => $good];
    }
}
