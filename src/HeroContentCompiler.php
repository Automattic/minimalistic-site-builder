<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Compile the front hero's content document into the markup HeroUnit
 * delivers: the recipe and mobile markers, the copy and media region hooks,
 * the planned action, and one AI_IMAGE in the blueprint's aspect.
 *
 * The document is the same shape SectionContent::schema() bounds for page
 * sections, so the hero request shares the sections' cached layers and
 * cache key. Only heading, heading_emphasis, lead, action_label, and the
 * image fields are read.
 */
final class HeroContentCompiler
{
    /**
     * @param array<string,mixed> $doc       the decoded content document
     * @param array<string,mixed> $context   HeroUnit's resolved context: section, blueprint, recipe,
     *                                       mobile_transformation, primary_action, contract
     * @param array<string,mixed> $input     the HeroUnit input, for the writing direction and slug
     */
    public static function compile(array $doc, array $context, array $input): string
    {
        $blueprint = $context['blueprint'];
        $contract = $context['contract'];
        $recipe = (string) $context['recipe'];
        $section = $context['section'];
        $slug = trim((string) ($section['slug'] ?? 'hero')) ?: 'hero';
        $direction = (string) ($contract['writing_direction'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr';
        $emphasis = preg_match('/\*\*Heading emphasis\*\*: (?!none\b)\S/', (string) ($input['design_direction'] ?? '')) === 1;
        $mode = (string) ($contract['header']['mode'] ?? AboveFoldContract::MODE_STACKED);
        $overlay = $mode === AboveFoldContract::MODE_OVERLAY;
        $anchor = (string) ($blueprint['text_anchor'] ?? 'center');
        $side = match ($anchor) {
            'center'       => 'center',
            'center-start' => $direction === 'rtl' ? 'right' : 'left',
            'center-end'   => $direction === 'rtl' ? 'left' : 'right',
            default        => 'left',
        };

        $copy = self::copy($doc, $context, $side, $emphasis);
        $image = self::image($doc, $slug, (string) $blueprint['media_aspect'], (string) ($blueprint['media_mode'] ?? 'cover-image'));
        $rootClasses = 'hero-composition--' . $recipe . ' hero-mobile--' . (string) $context['mobile_transformation'];

        if ((string) ($blueprint['media_mode'] ?? '') === 'foreground-image') {
            $dominant = (string) ($blueprint['media_weight'] ?? 'balanced') === 'dominant';
            $media = $image === null
                ? self::block('group', ['className' => 'hero-composition__media', 'layout' => ['type' => 'constrained']], '')
                : self::block('image', ['className' => 'hero-composition__media'], '<img src="' . self::h($image['url']) . '" alt="' . self::h($image['alt']) . '"/>', 'figure');
            $row = self::block('columns', ['align' => 'wide', 'verticalAlignment' => 'center'],
                self::block('column', ['width' => $dominant ? '40%' : '58%', 'verticalAlignment' => 'center'], $copy)
                . self::block('column', ['width' => $dominant ? '60%' : '42%', 'verticalAlignment' => 'center'], $media));
            $background = (string) ($section['background'] ?? 'base');
            $attrs = ['anchor' => $slug, 'className' => $rootClasses];
            $attrs += match ($background) {
                'tinted'   => ['backgroundColor' => 'band', 'textColor' => 'contrast'],
                'contrast' => ['backgroundColor' => 'contrast', 'textColor' => 'base'],
                default    => [],
            };
            $attrs['style'] = ['spacing' => ['margin' => ['top' => '0', 'bottom' => '0'], 'padding' => ['top' => 'var:preset|spacing|md']]];
            $attrs['layout'] = ['type' => 'constrained'];
            return self::root($attrs, $row, $slug);
        }

        $minHeight = match ((string) ($blueprint['height_profile'] ?? 'standard')) {
            'compact'   => 56,
            'immersive' => 88,
            default     => 72,
        };
        $protection = $overlay ? (string) ($contract['header']['protection_token'] ?? 'base') : 'base';
        $cover = [
            'dimRatio'           => 50,
            'overlayColor'       => $protection,
            'isUserOverlayColor' => true,
            'minHeight'          => $minHeight,
            'minHeightUnit'      => 'vh',
            'contentPosition'    => 'center ' . $side,
            'align'              => 'full',
            'className'          => 'hero-composition__media',
            'textColor'          => $protection === 'contrast' ? 'base' : 'contrast',
            'style'              => ['spacing' => ['padding' => [
                'top' => 'var:preset|spacing|xl', 'bottom' => 'var:preset|spacing|xl',
                'left' => 'var:preset|spacing|md', 'right' => 'var:preset|spacing|md',
            ]]],
            'layout'             => ['type' => 'constrained'],
        ];
        if ($image !== null) {
            $cover = ['url' => $image['url']] + $cover;
        }
        $html = ($image === null ? '' : '<img class="wp-block-cover__image-background" src="' . self::h($image['url']) . '" alt="' . self::h($image['alt']) . '" data-object-fit="cover"/>')
            . '<span aria-hidden="true" class="wp-block-cover__background has-' . $protection . '-background-color has-background-dim-50 has-background-dim"></span>'
            . '<div class="wp-block-cover__inner-container">' . $copy . '</div>';
        $coverMarkup = BlockMarkup::serializeComment('cover', $cover, false)
            . '<div class="wp-block-cover alignfull hero-composition__media">' . $html . '</div><!-- /wp:cover -->';
        $attrs = [
            'anchor'    => $slug,
            'className' => $rootClasses,
            'align'     => 'full',
        ];
        if ($overlay) {
            $attrs['backgroundColor'] = $protection;
            $attrs['textColor'] = $protection === 'contrast' ? 'base' : 'contrast';
        }
        $attrs['style'] = ['spacing' => ['margin' => ['top' => '0', 'bottom' => '0'], 'padding' => ['top' => '0', 'bottom' => '0', 'left' => '0', 'right' => '0']]];
        $attrs['layout'] = ['type' => 'constrained'];
        return self::root($attrs, $coverMarkup, $slug);
    }

    /** The one copy region: headline, one supporting line, the planned action. */
    private static function copy(array $doc, array $context, string $side, bool $emphasis): string
    {
        $headline = trim((string) ($doc['heading'] ?? '')) ?: trim((string) ($context['section']['title'] ?? 'Welcome'));
        $emph = trim((string) ($doc['heading_emphasis'] ?? ''));
        $headlineHtml = self::h($headline);
        if ($emphasis && $emph !== '' && str_contains($headline, $emph)) {
            $headlineHtml = str_replace(self::h($emph), '<span class="emph">' . self::h($emph) . '</span>', $headlineHtml);
        }
        $centered = $side === 'center';
        $heading = ['level' => 1, 'fontSize' => 'display'];
        if ($centered) {
            $heading['style'] = ['typography' => ['textAlign' => 'center']];
        }
        $inner = self::block('heading', $heading, $headlineHtml, 'h1');
        $lead = trim((string) ($doc['lead'] ?? ''));
        if ($lead === '') {
            $paragraphs = is_array($doc['paragraphs'] ?? null) ? $doc['paragraphs'] : [];
            $lead = trim((string) ($paragraphs[0] ?? ''));
        }
        if ($lead !== '') {
            $inner .= self::block(
                'paragraph',
                ['fontSize' => 'lead'] + ($centered ? ['align' => 'center', 'style' => ['typography' => ['textAlign' => 'center']]] : []),
                self::h($lead),
                'p',
            );
        }
        $action = $context['primary_action'];
        if (is_array($action)) {
            $inner .= self::block('buttons', ['layout' => ['type' => 'flex', 'justifyContent' => $side]],
                self::block('button', [], '<a href="' . self::h((string) $action['destination']) . '">' . self::h((string) $action['label']) . '</a>'));
        }
        return self::block('group', [
            'className' => 'hero-composition__copy',
            'style'     => ['spacing' => ['blockGap' => 'var:preset|spacing|md']],
            'layout'    => ['type' => 'constrained', 'contentSize' => '640px', 'justifyContent' => $side],
        ], $inner);
    }

    /** @return array{url:string,alt:string}|null */
    private static function image(array $doc, string $slug, string $aspect, string $mediaMode): ?array
    {
        $subject = trim(str_replace('|', ' ', (string) ($doc['image_subject'] ?? '')));
        if ($subject === '') {
            return null;
        }
        $context = trim(str_replace('|', ' ', (string) ($doc['image_context'] ?? '')));
        if ($context === '') {
            $context = $mediaMode === 'cover-image'
                ? 'full-frame photographic backdrop behind the page-opening copy, with a calm low-detail area kept clear for it'
                : 'contained foreground photograph beside the page-opening copy';
        }
        $style = in_array($doc['image_style'] ?? '', SectionContent::IMAGE_STYLES, true) ? (string) $doc['image_style'] : 'photorealistic';
        $words = preg_split('/[^a-z0-9]+/', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $subject) ?: $subject), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $name = trim(preg_replace('/[^a-z0-9-]+/', '-', $slug . '-' . (implode('-', array_slice($words, 0, 6)) ?: 'image')) ?? '', '-');
        return [
            'url' => 'theme:./assets/' . $name . '.jpg',
            'alt' => 'AI_IMAGE: ' . $subject . ' | ' . $context . ' | ' . $style . ' | ' . $aspect,
        ];
    }

    private static function root(array $attrs, string $inner, string $slug): string
    {
        $classes = (string) $attrs['className'];
        if (isset($attrs['align'])) {
            $classes .= ' align' . $attrs['align'];
        }
        if (isset($attrs['backgroundColor'])) {
            $classes .= ' has-' . $attrs['backgroundColor'] . '-background-color has-background';
        }
        return BlockMarkup::serializeComment('group', $attrs, false)
            . '<div id="' . self::h($slug) . '" class="' . self::h($classes) . '">' . $inner . '</div><!-- /wp:group -->';
    }

    private static function block(string $name, array $attrs, string $inner, string $tag = 'div'): string
    {
        $class = isset($attrs['className']) ? ' class="' . self::h((string) $attrs['className']) . '"' : '';
        return BlockMarkup::serializeComment($name, $attrs, false)
            . '<' . $tag . $class . '>' . $inner . '</' . $tag . '><!-- /wp:' . $name . ' -->';
    }

    private static function h(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
