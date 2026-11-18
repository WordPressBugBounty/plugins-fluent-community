<?php

namespace FluentCommunity\Modules\Mcp\Support;

use FluentCommunity\Framework\Support\Arr;

defined('ABSPATH') || exit;

/**
 * A lesson as its top-level blocks, each with its exact stored markup, and block edits
 * against that list. An agent reads the real blocks and changes only the ones it means
 * to: a block it does not touch is written back byte for byte.
 */
class LessonBlocks
{
    const MAX_EDITS = 50;

    /** WordPress's own block comment pattern (WP_Block_Parser::next_token). */
    const DELIMITER = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

    /**
     * @param string $body Stored lesson body.
     * @return array[] Each: index, name (null for content outside any block), markup.
     */
    public static function split($body)
    {
        $pieces = self::pieces((string) $body, false);

        // A body whose block comments do not pair up is shown as one piece, so nothing is hidden.
        if (is_wp_error($pieces)) {
            $pieces = trim((string) $body) === '' ? [] : [ [ 'name' => null, 'markup' => (string) $body ] ];
        }

        $blocks = [];
        foreach ($pieces as $index => $piece) {
            $blocks[] = [ 'index' => $index, 'name' => $piece['name'], 'markup' => $piece['markup'] ];
        }

        return $blocks;
    }

    /**
     * @param string $body
     * @return string Changes whenever the stored body changes.
     */
    public static function version($body)
    {
        return substr(md5((string) $body), 0, 12);
    }

    /**
     * Applies edits to the stored body. Indexes refer to the blocks as read, before any edit.
     *
     * @param string $body  Stored lesson body.
     * @param mixed  $edits Each: {index, markup|markdown|delete: true} or {after, markup|markdown}; after -1 inserts at the start.
     * @return string|\WP_Error The new body.
     */
    public static function apply($body, $edits)
    {
        if (!is_array($edits) || !$edits) {
            return self::error('edits must be a list of changes.');
        }

        if (count($edits) > self::MAX_EDITS) {
            return McpHelper::error('cap_reached', sprintf('Pass at most %d edits per call.', self::MAX_EDITS), [ 'fields' => [ 'edits' ] ]);
        }

        $blocks = self::split($body);
        $count = count($blocks);
        $replace = [];
        $delete = [];
        $insert = [];

        foreach (array_values($edits) as $number => $edit) {
            if (!is_array($edit) || (array_key_exists('index', $edit) === array_key_exists('after', $edit))) {
                return self::error(sprintf('edits[%d] needs index (to replace or delete a block) or after (to insert).', $number));
            }

            if (array_key_exists('after', $edit)) {
                $after = self::intValue($edit['after']);
                if ($after === null || $after < -1 || $after >= $count) {
                    return self::error(sprintf('edits[%d].after must be -1 (the start) or a block index from 0 to %d.', $number, $count - 1));
                }

                $markup = self::newMarkup($edit, $number);
                if (is_wp_error($markup)) {
                    return $markup;
                }

                $insert[$after][] = $markup;
                continue;
            }

            $index = self::intValue($edit['index']);
            if ($index === null || $index < 0 || $index >= $count) {
                return self::error(sprintf('edits[%d].index must be a block index from 0 to %d.', $number, $count - 1));
            }

            if (isset($replace[$index]) || isset($delete[$index])) {
                return self::error(sprintf('edits[%d] changes block %d, which another edit already changes.', $number, $index));
            }

            if (!empty($edit['delete'])) {
                if (isset($edit['markup']) || isset($edit['markdown'])) {
                    return self::error(sprintf('edits[%d] has delete and new content; pass one.', $number));
                }

                $delete[$index] = true;
                continue;
            }

            $markup = self::newMarkup($edit, $number);
            if (is_wp_error($markup)) {
                return $markup;
            }

            $replace[$index] = $markup;
        }

        $out = isset($insert[-1]) ? $insert[-1] : [];

        foreach ($blocks as $block) {
            $index = $block['index'];

            if (isset($replace[$index])) {
                $out[] = $replace[$index];
            } elseif (!isset($delete[$index])) {
                $out[] = $block['markup'];
            }

            if (isset($insert[$index])) {
                $out = array_merge($out, $insert[$index]);
            }
        }

        return implode("\n\n", array_map('trim', $out));
    }

    /**
     * Strict check of block markup an agent sends: every block comment pairs up, attributes
     * are valid JSON, and no text sits outside a block.
     *
     * @param string $markup
     * @return true|string True, or why it is refused.
     */
    public static function check($markup)
    {
        $pieces = self::pieces((string) $markup, true);

        if (is_wp_error($pieces)) {
            return $pieces->get_error_message();
        }

        if (!$pieces) {
            return 'it has no block.';
        }

        return true;
    }

    /**
     * @return string|\WP_Error Block markup for the edit.
     */
    private static function newMarkup(array $edit, $number)
    {
        $hasMarkup = isset($edit['markup']) && trim((string) $edit['markup']) !== '';
        $hasMarkdown = isset($edit['markdown']) && trim((string) $edit['markdown']) !== '';

        if ($hasMarkup === $hasMarkdown) {
            return self::error(sprintf('edits[%d] needs markup (block editor markup) or markdown (plain text to turn into blocks); pass one. To remove a block, pass delete=true.', $number));
        }

        if ($hasMarkdown) {
            return LessonContent::toBlocks((string) $edit['markdown']);
        }

        $markup = (string) $edit['markup'];
        $valid = self::check($markup);

        if ($valid !== true) {
            return McpHelper::error('invalid_param', sprintf('edits[%d].markup is not valid block markup: %s', $number, $valid), [
                'fields'    => [ 'edits' ],
                'next_step' => 'Call get-block-guide for the rules and exact examples.',
            ]);
        }

        $notOffered = BlockGuide::blocksNotOffered($markup);
        if ($notOffered) {
            McpHelper::warn('The lesson editor does not offer these blocks: ' . implode(', ', $notOffered) . '. Students still see them, but an author cannot add them in the editor. get-block-guide lists the blocks it offers.');
        }

        return $markup;
    }

    /**
     * Top-level pieces of a body: each block with its exact markup, and any non-blank
     * text between blocks.
     *
     * @param string $body
     * @param bool   $strict Refuse text outside a block (for markup an agent sends).
     * @return array|\WP_Error Each: name, markup.
     */
    private static function pieces($body, $strict)
    {
        preg_match_all(self::DELIMITER, $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

        $pieces = [];
        $stack = [];
        $cursor = 0;
        $start = 0;

        foreach ($matches as $match) {
            $offset = $match[0][1];
            $end = $offset + strlen($match[0][0]);
            $name = ($match['namespace'][0] !== null ? $match['namespace'][0] : 'core/') . $match['name'][0];
            $isCloser = $match['closer'][0] !== null;
            $isVoid = $match['void'][0] !== null;
            $attrs = $match['attrs'][0];

            if (!$isCloser && $attrs !== null && !is_array(json_decode(trim($attrs), true))) {
                return new \WP_Error('invalid_block', sprintf('the attributes of wp:%s are not valid JSON.', preg_replace('#^core/#', '', $name)));
            }

            if (!$stack) {
                $gap = substr($body, $cursor, $offset - $cursor);
                if (trim($gap) !== '') {
                    if ($strict) {
                        return new \WP_Error('invalid_block', 'it has text outside a block; wrap every piece in <!-- wp:NAME --> … <!-- /wp:NAME -->.');
                    }
                    $pieces[] = [ 'name' => null, 'markup' => trim($gap) ];
                }
                $start = $offset;
            }

            if ($isCloser) {
                if (!$stack || end($stack) !== $name) {
                    return new \WP_Error('invalid_block', sprintf('<!-- /wp:%s --> closes a block that is not open.', $match['name'][0]));
                }

                array_pop($stack);
            } elseif (!$isVoid) {
                $stack[] = $name;
                continue;
            }

            if (!$stack) {
                $pieces[] = [ 'name' => $name, 'markup' => substr($body, $start, $end - $start) ];
                $cursor = $end;
            }
        }

        if ($stack) {
            return new \WP_Error('invalid_block', sprintf('<!-- wp:%s --> is never closed.', preg_replace('#^core/#', '', end($stack))));
        }

        $rest = substr($body, $cursor);
        if (trim($rest) !== '') {
            if ($strict) {
                return new \WP_Error('invalid_block', 'it has text outside a block; wrap every piece in <!-- wp:NAME --> … <!-- /wp:NAME -->.');
            }
            $pieces[] = [ 'name' => null, 'markup' => trim($rest) ];
        }

        return $pieces;
    }

    private static function intValue($value)
    {
        return (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value))) ? (int) $value : null;
    }

    private static function error($message)
    {
        return McpHelper::error('invalid_param', $message, [ 'fields' => [ 'edits' ] ]);
    }
}
