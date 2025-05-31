<?php

namespace BlueM;

use BlueM\Tree\Node;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BlueM\Tree.
 *
 * These are not really unit tests, as they test the class including
 * BlueM\Tree\Node as a whole.
 *
 * @covers \BlueM\Tree
 */
class TreeTest extends TestCase
{
    /**
     * @test
     */
    public function anExceptionIsThrownIfANonScalarValueShouldBeUsedAsRootId()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Option “rootid” must be a scalar');
        new Tree([], ['rootId' => []]);
    }

    /**
     * @test
     */
    public function anExceptionIsThrownIfANonStringValueShouldBeUsedAsIdFieldName()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Option “id” must be a string');
        new Tree([], ['id' => 123]);
    }

    /**
     * @test
     */
    public function anExceptionIsThrownIfANonStringValueShouldBeUsedAsParentIdFieldName()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Option “parent” must be a string');
        new Tree([], ['parent' => new \DateTime()]);
    }

    /**
     * @test
     */
    public function theRootNodesCanBeRetrieved()
    {
        $data = self::dataWithNumericKeys();
        $tree = new Tree($data);

        $nodes = $tree->getRootNodes();
        static::assertIsArray($nodes);
        static::assertCount(5, $nodes);

        $expectedOrder = [5, 3, 4, 6, 1];

        for ($i = 0, $ii = \count($nodes); $i < $ii; $i++) {
            static::assertInstanceOf(Node::class, $nodes[$i]);
            static::assertSame($expectedOrder[$i], $nodes[$i]->getId());
        }
    }

    /**
     * @test
     */
    public function theRootNodesCanBeRetrievedWhenTheIdsAreStrings()
    {
        $data = self::dataWithStringKeys();
        $tree = new Tree($data, ['rootId' => '']);

        $nodes = $tree->getRootNodes();
        static::assertIsArray($nodes);

        $expectedOrder = ['building', 'vehicle'];

        for ($i = 0, $ii = \count($nodes); $i < $ii; $i++) {
            static::assertInstanceOf(Node::class, $nodes[$i]);
            static::assertSame($expectedOrder[$i], $nodes[$i]->getId());
        }
    }

    /**
     * @test
     */
    public function theTreeCanBeRebuiltFromNewData()
    {
        $data = self::dataWithNumericKeys();

        $tree = new Tree($data);
        $originalData = json_encode($tree);

        for ($i = 0; $i < 3; $i++) {
            $tree->rebuildWithData($data);
            static::assertSame($originalData, json_encode($tree));
        }
    }

    /**
     * @test
     */
    public function anExceptionIsThrownWhenTryingToCreateATreeFromUnusableData()
    {
        $this->expectException(\BlueM\Tree\Exception\InvalidDatatypeException::class);
        $this->expectExceptionMessage('Data must be an iterable');
        new Tree('a');
    }

    /**
     * @test
     */
    public function aTreeCanBeCreatedFromAnIterable()
    {
        function gen()
        {
            yield ['id' => 1, 'parent' => 0];
            yield ['id' => 2, 'parent' => 0];
            yield ['id' => 3, 'parent' => 2];
            yield ['id' => 4, 'parent' => 0];
        }

        $tree = new Tree(gen());
        static::assertSame('[{"id":1,"parent":0},{"id":2,"parent":0},{"id":3,"parent":2},{"id":4,"parent":0}]', json_encode($tree));
    }

    /**
     * @test
     */
    public function aTreeCanBeCreatedFromAnArrayOfObjectsImplementingIterator()
    {
        function makeIterableInstance($data) {
            return new class($data) implements \Iterator {

                private $data;
                private $pos = 0;
                private $keys;

                public function __construct(array $data)
                {
                    $this->data = $data;
                    $this->keys = array_keys($data);
                }

                public function current()
                {
                    return $this->data[$this->keys[$this->pos]];
                }

                public function next()
                {
                    ++$this->pos;
                }

                public function key()
                {
                    return $this->keys[$this->pos];
                }

                public function valid()
                {
                    return isset($this->keys[$this->pos]);
                }

                public function rewind()
                {
                    $this->pos = 0;
                }
            };
        }

        $tree = new Tree([
            makeIterableInstance(['id' => 1, 'parent' => 0, 'title' => 'A']),
            makeIterableInstance(['id' => 2, 'parent' => 0, 'title' => 'B']),
            makeIterableInstance(['id' => 3, 'parent' => 2, 'title' => 'B-1']),
            makeIterableInstance(['id' => 4, 'parent' => 0, 'title' => 'D']),
        ]);
        static::assertSame(
            '[{"title":"A","id":1,"parent":0},{"title":"B","id":2,"parent":0},{"title":"B-1","id":3,"parent":2},{"title":"D","id":4,"parent":0}]',
            json_encode($tree)
        );
    }

    /**
     * @test
     */
    public function theTreeCanBeSerializedToAJsonRepresentationFromWhichATreeWithTheSameDataCanBeBuiltWhenDecoded()
    {
        $data = self::dataWithNumericKeys();

        $tree1 = new Tree($data);
        $tree1Json = json_encode($tree1);
        $tree1JsonDecoded = json_decode($tree1Json, true);

        static::assertCount(\count($data), $tree1JsonDecoded);
        foreach ($data as $nodeData) {
            // Prepare expected data consistent with Node's property key casing logic
            $idKey = $tree1->idKey ?? 'id'; // Access protected property via reflection or make them public for test
            $parentKey = $tree1->parentKey ?? 'parent'; // Or get them from options used for $tree1

            $expectedNodeData = [];
            foreach ($nodeData as $key => $value) {
                if ($key === $idKey || $key === $parentKey) {
                    $expectedNodeData[$key] = $value;
                } else {
                    $expectedNodeData[strtolower($key)] = $value;
                }
            }
            // The Node constructor unsets $properties['id'] and $properties['parent']
            // and then sets $this->properties['id'] and $this->properties['parent']
            // from its direct $id, $parent arguments.
            // So, if original $nodeData had 'ID' => 1, it becomes $properties['id'] => 1 (lowercase key)
            // then it's unset, then $this->properties['id'] = 1 is set.
            // The custom keys are lowercased. 'id' and 'parent' keys are effectively fixed to 'id', 'parent'.
            // The test data uses 'id', 'parent' so this is simpler.

            $finalExpectedNodeData = [];
            $originalIdValue = $nodeData[$idKey];
            $originalParentValue = $nodeData[$parentKey];

            foreach($nodeData as $k => $v) {
                if ($k !== $idKey && $k !== $parentKey) {
                    $finalExpectedNodeData[strtolower($k)] = $v;
                }
            }
            $finalExpectedNodeData[$idKey] = $originalIdValue;
            $finalExpectedNodeData[$parentKey] = $originalParentValue;

            static::assertContains($finalExpectedNodeData, $tree1JsonDecoded);
        }

        $tree2 = new Tree($tree1JsonDecoded);
        $tree2Json = json_encode($tree2);

        static::assertSame($tree1Json, $tree2Json);
    }

    /**
     * @test
     */
    public function allNodesCanBeRetrieved()
    {
        $data = self::dataWithNumericKeys();
        $tree = new Tree($data);
        $nodes = $tree->getNodes();

        static::assertIsArray($nodes);
        static::assertCount(\count($data), $nodes);

        $expectedOrder = [5, 3, 4, 6, 1, 7, 15, 11, 21, 27, 12, 10, 20];

        for ($i = 0, $ii = \count($nodes); $i < $ii; $i++) {
            static::assertInstanceOf(Node::class, $nodes[$i]);
            static::assertSame($expectedOrder[$i], $nodes[$i]->getId());
        }
    }

    /**
     * @test
     */
    public function allNodesCanBeRetrievedWhenNodeIdsAreStrings()
    {
        $data = self::dataWithStringKeys();
        $tree = new Tree($data, ['rootId' => '']);

        $nodes = $tree->getNodes();
        static::assertIsArray($nodes);
        static::assertCount(\count($data), $nodes);

        $expectedOrder = [
            'building', 'library', 'school', 'primary-school', 'vehicle', 'bicycle', 'car',
        ];

        for ($i = 0, $ii = \count($nodes); $i < $ii; $i++) {
            static::assertInstanceOf(Node::class, $nodes[$i]);
            static::assertSame($expectedOrder[$i], $nodes[$i]->getId());
        }
    }

    /**
     * @test
     */
    public function aNodeCanBeAccessedByItsIntegerId()
    {
        $data = self::dataWithNumericKeys();
        $tree = new Tree($data);
        $node = $tree->getNodeById(20);
        static::assertEquals(20, $node->getId());
    }

    /**
     * @test
     */
    public function aNodeCanBeAccessedByItsStringId()
    {
        $data = self::dataWithStringKeys();
        $tree = new Tree($data, ['rootId' => '']);
        $node = $tree->getNodeById('library');
        static::assertEquals('library', $node->getId());
    }

    /**
     * @test
     */
    public function tryingToGetANodeByItsIdThrowsAnExceptionIfTheIdIsInvalid()
    {
        $this->expectException(\InvalidArgumentException::class);
        // No specific message was in the original annotation for the next line
        // $this->expectExceptionMessage('...');
        $data = self::dataWithNumericKeys();
        $tree = new Tree($data);
        $tree->getNodeById(999);
    }

    /**
     * @test
     */
    public function aNodeCanBeAccessedByItsValuePath()
    {
        $data = self::dataWithNumericKeys();
        $tree = new Tree($data);
        static::assertEquals(
            $tree->getNodeById(11),
            $tree->getNodeByValuePath('name', ['Europe', 'Germany', 'Hamburg'])
        );
    }

    /**
     * @test
     */
    public function tryingToGetANodeByItsValuePathReturnsNullIfNoNodeMatches()
    {
        $data = self::dataWithNumericKeys();
        $tree = new Tree($data);
        static::assertEquals(
            null,
            $tree->getNodeByValuePath('name', ['Europe', 'Germany', 'Frankfurt'])
        );
    }

    /**
     * @test
     */
    public function inScalarContextTheTreeIsReturnedAsAString()
    {
        $data = self::dataWithNumericKeys();
        $tree = new Tree($data);
        $actual = "$tree";
        $expected = <<<'EXPECTED'
- 5
- 3
- 4
- 6
- 1
  - 7
    - 15
    - 11
      - 21
      - 27
    - 12
  - 10
    - 20
EXPECTED;

        static::assertEquals($expected, $actual);
    }

    /**
     * @test
     */
    public function anExceptionIsThrownWhenAnInvalidParentIdIsReferenced()
    {
        $this->expectException(\BlueM\Tree\Exception\InvalidParentException::class);
        $this->expectExceptionMessage('123 points to non-existent parent with ID 456');
        new Tree(
            [
                ['id' => 123, 'parent' => 456],
            ]
        );
    }

    /**
     * @test
     */
    public function anExceptionIsThrownWhenANodeWouldBeItsOwnParent()
    {
        $this->expectException(\BlueM\Tree\Exception\InvalidParentException::class);
        $this->expectExceptionMessage('678 references its own ID as parent');
        new Tree(
            [
                ['id' => 123, 'parent' => 0],
                ['id' => 678, 'parent' => 678],
            ]
        );
    }

    /**
     * @test
     * @ticket                   3
     */
    public function anExceptionIsThrownWhenANodeWouldBeItsOwnParentWhenOwnIdAndParentIdHaveDifferentTypes()
    {
        $this->expectException(\BlueM\Tree\Exception\InvalidParentException::class);
        $this->expectExceptionMessage('references its own ID as parent');
        new Tree(
            [
                ['id' => '5', 'parent' => 5],
            ]
        );
    }

    /**
     * @test
     * @ticket 3
     */
    public function whenMixingNumericAndStringIdsNoExceptionIsThrownDueToImplicitTypecasting()
    {
        new Tree([
            ['id' => 'foo', 'parent' => 0],
        ]);
        static::assertTrue(true); // Just to make PHPUnit happy
    }

    /**
     * @test
     */
    public function clientsCanSupplyDifferingNamesForIdAndParentIdInInputData()
    {
        $data = self::dataWithStringKeys(true, 'id_node', 'id_parent');

        $tree = new Tree($data, ['rootId' => '', 'id' => 'id_node', 'parent' => 'id_parent']);

        $nodes = $tree->getRootNodes();
        static::assertIsArray($nodes);

        $expectedOrder = ['building', 'vehicle'];

        for ($i = 0, $ii = \count($nodes); $i < $ii; $i++) {
            static::assertInstanceOf(Node::class, $nodes[$i]);
            static::assertSame($expectedOrder[$i], $nodes[$i]->getId());
        }
    }

    /**
     * @param bool $sorted
     *
     * @return array
     */
    private static function dataWithNumericKeys($sorted = true): array
    {
        $data = [
            ['id' => 1, 'parent' => 0, 'name' => 'Europe'],
            ['id' => 3, 'parent' => 0, 'name' => 'America'],
            ['id' => 4, 'parent' => 0, 'name' => 'Asia'],
            ['id' => 5, 'parent' => 0, 'name' => 'Africa'],
            ['id' => 6, 'parent' => 0, 'name' => 'Australia'],
            // --
            ['id' => 7, 'parent' => 1, 'name' => 'Germany'],
            ['id' => 10, 'parent' => 1, 'name' => 'Portugal'],
            // --
            ['id' => 11, 'parent' => 7, 'name' => 'Hamburg'],
            ['id' => 12, 'parent' => 7, 'name' => 'Munich'],
            ['id' => 15, 'parent' => 7, 'name' => 'Berlin'],
            // --
            ['id' => 20, 'parent' => 10, 'name' => 'Lisbon'],
            // --
            ['id' => 27, 'parent' => 11, 'name' => 'Eimsbüttel'],
            ['id' => 21, 'parent' => 11, 'name' => 'Altona'],
        ];

        if ($sorted) {
            usort(
                $data,
                function ($a, $b) {
                    if ($a['name'] < $b['name']) {
                        return -1;
                    }
                    if ($a['name'] > $b['name']) {
                        return 1;
                    }

                    return 0;
                }
            );
        }

        return $data;
    }

    /**
     * @param bool   $sorted
     * @param string $idName
     * @param string $parentName
     *
     * @return array
     */
    private static function dataWithStringKeys($sorted = true, string $idName = 'id', string $parentName = 'parent'): array
    {
        $data = [
            [$idName => 'vehicle', $parentName => ''],
            [$idName => 'bicycle', $parentName => 'vehicle'],
            [$idName => 'car', $parentName => 'vehicle'],
            [$idName => 'building', $parentName => ''],
            [$idName => 'school', $parentName => 'building'],
            [$idName => 'library', $parentName => 'building'],
            [$idName => 'primary-school', $parentName => 'school'],
        ];

        if ($sorted) {
            usort(
                $data,
                function ($a, $b) use ($idName) {
                    if ($a[$idName] < $b[$idName]) {
                        return -1;
                    }
                    if ($a[$idName] > $b[$idName]) {
                        return 1;
                    }

                    return 0;
                }
            );
        }

        return $data;
    }
}
