<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy\Tests\Unit\Attribute\MultiTenancy;

use PHPUnit\Framework\TestCase;
use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy\FilterStrategy;

class FilterStrategyTest extends TestCase
{

    public function testFirstMatchCaseExists(): void
    {
        $this->assertSame('FirstMatch', FilterStrategy::FirstMatch->name);
    }


    public function testAnyMatchCaseExists(): void
    {
        $this->assertSame('AnyMatch', FilterStrategy::AnyMatch->name);
    }


    public function testStrictCaseExists(): void
    {
        $this->assertSame('Strict', FilterStrategy::Strict->name);
    }


    public function testCaseCount(): void
    {
        $this->assertCount(3, FilterStrategy::cases());
    }
}
