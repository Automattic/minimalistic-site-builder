<?php
declare(strict_types=1);

function validator_project(): array
{
    $tmp = sys_get_temp_dir() . '/builder_val_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    // Minimal valid theme.
    $project->writeText('theme/style.css', "/*\nTheme Name: Demo\n*/\n");
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $ok = '<!-- wp:template-part {"slug":"header"} /--><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->';
    $project->writeText('theme/templates/index.html', $ok);
    $project->writeText('theme/templates/front-page.html', $ok);
    $project->writeText('theme/parts/header.html', '<!-- wp:site-title /-->');
    $project->writeText('theme/parts/footer.html', '<!-- wp:paragraph --><p>f</p><!-- /wp:paragraph -->');
    return [$project, $tmp];
}

test('validator passes a well-formed theme', function () {
    [$project, $tmp] = validator_project();
    assert_eq([], ThemeValidator::validate($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('validator flags unbalanced block comments', function () {
    [$project, $tmp] = validator_project();
    // Opening with no close.
    $project->writeText('theme/templates/front-page.html', '<!-- wp:group --><div>oops</div>');
    $problems = ThemeValidator::validate($project);
    assert_true(count($problems) > 0, 'should report a problem');
    assert_contains('unbalanced', implode(' ', $problems));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('typography warnings flag hardcoded font sizes and an unused display preset', function () {
    [$project, $tmp] = validator_project();
    $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => ['typography' => ['fontSizes' => [
        ['slug' => 'small', 'name' => 'Small', 'size' => '0.875rem'],
        ['slug' => 'display', 'name' => 'Display', 'size' => 'clamp(3rem, 7vw, 6rem)'],
    ]]]]);
    $project->writeText(
        'theme/parts/section-hero.html',
        '<!-- wp:heading {"level":1,"style":{"typography":{"fontSize":"clamp(2.75rem, 5vw, 4.25rem)"}}} -->'
        . '<h1 class="wp-block-heading" style="font-size:clamp(2.75rem, 5vw, 4.25rem)">Big</h1><!-- /wp:heading -->'
    );
    $warnings = ThemeValidator::typographyWarnings($project);
    $joined = implode(' ', $warnings);
    assert_contains('hardcoded font-size', $joined);
    assert_contains('section-hero.html', $joined);
    assert_contains('"display" fontSize', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('typography warnings stay quiet when sizes come from the preset scale', function () {
    [$project, $tmp] = validator_project();
    $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => ['typography' => ['fontSizes' => [
        ['slug' => 'display', 'name' => 'Display', 'size' => 'clamp(3rem, 7vw, 6rem)'],
    ]]]]);
    $project->writeText(
        'theme/parts/section-hero.html',
        '<!-- wp:heading {"level":1,"fontSize":"display"} -->'
        . '<h1 class="wp-block-heading has-display-font-size">Big</h1><!-- /wp:heading -->'
    );
    assert_eq([], ThemeValidator::typographyWarnings($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('validator flags bad theme.json and leftover placeholders', function () {
    [$project, $tmp] = validator_project();
    $project->writeText('theme/theme.json', '{not json');
    $project->writeText('theme/parts/header.html', '<!-- wp:site-title {{THEME_NAME}} /-->');
    $problems = ThemeValidator::validate($project);
    $joined = implode(' ', $problems);
    assert_contains('theme.json', $joined);
    assert_contains('placeholder', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});
