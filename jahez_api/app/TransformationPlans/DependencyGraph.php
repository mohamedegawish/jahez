<?php

namespace App\TransformationPlans;

/**
 * Finish-to-start dependencies between the items of one plan version (ADR-025), as a
 * directed graph: an edge item → prerequisite means the item may start only once the
 * prerequisite is completed. Pure logic, no database: the draft save refuses a cycle with
 * it, and the presenter derives what runs in parallel and what waits.
 *
 * Two items "run in parallel" when neither depends on the other, directly or through a
 * chain of other items.
 */
final class DependencyGraph
{
    /**
     * @var array<int|string, list<int|string>>
     */
    private array $prerequisites;

    /**
     * @param  array<int|string>  $nodes
     * @param  array<int|string, list<int|string>>  $prerequisites  node => the nodes it depends on
     */
    public function __construct(private readonly array $nodes, array $prerequisites)
    {
        $this->prerequisites = [];

        foreach ($nodes as $node) {
            $this->prerequisites[$node] = array_values(array_unique($prerequisites[$node] ?? []));
        }
    }

    /**
     * One cycle as a list of nodes (the first node repeated at the end), or null.
     *
     * @return list<int|string>|null
     */
    public function findCycle(): ?array
    {
        $state = [];
        $path = [];

        foreach ($this->nodes as $start) {
            $cycle = $this->visit($start, $state, $path);

            if ($cycle !== null) {
                return $cycle;
            }
        }

        return null;
    }

    /**
     * The nodes the node depends on, directly.
     *
     * @return list<int|string>
     */
    public function prerequisitesOf(int|string $node): array
    {
        return $this->prerequisites[$node] ?? [];
    }

    /**
     * The nodes that depend on the node, directly.
     *
     * @return list<int|string>
     */
    public function dependentsOf(int|string $node): array
    {
        $dependents = [];

        foreach ($this->prerequisites as $candidate => $prerequisites) {
            if (in_array($node, $prerequisites, true)) {
                $dependents[] = $candidate;
            }
        }

        return $dependents;
    }

    /**
     * Every node the node depends on, directly or through others. Assumes no cycle.
     *
     * @return list<int|string>
     */
    public function ancestorsOf(int|string $node): array
    {
        $seen = [];
        $stack = $this->prerequisitesOf($node);

        while ($stack !== []) {
            $next = array_pop($stack);

            if (in_array($next, $seen, true)) {
                continue;
            }

            $seen[] = $next;
            array_push($stack, ...$this->prerequisitesOf($next));
        }

        return $seen;
    }

    /**
     * The nodes among `$candidates` that may run in parallel with the node: no chain of
     * dependencies links them in either direction.
     *
     * @param  array<int|string>  $candidates
     * @return list<int|string>
     */
    public function parallelWith(int|string $node, array $candidates): array
    {
        $ancestors = $this->ancestorsOf($node);
        $parallel = [];

        foreach ($candidates as $candidate) {
            if ($candidate === $node || in_array($candidate, $ancestors, true) || in_array($node, $this->ancestorsOf($candidate), true)) {
                continue;
            }

            $parallel[] = $candidate;
        }

        return $parallel;
    }

    /**
     * Depth-first search with three colours: absent (unvisited), 1 (on the current path),
     * 2 (done).
     *
     * @param  array<int|string, int>  $state
     * @param  list<int|string>  $path
     * @return list<int|string>|null
     */
    private function visit(int|string $node, array &$state, array &$path): ?array
    {
        if (($state[$node] ?? 0) === 2) {
            return null;
        }

        if (($state[$node] ?? 0) === 1) {
            $from = array_search($node, $path, true);

            return [...array_slice($path, (int) $from), $node];
        }

        $state[$node] = 1;
        $path[] = $node;

        foreach ($this->prerequisitesOf($node) as $prerequisite) {
            $cycle = $this->visit($prerequisite, $state, $path);

            if ($cycle !== null) {
                return $cycle;
            }
        }

        array_pop($path);
        $state[$node] = 2;

        return null;
    }
}
