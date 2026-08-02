<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy;
use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy\Filter;
use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy\FilterStrategy;
use Rentpost\Doctrine\MultiTenancy\AttributeException;

class MultiTenancyTest extends TestCase
{

    public function testDefaults(): void
    {
        $mt = new MultiTenancy();

        $this->assertTrue($mt->isEnabled());
        $this->assertSame([], $mt->getFilters());
        $this->assertSame(FilterStrategy::AnyMatch, $mt->getFilterStrategy());
        $this->assertNull($mt->isPermissive());
        $this->assertFalse($mt->isStrict());
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


    public function testPermissive(): void
    {
        $mt = new MultiTenancy(permissive: true);

        $this->assertTrue($mt->isPermissive());
        $this->assertFalse($mt->isStrict());
    }


    public function testNonPermissive(): void
    {
        $mt = new MultiTenancy(permissive: false);

        $this->assertFalse($mt->isPermissive());
        $this->assertTrue($mt->isStrict());
    }


    #[IgnoreDeprecations]
    public function testStrictMapsToNonPermissive(): void
    {
        $this->expectUserDeprecationMessage(
            'The MultiTenancy attribute\'s "strict" argument is deprecated and is removed in 3.0. ' .
            'Use "permissive: false" instead.',
        );

        $mt = new MultiTenancy(strict: true);

        $this->assertFalse($mt->isPermissive());
    }


    #[IgnoreDeprecations]
    public function testNonStrictMapsToPermissive(): void
    {
        $this->expectUserDeprecationMessage(
            'The MultiTenancy attribute\'s "strict" argument is deprecated and is removed in 3.0. ' .
            'Use "permissive: true" instead.',
        );

        $mt = new MultiTenancy(strict: false);

        // The former default, said out loud, which is a decision rather than an absent one
        $this->assertTrue($mt->isPermissive());
    }


    public function testBothArgumentsThrowsException(): void
    {
        $this->expectException(AttributeException::class);
        $this->expectExceptionMessage('inverses of one another');

        new MultiTenancy(strict: true, permissive: true);
    }
}
