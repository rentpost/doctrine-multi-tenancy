<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy\Tests\Unit\Attribute\MultiTenancy;

use PHPUnit\Framework\TestCase;
use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy\Filter;

class FilterTest extends TestCase
{

    public function testDefaults(): void
    {
        $filter = new Filter();

        $this->assertSame([], $filter->getContext());
        $this->assertSame('', $filter->getWhereClause());
        $this->assertFalse($filter->isIgnored());
    }


    public function testWithContext(): void
    {
        $filter = new Filter(context: ['staff', 'publisher']);

        $this->assertSame(['staff', 'publisher'], $filter->getContext());
    }


    public function testWithWhereClause(): void
    {
        $where = '$this.store_id = {storeId}';
        $filter = new Filter(where: $where);

        $this->assertSame($where, $filter->getWhereClause());
    }


    public function testWithIgnored(): void
    {
        $filter = new Filter(ignore: true);

        $this->assertTrue($filter->isIgnored());
    }


    public function testAllParameters(): void
    {
        $filter = new Filter(
            context: ['customer'],
            where: '$this.customer_id = {customerId}',
            ignore: false,
        );

        $this->assertSame(['customer'], $filter->getContext());
        $this->assertSame('$this.customer_id = {customerId}', $filter->getWhereClause());
        $this->assertFalse($filter->isIgnored());
    }
}
