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
use Rentpost\Doctrine\MultiTenancy\Listener;
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
    ): Filter {
        $filterCollection = $this->createMock(FilterCollection::class);
        $filterCollection->method('isEnabled')
            ->with('multi-tenancy')
            ->willReturn($filterEnabled);

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


    public function testNoListenerThrowsFilterException(): void
    {
        $filter = $this->createFilter(true, null);
        $metadata = $this->createClassMetadata(Book::class);

        $this->expectException(FilterException::class);
        $this->expectExceptionMessage('Listener');

        $filter->addFilterConstraint($metadata, 't0');
    }
}
