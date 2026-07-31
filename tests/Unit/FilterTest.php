<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy\Tests\Unit;

use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\FilterCollection;
use PHPUnit\Framework\TestCase;
use Rentpost\Doctrine\MultiTenancy\Filter;
use Rentpost\Doctrine\MultiTenancy\FilterException;
use Rentpost\Doctrine\MultiTenancy\KeyValueException;
use Rentpost\Doctrine\MultiTenancy\Listener;
use Rentpost\Doctrine\MultiTenancy\ValueHolderInterface;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\Book;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\StubValueHolder;

class FilterTest extends TestCase
{

    /**
     * Creates a Filter instance with a mocked EntityManager
     */
    private function createFilter(
        bool $filterEnabled = true,
        ?Listener $listener = null,
        ?FilterCollection $filterCollection = null,
    ): Filter {
        if (!$filterCollection) {
            $filterCollection = $this->createMock(FilterCollection::class);
            $filterCollection->method('isEnabled')
                ->with('multi-tenancy')
                ->willReturn($filterEnabled);
        }

        $evm = new EventManager();
        if ($listener) {
            $evm->addEventSubscriber($listener);
        }

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getFilters')->willReturn($filterCollection);
        $em->method('getEventManager')->willReturn($evm);

        return new Filter($em);
    }


    private function createClassMetadata(string $entityClass): ClassMetadata
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getName')->willReturn($entityClass);
        $metadata->reflClass = new \ReflectionClass($entityClass);

        return $metadata;
    }


    public function testDelegatesToConditionResolver(): void
    {
        $listener = new Listener();
        $listener->addValueHolder(new StubValueHolder('storeId', '42'));
        $listener->refreshValues();

        $filter = $this->createFilter(true, $listener);
        $metadata = $this->createClassMetadata(Book::class);

        $result = $filter->addFilterConstraint($metadata, 't0');

        $this->assertSame('t0.store_id = 42', $result);
    }


    public function testDisabledFilterReturnsEmptyString(): void
    {
        $filter = $this->createFilter(false);
        $metadata = $this->createClassMetadata(Book::class);

        $result = $filter->addFilterConstraint($metadata, 't0');

        $this->assertSame('', $result);
    }


    /**
     * The whole point of the design: a ValueHolder is a producer, invoked when the consumer
     * refreshes, and never while Doctrine is generating SQL.  Anything read at generation time
     * would be invisible to Doctrine's SQL memoization, which is what this rules out.
     */
    public function testNoValueHolderIsReadWhileBuildingAConstraint(): void
    {
        $valueHolder = $this->createMock(ValueHolderInterface::class);
        $valueHolder->method('getIdentifier')->willReturn('storeId');
        $valueHolder->expects($this->once())->method('getValue')->willReturn('42');

        $listener = new Listener();
        $listener->addValueHolder($valueHolder);
        $listener->refreshValues();

        $filter = $this->createFilter(true, $listener);
        $metadata = $this->createClassMetadata(Book::class);

        $this->assertSame('t0.store_id = 42', $filter->addFilterConstraint($metadata, 't0'));
        $this->assertSame('t0.store_id = 42', $filter->addFilterConstraint($metadata, 't0'));
    }


    public function testRefreshingValuesMovesTheHash(): void
    {
        $valueHolder = new StubValueHolder('storeId', '42');

        $listener = new Listener();
        $listener->addValueHolder($valueHolder);
        $listener->refreshValues();

        $filter = $this->createFilter(true, $listener);
        $metadata = $this->createClassMetadata(Book::class);
        $filter->addFilterConstraint($metadata, 't0');
        $withFirstValue = (string) $filter;

        // The ambient state the ValueHolder reads changes, and the consumer refreshes
        $valueHolder->setValue('99');
        $listener->refreshValues();

        $this->assertNotSame($withFirstValue, (string) $filter);
        $this->assertSame('t0.store_id = 99', $filter->addFilterConstraint($metadata, 't0'));
    }


    public function testIdentifierWithNoValueThrowsException(): void
    {
        $filter = $this->createFilter(true, new Listener());

        $this->expectException(KeyValueException::class);
        $this->expectExceptionMessageMatches('/\{storeId\}.*has no value set/s');

        $filter->addFilterConstraint($this->createClassMetadata(Book::class), 't0');
    }


    public function testListenerValueResolvesWithoutValueHolder(): void
    {
        $listener = new Listener();
        $listener->setValue('storeId', '7');

        $filter = $this->createFilter(true, $listener);

        $result = $filter->addFilterConstraint($this->createClassMetadata(Book::class), 't0');

        $this->assertSame('t0.store_id = 7', $result);
    }


    /**
     * The filter's string representation is what Doctrine hashes to decide whether memoized SQL
     * can be reused, so each distinct value has to produce a distinct representation.
     */
    public function testChangingAListenerValueChangesTheStringRepresentation(): void
    {
        $listener = new Listener();
        $listener->setValue('storeId', '42');

        $filter = $this->createFilter(true, $listener);
        $filter->addFilterConstraint($this->createClassMetadata(Book::class), 't0');
        $withFirstValue = (string) $filter;

        $listener->setValue('storeId', '99');
        $withSecondValue = (string) $filter;

        $this->assertNotSame($withFirstValue, $withSecondValue);
        $this->assertSame(
            't0.store_id = 99',
            $filter->addFilterConstraint($this->createClassMetadata(Book::class), 't0'),
        );
    }


    /**
     * Doctrine builds a new Filter every time the filter is enabled after having been disabled,
     * so one has to pick up values that were set before it existed, without being told again.
     */
    public function testFilterBuiltAfterAValueWasSetDeclaresItOnFirstUse(): void
    {
        $listener = new Listener();
        $listener->setValue('storeId', '42');

        $filter = $this->createFilter(true, $listener);
        $beforeFirstUse = (string) $filter;

        $result = $filter->addFilterConstraint($this->createClassMetadata(Book::class), 't0');

        $this->assertSame('t0.store_id = 42', $result);
        $this->assertTrue($filter->hasParameter('storeId'));
        $this->assertNotSame($beforeFirstUse, (string) $filter);
    }


    public function testUnchangedValueLeavesTheFilterCollectionClean(): void
    {
        $filterCollection = $this->createMock(FilterCollection::class);
        $filterCollection->method('isEnabled')->willReturn(true);
        $filterCollection->expects($this->once())->method('setFiltersStateDirty');

        $listener = new Listener();
        $listener->addValueHolder(new StubValueHolder('storeId', '42'));
        $listener->refreshValues();

        $filter = $this->createFilter(true, $listener, $filterCollection);
        $metadata = $this->createClassMetadata(Book::class);

        // Only the first of these can dirty the collection, the value never changing
        $filter->addFilterConstraint($metadata, 't0');
        $listener->refreshValues();
        $listener->setValue('storeId', '42');
        $filter->addFilterConstraint($metadata, 't0');

        $this->assertTrue($filter->hasParameter('storeId'));
    }


    public function testNoListenerThrowsFilterException(): void
    {
        $filter = $this->createFilter(true, null);
        $metadata = $this->createClassMetadata(Book::class);

        $this->expectException(FilterException::class);
        $this->expectExceptionMessage('Listener');

        $filter->addFilterConstraint($metadata, 't0');
    }
}
