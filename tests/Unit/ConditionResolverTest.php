<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rentpost\Doctrine\MultiTenancy\AttributeException;
use Rentpost\Doctrine\MultiTenancy\ConditionResolver;
use Rentpost\Doctrine\MultiTenancy\KeyValueException;
use Rentpost\Doctrine\MultiTenancy\Listener;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\Book;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\ExternalCatalog;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\FirstMatchWithContextFreeEntity;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\Invoice;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\MisconfiguredEntity;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\Order;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\Product;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\Review;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\StrictEntity;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\StrictNoContextFreeEntity;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\UnmappedEntity;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\Wishlist;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\StubAmbientContextProvider;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\StubContextProvider;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\StubValueHolder;

class ConditionResolverTest extends TestCase
{

    private function createListener(array $valueHolders = [], array $contextProviders = []): Listener
    {
        $listener = new Listener();

        foreach ($valueHolders as $vh) {
            $listener->addValueHolder($vh);
        }

        foreach ($contextProviders as $cp) {
            $listener->addContextProvider($cp);
        }

        return $listener;
    }


    public function testSimpleFilter(): void
    {
        $listener = $this->createListener([
            new StubValueHolder('storeId', '42'),
        ]);
        $resolver = new ConditionResolver($listener);

        $result = $resolver->resolve(Book::class, 't0');

        $this->assertSame('t0.store_id = 42', $result);
    }


    public function testTableAliasSubstitution(): void
    {
        $listener = $this->createListener([
            new StubValueHolder('storeId', '42'),
        ]);
        $resolver = new ConditionResolver($listener);

        $result = $resolver->resolve(Book::class, 'b');

        $this->assertSame('b.store_id = 42', $result);
    }


    public function testDisabledEntityReturnsEmptyString(): void
    {
        $listener = $this->createListener();
        $resolver = new ConditionResolver($listener);

        $result = $resolver->resolve(ExternalCatalog::class, 't0');

        $this->assertSame('', $result);
    }


    public function testNoAttributeThrowsException(): void
    {
        $listener = $this->createListener();
        $resolver = new ConditionResolver($listener);

        $this->expectException(AttributeException::class);
        $this->expectExceptionMessage('must have the MultiTenancy attribute');

        $resolver->resolve(UnmappedEntity::class, 't0');
    }


    public function testEnabledNoFiltersThrowsException(): void
    {
        $listener = $this->createListener();
        $resolver = new ConditionResolver($listener);

        $this->expectException(AttributeException::class);
        $this->expectExceptionMessage('there were not any added filters');

        $resolver->resolve(MisconfiguredEntity::class, 't0');
    }


    public function testAnyMatchJoinsMultipleFilters(): void
    {
        $listener = $this->createListener(
            [
                new StubValueHolder('storeId', '42'),
                new StubValueHolder('customerId', '7'),
            ],
            [
                new StubContextProvider('customer', true),
                new StubContextProvider('publisher', false),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // Product has: default store filter + customer filter + publisher filter
        // customer is contextual, publisher is not
        $result = $resolver->resolve(Product::class, 't0');

        $this->assertSame(
            't0.store_id = 42 AND t0.id IN(SELECT product_id FROM customer_purchase WHERE customer_id = 7)',
            $result,
        );
    }


    public function testFirstMatchUsesOnlyFirstMatchingFilter(): void
    {
        $listener = $this->createListener(
            [
                new StubValueHolder('storeId', '42'),
                new StubValueHolder('customerId', '7'),
            ],
            [
                new StubContextProvider('staff', true),
                new StubContextProvider('customer', true),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // Review: staff filter first, customer filter second, both contextual
        // FirstMatch should only use the staff filter
        $result = $resolver->resolve(Review::class, 't0');

        $this->assertSame('t0.store_id = 42', $result);
    }


    public function testFirstMatchContextFreeFilterShortCircuitsSubsequent(): void
    {
        $listener = $this->createListener(
            [
                new StubValueHolder('storeId', '42'),
                new StubValueHolder('customerId', '7'),
            ],
            [new StubContextProvider('customer', true)],
        );
        $resolver = new ConditionResolver($listener);

        // FirstMatchWithContextFreeEntity: context-free filter first, customer filter second.
        // Context-free is always contextual → always matches first → subsequent filters
        // never evaluated, even when their contexts are active.
        $result = $resolver->resolve(FirstMatchWithContextFreeEntity::class, 't0');

        $this->assertSame('t0.store_id = 42', $result);
    }


    public function testNonContextualFilterIsSkipped(): void
    {
        $listener = $this->createListener(
            [
                new StubValueHolder('storeId', '42'),
                new StubValueHolder('customerId', '7'),
            ],
            [
                new StubContextProvider('customer', false),
                new StubContextProvider('publisher', false),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // Product: default filter (no context, always applies) + customer + publisher
        // Both customer and publisher are not contextual, so only default filter applies
        $result = $resolver->resolve(Product::class, 't0');

        $this->assertSame('t0.store_id = 42', $result);
    }


    public function testContextualFilterIsApplied(): void
    {
        $listener = $this->createListener(
            [
                new StubValueHolder('storeId', '42'),
                new StubValueHolder('publisherId', '15'),
            ],
            [
                new StubContextProvider('customer', false),
                new StubContextProvider('publisher', true),
            ],
        );
        $resolver = new ConditionResolver($listener);

        $result = $resolver->resolve(Product::class, 't0');

        $this->assertSame('t0.store_id = 42 AND t0.publisher_id = 15', $result);
    }


    public function testMultipleContextsOneMatchSuffices(): void
    {
        $listener = $this->createListener(
            [
                new StubValueHolder('storeId', '42'),
                new StubValueHolder('customerId', '7'),
            ],
            [
                // Review has staff and customer contexts
                new StubContextProvider('staff', false),
                new StubContextProvider('customer', true),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // FirstMatch: staff not contextual, so its filter skipped. Customer is contextual.
        $result = $resolver->resolve(Review::class, 't0');

        $this->assertSame(
            't0.book_id IN(SELECT book_id FROM customer_review WHERE customer_id = 7)',
            $result,
        );
    }


    public function testEmptyContextAppliesToAll(): void
    {
        $listener = $this->createListener([
            new StubValueHolder('storeId', '42'),
        ]);
        $resolver = new ConditionResolver($listener);

        // Book has a filter with empty context — should always apply
        $result = $resolver->resolve(Book::class, 't0');

        $this->assertSame('t0.store_id = 42', $result);
    }


    public function testIgnoredFilterAnyMatchSkipsButOthersApply(): void
    {
        $listener = $this->createListener(
            [new StubValueHolder('storeId', '42')],
            [new StubContextProvider('staff', true)],
        );
        $resolver = new ConditionResolver($listener);

        // Order: staff filter (ignored) + default store filter
        // AnyMatch: ignored filter skipped via continue, store filter still applies
        $result = $resolver->resolve(Order::class, 't0');

        $this->assertSame('t0.store_id = 42', $result);
    }


    public function testIgnoredFilterFirstMatchBreaks(): void
    {
        $listener = $this->createListener(
            [new StubValueHolder('customerId', '7')],
            [
                new StubContextProvider('staff', true),
                new StubContextProvider('customer', true),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // Wishlist: staff filter (ignored, contextual) + customer filter
        // FirstMatch: ignored filter breaks loop, customer filter never reached
        $result = $resolver->resolve(Wishlist::class, 't0');

        $this->assertSame('', $result);
    }


    public function testValueHolderNullThrowsException(): void
    {
        $listener = $this->createListener([
            new StubValueHolder('storeId', null),
        ]);
        $resolver = new ConditionResolver($listener);

        $this->expectException(KeyValueException::class);
        $this->expectExceptionMessage('{storeId}');

        $resolver->resolve(Book::class, 't0');
    }


    public function testValueHolderNotInWhereClauseNullIsOk(): void
    {
        $listener = $this->createListener([
            new StubValueHolder('storeId', '42'),
            new StubValueHolder('unusedId', null),  // Not referenced in Book's where clause
        ]);
        $resolver = new ConditionResolver($listener);

        // Should not throw despite null value — identifier not used in where clause
        $result = $resolver->resolve(Book::class, 't0');

        $this->assertSame('t0.store_id = 42', $result);
    }


    public function testMultipleValueHolderSubstitutions(): void
    {
        $listener = $this->createListener([
            new StubValueHolder('storeId', '42'),
            new StubValueHolder('authorId', '7'),
        ]);
        $resolver = new ConditionResolver($listener);

        $result = $resolver->resolve(Invoice::class, 't0');

        $this->assertSame('t0.store_id = 42 AND t0.author_id = 7', $result);
    }


    public function testNoApplicableFiltersReturnsEmptyString(): void
    {
        $listener = $this->createListener(
            [new StubValueHolder('storeId', '42')],
            [
                new StubContextProvider('staff', false),
                new StubContextProvider('customer', false),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // Review only has contextual filters (staff, customer), both non-contextual
        $result = $resolver->resolve(Review::class, 't0');

        $this->assertSame('', $result);
    }


    public function testStrictCoveredContextAppliesNormally(): void
    {
        $listener = $this->createListener(
            [
                new StubValueHolder('storeId', '42'),
                new StubValueHolder('customerId', '7'),
            ],
            [
                new StubContextProvider('staff', false),
                new StubContextProvider('customer', true),
                new StubContextProvider('publisher', false),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // StrictEntity: customer is covered and active → normal filters, no denial
        $result = $resolver->resolve(StrictEntity::class, 't0');

        $this->assertSame(
            't0.store_id = 42 AND t0.id IN(SELECT book_id FROM customer_purchase WHERE customer_id = 7)',
            $result,
        );
    }


    public function testStrictIgnoredContextIsCovered(): void
    {
        $listener = $this->createListener(
            [new StubValueHolder('storeId', '42')],
            [
                new StubContextProvider('staff', true),
                new StubContextProvider('customer', false),
                new StubContextProvider('publisher', false),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // StrictEntity: staff is active and covered (listed via ignore: true filter, also via context-free) → no denial
        $result = $resolver->resolve(StrictEntity::class, 't0');

        $this->assertSame('t0.store_id = 42', $result);
    }


    public function testStrictContextFreeFilterCoversAllContexts(): void
    {
        $listener = $this->createListener(
            [new StubValueHolder('storeId', '42')],
            [
                new StubContextProvider('staff', false),
                new StubContextProvider('customer', false),
                new StubContextProvider('publisher', false),
                new StubContextProvider('vendor', true),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // StrictEntity has a context-free filter which, consistent with "applies to all",
        // also covers all contexts. Vendor is active and not explicitly listed, but the
        // context-free filter means strict has nothing to enforce.
        $result = $resolver->resolve(StrictEntity::class, 't0');

        $this->assertSame('t0.store_id = 42', $result);
    }


    public function testStrictUncoveredContextDenied(): void
    {
        $listener = $this->createListener(
            [],
            [
                new StubContextProvider('customer', false),
                new StubContextProvider('publisher', false),
                new StubContextProvider('vendor', true),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // StrictNoContextFreeEntity has only contextual filters. Vendor is active but
        // not in any filter's context → denied.
        $result = $resolver->resolve(StrictNoContextFreeEntity::class, 't0');

        $this->assertSame('1 = 0', $result);
    }


    public function testStrictNoActiveContextUsesContextFreeFilters(): void
    {
        $listener = $this->createListener(
            [new StubValueHolder('storeId', '42')],
            [
                new StubContextProvider('staff', false),
                new StubContextProvider('customer', false),
                new StubContextProvider('publisher', false),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // StrictEntity: no context active → only context-free filters, no denial
        $result = $resolver->resolve(StrictEntity::class, 't0');

        $this->assertSame('t0.store_id = 42', $result);
    }


    public function testStrictMultipleActiveContextsOneCovered(): void
    {
        $listener = $this->createListener(
            [
                new StubValueHolder('storeId', '42'),
                new StubValueHolder('customerId', '7'),
            ],
            [
                new StubContextProvider('customer', true),
                new StubContextProvider('publisher', false),
                new StubContextProvider('vendor', true),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // StrictNoContextFreeEntity: customer covered, vendor NOT → denied
        $result = $resolver->resolve(StrictNoContextFreeEntity::class, 't0');

        $this->assertSame(
            't0.id IN(SELECT book_id FROM customer_purchase WHERE customer_id = 7) AND 1 = 0',
            $result,
        );
    }


    public function testStrictAmbientContextIsSkipped(): void
    {
        $listener = $this->createListener(
            [new StubValueHolder('customerId', '7')],
            [
                new StubContextProvider('customer', true),
                new StubContextProvider('publisher', false),
                new StubAmbientContextProvider('role', true),
                new StubAmbientContextProvider('user', true),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // StrictNoContextFreeEntity: customer covered and active. Ambients (role, user)
        // active but skipped during coverage check. No denial.
        $result = $resolver->resolve(StrictNoContextFreeEntity::class, 't0');

        $this->assertSame(
            't0.id IN(SELECT book_id FROM customer_purchase WHERE customer_id = 7)',
            $result,
        );
    }


    public function testStrictAmbientContextDoesNotMaskUncovered(): void
    {
        $listener = $this->createListener(
            [new StubValueHolder('customerId', '7')],
            [
                new StubContextProvider('customer', true),
                new StubContextProvider('publisher', false),
                new StubContextProvider('vendor', true),
                new StubAmbientContextProvider('role', true),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // StrictNoContextFreeEntity: role is ambient (skipped), customer covered,
        // but vendor is active and uncovered → denied
        $result = $resolver->resolve(StrictNoContextFreeEntity::class, 't0');

        $this->assertSame(
            't0.id IN(SELECT book_id FROM customer_purchase WHERE customer_id = 7) AND 1 = 0',
            $result,
        );
    }
}
