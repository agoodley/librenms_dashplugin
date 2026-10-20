<?php

namespace Agoodley\LibrenmsTopology\Support;

/**
 * Small undirected multigraph used for scoping maps and for the STP and redundancy analysis.
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

    /**
     * Add an undirected edge; edges with distinct keys between the same nodes are parallel edges.
     */
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

    /**
     * Number of distinct neighbours.
     */
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
     * Nodes that lie on at least one cycle, i.e. that have an incident edge which is not a
     * bridge (cut edge). Parallel edges count as a cycle. Tarjan's bridge finding with an
     * iterative DFS, O(nodes + edges).
     *
     * @return array<int, int|string>
     */
    public function nodesOnCycles(): array
    {
        $index = [];
        $low = [];
        $counter = 0;
        $onCycle = [];

        foreach ($this->nodes() as $root) {
            if (isset($index[$root])) {
                continue;
            }

            $index[$root] = $low[$root] = $counter++;
            // frames: node, edge used to reach it, its incident [neighbour, edge] pairs, next position
            $stack = [[$root, null, $this->incident($root), 0]];
            while ($stack) {
                $top = count($stack) - 1;
                [$node, $parentEdge, $incident, $position] = $stack[$top];

                if ($position < count($incident)) {
                    $stack[$top][3]++;
                    [$next, $edgeKey] = $incident[$position];
                    if ($edgeKey === $parentEdge) {
                        continue;
                    }
                    if (! isset($index[$next])) {
                        $index[$next] = $low[$next] = $counter++;
                        $stack[] = [$next, $edgeKey, $this->incident($next), 0];
                    } else {
                        $low[$node] = min($low[$node], $index[$next]);
                    }
                    continue;
                }

                array_pop($stack);
                if ($stack) {
                    $parent = $stack[count($stack) - 1][0];
                    $low[$parent] = min($low[$parent], $low[$node]);
                    if ($low[$node] <= $index[$parent]) {
                        // the tree edge parent-node is not a bridge: both ends are on a cycle
                        $onCycle[$parent] = true;
                        $onCycle[$node] = true;
                    }
                }
            }
        }

        return array_keys($onCycle);
    }

    /**
     * What loses its connection to $root when a single node or a single edge fails: the cut
     * vertices and bridges of the component of $root, from one iterative depth first search
     * (Tarjan), O(nodes + edges). The nodes are returned in DFS preorder with the position of each
     * node (tin) and the last position of its subtree (tout), so the nodes cut off by a failure
     * are contiguous ranges of 'order':
     *  - vertices: node => children whose subtrees lose $root when the node fails ($root itself is
     *    never listed);
     *  - bridges: edge key => the child whose subtree loses $root when the edge fails.
     * A parallel edge protects against the failure of an edge, not against that of its nodes.
     *
     * @return array{order: array<int, int|string>, tin: array<int|string, int>, tout: array<int|string, int>,
     *     vertices: array<int|string, array<int, int|string>>, bridges: array<int|string, int|string>}
     */
    public function cuts(int|string $root): array
    {
        $result = ['order' => [], 'tin' => [], 'tout' => [], 'vertices' => [], 'bridges' => []];
        if (! $this->hasNode($root)) {
            return $result;
        }

        $order = [$root];
        $tin = [$root => 0];
        $low = [$root => 0];
        $tout = [];
        $vertices = [];
        $bridges = [];
        // frames: node, edge used to reach it, its incident [neighbour, edge] pairs, next position
        $stack = [[$root, null, $this->incident($root), 0]];
        while ($stack) {
            $top = count($stack) - 1;
            [$node, $parentEdge, $incident, $position] = $stack[$top];

            if ($position < count($incident)) {
                $stack[$top][3]++;
                [$next, $edgeKey] = $incident[$position];
                if ($edgeKey === $parentEdge) {
                    continue;
                }
                if (! isset($tin[$next])) {
                    $tin[$next] = $low[$next] = count($order);
                    $order[] = $next;
                    $stack[] = [$next, $edgeKey, $this->incident($next), 0];
                } else {
                    $low[$node] = min($low[$node], $tin[$next]);
                }
                continue;
            }

            array_pop($stack);
            $tout[$node] = count($order) - 1;
            if ($stack) {
                $parent = $stack[count($stack) - 1][0];
                $low[$parent] = min($low[$parent], $low[$node]);
                if ($low[$node] >= $tin[$parent] && $parent !== $root) {
                    $vertices[$parent][] = $node;
                }
                if ($low[$node] > $tin[$parent]) {
                    $bridges[$parentEdge] = $node;
                }
            }
        }

        return ['order' => $order, 'tin' => $tin, 'tout' => $tout, 'vertices' => $vertices, 'bridges' => $bridges];
    }

    /**
     * Edges of a node as [neighbour, edge key] pairs.
     *
     * @return array<int, array{0: int|string, 1: int|string}>
     */
    public function edgesOf(int|string $node): array
    {
        return $this->incident($node);
    }

    /**
     * @return array<int, array{0: int|string, 1: int|string}> [neighbour, edge key] pairs of a node
     */
    private function incident(int|string $node): array
    {
        $pairs = [];
        foreach ($this->adjacency[$node] ?? [] as $next => $edgeKeys) {
            foreach (array_keys($edgeKeys) as $edgeKey) {
                $pairs[] = [$next, $edgeKey];
            }
        }

        return $pairs;
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
