<?php

namespace Agoodley\LibrenmsTopology\Support;

/**
 * Small undirected multigraph used for scoping maps and for STP analysis.
 * Node ids are strings or ints, edges are identified by an arbitrary key.
 */
class Graph
{
    /** @var array<int|string, true> */
    private array $nodes = [];

    /** @var array<int|string, array<int|string, array<int|string, true>>> node => neighbour => edgeKeys */
    private array $adjacency = [];

    /** @var array<int|string, array{0: int|string, 1: int|string}> */
    private array $edges = [];

    public function addNode(int|string $node): self
    {
        $this->nodes[$node] = true;
        $this->adjacency[$node] ??= [];

        return $this;
    }

    public function addEdge(int|string $a, int|string $b, int|string|null $key = null): self
    {
        $this->addNode($a)->addNode($b);
        $key ??= count($this->edges);
        $this->edges[$key] = [$a, $b];
        $this->adjacency[$a][$b][$key] = true;
        $this->adjacency[$b][$a][$key] = true;

        return $this;
    }

    public function hasNode(int|string $node): bool
    {
        return isset($this->nodes[$node]);
    }

    /**
     * @return array<int, int|string>
     */
    public function nodes(): array
    {
        return array_keys($this->nodes);
    }

    /**
     * @return array<int, int|string>
     */
    public function neighbours(int|string $node): array
    {
        return array_keys($this->adjacency[$node] ?? []);
    }

    public function degree(int|string $node): int
    {
        return count($this->adjacency[$node] ?? []);
    }

    public function edgeCount(): int
    {
        return count($this->edges);
    }

    /**
     * Breadth first search returning hop count per reachable node.
     *
     * @param  array<int, int|string>|int|string  $start
     * @return array<int|string, int>
     */
    public function distances(array|int|string $start, ?int $maxDepth = null): array
    {
        $distance = [];
        $queue = [];
        foreach ((array) $start as $node) {
            if ($this->hasNode($node)) {
                $distance[$node] = 0;
                $queue[] = $node;
            }
        }

        for ($i = 0; $i < count($queue); $i++) {
            $node = $queue[$i];
            if ($maxDepth !== null && $distance[$node] >= $maxDepth) {
                continue;
            }
            foreach ($this->neighbours($node) as $next) {
                if (! isset($distance[$next])) {
                    $distance[$next] = $distance[$node] + 1;
                    $queue[] = $next;
                }
            }
        }

        return $distance;
    }

    /**
     * Connected components.
     *
     * @return array<int, array<int, int|string>>
     */
    public function components(): array
    {
        $seen = [];
        $components = [];
        foreach ($this->nodes() as $node) {
            if (isset($seen[$node])) {
                continue;
            }
            $component = array_keys($this->distances($node));
            foreach ($component as $member) {
                $seen[$member] = true;
            }
            $components[] = $component;
        }

        return $components;
    }

    /**
     * Find one cycle in the (multi)graph. Two parallel edges between the same nodes count as a
     * cycle. Returns the node path of the cycle (first node repeated at the end) or null.
     *
     * @return array<int, int|string>|null
     */
    public function findCycle(): ?array
    {
        $visited = [];
        foreach ($this->nodes() as $root) {
            if (isset($visited[$root])) {
                continue;
            }

            // iterative DFS tracking parent node and the edge used to reach each node
            $parent = [$root => null];
            $parentEdge = [$root => null];
            $stack = [$root];
            $visited[$root] = true;

            while ($stack) {
                $node = array_pop($stack);
                foreach ($this->adjacency[$node] ?? [] as $next => $edgeKeys) {
                    foreach (array_keys($edgeKeys) as $edgeKey) {
                        if ($edgeKey === $parentEdge[$node]) {
                            continue; // don't walk back over the edge we arrived on
                        }
                        if (! isset($visited[$next])) {
                            $visited[$next] = true;
                            $parent[$next] = $node;
                            $parentEdge[$next] = $edgeKey;
                            $stack[] = $next;
                            continue;
                        }

                        return $this->cyclePath($parent, $node, $next);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Rebuild the cycle formed by tree paths to $a and $b plus the closing edge a-b.
     *
     * @param  array<int|string, int|string|null>  $parent
     * @return array<int, int|string>
     */
    private function cyclePath(array $parent, int|string $a, int|string $b): array
    {
        $pathA = [];
        for ($n = $a; $n !== null; $n = $parent[$n] ?? null) {
            $pathA[] = $n;
        }
        $pathB = [];
        for ($n = $b; $n !== null; $n = $parent[$n] ?? null) {
            $pathB[] = $n;
        }

        // trim common ancestors, keeping the lowest common one
        $setA = array_flip(array_map('strval', $pathA));
        $lca = null;
        foreach ($pathB as $index => $n) {
            if (isset($setA[(string) $n])) {
                $lca = $n;
                $pathB = array_slice($pathB, 0, $index);
                break;
            }
        }

        $cycle = [];
        foreach ($pathA as $n) {
            $cycle[] = $n;
            if ($n === $lca) {
                break;
            }
        }

        $cycle = array_merge($cycle, array_reverse($pathB));
        $cycle[] = $a;

        return $cycle;
    }
}
