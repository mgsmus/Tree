<?php

namespace BlueM;

use BlueM\Tree\Exception\InvalidDatatypeException;
use BlueM\Tree\Exception\InvalidParentException;
use BlueM\Tree\Node;

/**
 * Builds and gives access to a tree of nodes which is constructed thru nodes' parent node ID references.
 *
 * @author  Carsten Bluem <carsten@bluem.net>
 * @license http://www.opensource.org/licenses/bsd-license.php BSD 2-Clause License
 */
class Tree implements \JsonSerializable
{
    /**
     * API version (will always be in sync with first digit of release version number).
     *
     * @var int
     */
    const API = 2;

    /**
     * @var int|float|string
     */
    protected $rootId = 0;

    /**
     * @var string
     */
    protected $idKey = 'id';

    /**
     * @var string
     */
    protected $parentKey = 'parent';

    /**
     * @var Node[]
     */
    protected $nodes;

    /**
     * @param array|\Traversable $data    The data for the tree (iterable)
     * @param array              $options 0 or more of the following keys: "rootId" (ID of the root node, defaults to 0), "id"
     *                                    (name of the ID field / array key, defaults to "id"), "parent", (name of the parent
     *                                    ID field / array key, defaults to "parent")
     *
     * @throws \BlueM\Tree\Exception\InvalidParentException
     * @throws \BlueM\Tree\Exception\InvalidDatatypeException
     * @throws \InvalidArgumentException
     */
    public function __construct($data = [], array $options = [])
    {
        $options = array_change_key_case($options, CASE_LOWER);

        if (isset($options['rootid'])) {
            if (!\is_scalar($options['rootid'])) {
                throw new \InvalidArgumentException('Option “rootid” must be a scalar');
            }
            $this->rootId = $options['rootid'];
        }

        if (!empty($options['id'])) {
            if (!\is_string($options['id'])) {
                throw new \InvalidArgumentException('Option “id” must be a string');
            }
            $this->idKey = $options['id'];
        }

        if (!empty($options['parent'])) {
            if (!\is_string($options['parent'])) {
                throw new \InvalidArgumentException('Option “parent” must be a string');
            }
            $this->parentKey = $options['parent'];
        }

        $this->build($data);
    }

    /**
     * @param array $data
     *
     * @throws \BlueM\Tree\Exception\InvalidParentException
     * @throws \BlueM\Tree\Exception\InvalidDatatypeException
     */
    public function rebuildWithData(array $data)
    {
        $this->build($data);
    }

    /**
     * Returns a flat, sorted array of all node objects in the tree.
     *
     * @return Node[] Nodes, sorted as if the tree was hierarchical,
     *                i.e.: the first level 1 item, then the children of
     *                the first level 1 item (and their children), then
     *                the second level 1 item and so on.
     */
    public function getNodes(): array
    {
        $nodes = [];
        foreach ($this->nodes[$this->rootId]->getDescendants() as $subnode) {
            $nodes[] = $subnode;
        }

        return $nodes;
    }

    /**
     * Returns a single node from the tree, identified by its ID.
     *
     * @param int|string $id Node ID
     *
     * @throws \InvalidArgumentException
     *
     * @return Node
     */
    public function getNodeById($id): Node
    {
        if (empty($this->nodes[$id])) {
            throw new \InvalidArgumentException("Invalid node primary key $id");
        }

        return $this->nodes[$id];
    }

    /**
     * Returns an array of all nodes in the root level.
     *
     * @return Node[] Nodes in the correct order
     */
    public function getRootNodes(): array
    {
        return $this->nodes[$this->rootId]->getChildren();
    }

    /**
     * Returns the first node for which a specific property's values of all ancestors
     * and the node are equal to the values in the given argument.
     *
     * Example: If nodes have property "name", and on the root level there is a node with
     * name "A" which has a child with name "B" which has a child which has node "C", you
     * would get the latter one by invoking getNodeByValuePath('name', ['A', 'B', 'C']).
     * Comparison is case-sensitive and type-safe.
     *
     * @param string $name
     * @param array  $search
     *
     * @return Node|null
     */
    public function getNodeByValuePath($name, array $search)
    {
        $findNested = function (array $nodes, array $tokens) use ($name, &$findNested) {
            $token = array_shift($tokens);
            foreach ($nodes as $node) {
                $nodeName = $node->get($name);
                if ($nodeName === $token) {
                    // Match
                    if (\count($tokens)) {
                        // Search next level
                        return $findNested($node->getChildren(), $tokens);
                    }

                    // We found the node we were looking for
                    return $node;
                }
            }

            return null;
        };

        return $findNested($this->getRootNodes(), $search);
    }

    /**
     * Core method for creating the tree.
     *
     * @param array|\Traversable $data The data from which to generate the tree
     *
     * @throws \BlueM\Tree\Exception\InvalidParentException
     * @throws InvalidDatatypeException
     */
private function build($data)
{
    if (!\is_array($data) && !($data instanceof \Traversable)) {
        throw new InvalidDatatypeException('Data must be an iterable (array or implement Traversable)');
    }

    $this->nodes = [];
    // Step 1: Create all node objects from $data and map children to parent IDs.
    // Children map stores child *node objects*.
    $children_map = [];

    // Create the root node object.
    $this->nodes[$this->rootId] = $this->createNode($this->rootId, null, []);
    // Explicitly set the root node's level. This is the base for all other level calculations.
    $this->nodes[$this->rootId]->setLevel(0);

    foreach ($data as $row) {
        if ($row instanceof \Iterator) {
            $row = iterator_to_array($row);
        }

        $nodeId = $row[$this->idKey];
        $parentId = $row[$this->parentKey];

        if ((string) $nodeId === (string) $parentId) {
            throw new InvalidParentException(
                "Node with ID $nodeId references its own ID as parent ID"
            );
        }

        if (!isset($this->nodes[$nodeId])) {
            $this->nodes[$nodeId] = $this->createNode(
                $nodeId,
                $parentId, // Store parentId in properties
                $row
            );
        }
        // Don't set level here for non-root nodes yet.

        // Map child NODE OBJECTS to their parent ID for BFS processing.
        if (isset($this->nodes[$parentId])) { // Check if parent exists before mapping
             $children_map[$parentId][] = $this->nodes[$nodeId];
        } else {
            // This check should ideally cover cases where parentId is not the rootId
            // and also not yet in $this->nodes. This implies an invalid parent or unordered data.
            // For now, we rely on all valid parents being defined or being the rootId.
            // A stricter check for non-existent parents (other than root) can be added here or later.
            if ($parentId !== $this->rootId) {
                 // This is a temporary placeholder for children whose parents are not yet encountered.
                 // Or, throw exception if strict parent existence is required before child definition.
                 $children_map[$parentId][] = $this->nodes[$nodeId];
            }
        }
    }

    // Check for nodes pointing to non-existent parents after all nodes are created
    foreach($this->nodes as $nodeId => $node) {
        if ($nodeId === $this->rootId) continue; // Skip root node
        $parentIdInProps = $node->get($this->parentKey); // Assuming Node::get can fetch raw parent ID
        if ($parentIdInProps !== null && !isset($this->nodes[$parentIdInProps])) {
            throw new InvalidParentException(
                "Node with ID $nodeId points to non-existent parent with ID $parentIdInProps"
            );
        }
    }


    // Step 2: Build tree structure using BFS to ensure top-down processing for addChild calls.
    $queue = [$this->nodes[$this->rootId]];
    // $visited helps in not queuing a node multiple times if it appears in $children_map multiple times (should not happen with current map build)
    // or if data could represent a graph. For tree, it's mainly for initial queue.
    $processedForQueue = [$this->rootId => true];

    while (!empty($queue)) {
        $parentNode = array_shift($queue);
        $parentActualId = $parentNode->getId();

        if (!empty($children_map[$parentActualId])) {
            foreach ($children_map[$parentActualId] as $childNode) {
                // $parentNode->addChild($childNode) will:
                // 1. Add $childNode to $parentNode->children array.
                // 2. Set $childNode->parent = $parentNode.
                // 3. Call $childNode->setLevel($parentNode->getLevel() + 1).
                // Since $parentNode->getLevel() is guaranteed to be correct due to BFS,
                // $childNode's level will also be correct.
                $parentNode->addChild($childNode);

                if (!isset($processedForQueue[$childNode->getId()])) {
                    $queue[] = $childNode;
                    $processedForQueue[$childNode->getId()] = true;
                }
            }
        }
    }
}

    /**
     * Returns a textual representation of the tree.
     *
     * @return string
     */
    public function __toString()
    {
        $str = [];
        foreach ($this->getNodes() as $node) {
            $indent1st = str_repeat('  ', $node->getLevel() - 1).'- ';
            $indent = str_repeat('  ', ($node->getLevel() - 1) + 2);
            $node = (string) $node;
            $str[] = $indent1st.str_replace("\n", "$indent\n  ", $node);
        }

        return implode("\n", $str);
    }

    /**
     * @return array
     */
    public function jsonSerialize()
    {
        return $this->getNodes();
    }

    /**
     * Creates and returns a node with the given properties.
     *
     * Can be overridden by subclasses to use a Node subclass for nodes.
     *
     * @param string|int $id
     * @param string|int $parent
     * @param array      $properties
     *
     * @return Node
     */
    protected function createNode($id, $parent, array $properties): Node
    {
        return new Node($id, $parent, $properties);
    }
}
