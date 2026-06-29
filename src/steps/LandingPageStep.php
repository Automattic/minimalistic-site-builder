<?php
declare(strict_types=1);

/**
 * Step 7 (LLM): build the landing page and the template parts it needs.
 *
 * Input:  meta.json (user prompt) + siteSpec.json + theme/theme.json. The model
 *         infers design intent from the brief and the theme tokens — there is no
 *         separate design document.
 * Output: theme/templates/{front-page,index}.html and theme/parts/{header,footer}.html
 *
 * The model returns a JSON map of theme-relative path => block markup; we
 * validate the expected files exist, look like block markup, and write them.
 */
final class LandingPageStep implements Step
{
    private const REQUIRED_FILES = [
        'parts/header.html',
        'parts/footer.html',
        'templates/index.html',
        'templates/front-page.html',
    ];

    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
        private ?string $model = null,
    ) {}

    public function id(): string
    {
        return 'landing-page';
    }

    public function label(): string
    {
        return 'Build landing page';
    }

    public function run(Project $project): void
    {
        $meta = $project->readJson('meta.json');
        $rendered = $this->renderer->render('landing-page.md', [
            'user_prompt' => (string) ($meta['prompt'] ?? ''),
            'site_spec'   => $project->readText('siteSpec.json'),
            'theme_json'  => $project->readText('theme/theme.json'),
        ]);

        // This is the slowest LLM step (large block-markup output); tell the user
        // what it's producing so the build doesn't look frozen while it runs.
        fwrite(STDERR, "    writing header, footer, index, front-page…\n");

        // Block markup for four files is large — give the model room. The
        // per-step model override (when set) rides alongside the token budget.
        $opts = ['max_tokens' => 32000];
        if ($this->model !== null) {
            $opts['model'] = $this->model;
        }
        $files = $this->llm->completeJson($rendered, $opts);

        foreach (self::REQUIRED_FILES as $rel) {
            $content = $files[$rel] ?? null;
            if (!is_string($content) || trim($content) === '') {
                throw new RuntimeException("landing-page output missing file: {$rel}");
            }
            if (!str_contains($content, 'wp:')) {
                throw new RuntimeException("landing-page file {$rel} has no block markup");
            }
            $project->writeText('theme/' . $rel, rtrim($content) . "\n");
        }

        // The two page templates must compose the parts.
        foreach (['templates/front-page.html', 'templates/index.html'] as $tpl) {
            $markup = (string) $files[$tpl];
            if (!str_contains($markup, 'wp:template-part')) {
                throw new RuntimeException("{$tpl} does not include template parts");
            }
        }
    }
}
