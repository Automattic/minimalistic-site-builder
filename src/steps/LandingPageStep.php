<?php
declare(strict_types=1);

/**
 * Step 7 (LLM): build the landing page and the template parts it needs.
 *
 * Input:  theme/theme.json + design.md + siteSpec.json
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
        $rendered = $this->renderer->render('landing-page.txt', [
            'site_spec'  => $project->readText('siteSpec.json'),
            'theme_json' => $project->readText('theme/theme.json'),
            'design_md'  => $project->readText('design.md'),
        ]);

        // Block markup for four files is large — give the model room.
        $files = $this->llm->completeJson($rendered, ['max_tokens' => 32000]);

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
