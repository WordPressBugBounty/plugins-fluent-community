<?php

namespace FluentCommunity\Modules\Mcp\Support;

use FluentCommunity\App\Services\FeedsHelper;

defined('ABSPATH') || exit;

/**
 * Lessons are stored as block-editor markup; agents read and write Markdown.
 * This converts in both directions for the blocks a text lesson uses. A block it
 * does not know is kept as it is (to Markdown: as its HTML; to blocks: as an
 * HTML block), so nothing an author made in the editor is dropped on read.
 */
class LessonContent
{
    /** Blocks that Markdown can express. Any other block is layout the conversion cannot keep. */
    const MARKDOWN_BLOCKS = [
        'core/paragraph',
        'core/heading',
        'core/list',
        'core/list-item',
        'core/quote',
        'core/code',
        'core/separator',
        'core/table',
        'core/image',
    ];

    /** Block settings the Markdown conversion writes back. Any other setting (an image's media id and size, colour, alignment) is lost. */
    const MARKDOWN_ATTRIBUTES = [ 'level', 'ordered' ];

    /** Tags the conversion keeps. Any other tag (a caption, highlight, underline, kbd, sup) is lost. */
    const MARKDOWN_TAGS = [
        'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'hr',
        'table', 'thead', 'tbody', 'tr', 'th', 'td', 'figure', 'img', 'strong', 'b', 'em', 'i', 'a', 'br', 's', 'del',
    ];

    /**
     * The blocks of a lesson that a Markdown round trip would lose: embeds,
     * columns, buttons, video, raw HTML and any custom block, and a Markdown
     * block that carries settings or inline formatting Markdown cannot hold.
     *
     * @param string $content Stored lesson body.
     * @return string[] Block names, each once, in the order they appear. Empty when Markdown keeps everything.
     */
    public static function layoutBlocks($content)
    {
        $content = (string) $content;

        if ($content === '' || !function_exists('parse_blocks') || !has_blocks($content)) {
            return [];
        }

        $found = [];
        self::collectLayoutBlocks(parse_blocks($content), $found);

        return array_keys($found);
    }

    private static function collectLayoutBlocks(array $blocks, array &$found)
    {
        foreach ($blocks as $block) {
            $name = isset($block['blockName']) ? $block['blockName'] : null;

            if ($name && (!in_array($name, self::MARKDOWN_BLOCKS, true) || !self::markdownKeeps($block))) {
                $found[$name] = true;
            }

            if (!empty($block['innerBlocks'])) {
                self::collectLayoutBlocks($block['innerBlocks'], $found);
            }
        }
    }

    private static function markdownKeeps(array $block)
    {
        $attributes = isset($block['attrs']) ? array_keys((array) $block['attrs']) : [];

        if (array_diff($attributes, self::MARKDOWN_ATTRIBUTES)) {
            return false;
        }

        $html = isset($block['innerHTML']) ? (string) $block['innerHTML'] : '';

        if (!preg_match_all('/<([a-zA-Z][a-zA-Z0-9]*)/', $html, $matches)) {
            return true;
        }

        return !array_diff(array_map('strtolower', $matches[1]), self::MARKDOWN_TAGS);
    }

    /**
     * @param string $markdown
     * @return string Block markup, each top-level element in its own block.
     */
    public static function toBlocks($markdown)
    {
        $markdown = trim((string) $markdown);

        if ($markdown === '') {
            return '';
        }

        $html = FeedsHelper::mdToHtml($markdown, [ 'disable_link_process' => true ]);
        $body = self::loadBody($html);

        if (!$body) {
            return self::wrap('html', $html);
        }

        $blocks = [];

        foreach ($body->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                if (trim($node->textContent) !== '') {
                    $blocks[] = self::wrap('paragraph', '<p>' . esc_html(trim($node->textContent)) . '</p>');
                }
                continue;
            }

            if ($node instanceof \DOMElement) {
                $blocks[] = self::elementToBlock($node);
            }
        }

        return implode("\n\n", array_filter($blocks));
    }

    /**
     * @param string $content Block markup or plain HTML.
     * @return string Markdown.
     */
    public static function toMarkdown($content)
    {
        $content = trim((string) $content);

        if ($content === '') {
            return '';
        }

        $html = preg_replace('/<!--\s*\/?wp:.*?-->/s', '', $content);
        $body = self::loadBody($html);

        if (!$body) {
            return trim(wp_strip_all_tags($html));
        }

        $markdown = preg_replace('/[ \t]+$/m', '', self::childrenToMarkdown($body));

        return trim(preg_replace("/\n{3,}/", "\n\n", $markdown));
    }

    private static function elementToBlock(\DOMElement $node)
    {
        $tag = strtolower($node->nodeName);
        $html = self::outerHtml($node);

        if ($tag === 'p') {
            $image = self::onlyChild($node, 'img');
            if ($image) {
                return self::wrap('image', '<figure class="wp-block-image"><img src="' . esc_url($image->getAttribute('src')) . '" alt="' . esc_attr($image->getAttribute('alt')) . '"/></figure>');
            }

            return self::wrap('paragraph', $html);
        }

        if (preg_match('/^h([1-6])$/', $tag, $match)) {
            $level = (int) $match[1];
            $inner = self::innerHtml($node);

            return self::wrap('heading', '<h' . $level . ' class="wp-block-heading">' . $inner . '</h' . $level . '>', $level === 2 ? [] : [ 'level' => $level ]);
        }

        if ($tag === 'ul' || $tag === 'ol') {
            return self::listBlock($node);
        }

        if ($tag === 'blockquote') {
            $inner = [];
            foreach ($node->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $inner[] = self::elementToBlock($child);
                } elseif (trim($child->textContent) !== '') {
                    $inner[] = self::wrap('paragraph', '<p>' . esc_html(trim($child->textContent)) . '</p>');
                }
            }

            return self::wrap('quote', '<blockquote class="wp-block-quote">' . implode('', $inner) . '</blockquote>');
        }

        if ($tag === 'pre') {
            $code = self::onlyChild($node, 'code');

            return self::wrap('code', '<pre class="wp-block-code"><code>' . esc_html($code ? $code->textContent : $node->textContent) . '</code></pre>');
        }

        if ($tag === 'hr') {
            return self::wrap('separator', '<hr class="wp-block-separator has-alpha-channel-opacity"/>');
        }

        if ($tag === 'table') {
            return self::wrap('table', '<figure class="wp-block-table">' . $html . '</figure>');
        }

        return self::wrap('html', $html);
    }

    private static function listBlock(\DOMElement $listNode)
    {
        $tag = strtolower($listNode->nodeName);
        $items = [];

        foreach ($listNode->childNodes as $item) {
            if (!$item instanceof \DOMElement || strtolower($item->nodeName) !== 'li') {
                continue;
            }

            $inline = '';
            $nested = '';

            foreach ($item->childNodes as $child) {
                $childTag = $child instanceof \DOMElement ? strtolower($child->nodeName) : '';

                if ($child instanceof \DOMElement && ($childTag === 'ul' || $childTag === 'ol')) {
                    $nested .= self::listBlock($child);
                } elseif ($childTag === 'p') {
                    $inline .= self::innerHtml($child);
                } else {
                    $inline .= self::outerHtml($child);
                }
            }

            $items[] = self::wrap('list-item', '<li>' . trim($inline) . $nested . '</li>');
        }

        return self::wrap('list', '<' . $tag . ' class="wp-block-list">' . implode('', $items) . '</' . $tag . '>', $tag === 'ol' ? [ 'ordered' => true ] : []);
    }

    private static function wrap($name, $html, array $attributes = [])
    {
        $attrs = $attributes ? ' ' . wp_json_encode($attributes) : '';

        return '<!-- wp:' . $name . $attrs . ' -->' . $html . '<!-- /wp:' . $name . ' -->';
    }

    private static function childrenToMarkdown(\DOMNode $parentNode, $listDepth = 0)
    {
        $markdown = '';

        foreach ($parentNode->childNodes as $node) {
            $markdown .= self::nodeToMarkdown($node, $listDepth);
        }

        return $markdown;
    }

    private static function nodeToMarkdown(\DOMNode $node, $listDepth = 0)
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return self::escapeText(preg_replace('/\s+/', ' ', $node->textContent));
        }

        if (!$node instanceof \DOMElement) {
            return '';
        }

        $tag = strtolower($node->nodeName);
        $inner = function () use ($node, $listDepth) {
            return trim(self::childrenToMarkdown($node, $listDepth));
        };

        if (preg_match('/^h([1-6])$/', $tag, $match)) {
            return "\n\n" . str_repeat('#', (int) $match[1]) . ' ' . $inner() . "\n\n";
        }

        switch ($tag) {
            case 'p':
                return "\n\n" . self::escapeBlockStarts($inner()) . "\n\n";
            case 'br':
                return "\n";
            case 'strong':
            case 'b':
                return '**' . $inner() . '**';
            case 'em':
            case 'i':
                return '*' . $inner() . '*';
            case 'del':
            case 's':
                return '~~' . $inner() . '~~';
            case 'code':
                return self::inlineCode($node->textContent);
            case 'pre':
                $code = rtrim($node->textContent);
                // A fence closes only on a line at least as long, so it must be longer than any run inside.
                $fence = str_repeat('`', max(3, self::longestBacktickRun($code) + 1));

                return "\n\n" . $fence . "\n" . $code . "\n" . $fence . "\n\n";
            case 'a':
                return '[' . $inner() . '](' . $node->getAttribute('href') . ')';
            case 'img':
                return '![' . $node->getAttribute('alt') . '](' . $node->getAttribute('src') . ')';
            case 'hr':
                return "\n\n---\n\n";
            case 'blockquote':
                return "\n\n> " . str_replace("\n", "\n> ", $inner()) . "\n\n";
            case 'ul':
            case 'ol':
                return ($listDepth ? "\n" : "\n\n") . self::listToMarkdown($node, $listDepth) . ($listDepth ? '' : "\n\n");
            case 'table':
                return "\n\n" . self::tableToMarkdown($node) . "\n\n";
            case 'figure':
            case 'div':
            case 'span':
            case 'section':
            case 'figcaption':
                return self::childrenToMarkdown($node, $listDepth);
            case 'script':
            case 'style':
                return '';
        }

        // An embed or a custom block: keep its HTML so the agent can see it is there.
        return "\n\n" . self::outerHtml($node) . "\n\n";
    }

    /**
     * Inline code whose delimiter is longer than any backtick run in it, so the code keeps
     * its own backticks. A space pads code that starts or ends with a backtick.
     *
     * @param string $code
     * @return string
     */
    private static function inlineCode($code)
    {
        $code = (string) $code;
        $fence = str_repeat('`', self::longestBacktickRun($code) + 1);
        $pad = ($code !== '' && ($code[0] === '`' || substr($code, -1) === '`')) ? ' ' : '';

        return $fence . $pad . $code . $pad . $fence;
    }

    /**
     * @param string $text
     * @return int
     */
    private static function longestBacktickRun($text)
    {
        if (!preg_match_all('/`+/', (string) $text, $runs)) {
            return 0;
        }

        return max(array_map('strlen', $runs[0]));
    }

    /**
     * Literal text must read back as the same text after the Markdown parser and the
     * HTML filter of the save. Characters that would start markup are escaped:
     * Markdown markers with a backslash, a tag or entity start as an HTML entity.
     *
     * @param string $text
     * @return string
     */
    private static function escapeText($text)
    {
        $text = preg_replace('/&(?=#?[a-zA-Z0-9]+;)/', '&amp;', $text);
        $text = preg_replace('/<(?=[a-zA-Z\/!?])/', '&lt;', $text);
        // Underscores too, even inside a word: the parser can open emphasis at snake_case.
        return preg_replace('/([\\\\`*_\[\]~])/', '\\\\$1', $text);
    }

    /**
     * Text at the start of a line that Markdown would read as a block (# heading, > quote,
     * - or + or 1. list, a line of - or =) is escaped, so a paragraph that says "# Example"
     * stays a paragraph after a read-edit-save. escapeText() covers the inline markers.
     *
     * @param string $text One block's Markdown; each line is checked.
     * @return string
     */
    private static function escapeBlockStarts($text)
    {
        $lines = explode("\n", (string) $text);

        foreach ($lines as $index => $line) {
            // Spaces that open a line after a break mean nothing in Markdown; they only make a re-read differ.
            $line = $index ? ltrim($line, " \t") : $line;
            $line = preg_replace('/^(\s*)(#{1,6}(?=\s|$)|>|[-+](?=\s|$))/', '$1\\\\$2', $line);
            $line = preg_replace('/^(\s*)(\d+)([.)])(?=\s|$)/', '$1$2\\\\$3', $line);
            $line = preg_replace('/^(\s*)-(-+\s*)$/', '$1\\\\-$2', $line);
            $lines[$index] = preg_replace('/^(\s*)=(=*\s*)$/', '$1&#61;$2', $line);
        }

        return implode("\n", $lines);
    }

    private static function listToMarkdown(\DOMElement $listNode, $depth)
    {
        $ordered = strtolower($listNode->nodeName) === 'ol';
        $lines = [];
        $number = 1;

        foreach ($listNode->childNodes as $item) {
            if ($item->nodeType !== XML_ELEMENT_NODE || strtolower($item->nodeName) !== 'li') {
                continue;
            }

            $marker = $ordered ? ($number++) . '.' : '-';
            $text = trim(self::childrenToMarkdown($item, $depth + 1));
            // Only the item's own first line is text that could open a block; later lines hold nested lists.
            $itemLines = explode("\n", $text, 2);
            $itemLines[0] = self::escapeBlockStarts($itemLines[0]);
            $text = implode("\n", $itemLines);
            $lines[] = str_repeat('   ', $depth) . $marker . ' ' . $text;
        }

        return implode("\n", $lines);
    }

    private static function tableToMarkdown(\DOMElement $table)
    {
        $rows = [];

        foreach ($table->getElementsByTagName('tr') as $row) {
            $cells = [];
            foreach ($row->childNodes as $cell) {
                if ($cell->nodeType === XML_ELEMENT_NODE) {
                    $cells[] = str_replace('|', '\\|', trim(preg_replace('/\s+/', ' ', self::childrenToMarkdown($cell))));
                }
            }
            if ($cells) {
                $rows[] = $cells;
            }
        }

        if (!$rows) {
            return '';
        }

        $lines = [ '| ' . implode(' | ', $rows[0]) . ' |', '|' . str_repeat(' --- |', count($rows[0])) ];

        foreach (array_slice($rows, 1) as $cells) {
            $lines[] = '| ' . implode(' | ', $cells) . ' |';
        }

        return implode("\n", $lines);
    }

    /**
     * @return \DOMElement|null The <body> holding the fragment.
     */
    private static function loadBody($html)
    {
        if (!class_exists('\DOMDocument')) {
            return null;
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return null;
        }

        $body = $document->getElementsByTagName('body')->item(0);

        return $body instanceof \DOMElement ? $body : null;
    }

    private static function outerHtml(\DOMNode $node)
    {
        return (string) $node->ownerDocument->saveHTML($node);
    }

    private static function innerHtml(\DOMNode $node)
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= self::outerHtml($child);
        }

        return $html;
    }

    /**
     * @return \DOMElement|null The single element child, when the node holds nothing else.
     */
    private static function onlyChild(\DOMNode $node, $tag)
    {
        $element = null;

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE && trim($child->textContent) === '') {
                continue;
            }

            if ($element || !$child instanceof \DOMElement || strtolower($child->nodeName) !== $tag) {
                return null;
            }

            $element = $child;
        }

        return $element;
    }
}
