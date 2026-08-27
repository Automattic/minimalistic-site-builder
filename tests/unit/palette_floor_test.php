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

function palette_floor_same_hex(string $a, string $b): bool
{
    $ra = \Automattic\SiteBuild\ContrastMath::hexToRgb($a);
    $rb = \Automattic\SiteBuild\ContrastMath::hexToRgb($b);
    return $ra !== null && $rb !== null && $ra === $rb;
}

/** @param list<string> $warnings */
function palette_floor_warning_for(array $warnings, string $role): string
{
    foreach ($warnings as $row) {
        if (str_contains($row, 'path="palette.' . $role . '"')) {
            return $row;
        }
    }
    return '';
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
    // 12 findings across 6 palettes — not one-per-fixture. (13 before
    // BIGR-918: v6's mid-blue accent lost its contrast finding because the
    // light contrast ink reads on that fill at ~6:1.)
    assert_eq(12, $findingTotal);
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
    assert_contains('path="palette.accent"', $blob);
    assert_contains('authored="#E2622A"', $blob);
});

test('repair() of azure-island lime keeps the fill; only the chroma ceiling may touch it', function () {
    // Before BIGR-918 the accent was judged as text on base, and the tan
    // ground made the lime collapse toward white. As a fill it is legal:
    // the near-black contrast ink reads on it far above 4.5:1. Only the
    // garish-lime chroma ceiling still applies at this luminance.
    $palette = [
        'base' => '#D9C7A5',
        'contrast' => '#16130F',
        'primary' => '#1B3BE0',
        'secondary' => '#B0125A',
        'accent' => '#B4FF29',
    ];
    $warnings = [];
    $out = PaletteFloor::repair($palette, $warnings);
    assert_true(!palette_floor_same_hex($out['accent'], '#FFFFFF'), 'accent must not become white');
    assert_true(PaletteFloor::chroma($out['accent']) <= PaletteFloor::CHROMA_CEILING);
    $labelInk = max(
        PaletteFloor::ratio($out['accent'], $out['base']) ?? 0.0,
        PaletteFloor::ratio($out['accent'], $out['contrast']) ?? 0.0,
    );
    assert_true($labelInk >= PaletteFloor::LABEL_ON_ACCENT, 'an ink still reads on the capped fill');
    $row = palette_floor_warning_for($warnings, 'accent');
    assert_true($row === '' || str_contains($row, 'chroma ceiling'), $row);
    assert_eq([], array_filter(
        PaletteFloor::check($out),
        static fn (array $f): bool => $f['class'] === 'contrast' && $f['role'] === 'accent',
    ));
});

test('repair() crosses to the other lightness side when the authored side cannot meet the floor', function () {
    // secondary is a text role, so it still measures on base alone.
    $palette = [
        'base' => '#D9C7A5',
        'contrast' => '#16130F',
        'primary' => '#1B3BE0',
        'secondary' => '#E8FF9A',
        'accent' => '#16130F',
    ];
    $authoredY = PaletteFloor::luminance($palette['secondary']);
    $baseY = PaletteFloor::luminance($palette['base']);
    assert_true($authoredY !== null && $baseY !== null && $authoredY > $baseY, 'authored sits on the light side');
    assert_true(PaletteFloor::ratio('#FFFFFF', $palette['base']) < PaletteFloor::ROLE_ON_BASE, 'light extreme loses');
    assert_true(PaletteFloor::ratio('#000000', $palette['base']) >= PaletteFloor::ROLE_ON_BASE, 'dark extreme wins');

    $warnings = [];
    $out = PaletteFloor::repair($palette, $warnings);
    $y = PaletteFloor::luminance($out['secondary']);
    assert_true($y !== null && $y < $baseY, 'repair crossed to the dark side');
    assert_true(
        PaletteFloor::ratio($out['secondary'], $out['base']) >= PaletteFloor::ROLE_ON_BASE,
        'crossed repair clears 4.5:1',
    );
    assert_true(!palette_floor_same_hex($out['secondary'], '#FFFFFF'));
    assert_contains('disposition=repaired', palette_floor_warning_for($warnings, 'secondary'));
});

test('repair() leaves a vivid warm accent bright when a label ink reads on the fill', function () {
    // The recorded atlas build (BIGR-918): authored amber #D4820A on cream
    // shipped as olive #746C05 because the old floor read the fill as text
    // on base. The hue-separation rule may still rotate it off the rust
    // primary, but nothing may darken it into mud: the delivered fill must
    // keep its brightness and its saturation, and the dark contrast ink is
    // the label that reads on it.
    $palette = [
        'base' => '#F4EBDD',
        'contrast' => '#241C15',
        'primary' => '#8C3A1E',
        'secondary' => '#6B5A47',
        'accent' => '#D4820A',
    ];
    $authoredY = PaletteFloor::luminance($palette['accent']);
    $warnings = [];
    $out = PaletteFloor::repair($palette, $warnings);
    $y = PaletteFloor::luminance($out['accent']);
    assert_true($y !== null && $authoredY !== null && $y >= $authoredY - 0.05, "fill went dark: Y {$y}");
    assert_true(PaletteFloor::chroma($out['accent']) >= 0.5, 'fill kept its saturation');
    assert_true(
        PaletteFloor::ratio($out['accent'], $out['contrast']) >= PaletteFloor::LABEL_ON_ACCENT,
        'the dark ink labels the fill',
    );
    assert_eq([], PaletteFloor::check($out));
});

test('repair() leaves a deep accent fill alone on a dark ground when the light ink reads on it', function () {
    // A wine CTA fill on a near-black page is legal: the bone contrast ink
    // reads on it. The old base-only floor forced exactly this fill light.
    $palette = [
        'base' => '#16151A',
        'contrast' => '#E7E1D6',
        'primary' => '#8FB4D9',
        'secondary' => '#A69A83',
        'accent' => '#7A2E2E',
    ];
    assert_true(
        PaletteFloor::ratio($palette['accent'], $palette['base']) < PaletteFloor::LABEL_ON_ACCENT,
        'the fill fails against base on its own',
    );
    $warnings = [];
    $out = PaletteFloor::repair($palette, $warnings);
    assert_true(palette_floor_same_hex($out['accent'], '#7A2E2E'), 'fill stays authored');
    assert_eq('', palette_floor_warning_for($warnings, 'accent'));
});

test('repair() still moves a mid-tone accent no ink can label, and names the ink', function () {
    $palette = [
        'base' => '#FAF7F2',
        'contrast' => '#1A1A1A',
        'primary' => '#1B3BE0',
        'secondary' => '#5E564A',
        'accent' => '#8A7A65',
    ];
    $bestBefore = max(
        PaletteFloor::ratio($palette['accent'], $palette['base']) ?? 0.0,
        PaletteFloor::ratio($palette['accent'], $palette['contrast']) ?? 0.0,
    );
    assert_true($bestBefore < PaletteFloor::LABEL_ON_ACCENT, 'no ink reads on the authored mid-tone');

    $warnings = [];
    $out = PaletteFloor::repair($palette, $warnings);
    $bestAfter = max(
        PaletteFloor::ratio($out['accent'], $out['base']) ?? 0.0,
        PaletteFloor::ratio($out['accent'], $out['contrast']) ?? 0.0,
    );
    assert_true($bestAfter >= PaletteFloor::LABEL_ON_ACCENT, 'an ink reads on the delivered fill');
    assert_contains('label ink', palette_floor_warning_for($warnings, 'accent'));
});

test('the default floor is AA: a mid-dark ink on cream survives repair()', function () {
    // BIGR-923: #6B5138 on #F4EBDD measures ~6.2:1 — above AA, below the old
    // AAA floor that used to overwrite it.
    $palette = [
        'base' => '#F4EBDD',
        'contrast' => '#6B5138',
        'primary' => '#8C3A1E',
        'secondary' => '#6B5A47',
        'accent' => '#7A2E2E',
    ];
    $ratio = PaletteFloor::ratio($palette['contrast'], $palette['base']);
    assert_true($ratio !== null && $ratio > 4.5 && $ratio < 7.0, "mid-dark ink measures {$ratio}");

    $warnings = [];
    $out = PaletteFloor::repair($palette, $warnings);
    assert_true(palette_floor_same_hex($out['contrast'], '#6B5138'), 'the authored ink ships');
    assert_eq('', palette_floor_warning_for($warnings, 'contrast'));

    // The same palette under a committed surface texture is still raised.
    $surfaceWarnings = [];
    $raised = PaletteFloor::repair($palette, $surfaceWarnings, 7.0);
    assert_true(!palette_floor_same_hex($raised['contrast'], '#6B5138'), 'the texture floor still moves it');
});

test('repair() keeps the authored hex when the raised surface floor is unreachable', function () {
    // Any base reaches 4.5:1 with black or white, so the unreachable branch
    // exists only under the raised 7:1 surface-texture floor (BIGR-923).
    $surfaceFloor = 7.0;
    $palette = [
        'base' => '#808080',
        'contrast' => '#AAAAAA',
        'primary' => '#000000',
        'secondary' => '#000000',
        'accent' => '#000000',
    ];
    $before = PaletteFloor::ratio($palette['contrast'], $palette['base']);
    assert_true($before !== null && $before < $surfaceFloor);
    assert_true(PaletteFloor::ratio('#FFFFFF', $palette['base']) < $surfaceFloor);
    assert_true(PaletteFloor::ratio('#000000', $palette['base']) < $surfaceFloor);

    $warnings = [];
    $out = PaletteFloor::repair($palette, $warnings, $surfaceFloor);
    assert_true(palette_floor_same_hex($out['contrast'], '#AAAAAA'), 'authored contrast survives');
    $row = palette_floor_warning_for($warnings, 'contrast');
    assert_contains('disposition=unrepaired', $row);
    assert_contains('contrast floor 7.0:1', $row);
    assert_contains('best achieved', $row);
    assert_contains(':1', $row);
    $residual = palette_floor_finding(PaletteFloor::check($out, $surfaceFloor), 'contrast', 'contrast');
    assert_true($residual !== null);
});

test('repair() emits one warning per role naming the hex that entered and the hex that shipped', function () {
    $palette = [
        'base' => '#F5EDE1',
        'contrast' => '#1A1A1A',
        'primary' => '#8B6421',
        'secondary' => '#8A6F1E',
        'accent' => '#C9A227',
    ];
    $warnings = [];
    $out = PaletteFloor::repair($palette, $warnings);
    $byRole = [];
    foreach ($warnings as $row) {
        assert_true(
            preg_match('/path="palette\\.([^"]+)"/', $row, $match) === 1,
            $row,
        );
        $role = $match[1];
        assert_true(!isset($byRole[$role]), "{$role} emitted more than one warning");
        $byRole[$role] = $row;
        assert_contains('authored=' . Warnings::value($palette[$role]), $row, $role . ' authored is the input hex');
        assert_contains('delivered=' . Warnings::value($out[$role]), $row, $role . ' delivered is the shipped hex');
    }
    assert_true(isset($byRole['accent']), 'accent was repaired');
    assert_true($out['accent'] !== $palette['accent']);
    assert_eq(1, substr_count($byRole['accent'], 'delivered='));
});

test('repair() never claims repaired while check() still reports that role and class', function () {
    $palettes = [
        palette_floor_fixture('v1')['palette'],
        [
            'base' => '#D9C7A5',
            'contrast' => '#16130F',
            'primary' => '#1B3BE0',
            'secondary' => '#B0125A',
            'accent' => '#B4FF29',
        ],
        [
            'base' => '#808080',
            'contrast' => '#AAAAAA',
            'primary' => '#000000',
            'secondary' => '#000000',
            'accent' => '#000000',
        ],
    ];
    foreach ($palettes as $i => $palette) {
        $warnings = [];
        $out = PaletteFloor::repair($palette, $warnings);
        foreach (PaletteFloor::check($out) as $finding) {
            $row = palette_floor_warning_for($warnings, $finding['role']);
            assert_true($row !== '', "palette {$i} {$finding['role']} residual has a warning");
            assert_contains('disposition=unrepaired', $row, "palette {$i} {$finding['class']} {$finding['role']}");
            assert_true(
                !str_contains($row, 'disposition=repaired'),
                "palette {$i} {$finding['role']} residual must not say repaired",
            );
        }
        foreach ($warnings as $row) {
            if (!str_contains($row, 'disposition=repaired')) {
                continue;
            }
            assert_true(
                preg_match('/path="palette\\.([^"]+)"/', $row, $match) === 1,
                $row,
            );
            $role = $match[1];
            $hit = palette_floor_finding(PaletteFloor::check($out), 'contrast', $role)
                ?? palette_floor_finding(PaletteFloor::check($out), 'hue-separation', $role)
                ?? palette_floor_finding(PaletteFloor::check($out), 'chroma-ceiling', $role);
            assert_eq(null, $hit, "repaired warning for {$role} but check() still reports it");
        }
    }
});

