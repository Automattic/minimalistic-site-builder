<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\GeminiImage;
use Automattic\SiteBuild\JsonDecoder;
use Automattic\SiteBuild\MediaReferenceRemoval;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\Warnings;

/**
 * Step (deterministic): collect the AI image placeholders the sections step
 * emitted into the theme markup, so they can be generated.
 *
 * Input:  theme/parts/*.html
 * Output: images.json — an array of image specs, one per unique asset filename:
 *           { filename, src, subject, pageContext, style, aspectRatio, status, sources[] }
 *         plus in-place normalization of malformed AI_IMAGE url/src values to
 *         canonical theme asset paths.
 *
 * On the HTML-first path the design prompts never learned that convention, so
 * a second form is collected too (see parseAssignedImages): an <img> carrying
 * the theme asset path assign-image-sources gave it and the design's prose
 * alt, which is the generation subject as-is.
 *
 * Placeholders follow the telex convention: an <img> whose src is a theme-relative
 * "theme:./assets/<name>.jpg" path (".png" for transparent-background assets)
 * and whose alt is "AI_IMAGE: subject | page-context
 * | style | aspect-ratio". The subject describes what to render and from what POV; the
 * page-context describes where/how the image is used (it is context for the generator,
 * not part of the rendered subject — see ImagePromptComposer). This step is
 * deterministic and makes no network calls, so it always runs as part of the
 * build; the heavier GenerateImagesStep is opt-in.
 *
 * It runs BEFORE fix-blocks on purpose: the block re-serializer strips the alt
 * from wp:cover background images, so the AI_IMAGE spec is only intact in the raw
 * section markup. images.json is then the durable record of what to generate.
 *
 * The model sometimes drops the "AI_IMAGE:" spec straight into a wp:cover "url"
 * or a bare <img> src instead of the alt convention. Those values are decoded,
 * collected, and rewritten immediately to a synthetic theme asset path. Doing
 * that before fix-blocks gives every downstream step the same canonical path:
 * block serialization cannot change the later rewrite key, assemble-pages sees
 * a content asset to import, and raw prompt text never reaches final markup.
 */
final class CollectImagesStep implements Step
{
    /**
     * @param bool $htmlFirst also collect the plain "<img src=theme:./assets/…
     *        alt=prose>" form assign-image-sources produces. The HTML-first
     *        design prompts never learned the AI_IMAGE alt convention — the
     *        prose alt IS the subject. The AI_IMAGE parse stays on because the
     *        legacy chrome/page prompts still run as that path's fallbacks.
     */
    public function __construct(private bool $htmlFirst = false) {}

    public function id(): string
    {
        return 'collect-images';
    }

    public function label(): string
    {
        return 'Collect AI image placeholders';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['theme/parts/*', 'siteSpec.json'],
            writes: [
                'images.json',
                'theme/parts/*',
                'warnings.json',
            ],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        /** @var array<string,array<string,mixed>> $byFilename keyed by filename, deduped */
        $byFilename = [];
        /** @var array<string,bool> $canonicalByFilename keyed by filename */
        $canonicalByFilename = [];
        $warnings = [];

        foreach ($this->themeHtmlFiles($project) as $rel) {
            $content = $project->readText('theme/' . $rel);
            $parsed = self::parseAndNormalize($content);
            $updated = $parsed['content'];

            // parseAndNormalize returns four-field canonical entries first,
            // followed by recovery fallbacks. Preserve that parser provenance:
            // assigned rows also carry a non-empty derived pageContext, so the
            // fields alone cannot identify a canonical entry.
            $canonicalCount = count(self::parseCanonicalPlaceholders($parsed['content']));
            $images = [];
            foreach ($parsed['images'] as $index => $image) {
                $fromCanonicalParser = $index < $canonicalCount;
                $images[] = [
                    'image' => $image,
                    'canonical' => $fromCanonicalParser && (
                        $image['pageContext'] !== ''
                        || $image['style'] !== ''
                        || $image['aspectRatio'] !== 'landscape'
                    ),
                    'assigned' => false,
                ];
            }
            if ($this->htmlFirst) {
                foreach (self::parseAssignedImages($parsed['content'], $rel) as $image) {
                    // An assigned row is a re-detection of an <img> that already
                    // carries a theme asset path, so it is never an independent
                    // placeholder and must never trigger a collision rename.
                    $images[] = ['image' => $image, 'canonical' => false, 'assigned' => true];
                }
            }
            foreach ($images as $entry) {
                $img = $entry['image'];
                $cappedForFooter = false;
                $authoredRatio = $img['aspectRatio'] ?? null;
                if ($rel === 'parts/footer.html'
                    && is_string($authoredRatio)
                    && self::isPortraitAspectRatio($authoredRatio)
                ) {
                    $img['aspectRatio'] = 'square';
                    $cappedForFooter = true;
                    $warnings[] = "file='theme/parts/footer.html'; block='AI_IMAGE placeholder'; asset="
                        . Warnings::value($img['src'] ?? $img['filename'] ?? 'unknown') . '; authored aspect-ratio='
                        . Warnings::value($authoredRatio) . '; delivered aspect-ratio="square"; '
                        . 'disposition=portrait-oriented footer image capped after placeholder recovery '
                        . 'so it cannot stretch the footer band';
                }
                $filename = $img['filename'];
                if (isset($byFilename[$filename])) {
                    $sameSubject = self::normalizeSubject((string) $img['subject'])
                        === self::normalizeSubject((string) $byFilename[$filename]['subject']);
                    // A canonical AI_IMAGE occurrence upgrades a spec first seen
                    // as a non-canonical fallback for the same filename, so the
                    // richer four-field description wins even when the fallback's
                    // provisional subject differed.
                    $upgradesFallback = $entry['canonical'] && !$canonicalByFilename[$filename];
                    if ($sameSubject || $entry['assigned'] || $upgradesFallback) {
                        // The same asset genuinely shared, a re-detected assigned
                        // duplicate of a placeholder already collected here, or a
                        // canonical row upgrading a stored fallback — record the
                        // source once and never split.
                        if (!in_array($rel, $byFilename[$filename]['sources'], true)) {
                            $byFilename[$filename]['sources'][] = $rel;
                        }
                        if ($upgradesFallback) {
                            foreach (['subject', 'pageContext', 'style', 'aspectRatio'] as $field) {
                                $byFilename[$filename][$field] = $img[$field];
                            }
                            $canonicalByFilename[$filename] = true;
                        }
                        if ($cappedForFooter) {
                            // A shared asset must use the footer-safe shape even
                            // if an earlier non-footer source (or a canonical row
                            // above) set a portrait ratio, so the footer band
                            // can't be stretched.
                            $byFilename[$filename]['aspectRatio'] = 'square';
                        }
                        continue;
                    }
                    // Concurrently authored sections cannot see each other's
                    // asset names, so two of them sometimes coin the SAME
                    // descriptive filename for DIFFERENT subjects. Merging on
                    // the name would render one photo in both slots
                    // (BIGR-793); give the newcomer a deterministic variant
                    // name and its own spec instead.
                    $variant = self::availableVariantFilename(
                        $filename,
                        (string) $img['subject'],
                        $byFilename,
                    );
                    $rewritten = self::renameCanonicalPlaceholder(
                        $updated,
                        $filename,
                        (string) $img['subject'],
                        $variant,
                    );
                    if ($rewritten === null) {
                        if (!in_array($rel, $byFilename[$filename]['sources'], true)) {
                            $byFilename[$filename]['sources'][] = $rel;
                        }
                        if ($cappedForFooter) {
                            $byFilename[$filename]['aspectRatio'] = 'square';
                        }
                        $warnings[] = "file='theme/{$rel}'; block="
                            . Warnings::value('AI_IMAGE placeholder for ' . (string) $img['subject'])
                            . '; authored asset=' . Warnings::value($filename)
                            . '; delivered asset=' . Warnings::value($filename)
                            . '; disposition=different-subject filename collision retained because the '
                            . 'individual placeholder could not be isolated safely; the first image may repeat';
                        continue;
                    }
                    $updated = $rewritten;
                    $warnings[] = "file='theme/{$rel}'; block="
                        . Warnings::value('AI_IMAGE placeholder for ' . (string) $img['subject'])
                        . '; authored asset=' . Warnings::value($filename)
                        . '; delivered asset=' . Warnings::value($variant)
                        . '; disposition=another placeholder already claimed this filename for a different '
                        . 'subject, so this reference was renamed to generate its own image instead of '
                        . 'repeating that photo';
                    $img['filename'] = $variant;
                    $img['src'] = 'theme:./assets/' . $variant;
                    $filename = $variant;
                    if (isset($byFilename[$filename])) {
                        if (!in_array($rel, $byFilename[$filename]['sources'], true)) {
                            $byFilename[$filename]['sources'][] = $rel;
                        }
                        if ($cappedForFooter) {
                            $byFilename[$filename]['aspectRatio'] = 'square';
                        }
                        continue;
                    }
                }
                $img['sources'] = [$rel];
                $img['status']  = 'pending';
                $byFilename[$filename] = $img;
                $canonicalByFilename[$filename] = $entry['canonical'];
            }
            if ($updated !== $content) {
                $project->writeText('theme/' . $rel, $updated);
            }
        }

        // A part can reference a theme asset that no placeholder declares —
        // typically a mangled marker ("AI_IMATE_PLACEHOLDER") on an otherwise
        // well-shaped theme: src (BIGR-787). Nothing will ever generate that
        // file, so the reference would ship as a broken image with its raw alt
        // visible. Degrade exactly like a failed generation: remove the owning
        // media block where structurally safe, warn either way.
        foreach ($this->themeHtmlFiles($project) as $rel) {
            $content = $project->readText('theme/' . $rel);
            $updated = $content;
            foreach (self::unresolvableSources($updated, $byFilename) as $source) {
                $removal = MediaReferenceRemoval::removeSourceWithReport($updated, $source);
                $candidate = $removal['markup'];
                if (MediaReferenceRemoval::position($candidate, $source) !== null) {
                    $warnings[] = "file='theme/{$rel}'; asset=" . Warnings::value($source)
                        . '; delivered retained in unsafe markup; disposition=no placeholder declares '
                        . 'this asset and no safe media isolation was available';
                    continue;
                }
                $updated = $candidate;
                $warnings[] = "file='theme/{$rel}'; asset=" . Warnings::value($source)
                    . '; delivered removed; disposition=referenced theme asset has no AI_IMAGE '
                    . 'placeholder (malformed or missing spec), so nothing would ever generate it; '
                    . 'its media block was removed instead of shipping a broken image';
                foreach ($removal['removedCaptions'] as $caption) {
                    $warnings[] = "file='theme/{$rel}'; block='wp:paragraph at byte {$caption['start']}'; asset="
                        . Warnings::value($source) . '; authored caption=' . Warnings::value($caption['text'])
                        . '; delivered removed; disposition=caption removed with its unavailable image '
                        . 'instead of shipping an orphaned description';
                }
            }
            if ($updated !== $content) {
                $project->writeText('theme/' . $rel, $updated);
            }
        }

        $this->maybeAppendSiteLogo($project, $byFilename, $warnings);
        $project->writeJson('images.json', array_values($byFilename));
        $project->addWarnings($this->id(), $warnings);
    }

    /**
     * @param array<string,array<string,mixed>> $byFilename
     * @param list<string> $warnings
     */
    private function maybeAppendSiteLogo(Project $project, array &$byFilename, array &$warnings): void
    {
        if (!$project->exists('siteSpec.json')) {
            return;
        }
        $siteSpec = $project->readJson('siteSpec.json');
        if (!SiteSpecStep::wantsLogoMark($siteSpec)) {
            return;
        }
        if (isset($byFilename['site-logo.png'])) {
            $warnings[] = "file='images.json'; asset='site-logo.png'; delivered no synthetic mark; "
                . 'disposition=the reserved site-logo filename was already collected from page markup';
            return;
        }
        $byFilename['site-logo.png'] = self::siteLogoSpec($siteSpec);
    }

    /**
     * @param array<mixed> $siteSpec
     * @return array<string,mixed>
     */
    private static function siteLogoSpec(array $siteSpec): array
    {
        $identities = [
            trim((string) ($siteSpec['name'] ?? '')),
            trim((string) ($siteSpec['persona_name'] ?? '')),
            trim((string) ($siteSpec['email_domain'] ?? '')),
        ];
        $area = trim((string) ($siteSpec['area'] ?? ''));
        $topic = trim((string) ($siteSpec['topic'] ?? ''));
        $siteType = trim((string) ($siteSpec['site_type'] ?? ''));
        $vibe = trim((string) ($siteSpec['visual_vibe'] ?? ''));
        // Every non-personal site gets a mark, so the fallback is the
        // neutral word "organization". A nonprofit, a school, and a festival
        // all get a mark too.
        $about = 'an organization';
        if ($area !== '' && GenerateImagesStep::safeSubjectMatter($area, $identities)) {
            $about = $area;
        } elseif ($topic !== '' && GenerateImagesStep::safeSubjectMatter($topic, $identities)) {
            $about = $topic;
        } elseif ($siteType !== '' && GenerateImagesStep::safeSubjectMatter($siteType, $identities)) {
            $about = "a {$siteType}";
        }
        $mood = ($vibe !== '' && GenerateImagesStep::safeSubjectMatter($vibe, $identities))
            ? ", {$vibe} mood"
            : '';
        return [
            'filename'    => 'site-logo.png',
            'src'         => 'theme:./assets/site-logo.png',
            'subject'     => "simple geometric brand mark for {$about}{$mood}, single ink, no letters, no numerals, no wordmark, no signage",
            'pageContext' => 'site logo and site icon, small square mark in the header',
            'style'       => 'flat',
            'aspectRatio' => 'square',
            'status'      => 'pending',
            'sources'     => [],
            'role'        => 'site-logo',
        ];
    }

    /**
     * Theme asset sources referenced in this markup that no collected
     * placeholder declares — the set nothing will ever generate.
     *
     * @param array<string,array<string,mixed>> $byFilename collected specs
     * @return list<string> unique "theme:./assets/<file>" sources
     */
    public static function unresolvableSources(string $content, array $byFilename): array
    {
        if (!preg_match_all(
            '/theme:\.\/assets\/([a-z0-9-]+\.(?:jpe?g|png))/i',
            $content,
            $matches,
            PREG_SET_ORDER
        )) {
            return [];
        }
        $sources = [];
        foreach ($matches as $match) {
            if (
                !isset($byFilename[$match[1]])
                && MediaReferenceRemoval::position($content, $match[0]) !== null
            ) {
                $sources[$match[0]] = true;
            }
        }
        return array_keys($sources);
    }

    /**
     * Theme-relative paths of every markup file that may hold image
     * placeholders. Templates are scanned only when they already exist: in
     * the default graph assemble-pages writes them after this step, so on a
     * normal build the section parts are the whole story.
     */
    private function themeHtmlFiles(Project $project): array
    {
        $files = [];
        foreach (glob($project->themePath('parts/*.html')) ?: [] as $abs) {
            $files[] = 'parts/' . basename($abs);
        }
        if (!$this->htmlFirst) {
            return $files;
        }
        foreach (glob($project->themePath('templates/*.html')) ?: [] as $abs) {
            $files[] = 'templates/' . basename($abs);
        }
        return $files;
    }

    /**
     * Parse the HTML-first form: an <img> whose src is the theme asset path
     * assign-image-sources gave it and whose alt is the design's prose
     * description. That alt is the whole generation brief, so it becomes the
     * subject verbatim; the page-context comes from the part path and style
     * stays empty (the composer already folds in the design's image grade).
     * The ratio defaults to landscape like every other recovered placeholder —
     * the design markup carries no reliable shape hint.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function parseAssignedImages(string $content, string $source = ''): array
    {
        preg_match_all('/<img\b[^>]*>/i', $content, $matches);

        $images = [];
        foreach ($matches[0] as $tag) {
            // The lookbehind keeps data-src/data-alt from standing in for the
            // real attributes.
            if (!preg_match('/(?<![-\w])src=(["\'])(theme:\.\/assets\/([a-z0-9-]+\.(?:jpe?g|png)))\1/i', $tag, $src)) {
                continue;
            }
            if (!preg_match('/(?<![-\w])alt=(["\'])(.*?)\1/is', $tag, $alt)) {
                continue;
            }
            $subject = trim((string) preg_replace(
                '/\s+/',
                ' ',
                html_entity_decode($alt[2], ENT_QUOTES | ENT_HTML5),
            ));
            if (str_starts_with($subject, 'AI_IMAGE:')) {
                $subject = trim(explode('|', substr($subject, strlen('AI_IMAGE:')), 2)[0]);
            }
            $context = self::pageContextFor($source);
            // A decorative image the design left undescribed still has an
            // assigned path: generate something on-brand for its slot rather
            // than ship a reference nothing ever writes a file for.
            $subject = $subject !== '' ? $subject : $context;
            if ($subject === '') {
                continue;
            }

            $images[] = [
                'filename'    => $src[3],
                'src'         => $src[2],
                'subject'     => $subject,
                'pageContext' => $context,
                'style'       => '',
                'aspectRatio' => 'landscape',
            ];
        }

        return $images;
    }

    /** Where an image is used, read off the part path assemble-pages keys on. */
    private static function pageContextFor(string $source): string
    {
        $base = preg_replace('/\.html$/', '', basename($source)) ?? '';
        if ($base === 'header' || $base === 'footer') {
            return "site {$base}";
        }
        if (preg_match('/^page-(.+?)--(.+)$/', $base, $m) !== 1) {
            return '';
        }
        return str_replace('-', ' ', $m[2]) . ' section of the ' . str_replace('-', ' ', $m[1]) . ' page';
    }

    /**
     * Extract image specs from one markup file. Matches <img> tags whose alt
     * begins with the AI_IMAGE marker; the filename comes from the src attribute.
     * Pure (no I/O) so it is unit-testable. Recovered specs report the
     * canonical theme asset path that run() writes into the markup.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function parsePlaceholders(string $content): array
    {
        return self::parseAndNormalize($content)['images'];
    }

    /**
     * Parse canonical placeholders and recover malformed URL/source forms,
     * returning the normalized markup alongside their shared image specs.
     *
     * @return array{content:string,images:array<int,array<string,mixed>>}
     */
    private static function parseAndNormalize(string $content): array
    {
        if (!str_contains($content, 'AI_IMAGE:')) {
            return ['content' => $content, 'images' => []];
        }

        // Canonical placeholders in the original markup double as recovery
        // targets: a malformed cover "url" whose subject matches its inner
        // img's documented AI_IMAGE alt must adopt that img's asset path, not
        // synthesize a second image for the same background.
        $canonicalSrcBySubject = [];
        foreach (self::parseCanonicalPlaceholders($content) as $image) {
            $canonicalSrcBySubject[self::normalizeSubject($image['subject'])] = $image['src'];
        }

        $recovered = self::recoverPlaceholders($content, $canonicalSrcBySubject);
        $images = self::parseCanonicalPlaceholders($recovered['content']);

        // A malformed src can coexist with a valid AI_IMAGE alt. Once recovery
        // gives that tag a canonical theme path, the canonical parser above
        // produces the richer four-field spec under the same filename. Keep it
        // and discard the recovery fallback rather than generating twice.
        $seen = array_fill_keys(array_column($images, 'filename'), true);
        foreach ($recovered['images'] as $image) {
            if (!isset($seen[$image['filename']])) {
                $images[] = $image;
                $seen[$image['filename']] = true;
            }
        }

        return ['content' => $recovered['content'], 'images' => $images];
    }

    /**
     * Parse the documented "<img alt=AI_IMAGE src=theme:./assets/...>" form.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function parseCanonicalPlaceholders(string $content): array
    {
        // alt=(quote)AI_IMAGE: ... (same quote). Backreference \1 matches the
        // opening quote type so quotes inside the alt don't truncate the match.
        $pattern = '/<img[^>]+alt=(["\'])AI_IMAGE:\s*(.*?)\1[^>]*>/is';
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        $images = [];
        foreach ($matches as $match) {
            $imgTag = $match[0];
            $alt    = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5);

            if (!preg_match('/src=(["\'])(theme:\.\/assets\/([a-z0-9-]+\.(?:jpe?g|png)))\1/i', $imgTag, $srcMatch)) {
                continue; // no theme-relative asset src — skip
            }
            $src      = $srcMatch[2];
            $filename = $srcMatch[3];

            // subject | page-context | style | aspect-ratio. We pop the three
            // trailing fixed fields from the end, so the subject (the lead, the
            // only field meant to be rich) may itself contain pipes.
            $parts = explode('|', $alt);
            if (count($parts) < 4) {
                continue;
            }
            $aspectRatio = strtolower(trim(array_pop($parts)));
            $style       = strtolower(trim(array_pop($parts)));
            $pageContext = trim(array_pop($parts));
            $subject     = trim(implode('|', $parts));
            if ($subject === '') {
                continue;
            }

            $images[] = [
                'filename'    => $filename,
                'src'         => $src,
                'subject'     => $subject,
                'pageContext' => $pageContext,
                'style'       => $style,
                'aspectRatio' => $aspectRatio,
            ];
        }

        return $images;
    }

    /**
     * Recover AI_IMAGE specs the model placed where a resolved asset path
     * belongs — a wp:cover block's "url" or a bare <img> src — instead of the
     * documented "<img alt=\"AI_IMAGE: …\" src=\"theme:./assets/…\">" form.
     *
     * JSON escapes and HTML entities are decoded before hashing/deduplication,
     * so a cover "url" containing "\u0026" and its <img> src containing
     * "&amp;" resolve to one semantic prompt and one filename. Both contexts
     * are rewritten in place to one theme asset path — the same-file canonical
     * placeholder's path when one names the same subject (so a half-canonical
     * cover keeps its url and rendered src on one asset), otherwise a
     * synthetic one.
     *
     * @param array<string,string> $canonicalSrcBySubject normalized subject => theme: src
     * @return array{content:string,images:array<int,array<string,mixed>>}
     */
    private static function recoverPlaceholders(string $content, array $canonicalSrcBySubject): array
    {
        /** @var array<string,array<string,mixed>> $byPrompt semantic prompt => spec */
        $byPrompt = [];

        $imageFor = static function (string $semantic) use (&$byPrompt, $canonicalSrcBySubject): ?array {
            $body = trim(substr($semantic, strlen('AI_IMAGE:')));
            $promptKey = 'AI_IMAGE:' . $body;
            if (!isset($byPrompt[$promptKey])) {
                [$subject, $aspectRatio] = self::recoverSubjectAndRatio($body);
                if ($subject === '') {
                    return null;
                }
                $src = $canonicalSrcBySubject[self::normalizeSubject($subject)]
                    ?? 'theme:./assets/' . self::synthesizeFilename($subject, $promptKey);
                $byPrompt[$promptKey] = [
                    'filename'    => substr($src, strlen('theme:./assets/')),
                    'src'         => $src,
                    'subject'     => $subject,
                    'pageContext' => '',
                    'style'       => '',
                    'aspectRatio' => $aspectRatio,
                ];
            }
            return $byPrompt[$promptKey];
        };

        // One rule per SOURCE SYNTAX, not per malformed shape. There are only
        // three ways this grammar writes a source value (a JSON "url"/"src"
        // field, a quoted src= attribute, an unquoted src= attribute), so each
        // pattern captures the whole value for its syntax and stays frozen;
        // whether that value is a spec, and under which wrappers (comments,
        // entities, whitespace), is decided by specFromSourceValue(). The
        // model's next creative wrapper lands there as plain string handling,
        // not as another regex. A value that is a real path no-ops.
        // The passes run in listed order over the same content, but the order
        // is incidental: a repaired value no longer carries AI_IMAGE:, so later
        // passes no-op on it. By default a rule splices the resolved path
        // between its captured prefix and suffix; the unquoted rule overrides
        // 'rewrite' to also add the quotes the model omitted.
        // ThemeValidator::unresolvedImageSourceProblems() keeps its own two
        // anchored detections of these same syntaxes; they must stay a subset
        // of what these rules repair, which the "recovers every source shape
        // the validator flags" test in collect_images_test.php pins.
        $spliceInPlace = static fn (array $match, array $image): string =>
            $match['prefix'] . $image['src'] . $match['suffix'];
        $rules = [
            // JSON source field in a block comment. Match "src" as well as
            // "url" so any block that puts the prompt in a JSON source field
            // gets the same repair.
            [
                'pattern' => '/(?P<prefix>"(?:url|src)"\s*:\s*")'
                    . '(?P<raw>(?:[^"\\\\]|\\\\.)*)(?P<suffix>")/is',
                'json'    => true,
            ],
            // Quoted src= attribute in rendered HTML. Keep the replacement
            // scoped to src= so an identical AI_IMAGE alt remains available to
            // the canonical parser above.
            [
                'pattern' => '/(?P<prefix>\bsrc\s*=\s*(?P<quote>["\']))'
                    . '(?P<raw>.*?)(?P<suffix>\k<quote>)/is',
                'json'    => false,
            ],
            // Unquoted src=AI_IMAGE:… — the value necessarily ends at the first
            // whitespace or ">", so a piped spec loses everything past the
            // subject's first word (and no wrapper can survive in it, which is
            // why this one keeps its AI_IMAGE anchor); a truncated recovery
            // still beats shipping the raw prompt (or, with images requested,
            // failing the whole build at the gate). The rewrite adds the
            // quotes the model omitted.
            [
                'pattern' => '/\bsrc\s*=\s*(?P<raw>AI_IMAGE:[^\s>"\'`=]*)/i',
                'json'    => false,
                'rewrite' => static fn (array $match, array $image): string =>
                    'src="' . $image['src'] . '"',
            ],
        ];

        foreach ($rules as $rule) {
            $rewrite = $rule['rewrite'] ?? $spliceInPlace;
            $content = (string) preg_replace_callback(
                $rule['pattern'],
                static function (array $match) use ($imageFor, $rule, $rewrite): string {
                    // Cheap bail for the common case: a real path. The literal
                    // token requirement matches the file-level guard in
                    // parseAndNormalize(), so nothing new is excluded.
                    if (stripos($match['raw'], 'AI_IMAGE') === false) {
                        return $match[0];
                    }
                    $spec = self::specFromSourceValue($match['raw'], $rule['json']);
                    if ($spec === null) {
                        return $match[0];
                    }
                    $image = $imageFor($spec);
                    return $image === null ? $match[0] : $rewrite($match, $image);
                },
                $content
            );
        }

        return ['content' => $content, 'images' => array_values($byPrompt)];
    }

    /**
     * The AI_IMAGE spec carried by one source value, or null when the value
     * is an ordinary path. All wrapper creativity is normalized away HERE, in
     * plain string handling (JSON escapes, HTML entities, an HTML comment
     * wrapped around the spec, stray whitespace), so the source-syntax
     * patterns in recoverPlaceholders() never change for a new wrapper.
     */
    private static function specFromSourceValue(string $raw, bool $json): ?string
    {
        if ($json) {
            $decoded = json_decode('"' . $raw . '"', true);
            if (is_string($decoded)) {
                $raw = $decoded;
            }
        }
        $value = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5));
        // The model sometimes wraps the whole spec in an HTML comment inside
        // the value (src="<!-- AI_IMAGE: {…} -->"); peel it and re-trim.
        $value = trim((string) preg_replace('/\A<!--\s*|\s*-->\z/', '', $value));
        return str_starts_with($value, 'AI_IMAGE:') ? $value : null;
    }

    /**
     * The comparison key under which a recovered subject matches a canonical
     * placeholder's subject: case- and whitespace-insensitive.
     */
    private static function normalizeSubject(string $subject): string
    {
        return strtolower((string) preg_replace('/\s+/', ' ', trim($subject)));
    }

    /**
     * A deterministic variant name for a filename another section already
     * claimed for a different subject: the stem plus a short hash of this
     * subject, so re-runs and retries produce the same variant.
     */
    public static function variantFilename(string $filename, string $subject): string
    {
        $dot = strrpos($filename, '.');
        $stem = $dot === false ? $filename : substr($filename, 0, $dot);
        $ext = $dot === false ? '' : substr($filename, $dot);
        return $stem . '-' . substr(sha1(self::normalizeSubject($subject)), 0, 6) . $ext;
    }

    /**
     * Pick the stable subject variant unless that exact filename already
     * belongs to another subject; never overwrite a collected manifest row.
     *
     * @param array<string,array<string,mixed>> $byFilename
     */
    private static function availableVariantFilename(string $filename, string $subject, array $byFilename): string
    {
        $variant = self::variantFilename($filename, $subject);
        if (
            !isset($byFilename[$variant])
            || self::normalizeSubject((string) $byFilename[$variant]['subject']) === self::normalizeSubject($subject)
        ) {
            return $variant;
        }

        $dot = strrpos($variant, '.');
        $stem = $dot === false ? $variant : substr($variant, 0, $dot);
        $ext = $dot === false ? '' : substr($variant, $dot);
        for ($suffix = 2; ; $suffix++) {
            $candidate = $stem . '-' . $suffix . $ext;
            if (
                !isset($byFilename[$candidate])
                || self::normalizeSubject((string) $byFilename[$candidate]['subject']) === self::normalizeSubject($subject)
            ) {
                return $candidate;
            }
        }
    }

    /**
     * Rename only canonical placeholders for the colliding subject. Bare
     * references and placeholders for another subject stay byte-for-byte.
     */
    private static function renameCanonicalPlaceholder(
        string $content,
        string $filename,
        string $subject,
        string $variant,
    ): ?string {
        $changed = false;
        $oldSrc = 'theme:./assets/' . $filename;
        $newSrc = 'theme:./assets/' . $variant;
        $normalizedSubject = self::normalizeSubject($subject);
        $updated = preg_replace_callback(
            '/<img\b[^>]*>/is',
            static function (array $match) use (
                &$changed,
                $filename,
                $normalizedSubject,
                $oldSrc,
                $newSrc,
            ): string {
                $images = self::parseCanonicalPlaceholders($match[0]);
                if (count($images) !== 1) {
                    return $match[0];
                }
                $image = $images[0];
                if (
                    $image['filename'] !== $filename
                    || self::normalizeSubject((string) $image['subject']) !== $normalizedSubject
                ) {
                    return $match[0];
                }
                $renamed = str_replace($oldSrc, $newSrc, $match[0]);
                $changed = $changed || $renamed !== $match[0];
                return $renamed;
            },
            $content,
        );

        return $changed && is_string($updated) ? $updated : null;
    }

    /**
     * A deterministic "<subject-slug>-<hash>.jpg" filename for a recovered
     * placeholder. The hash keys on the decoded semantic prompt, so serializer-
     * equivalent spellings share a filename while distinct prompts do not.
     */
    private static function synthesizeFilename(string $subject, string $literal): string
    {
        $slug = rtrim(substr(ProjectStore::slugify($subject), 0, 40), '-') ?: 'image';
        return $slug . '-' . substr(sha1($literal), 0, 8) . '.jpg';
    }

    /**
     * The subject and aspect ratio a recovered AI_IMAGE body carries.
     *
     * Two shapes reach here. The documented one is pipe-delimited: "subject |
     * page context | style | ratio", where the subject is the first field.
     * The model also emits an object form, {"prompt":…,"alt":…,"width":…,
     * "height":…}, usually wrapped in a comment inside src; there the prompt is
     * the subject and the dimensions give the aspect. Either way a usable
     * subject is what keeps the image from being dropped and the raw spec
     * shipped as a src.
     *
     * @return array{0:string,1:string} subject, aspect ratio
     */
    private static function recoverSubjectAndRatio(string $body): array
    {
        if (str_starts_with($body, '{')) {
            $object = JsonDecoder::decode($body);
            if ($object !== null) {
                $subject = trim((string) ($object['prompt'] ?? $object['alt'] ?? ''));
                $width   = (int) ($object['width'] ?? 0);
                $height  = (int) ($object['height'] ?? 0);
                // Route the dimensions through the same numeric-ratio mapping
                // the rest of the class uses rather than classifying inline.
                $ratio   = $width > 0 && $height > 0
                    ? GeminiImage::aspectRatio($width . ':' . $height)
                    : self::sniffAspectRatio($body);
                return [$subject, $ratio];
            }
        }

        return [trim(explode('|', $body, 2)[0]), self::sniffAspectRatio($body)];
    }

    /**
     * The aspect ratio in a recovered spec. The documented structured form's
     * trailing pipe field wins over ratio-like words in its subject, context or
     * style; malformed forms then fall back to an explicit `ratio:` label and,
     * finally, a heuristic keyword anywhere in the value. Explicit supported
     * ratios survive, unsupported numeric ratios are mapped by GeminiImage to
     * the closest supported shape, and named ratios remain named. Defaults to
     * landscape, the full-bleed default.
     */
    private static function sniffAspectRatio(string $body): string
    {
        $token = '(\d+:\d+|card-landscape|card-portrait|ultrawide|square|portrait|landscape)';
        $patterns = [
            // Canonical AI_IMAGE: subject | page context | style | aspect ratio.
            '/\|\s*(?:ratio:\s*)?' . $token . '\s*$/i',
            '/ratio:\s*' . $token . '/i',
            '/\b' . $token . '\b/i',
        ];
        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $body, $m)) {
                continue;
            }
            $ratio = strtolower($m[1]);
            return preg_match('/^\d+:\d+$/', $ratio) ? GeminiImage::aspectRatio($ratio) : $ratio;
        }

        return 'landscape';
    }

    /** Whether Gemini's delivered shape for this authored ratio is portrait. */
    private static function isPortraitAspectRatio(string $ratio): bool
    {
        if (preg_match('/^(\d+):(\d+)$/', GeminiImage::aspectRatio($ratio), $parts) !== 1) {
            return false;
        }
        return (int) $parts[1] < (int) $parts[2];
    }
}
