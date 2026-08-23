<?php
declare(strict_types=1);

use Automattic\SiteBuild\SiteVerifier;
use Automattic\SiteBuild\StudioCli;

function verifier_cli(string $payload): StudioCli
{
    return new StudioCli(fn (string $c, int $t): array
        => ['exitCode' => 0, 'stdout' => $payload, 'stderr' => '']);
}

test('a sound site produces no findings', function () {
    $cli = verifier_cli(json_encode(['pages' => 4, 'front_page' => 12, 'theme_errors' => []]));
    assert_eq([], SiteVerifier::check($cli, '/tmp/site'));
});

test('a site with no front page set is reported', function () {
    $cli = verifier_cli(json_encode(['pages' => 4, 'front_page' => 0, 'theme_errors' => []]));
    $findings = SiteVerifier::check($cli, '/tmp/site');
    assert_eq(1, count($findings));
    assert_contains('front page', $findings[0]);
});

test('theme validation errors are reported one row each', function () {
    $cli = verifier_cli(json_encode(['pages' => 4, 'front_page' => 12,
                                     'theme_errors' => ['missing index.php', 'bad style header']]));
    assert_eq(2, count(SiteVerifier::check($cli, '/tmp/site')));
});

test('a site with zero published pages is reported', function () {
    $cli = verifier_cli(json_encode(['pages' => 0, 'front_page' => 0, 'theme_errors' => []]));
    assert_true(count(SiteVerifier::check($cli, '/tmp/site')) >= 1, 'reports the empty site');
});
