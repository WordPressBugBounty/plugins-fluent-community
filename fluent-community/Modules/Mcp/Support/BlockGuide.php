<?php

namespace FluentCommunity\Modules\Mcp\Support;

use FluentCommunity\App\Hooks\Handlers\FluentBlockEditorHandler;

defined('ABSPATH') || exit;

/**
 * What an agent needs to write lesson content as block editor (Gutenberg)
 * markup: the rules, exact examples, and where the official reference is.
 *
 * Lessons open in FluentCommunity's own lesson editor, which offers only the
 * blocks in FluentBlockEditorHandler::allowedBlockTypes(). The guide lists that
 * live set, and every example is one of those blocks.
 *
 * The examples are what that editor itself writes for each block
 * (wp.blocks.serialize, run inside the lesson editor on WordPress 7.1). It uses
 * WordPress's own block code, so the markup is the same as in the post editor. The editor compares a block's HTML
 * with what its own code would produce, so a block that differs from these
 * shows "unexpected or invalid content" there. Older markup stays valid through
 * the blocks' own deprecations, so the examples do not expire with an update.
 */
class BlockGuide
{
    const DOCS = [
        'format'      => 'https://developer.wordpress.org/block-editor/getting-started/fundamentals/markup-representation-block/',
        'core_blocks' => 'https://developer.wordpress.org/block-editor/reference-guides/core-blocks/',
    ];

    const RULES = [
        'A block is HTML between two comments: <!-- wp:NAME {JSON attributes} --> ... <!-- /wp:NAME -->. A core block drops the "core/" prefix in the comment; other blocks keep their namespace.',
        'To edit a lesson, read it with get-course format=blocks, change the text inside the tags, and keep every comment, tag, class and attribute you did not mean to change.',
        'To add content, copy an example from this guide and change its text. Do not invent class names or wrappers: the editor rejects a block whose HTML differs from what it would write itself.',
        'IMAGE_URL and AUDIO_URL in an example are placeholders: replace each one with the URL the media library gives for that file. VIDEO_ID in the core/embed example is a placeholder for the video id in the URL the author gives you. A core/cover block repeats its image URL in the block attributes and in the img tag, so change both to the same URL.',
        'Use only the blocks in allowed_blocks: they are the ones the lesson editor offers. A block outside that list still shows to students, but an author cannot add it in the editor. There is no video block: embed a video URL with core/embed.',
        'A block you have not seen in this guide or in the lesson (a custom or third-party block): keep it exactly as it is, and do not write a new one.',
        'Put all content inside blocks. Text outside a block becomes a "Classic" block in the editor.',
        'For a lesson that is only text, headings, lists, quotes, code, tables and images, send Markdown instead: the server writes the blocks for you.',
    ];

    const EXAMPLES = [
        'core/paragraph' => '<!-- wp:paragraph -->
<p>Text with <strong>bold</strong> and a <a href="https://example.com">link</a>.</p>
<!-- /wp:paragraph -->',
        'core/heading' => '<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Section title</h3>
<!-- /wp:heading -->',
        'core/list' => '<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>First</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Second</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->',
        'core/list (ordered)' => '<!-- wp:list {"ordered":true} -->
<ol class="wp-block-list"><!-- wp:list-item -->
<li>Step one</li>
<!-- /wp:list-item --></ol>
<!-- /wp:list -->',
        'core/quote' => '<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph -->
<p>Quoted text.</p>
<!-- /wp:paragraph --></blockquote>
<!-- /wp:quote -->',
        'core/code' => '<!-- wp:code -->
<pre class="wp-block-code"><code>echo "hi";</code></pre>
<!-- /wp:code -->',
        'core/separator' => '<!-- wp:separator -->
<hr class="wp-block-separator has-alpha-channel-opacity"/>
<!-- /wp:separator -->',
        'core/image' => '<!-- wp:image -->
<figure class="wp-block-image"><img src="IMAGE_URL" alt="A photo"/><figcaption class="wp-element-caption">Caption text</figcaption></figure>
<!-- /wp:image -->',
        'core/buttons' => '<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.com/enroll">Enroll now</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->',
        'core/columns' => '<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>Left.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>Right.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->',
        'core/embed' => '<!-- wp:embed {"url":"https://www.youtube.com/watch?v=VIDEO_ID","type":"video","providerNameSlug":"youtube","responsive":true} -->
<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube"><div class="wp-block-embed__wrapper">
https://www.youtube.com/watch?v=VIDEO_ID
</div></figure>
<!-- /wp:embed -->',
        'core/table' => '<!-- wp:table -->
<figure class="wp-block-table"><table class="has-fixed-layout"><thead><tr><th>Name</th><th>Value</th></tr></thead><tbody><tr><td>A</td><td>1</td></tr></tbody></table></figure>
<!-- /wp:table -->',
        'core/details' => '<!-- wp:details -->
<details class="wp-block-details"><summary>Click to open</summary><!-- wp:paragraph -->
<p>Hidden text.</p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->',
        'core/group' => '<!-- wp:group -->
<div class="wp-block-group"><!-- wp:paragraph -->
<p>Grouped text.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->',
        'core/spacer' => '<!-- wp:spacer {"height":"40px"} -->
<div style="height:40px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->',
        'core/pullquote' => '<!-- wp:pullquote -->
<figure class="wp-block-pullquote"><blockquote><p>A key sentence.</p><cite>Author</cite></blockquote></figure>
<!-- /wp:pullquote -->',
        'core/preformatted' => '<!-- wp:preformatted -->
<pre class="wp-block-preformatted">Keeps  spacing</pre>
<!-- /wp:preformatted -->',
        'core/media-text' => '<!-- wp:media-text {"mediaType":"image"} -->
<div class="wp-block-media-text is-stacked-on-mobile"><figure class="wp-block-media-text__media"><img src="IMAGE_URL" alt="A photo"/></figure><div class="wp-block-media-text__content"><!-- wp:paragraph -->
<p>Text beside the image.</p>
<!-- /wp:paragraph --></div></div>
<!-- /wp:media-text -->',
        'core/audio' => '<!-- wp:audio -->
<figure class="wp-block-audio"><audio controls src="AUDIO_URL"></audio></figure>
<!-- /wp:audio -->',
        'core/cover' => '<!-- wp:cover {"url":"IMAGE_URL","dimRatio":50} -->
<div class="wp-block-cover"><img class="wp-block-cover__image-background" alt="" src="IMAGE_URL" data-object-fit="cover"/><span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span><div class="wp-block-cover__inner-container"><!-- wp:paragraph -->
<p>Text on the image.</p>
<!-- /wp:paragraph --></div></div>
<!-- /wp:cover -->',
    ];

    /**
     * @param string[] $blockNames Block names to describe; empty returns every example.
     * @return array
     */
    public static function guide(array $blockNames = [])
    {
        $guide = [
            'rules'          => self::RULES,
            'allowed_blocks' => self::allowedBlocks(),
            'docs'           => self::DOCS,
        ];

        if (!$blockNames) {
            $guide['examples'] = self::EXAMPLES;

            return $guide;
        }

        $guide['examples'] = [];
        $guide['blocks'] = [];

        foreach ($blockNames as $name) {
            $name = strtolower(trim((string) $name));
            if ($name === '') {
                continue;
            }

            if (strpos($name, '/') === false) {
                $name = 'core/' . $name;
            }

            foreach (self::EXAMPLES as $key => $markup) {
                if ($key === $name || strpos($key, $name . ' ') === 0) {
                    $guide['examples'][$key] = $markup;
                }
            }

            $guide['blocks'][$name] = self::describe($name);
        }

        return $guide;
    }

    /**
     * The blocks the lesson editor offers on this site.
     *
     * @return string[]
     */
    public static function allowedBlocks()
    {
        return array_values(array_unique(array_filter(array_map('strval', FluentBlockEditorHandler::allowedBlockTypes()))));
    }

    /**
     * Blocks in a lesson body that the lesson editor does not offer.
     *
     * @param string $content Block markup.
     * @return string[] Block names, each once.
     */
    public static function blocksNotOffered($content)
    {
        if (!function_exists('parse_blocks') || !has_blocks((string) $content)) {
            return [];
        }

        $used = [];
        self::collectNames(parse_blocks((string) $content), $used);

        return array_values(array_diff(array_keys($used), self::allowedBlocks()));
    }

    private static function collectNames(array $blocks, array &$used)
    {
        foreach ($blocks as $block) {
            if (!empty($block['blockName'])) {
                $used[$block['blockName']] = true;
            }

            if (!empty($block['innerBlocks'])) {
                self::collectNames($block['innerBlocks'], $used);
            }
        }
    }

    /**
     * What this site's block registry knows about one block. It has the
     * attributes, not the saved HTML: that is written by the block's own
     * JavaScript.
     *
     * @param string $name Full block name, for example core/embed.
     * @return array
     */
    private static function describe($name)
    {
        $type = class_exists('\WP_Block_Type_Registry') ? \WP_Block_Type_Registry::get_instance()->get_registered($name) : null;

        $allowed = in_array($name, self::allowedBlocks(), true);

        if (!$type) {
            return [
                'registered' => false,
                'allowed'    => $allowed,
                'note'       => 'This site has no block with this name. Do not write it.',
            ];
        }

        if (!$allowed) {
            return [
                'registered' => true,
                'allowed'    => false,
                'title'      => (string) $type->title,
                'note'       => 'The lesson editor does not offer this block. Do not add it; keep one that is already in a lesson unchanged.',
            ];
        }

        $attributes = [];
        foreach ((array) $type->attributes as $key => $definition) {
            $attributes[$key] = array_intersect_key((array) $definition, array_flip([ 'type', 'enum', 'default' ]));
        }

        return [
            'registered' => true,
            'allowed'    => true,
            'title'      => (string) $type->title,
            'attributes' => array_slice($attributes, 0, 40, true),
            'note'       => isset(self::EXAMPLES[$name])
                ? 'Use the example for the exact HTML.'
                : 'No example here. The exact HTML comes from the block\'s own code: copy this block from a lesson that has it, and change only its text or attributes.',
        ];
    }
}
