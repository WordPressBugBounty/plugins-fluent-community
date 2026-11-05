<?php

namespace FluentCommunity\App\Services\Search;

/**
 * A parsed search text: the words a result must contain, plus the helpers every
 * search provider shares (SQL matching, text matching, snippets).
 */
class SearchQuery
{
    const MIN_LENGTH = 2;
    const MAX_LENGTH = 100;
    const MAX_TERMS = 8;

    /** @var string */
    public $text = '';

    /** @var string[] */
    public $terms = [];

    public function __construct($text)
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));
        $this->text = mb_substr($text, 0, self::MAX_LENGTH);

        $terms = [];
        foreach (explode(' ', $this->text) as $term) {
            $key = mb_strtolower($term);
            // A one-letter word matches nearly every row, so it would only make the search slow
            if (mb_strlen($term) >= self::MIN_LENGTH && !isset($terms[$key])) {
                $terms[$key] = $term;
            }
        }

        $this->terms = array_slice(array_values($terms), 0, self::MAX_TERMS);
    }

    public function isValid()
    {
        return mb_strlen($this->text) >= self::MIN_LENGTH && !empty($this->terms);
    }

    /**
     * Every term must appear in at least one of the columns. LIKE wildcards in a
     * term are escaped, so "50%" matches the text "50%" and not "500".
     */
    public function whereAllTerms($query, array $columns, $orMatch = null)
    {
        global $wpdb;

        foreach ($this->terms as $term) {
            $escaped = $wpdb->esc_like($term);
            $like = '%' . $escaped . '%';

            $query->where(function ($group) use ($columns, $like, $orMatch, $escaped) {
                foreach (array_values($columns) as $index => $column) {
                    if ($index === 0) {
                        $group->where($column, 'LIKE', $like);
                    } else {
                        $group->orWhere($column, 'LIKE', $like);
                    }
                }
                // Lets a provider add one more way a term can match, e.g. a document title in meta
                if ($orMatch) {
                    $orMatch($group, $escaped);
                }
            });
        }

        return $query;
    }

    /**
     * Orders rows whose $column contains every term first. $column must be a fixed
     * column name from the caller, never request input.
     */
    public function orderByAllTermsIn($query, $column)
    {
        global $wpdb;

        $conditions = [];
        $bindings = [];

        foreach ($this->terms as $term) {
            $conditions[] = $column . ' LIKE ?';
            $bindings[] = '%' . $wpdb->esc_like($term) . '%';
        }

        if ($conditions) {
            $query->orderByRaw('CASE WHEN ' . implode(' AND ', $conditions) . ' THEN 1 ELSE 0 END DESC', $bindings);
        }

        return $query;
    }

    public function matchesAll($text)
    {
        $haystack = mb_strtolower((string) $text);

        foreach ($this->terms as $term) {
            if (mb_strpos($haystack, mb_strtolower($term)) === false) {
                return false;
            }
        }

        return true;
    }

    public static function plainText($html)
    {
        $text = wp_strip_all_tags((string) $html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * A plain-text window around the first matched term. The client highlights
     * the terms; the snippet itself is never HTML.
     */
    public function snippet($text, $length = 160)
    {
        $text = (string) $text;

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $lower = mb_strtolower($text);
        $firstHit = false;

        foreach ($this->terms as $term) {
            $position = mb_strpos($lower, mb_strtolower($term));
            if ($position !== false && ($firstHit === false || $position < $firstHit)) {
                $firstHit = $position;
            }
        }

        if ($firstHit === false) {
            return rtrim(mb_substr($text, 0, $length)) . '…';
        }

        $start = max(0, $firstHit - (int) floor($length / 3));

        if ($start > 0) {
            $nextSpace = mb_strpos($text, ' ', $start);
            if ($nextSpace !== false && $nextSpace < $firstHit) {
                $start = $nextSpace + 1;
            }
        }

        $end = min(mb_strlen($text), $start + $length);

        return ($start > 0 ? '…' : '') . trim(mb_substr($text, $start, $end - $start)) . ($end < mb_strlen($text) ? '…' : '');
    }
}
