<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\JetpackFormFixer;
use Automattic\SiteBuild\Steps\FixBlocksStep;

test('JetpackFormFixer repairs an element-less submit button nested inside a contact form', function () {
    $markup = '<!-- wp:jetpack/contact-form --><div class="wp-block-jetpack-contact-form">'
        . '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:jetpack/button {"text":"Submit"} /-->'
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:jetpack/contact-form -->';

    $result = JetpackFormFixer::fix($markup);

    assert_contains('<!-- wp:jetpack/button {"text":"Submit","element":"button"} /-->', $result['markup']);
    assert_eq(1, count($result['notes']));
    assert_eq(
        ['markup' => $result['markup'], 'notes' => []],
        JetpackFormFixer::fix($result['markup']),
        'the repair reaches a fixed point',
    );
});

test('JetpackFormFixer leaves unrelated and explicitly configured buttons unchanged', function () {
    $outside = '<!-- wp:jetpack/button {"text":"Continue"} /-->';
    $explicit = '<!-- wp:jetpack/contact-form --><div class="wp-block-jetpack-contact-form">'
        . '<!-- wp:jetpack/button {"text":"Learn more","element":"a"} /-->'
        . '</div><!-- /wp:jetpack/contact-form -->';

    assert_eq(['markup' => $outside, 'notes' => []], JetpackFormFixer::fix($outside));
    assert_eq(['markup' => $explicit, 'notes' => []], JetpackFormFixer::fix($explicit));
});

test('JetpackFormFixer leaves structurally unsafe form markup for structural recovery', function () {
    $markup = '<!-- wp:jetpack/contact-form --><div class="wp-block-jetpack-contact-form">'
        . '<!-- wp:jetpack/button {"text":"Submit"} /-->';

    assert_eq(['markup' => $markup, 'notes' => []], JetpackFormFixer::fix($markup));
});

test('FixBlocksStep applies the Jetpack form repair before block re-serialization', function () {
    with_project('jetpack-form-step-', function ($project): void {
        $project->writeText(
            'theme/parts/contact.html',
            '<!-- wp:jetpack/contact-form --><div class="wp-block-jetpack-contact-form">'
                . '<!-- wp:jetpack/button {"text":"Submit"} /-->'
                . '</div><!-- /wp:jetpack/contact-form -->',
        );
        $fixer = new class implements BlockFixer {
            public function fix(string $themeDir): string
            {
                return '[fix-templates] noop';
            }
        };

        quietly(fn () => (new FixBlocksStep($fixer))->run($project));

        assert_contains(
            '<!-- wp:jetpack/button {"text":"Submit","element":"button"} /-->',
            $project->readText('theme/parts/contact.html'),
        );
        assert_contains('[forms] 1 form fix(es)', $project->readText('logs/fix-blocks.log'));
    });
});
