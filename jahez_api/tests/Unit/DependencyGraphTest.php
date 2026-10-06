<?php

use App\TransformationPlans\DependencyGraph;

/**
 * The plan's finish-to-start dependencies (ADR-025): A and B in parallel; C after A; D in
 * parallel with C; E after C and D.
 */
function sampleGraph(): DependencyGraph
{
    return new DependencyGraph(['a', 'b', 'c', 'd', 'e'], ['c' => ['a'], 'e' => ['c', 'd']]);
}

it('finds no cycle in a valid plan, and one when a chain closes on itself', function () {
    expect(sampleGraph()->findCycle())->toBeNull()
        ->and((new DependencyGraph(['a', 'b', 'c'], ['a' => ['c'], 'b' => ['a'], 'c' => ['b']]))->findCycle())->toBe(['a', 'c', 'b', 'a'])
        ->and((new DependencyGraph(['a'], ['a' => ['a']]))->findCycle())->toBe(['a', 'a']);
});

it('lists direct prerequisites, dependents and every ancestor', function () {
    $graph = sampleGraph();

    expect($graph->prerequisitesOf('e'))->toBe(['c', 'd'])
        ->and($graph->dependentsOf('a'))->toBe(['c'])
        ->and($graph->ancestorsOf('e'))->toEqualCanonicalizing(['a', 'c', 'd'])
        ->and($graph->prerequisitesOf('b'))->toBe([]);
});

it('treats items with no chain between them as parallel, and never an item and its ancestor', function () {
    $graph = sampleGraph();

    expect($graph->parallelWith('a', ['a', 'b']))->toBe(['b'])
        ->and($graph->parallelWith('c', ['c', 'd']))->toBe(['d'])
        ->and($graph->parallelWith('e', ['a', 'b', 'c', 'd']))->toBe(['b'])
        ->and($graph->parallelWith('a', ['c', 'e']))->toBe([]);
});
