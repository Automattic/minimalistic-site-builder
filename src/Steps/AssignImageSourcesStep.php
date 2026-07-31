<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Step (deterministic, HTML-first only): give every content <img> in the
 * design pages a real theme asset path.
 *
 * Input:  design/*.html
 * Output: the same files, with each <img> src rewritten to
 *         "theme:./assets/<slug>-<hash>.<ext>".
 *
 * The design prompts author <img> tags with a rich prose alt but an invented
 * (or empty) src. Both forms lose the image: the transformer drops an <img>
 * with no resolvable src, and a dangling "hero.jpg" ships as a broken image.
 *
 * Assigning the path HERE — before transform-site — is what makes the rest of
 * the image pipeline work unchanged: the <img> becomes a real core/image
 * block carrying the path, collect-images reads the path back out of the
 * transformed parts, and generate-images writes the file at exactly that path
 * and rewrites the reference. The prose alt is never touched: it is the
 * generation prompt AND the accessibility text.
 */
final class AssignImageSourcesStep implements Step
{
    /** Assets the design marks as needing an alpha channel get .png, not .jpg. */
    private const TRANSPARENT_RE = '/\b(logo|wordmark|monogram|icon|badge|crest|seal|transparent)\b/i';

    private const LOG_FILE = 'assign-image-sources.log';

    public function id(): string
    {
        return 'assign-image-sources';
    }

    public function label(): string
    {
        return 'Assign design image sources';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['design/*'],
            writes: ['design/*'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $assigned = 0;
        $notes = [];
        foreach (glob($project->path('design/*.html')) ?: [] as $abs) {
            $rel = 'design/' . basename($abs);
            $page = preg_replace('/\.html$/', '', basename($abs)) ?? 'page';
            $content = $project->readText($rel);
            $result = self::assign($content, $page);
            if ($result['content'] !== $content) {
                $project->writeText($rel, $result['content']);
            }
            foreach ($result['assigned'] as $src) {
                $notes[] = "{$rel}: {$src}";
                $assigned++;
            }
        }

        $project->writeText(
            'logs/' . self::LOG_FILE,
            $notes === [] ? "No design images to assign.\n" : '- ' . implode("\n- ", $notes) . "\n",
        );
        echo '  images: ' . $assigned . " design <img> source(s) assigned\n";
    }

    /**
     * Rewrite every <img> src in one design document. Pure, so it is
     * unit-testable and the step itself stays I/O only.
     *
     * @return array{content:string,assigned:list<string>}
     */
    public static function assign(string $content, string $page): array
    {
        $assigned = [];
        $index = 0;
        $content = (string) preg_replace_callback(
            '/<img\b[^>]*>/i',
            static function (array $match) use (&$assigned, &$index, $page): string {
                $tag = $match[0];
                $index++;
                $src = self::attr($tag, 'src');
                // A data: URI is already self-contained artwork, and a path we
                // assigned on an earlier run is stable by design.
                if (str_starts_with(strtolower(ltrim($src)), 'data:')
                    || str_starts_with($src, 'theme:./assets/')) {
                    return $tag;
                }

                $alt = self::attr($tag, 'alt');
                $src = 'theme:./assets/' . self::assetName($alt, $page, $index, $tag);
                $assigned[] = $src;
                return self::withSrc($tag, $src);
            },
            $content,
        );

        return ['content' => $content, 'assigned' => $assigned];
    }

    /**
     * A stable "<slug>-<hash>.<ext>" asset name. The hash keys on the prose
     * alt, so the same described image reused across pages resolves to one
     * generated file while two different descriptions never collide.
     */
    private static function assetName(string $alt, string $page, int $index, string $tag): string
    {
        $key = trim((string) preg_replace('/\s+/', ' ', $alt));
        if ($key === '') {
            $key = "{$page}#{$index}";
            $slug = ProjectStore::slugify("{$page}-image-{$index}");
        } else {
            $slug = ProjectStore::slugify($key);
        }
        $slug = rtrim(substr($slug, 0, 40), '-') ?: 'image';
        $ext = preg_match(self::TRANSPARENT_RE, $alt . ' ' . self::attr($tag, 'class')) === 1 ? 'png' : 'jpg';

        return $slug . '-' . substr(sha1(strtolower($key)), 0, 8) . '.' . $ext;
    }

    /**
     * One attribute's value from a tag, entity-decoded; '' when absent. The
     * lookbehind keeps data-src/data-alt from standing in for the real ones.
     */
    private static function attr(string $tag, string $name): string
    {
        $pattern = '/(?<![-\w])' . preg_quote($name, '/') . '\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i';
        if (preg_match($pattern, $tag, $m) !== 1) {
            return '';
        }
        $value = $m[1];
        if (str_starts_with($value, '"') || str_starts_with($value, "'")) {
            $value = substr($value, 1, -1);
        }
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
    }

    /**
     * Replace (or add) src, and drop srcset/sizes: those name candidates for
     * the source we just replaced, and the transformer would carry them into
     * the block as dangling references.
     */
    private static function withSrc(string $tag, string $src): string
    {
        $tag = (string) preg_replace('/\s+(?:srcset|sizes)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $tag);
        $replaced = (string) preg_replace(
            '/(?<![-\w])src\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i',
            'src="' . $src . '"',
            $tag,
            1,
            $count,
        );
        if ($count > 0) {
            return $replaced;
        }
        return (string) preg_replace('/^<img\b/i', '<img src="' . $src . '"', $tag, 1);
    }
}
