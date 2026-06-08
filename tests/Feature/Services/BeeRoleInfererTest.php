<?php

use App\Services\BeeRoleInferer;
use App\Support\BeeRole;

function inferRole(array $task): BeeRole
{
    return (new BeeRoleInferer)->infer($task);
}

test('keeps a valid explicit role even if keywords suggest otherwise', function () {
    expect(inferRole(['role' => 'security', 'title' => 'add pest tests']))->toBe(BeeRole::Security);
});

test('infers the role from keywords when none is given', function () {
    expect(inferRole(['title' => 'Add Pest tests for checkout']))->toBe(BeeRole::Qa)
        ->and(inferRole(['title' => 'Deploy via Forge', 'description' => 'docker image']))->toBe(BeeRole::Devops)
        ->and(inferRole(['description' => 'fix the auth token leak']))->toBe(BeeRole::Security)
        ->and(inferRole(['title' => 'A design decision on the dependency graph']))->toBe(BeeRole::Architect)
        ->and(inferRole(['title' => 'Build the login Livewire component']))->toBe(BeeRole::Frontend)
        ->and(inferRole(['title' => 'Add the orders API controller']))->toBe(BeeRole::Backend);
});

test('replaces an invalid role by an inferred one', function () {
    expect(inferRole(['role' => 'banana', 'title' => 'write phpunit coverage']))->toBe(BeeRole::Qa);
});

test('falls back to fullstack when no rule matches', function () {
    expect(inferRole(['title' => 'Tweak the landing page wording']))->toBe(BeeRole::Fullstack)
        ->and(inferRole([]))->toBe(BeeRole::Fullstack);
});

test('does not match keywords inside unrelated words', function () {
    // "latest" must not trigger the qa "test" keyword.
    expect(inferRole(['title' => 'Ship the latest release notes']))->toBe(BeeRole::Fullstack);
});
