<?php
declare(strict_types=1);

use Automattic\SiteBuild\GroundTint;
use Automattic\SiteBuild\PaletteFloor;
use Automattic\SiteBuild\Warnings;

/**
 * @return array{id:string,label:string,palette:array<string,string>}
 */
function palette_floor_fixture(string $id): array
{
    $path = repo_path("tests/fixtures/palette-floor/{$id}.json");
    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        throw new RuntimeException("Missing palette-floor fixture: {$id}");
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['id'], $decoded['label'], $decoded['palette']) || !is_array($decoded['palette'])) {
        throw new RuntimeException("Invalid palette-floor fixture: {$id}");
    }

    return $decoded;
}

/**
 * @return array<string,array{id:string,label:string,palette:array<string,string>}>
 */
function palette_floor_fixtures(): array
{
    $files = glob(repo_path('tests/fixtures/palette-floor/*.json')) ?: [];
    $out = [];
    foreach ($files as $file) {
        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded) || !isset($decoded['id']) || !is_string($decoded['id'])) {
            throw new RuntimeException("Invalid palette-floor fixture: {$file}");
        }
        $out[$decoded['id']] = $decoded;
    }
    ksort($out);

    return $out;
}

/**
 * @param list<array<string,mixed>> $findings
 * @return array<string,mixed>|null
 */
function palette_floor_finding(array $findings, string $class, string $role): ?array
{
    foreach ($findings as $finding) {
        if (($finding['class'] ?? null) === $class && ($finding['role'] ?? null) === $role) {
            return $finding;
        }
    }

    return null;
}

test('check() labels every palette-floor fixture violation or clean', function () {
    $fixtures = palette_floor_fixtures();
    assert_eq(10, count($fixtures), 'exactly 10 real palettes');

    $violations = 0;
    $cleans = 0;
    $findingTotal = 0;
    foreach ($fixtures as $id => $fx) {
        assert_eq($id, $fx['id']);
        assert_true($fx['label'] === 'violation' || $fx['label'] === 'clean', "{$id} label");
        $findings = PaletteFloor::check($fx['palette']);
        $findingTotal += count($findings);
        if ($fx['label'] === 'clean') {
            $cleans++;
            assert_eq([], $findings, "{$id} is clean");
        } else {
            $violations++;
            assert_true($findings !== [], "{$id} is a violation");
        }
    }

    assert_eq(6, $violations);
    assert_eq(4, $cleans);
    // 13 findings across 6 palettes — not one-per-fixture.
    assert_eq(13, $findingTotal);
});

test('check() reports V1 primary-on-base near 2.1 and V5 hue near 0.3 not 8', function () {
    $v1 = palette_floor_fixture('v1');
    $primary = palette_floor_finding(PaletteFloor::check($v1['palette']), 'contrast', 'primary');
    assert_true($primary !== null, 'V1 primary contrast miss');
    assert_eq('base', $primary['against']);
    assert_eq('#8E1F26', $primary['authored']);
    assert_eq(PaletteFloor::ROLE_ON_BASE, $primary['floor']);
    assert_true(abs($primary['metric'] - 2.1) < 0.02, "V1 primary metric {$primary['metric']}");

    $v5 = palette_floor_fixture('v5');
    $hue = palette_floor_finding(PaletteFloor::check($v5['palette']), 'hue-separation', 'accent');
    assert_true($hue !== null, 'V5 hue-separation miss');
    assert_eq('primary', $hue['against']);
    assert_true($hue['metric'] < PaletteFloor::HUE_TOO_CLOSE, "V5 hue {$hue['metric']}");
    assert_true(abs($hue['metric'] - 0.30) < 0.05, "spec said 8deg — computed {$hue['metric']}");
});

test('repair() of every violation fixture leaves check() empty', function () {
    foreach (palette_floor_fixtures() as $id => $fx) {
        if ($fx['label'] !== 'violation') {
            continue;
        }
        $warnings = [];
        $out = PaletteFloor::repair($fx['palette'], $warnings);
        assert_eq([], PaletteFloor::check($out), "{$id} still has findings after repair");
        assert_eq($fx['palette']['base'], $out['base'], "{$id} must not move base");
        assert_true($warnings !== [], "{$id} changed a hex and must warn");
    }
});

test('repair() of a palette with contrast, hue-separation, and chroma-ceiling clears all three', function () {
    $palette = [
        'base' => '#1C2229',
        'contrast' => '#DCE4E3',
        'primary' => '#5A6B14',
        'secondary' => '#68B6C9',
        'accent' => '#C9F24D',
    ];
    $findings = PaletteFloor::check($palette);
    $classes = [];
    foreach ($findings as $finding) {
        $classes[$finding['class']] = true;
    }
    assert_true(isset($classes['contrast']));
    assert_true(isset($classes['hue-separation']));
    assert_true(isset($classes['chroma-ceiling']));

    $warnings = [];
    $out = PaletteFloor::repair($palette, $warnings);
    assert_eq([], PaletteFloor::check($out));
    assert_eq('#1C2229', $out['base']);
    assert_true($out['primary'] !== $palette['primary']);
    assert_true($out['accent'] !== $palette['accent']);
    assert_true(PaletteFloor::ratio($out['primary'], $out['base']) >= PaletteFloor::ROLE_ON_BASE);
    assert_true(PaletteFloor::hueDistance($out['primary'], $out['accent']) >= 39.0);
    assert_true(PaletteFloor::chroma($out['accent']) <= PaletteFloor::CHROMA_CEILING);
    assert_true($warnings !== []);
});

test('repair() is idempotent and a second pass adds no warnings', function () {
    foreach (palette_floor_fixtures() as $id => $fx) {
        $firstWarnings = [];
        $once = PaletteFloor::repair($fx['palette'], $firstWarnings);
        $secondWarnings = [];
        $twice = PaletteFloor::repair($once, $secondWarnings);
        assert_eq($once, $twice, "{$id} second pass moved a hex");
        assert_eq([], $secondWarnings, "{$id} second pass warned");
    }
});

test('repair() is a no-op on clean palettes', function () {
    foreach (palette_floor_fixtures() as $id => $fx) {
        if ($fx['label'] !== 'clean') {
            continue;
        }
        $warnings = [];
        $out = PaletteFloor::repair($fx['palette'], $warnings);
        assert_eq($fx['palette'], $out, "{$id} hexes moved");
        assert_eq([], $warnings, "{$id} warned on a no-op");
    }
});

test('repair() of V1 primary meets 4.5:1 and keeps authored hue within 1 degree', function () {
    $palette = palette_floor_fixture('v1')['palette'];
    $authored = $palette['primary'];
    $warnings = [];
    $out = PaletteFloor::repair($palette, $warnings);

    assert_true($out['primary'] !== $authored);
    assert_true(
        PaletteFloor::ratio($out['primary'], $out['base']) >= PaletteFloor::ROLE_ON_BASE,
        'V1 primary-on-base after repair',
    );
    $delta = PaletteFloor::hueDistance($authored, $out['primary']);
    assert_true($delta !== null && $delta < 1.0, "primary hue moved {$delta}");
});

test('repair() of V6 primary meets 4.5:1 at fixed hue', function () {
    $palette = palette_floor_fixture('v6')['palette'];
    $authored = $palette['primary'];
    $miss = palette_floor_finding(PaletteFloor::check($palette), 'contrast', 'primary');
    assert_true($miss !== null);
    assert_true($miss['metric'] < PaletteFloor::ROLE_ON_BASE);

    $warnings = [];
    $out = PaletteFloor::repair($palette, $warnings);
    assert_true(
        PaletteFloor::ratio($out['primary'], $out['base']) >= PaletteFloor::ROLE_ON_BASE,
        'V6 primary-on-base after repair',
    );
    $delta = PaletteFloor::hueDistance($authored, $out['primary']);
    assert_true($delta !== null && $delta < 1.0, "V6 primary hue moved {$delta}");
});

test('repair() rotates V3 accent off primary and leaves primary unchanged', function () {
    $palette = palette_floor_fixture('v3')['palette'];
    $authoredPrimary = $palette['primary'];
    $authoredAccent = $palette['accent'];
    assert_true(PaletteFloor::chroma($authoredPrimary) > PaletteFloor::CHROMA_MIN);
    assert_true(PaletteFloor::chroma($authoredAccent) > PaletteFloor::CHROMA_MIN);
    assert_true(PaletteFloor::hueDistance($authoredPrimary, $authoredAccent) < PaletteFloor::HUE_TOO_CLOSE);

    $warnings = [];
    $out = PaletteFloor::repair($palette, $warnings);
    assert_eq($authoredPrimary, $out['primary']);
    assert_true($out['accent'] !== $authoredAccent);
    $dist = PaletteFloor::hueDistance($out['primary'], $out['accent']);
    assert_true($dist !== null && $dist >= 39.0, "V3 hue distance after repair {$dist}");
});

test('repair() of V4 accent lands at least 39 degrees from primary', function () {
    $palette = palette_floor_fixture('v4')['palette'];
    $authoredPrimary = $palette['primary'];
    $warnings = [];
    $out = PaletteFloor::repair($palette, $warnings);
    assert_eq($authoredPrimary, $out['primary']);
    assert_true($out['accent'] !== $palette['accent']);
    $dist = PaletteFloor::hueDistance($out['primary'], $out['accent']);
    // 8-bit rounding can land a hair under 40.
    assert_true($dist !== null && $dist >= 39.0, "V4 hue distance after repair {$dist}");
});

test('repair() does not rotate a low-chroma pair inside 25 degrees', function () {
    $palette = [
        'base' => '#101418',
        'contrast' => '#E9EDF2',
        'primary' => '#8A8A80',
        'secondary' => '#6FBF73',
        'accent' => '#8C8C82',
    ];
    assert_true(PaletteFloor::chroma($palette['primary']) <= PaletteFloor::CHROMA_MIN);
    assert_true(PaletteFloor::chroma($palette['accent']) <= PaletteFloor::CHROMA_MIN);
    assert_true(PaletteFloor::hueDistance($palette['primary'], $palette['accent']) < PaletteFloor::HUE_TOO_CLOSE);
    assert_true(palette_floor_finding(PaletteFloor::check($palette), 'hue-separation', 'accent') === null);

    $warnings = [];
    $out = PaletteFloor::repair($palette, $warnings);
    assert_eq($palette['primary'], $out['primary']);
    assert_eq($palette['accent'], $out['accent']);
});

test('repair() of V2 accent drops chroma to the ceiling and holds luminance near 0.76', function () {
    $palette = palette_floor_fixture('v2')['palette'];
    $authored = $palette['accent'];
    $miss = palette_floor_finding(PaletteFloor::check($palette), 'chroma-ceiling', 'accent');
    assert_true($miss !== null);
    assert_true(abs($miss['metric'] - 0.65) < 0.02, "V2 accent chroma {$miss['metric']}");
    $authoredY = PaletteFloor::luminance($authored);
    assert_true($authoredY !== null && abs($authoredY - 0.765) < 0.01, "V2 accent Y {$authoredY}");

    $warnings = [];
    $out = PaletteFloor::repair($palette, $warnings);
    $chroma = PaletteFloor::chroma($out['accent']);
    $y = PaletteFloor::luminance($out['accent']);
    assert_true($chroma !== null && $chroma <= PaletteFloor::CHROMA_CEILING, "repaired chroma {$chroma}");
    assert_true($y !== null && abs($y - 0.76) < 0.02, "repaired Y {$y}");
    assert_eq($palette['primary'], $out['primary']);
});

test('repair() does not cap a high-chroma color at mid luminance', function () {
    $palette = [
        'base' => '#101418',
        'contrast' => '#E9EDF2',
        'primary' => '#FF0000',
        'secondary' => '#A8C0D6',
        'accent' => '#6FBF73',
    ];
    $chroma = PaletteFloor::chroma($palette['primary']);
    $y = PaletteFloor::luminance($palette['primary']);
    assert_true($chroma !== null && $chroma > PaletteFloor::CHROMA_CEILING);
    assert_true($y !== null && $y > PaletteFloor::LUMA_LOW && $y < PaletteFloor::LUMA_HIGH);
    assert_true(palette_floor_finding(PaletteFloor::check($palette), 'chroma-ceiling', 'primary') === null);

    $warnings = [];
    $out = PaletteFloor::repair($palette, $warnings);
    assert_eq('#FF0000', $out['primary']);
    assert_eq([], $warnings);
});

test('repair() moves contrast, not a warm GroundTint base', function () {
    $palette = [
        'base' => '#F4EBDA',
        'contrast' => '#B8A88F',
        'primary' => '#7B2D26',
        'secondary' => '#3E5C4A',
        'accent' => '#1F6F8B',
    ];
    assert_eq('warm', GroundTint::classify($palette['base']));
    $miss = palette_floor_finding(PaletteFloor::check($palette), 'contrast', 'contrast');
    assert_true($miss !== null);
    assert_true($miss['metric'] < PaletteFloor::CONTRAST_ON_BASE);

    $warnings = [];
    $out = PaletteFloor::repair($palette, $warnings);
    assert_eq('#F4EBDA', $out['base'], 'we move contrast not base');
    assert_eq('warm', GroundTint::classify($out['base']));
    assert_true($out['contrast'] !== '#B8A88F');
    assert_true(
        PaletteFloor::ratio($out['contrast'], $out['base']) >= PaletteFloor::CONTRAST_ON_BASE,
        'contrast-on-base after repair',
    );
    assert_eq([], PaletteFloor::check($out));
});

test('repair() warnings name authored, delivered, disposition, and palette path', function () {
    foreach (['v1', 'v2', 'v3', 'v4', 'v5', 'v6'] as $id) {
        $fx = palette_floor_fixture($id);
        $warnings = [];
        $out = PaletteFloor::repair($fx['palette'], $warnings);
        assert_true($warnings !== [], "{$id} has no warnings");
        foreach ($warnings as $row) {
            assert_contains("file='theme/theme.json'", $row, $id);
            assert_contains('path="palette.', $row, $id);
            assert_contains('authored=', $row, $id);
            assert_contains('delivered=', $row, $id);
            assert_contains('disposition=', $row, $id);
        }
        foreach ($fx['palette'] as $slug => $hex) {
            if ($out[$slug] === $hex) {
                continue;
            }
            $blob = implode("\n", $warnings);
            assert_contains('path="palette.' . $slug . '"', $blob, "{$id} {$slug}");
            assert_contains('authored=' . Warnings::value($hex), $blob, "{$id} {$slug} authored");
            assert_contains('delivered=' . Warnings::value($out[$slug]), $blob, "{$id} {$slug} delivered");
        }
    }

    $v1 = [];
    PaletteFloor::repair(palette_floor_fixture('v1')['palette'], $v1);
    $blob = implode("\n", $v1);
    assert_contains('path="palette.primary"', $blob);
    assert_contains('authored="#8E1F26"', $blob);
    assert_contains('delivered="#D84F57"', $blob);
    assert_contains('path="palette.accent"', $blob);
    assert_contains('authored="#E2622A"', $blob);
    assert_contains('delivered="#E29A2A"', $blob);
});
