<?php

namespace BlueM\Tree;

/**
 * Represents a node in a tree of nodes.
 *
 * @author  Carsten Bluem <carsten@bluem.net>
 * @license http://www.opensource.org/licenses/bsd-license.php  BSD 2-Clause License
 */
class Node implements \JsonSerializable
{
    /**
     * Indexed array of node properties. Must at least contain key
     * "id" and "parent"; any other keys may be added as needed.
     *
     * @var array Associative array
     */
    protected $properties = [];

    /**
     * Reference to the parent node, in case of the root object: null.
     *
     * @var Node
     */
    protected $parent;

    /**
     * Indexed array of child nodes in correct order.
     *
     * @var array
     */
    protected $children = [];

    /**
     * @var int|null The level of this node in the tree. Cached for performance.
     */
    protected $level = null;

    /**
     * @param string|int $id
     * @param string|int $parent
     * @param array      $properties Associative array of node properties
     */
    public function __construct($id, $parent, array $properties = [])
    {
        $this->properties = array_change_key_case($properties, CASE_LOWER);
        unset($this->properties['id'], $this->properties['parent']);
        $this->properties['id'] = $id;
        $this->properties['parent'] = $parent;
    }

    /**
     * Adds the given node to this node's children.
     *
     * @param Node $child
     */
    public function addChild(Node $child)
    {
        $this->children[] = $child;
        $child->parent = $this;
        $child->properties['parent'] = $this->getId();
        // $this->getLevel() will either return a stored level or calculate it once.
        // This ensures the parent's level is determined before setting the child's.
        $child->setLevel($this->getLevel() + 1);
    }

    /**
     * Returns previous node in the same level, or NULL if there's no previous node.
     *
     * @return Node|null
     */
    public function getPrecedingSibling()
    {
        return $this->getSibling(-1);
    }

    /**
     * Returns following node in the same level, or NULL if there's no following node.
     *
     * @return Node|null
     */
    public function getFollowingSibling()
    {
        return $this->getSibling(1);
    }

    /**
     * Returns the sibling with the given offset from this node, or NULL if there is no such sibling.
     *
     * @param int $offset
     *
     * @return Node|null
     */
    private function getSibling(int $offset)
    {
        $siblingsAndSelf = $this->parent->getChildren();
        $pos = array_search($this, $siblingsAndSelf, true);
        if (isset($siblingsAndSelf[$pos + $offset])) {
            return $siblingsAndSelf[$pos + $offset]; // Next / prev. node
        }

        return null;
    }

    /**
     * Returns siblings of the node.
     *
     * @return Node[]
     */
    public function getSiblings(): array
    {
        return $this->getSiblingsGeneric(false);
    }

    /**
     * Returns siblings of the node and the node itself.
     *
     * @return Node[]
     */
    public function getSiblingsAndSelf(): array
    {
        return $this->getSiblingsGeneric(true);
    }

    /**
     * @param bool $includeSelf
     *
     * @return array
     */
    protected function getSiblingsGeneric(bool $includeSelf): array
    {
        $siblings = [];
        foreach ($this->parent->getChildren() as $child) {
            if ($includeSelf || (string) $child->getId() !== (string) $this->getId()) {
                $siblings[] = $child;
            }
        }

        return $siblings;
    }

    /**
     * Returns all direct children of this node.
     *
     * @return Node[]
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    /**
     * Returns the parent object or null, if it has no parent.
     *
     * @return Node|null Either parent node or, when called on root node, NULL
     */
    public function getParent()
    {
        return $this->parent ?? null;
    }

    /**
     * Returns a node's ID.
     *
     * @return mixed
     */
    public function getId()
    {
        return $this->properties['id'];
    }

    /**
     * Returns a single node property by its name.
     *
     * @param string $name
     *
     * @throws \InvalidArgumentException
     *
     * @return mixed
     */
    public function get($name)
    {
        $lowerName = strtolower($name);
        if (isset($this->properties[$lowerName])) {
            return $this->properties[$lowerName];
        }
        throw new \InvalidArgumentException(
            "Undefined property: $name (Node ID: ".$this->properties['id'].')'
        );
    }

    /**
     * @param string $name
     * @param mixed  $args
     *
     * @throws \BadFunctionCallException
     *
     * @return mixed
     */
    public function __call($name, $args)
    {
        $lowerName = strtolower($name);
        if (0 === strpos($lowerName, 'get')) {
            $property = substr($lowerName, 3);
            if (array_key_exists($property, $this->properties)) {
                return $this->properties[$property];
            }
        }
        throw new \BadFunctionCallException("Invalid method $name() called");
    }

    /**
     * @param string $name
     *
     * @throws \RuntimeException
     *
     * @return mixed
     */
    public function __get($name)
    {
        if ('parent' === $name || 'children' === $name) {
            return $this->$name;
        }
        $lowerName = strtolower($name);
        if (array_key_exists($lowerName, $this->properties)) {
            return $this->properties[$lowerName];
        }
        throw new \RuntimeException(
            "Undefined property: $name (Node ID: ".$this->properties['id'].')'
        );
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function __isset($name)
    {
        return 'parent' === $name ||
               'children' === $name ||
               array_key_exists(strtolower($name), $this->properties);
    }

    /**
     * Returns the level of this node in the tree.
     *
     * @return int Tree level (1 = top level)
     */
    public function getLevel(): int
    {
        if (null === $this->level) {
            if (null === $this->parent) {
                // This node is considered a root or detached, level 0.
                // Note: Tree.php's root node (id 0) will have its level explicitly set to 0.
                // Children of that root node will be level 1.
                $this->level = 0;
            } else {
                // Fallback for safety, but levels should be set proactively.
                $this->level = $this->parent->getLevel() + 1;
            }
        }
        return $this->level;
    }

    public function setLevel(int $level): void
    {
        $this->level = $level;
    }

    /**
     * Returns whether or not this node has at least one child node.
     *
     * @return bool
     */
    public function hasChildren(): bool
    {
        return \count($this->children) > 0;
    }

    /**
     * Returns number of children this node has.
     *
     * @return int
     */
    public function countChildren(): int
    {
        return \count($this->children);
    }

    /**
     * Returns any node below (children, grandchildren, ...) this node.
     *
     * The order is as follows: A, A1, A2, ..., B, B1, B2, ..., where A and B are
     * 1st-level items in correct order, A1/A2 are children of A in correct order,
     * and B1/B2 are children of B in correct order. If the node itself is to be
     * included, it will be the very first item in the array.
     *
     * @return Node[]
     */
    public function getDescendants(): array
    {
        return $this->getDescendantsGeneric(false);
    }

    /**
     * Returns an array containing this node and all nodes below (children,
     * grandchildren, ...) it.
     *
     * For order of nodes, see comments on getDescendants()
     *
     * @return Node[]
     */
    public function getDescendantsAndSelf(): array
    {
        return $this->getDescendantsGeneric(true);
    }

    /**
     * @param bool $includeSelf
     *
     * @return array
     */
    protected function getDescendantsGeneric(bool $includeSelf): array
    {
        $descendants = [];
        if ($includeSelf) {
            $descendants[] = $this;
        }
        $this->collectDescendants($descendants);
        return $descendants;
    }

    private function collectDescendants(array &$descendants): void
    {
        foreach ($this->children as $childNode) {
            $descendants[] = $childNode;
            if ($childNode->hasChildren()) {
                $childNode->collectDescendants($descendants);
            }
        }
    }

    /**
     * Returns any node above (parent, grandparent, ...) this node.
     *
     * The array returned from this method will include the root node. If you
     * do not want the root node, you should do an array_pop() on the array.
     *
     * @return Node[] Indexed array of nodes, sorted from the nearest
     *                one (or self) to the most remote one
     */
    public function getAncestors(): array
    {
        return $this->getAncestorsGeneric(false);
    }

    /**
     * Returns an array containing this node and all nodes above (parent, grandparent,
     * ...) it.
     *
     * Note: The array returned from this method will include the root node. If you
     * do not want the root node, you should do an array_pop() on the array.
     *
     * @return Node[] Indexed, sorted array of nodes: self, parent, grandparent, ...
     */
    public function getAncestorsAndSelf(): array
    {
        return $this->getAncestorsGeneric(true);
    }

    /**
     * @param bool $includeSelf
     *
     * @return array
     */
    protected function getAncestorsGeneric(bool $includeSelf): array
    {
        $ancestors = [];
        if ($includeSelf) {
            $ancestors[] = $this; // Add self first if requested
        }
        // Start collecting from the parent
        if (null !== $this->parent) {
            // We need to ensure the root node itself (the one with ID $this->rootId in Tree, often 0)
            // is included if it's an ancestor, as per original behavior.
            // The collectAncestors method will add $this->parent, and its parent, and so on.
            // The loop in collectAncestors should naturally stop when $this->parent becomes null.
            $this->parent->collectAncestors($ancestors);
        }
        return $ancestors;
    }

    private function collectAncestors(array &$ancestors): void
    {
        $ancestors[] = $this; // Add current node (which is an ancestor)
        if (null !== $this->parent) {
            // The check `if (null !== $this->parent)` ensures we don't try to call a method on null.
            // And it also means the ultimate root node (whose parent IS null) will be added,
            // but it won't recurse further, effectively stopping the chain.
            $this->parent->collectAncestors($ancestors);
        }
    }

    /**
     * Returns the node's properties as an array.
     *
     * @return array Associative array
     */
    public function toArray(): array
    {
        return $this->properties;
    }

    /**
     * Returns a textual representation of this node.
     *
     * @return string The node's ID
     */
    public function __toString()
    {
        return (string) $this->properties['id'];
    }

    /**
     * @return array
     */
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
