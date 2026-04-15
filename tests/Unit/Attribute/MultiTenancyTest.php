<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy\Tests\Unit\Attribute;

use PHPUnit\Framework\TestCase;
use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy;
use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy\Filter;
use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy\FilterStrategy;

class MultiTenancyTest extends TestCase
{

    public function testDefaults(): void
    {
        $mt = new MultiTenancy();

        $this->assertTrue($mt->isEnabled());
        $this->assertSame([], $mt->getFilters());
        $this->assertSame(FilterStrategy::AnyMatch, $mt->getFilterStrategy());
    }


    public function testDisabled(): void
    {
        $mt = new MultiTenancy(enable: false);

        $this->assertFalse($mt->isEnabled());
    }


    public function testWithFilters(): void
    {
        $filters = [
            new Filter(where: '$this.store_id = {storeId}'),
            new Filter(context: ['staff'], where: '$this.staff_id = {staffId}'),
        ];

        $mt = new MultiTenancy(filters: $filters);

        $this->assertCount(2, $mt->getFilters());
        $this->assertSame($filters, $mt->getFilters());
    }


    public function testFirstMatchStrategy(): void
    {
        $mt = new MultiTenancy(strategy: FilterStrategy::FirstMatch);

        $this->assertSame(FilterStrategy::FirstMatch, $mt->getFilterStrategy());
    }
}
