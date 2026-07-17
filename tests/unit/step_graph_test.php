<?php
declare(strict_types=1);

use Automattic\SiteBuild\ConcurrentGroup;
use Automattic\SiteBuild\ConcurrentStep;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\StepGraph;
use Automattic\SiteBuild\Tests\FakeLlm;

/** @param list<string> $reads @param list<string> $writes */
function graph_fake_step(
    string $id,
    array $reads = [],
    array $writes = [],
    bool $concurrent = false,
): Step {
    return new class ($id, $reads, $writes, $concurrent) implements Step {
        /** @param list<string> $reads @param list<string> $writes */
        public function __construct(
            private string $id,
            private array $reads,
            private array $writes,
            private bool $concurrent,
        ) {}

        public function id(): string
        {
            return $this->id;
        }

        public function label(): string
        {
            return $this->id;
        }

        public function run(Project $project): void {}

        public function declaration(): StepDeclaration
        {
            return new StepDeclaration($this->id, $this->id, $this->reads, $this->writes, $this->concurrent);
        }
    };
}

test('StepGraph validates a legal chain with default meta.json seed', function () {
    StepGraph::validate([
        graph_fake_step('refine-prompt', ['meta.json'], ['meta.json']),
        graph_fake_step('site-spec', ['meta.json'], ['siteSpec.json']),
    ]);
    assert_true(true);
});

test('StepGraph rejects a step whose read was never written', function () {
    assert_throws(function () {
        StepGraph::validate([
            graph_fake_step('sections', ['sections.json'], ['theme/parts/*']),
        ]);
    });
});

test('StepGraph empty seeds fails refine-prompt without meta.json', function () {
    assert_throws(function () {
        StepGraph::validate(
            [graph_fake_step('refine-prompt', ['meta.json'], ['meta.json'])],
            seeds: [],
        );
    });
});

test('StepGraph directory write covers later directory and concrete reads', function () {
    StepGraph::validate([
        graph_fake_step('sections', [], ['theme/parts/*']),
        graph_fake_step('rhythm', ['theme/parts/*'], ['theme/parts/*']),
        graph_fake_step('header-reader', ['theme/parts/header.html'], []),
    ], seeds: []);
    assert_true(true);
});

test('StepGraph concrete write under dir covers later directory read', function () {
    StepGraph::validate([
        graph_fake_step('a', [], ['theme/parts/header.html']),
        graph_fake_step('b', ['theme/parts/*'], []),
    ], seeds: []);
    assert_true(true);
});

test('StepGraph theme/* covers any theme path', function () {
    StepGraph::validate([
        graph_fake_step('fix', [], ['theme/*']),
        graph_fake_step('val', ['theme/theme.json'], []),
    ], seeds: []);
    assert_true(true);
});

test('StepGraph rejects duplicate top-level ids', function () {
    assert_throws(function () {
        StepGraph::validate([
            graph_fake_step('site-spec', ['meta.json'], ['siteSpec.json']),
            graph_fake_step('site-spec', ['meta.json'], ['siteSpec.json']),
        ]);
    });
});

test('StepGraph rejects an id that differs from the declaration', function () {
    $step = new class implements Step {
        public function id(): string { return 'runtime-id'; }
        public function label(): string { return 'Label'; }
        public function run(Project $project): void {}
        public function declaration(): StepDeclaration
        {
            return new StepDeclaration('declared-id', 'Label', [], []);
        }
    };

    assert_throws(fn () => StepGraph::validate([$step], seeds: []));
    assert_throws(fn () => StepGraph::describe([$step]));
});

test('StepGraph rejects a label that differs from the declaration', function () {
    $step = new class implements Step {
        public function id(): string { return 'step'; }
        public function label(): string { return 'Runtime label'; }
        public function run(Project $project): void {}
        public function declaration(): StepDeclaration
        {
            return new StepDeclaration('step', 'Declared label', [], []);
        }
    };

    assert_throws(fn () => StepGraph::validate([$step], seeds: []));
});

test('StepGraph checks the identity of concurrent group members', function () {
    $member = new class implements ConcurrentStep {
        public function id(): string { return 'runtime-id'; }
        public function label(): string { return 'Label'; }
        public function declaration(): StepDeclaration
        {
            return new StepDeclaration('declared-id', 'Label', [], []);
        }
        public function requests(Project $project): array { return []; }
        public function consume(Project $project, array $results): void {}
        public function run(Project $project): void {}
    };

    assert_throws(fn () => StepGraph::validate([
        new ConcurrentGroup(new FakeLlm(), [$member]),
    ], seeds: []));
});

test('StepGraph rejects empty step list', function () {
    assert_throws(fn () => StepGraph::validate([]));
});

test('StepGraph describe exports portable rows', function () {
    $rows = StepGraph::describe([
        graph_fake_step('site-spec', ['meta.json'], ['siteSpec.json'], false),
        graph_fake_step('sections', ['siteSpec.json'], ['theme/parts/*'], true),
    ]);
    assert_eq('site-spec', $rows[0]['id']);
    assert_eq(['meta.json'], $rows[0]['reads']);
    assert_eq(['siteSpec.json'], $rows[0]['writes']);
    assert_eq(false, $rows[0]['concurrent']);
    assert_eq(true, $rows[1]['concurrent']);
    assert_true(!array_key_exists('members', $rows[0]));
});
