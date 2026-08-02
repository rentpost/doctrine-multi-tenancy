<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rentpost\Doctrine\MultiTenancy\AttributeException;
use Rentpost\Doctrine\MultiTenancy\ConditionResolver;
use Rentpost\Doctrine\MultiTenancy\KeyValueException;
use Rentpost\Doctrine\MultiTenancy\Listener;
use Rentpost\Doctrine\MultiTenancy\ValueHolderInterface;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\Book;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\ExternalCatalog;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\FirstMatchWithContextFreeEntity;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\Invoice;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\LegacyStrictEntity;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\MisconfiguredEntity;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\NonPermissiveEntity;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\NonPermissiveNoContextFreeEntity;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\Order;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\PermissiveEntity;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\PrivilegedIgnoredEntity;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\PrivilegedScopedEntity;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\Product;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\RestrictedIgnoredEntity;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\RestrictedScopedEntity;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\Review;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\UndeclaredEntity;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\UnmappedEntity;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity\Wishlist;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\StubAmbientContextProvider;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\StubContextProvider;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\StubPrivilegedContextProvider;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\StubRestrictedContextProvider;
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

        // As a consumer does once the ValueHolders are registered, and on every context change
        $listener->refreshValues();

        return $listener;
    }


    /**
     * Runs the given work with the deprecation notices it emits collected rather than raised
     *
     * @return string[]
     */
    private function collectDeprecations(callable $work): array
    {
        $deprecations = [];
        set_error_handler(
            function (int $errno, string $message) use (&$deprecations): bool {
                $deprecations[] = $message;

                return true;
            },
            E_USER_DEPRECATED,
        );

        try {
            $work();
        } finally {
            restore_error_handler();
        }

        return $deprecations;
    }


    /**
     * Reduces a fall-through notice to the entity and context pair it reports on
     */
    private function summarizeFallThrough(string $deprecation): string
    {
        preg_match('/Entity\\\\(\w+) resolved with no filter covering the active "(\w+)"/', $deprecation, $matches);

        return $matches[1] . '::' . $matches[2];
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

        // Book has a filter with empty context, so it should always apply
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

        // Should not throw despite null value, the identifier not being used in the where clause
        $result = $resolver->resolve(Book::class, 't0');

        $this->assertSame('t0.store_id = 42', $result);
    }


    public function testSetValueOverridesARefreshedValue(): void
    {
        $listener = $this->createListener([
            new StubValueHolder('storeId', '42'),
        ]);
        $listener->setValue('storeId', '99');
        $resolver = new ConditionResolver($listener);

        $result = $resolver->resolve(Book::class, 't0');

        $this->assertSame('t0.store_id = 99', $result);
    }


    public function testValueHoldersAreNotReadWhileResolving(): void
    {
        $valueHolder = $this->createMock(ValueHolderInterface::class);
        $valueHolder->method('getIdentifier')->willReturn('storeId');
        $valueHolder->expects($this->once())->method('getValue')->willReturn('42');

        $listener = $this->createListener([$valueHolder]);
        $resolver = new ConditionResolver($listener);

        // The ValueHolder was read once, by the refresh in createListener(), and must not be read
        // again by either of these
        $this->assertSame('t0.store_id = 42', $resolver->resolve(Book::class, 't0'));
        $this->assertSame('t0.store_id = 42', $resolver->resolve(Book::class, 't0'));
    }


    public function testIdentifierWithNoValueThrowsException(): void
    {
        $resolver = new ConditionResolver($this->createListener());

        $this->expectException(KeyValueException::class);
        $this->expectExceptionMessageMatches('/\{storeId\}.*has no value set/s');

        $resolver->resolve(Book::class, 't0');
    }


    public function testSetValueWithoutValueHolder(): void
    {
        $listener = $this->createListener();
        $listener->setValue('storeId', '42');
        $resolver = new ConditionResolver($listener);

        $result = $resolver->resolve(Book::class, 't0');

        $this->assertSame('t0.store_id = 42', $result);
    }


    public function testSetNullValueThrowsException(): void
    {
        $listener = $this->createListener([
            new StubValueHolder('storeId', '42'),
        ]);
        $listener->setValue('storeId', null);
        $resolver = new ConditionResolver($listener);

        $this->expectException(KeyValueException::class);
        $this->expectExceptionMessage('{storeId}');

        $resolver->resolve(Book::class, 't0');
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


    public function testNonPermissiveCoveredContextAppliesNormally(): void
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

        // NonPermissiveEntity: customer is covered and active → normal filters, no denial
        $result = $resolver->resolve(NonPermissiveEntity::class, 't0');

        $this->assertSame(
            't0.store_id = 42 AND t0.id IN(SELECT book_id FROM customer_purchase WHERE customer_id = 7)',
            $result,
        );
    }


    public function testNonPermissiveIgnoredContextIsCovered(): void
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

        // NonPermissiveEntity: staff is active and covered (listed via ignore: true filter, also via context-free) → no denial
        $result = $resolver->resolve(NonPermissiveEntity::class, 't0');

        $this->assertSame('t0.store_id = 42', $result);
    }


    public function testNonPermissiveContextFreeFilterCoversAllContexts(): void
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

        // NonPermissiveEntity has a context-free filter which, consistent with "applies to all",
        // also covers all contexts. Vendor is active and not explicitly listed, but the
        // context-free filter means there is nothing left uncovered to deny.
        $result = $resolver->resolve(NonPermissiveEntity::class, 't0');

        $this->assertSame('t0.store_id = 42', $result);
    }


    public function testNonPermissiveUncoveredContextDenied(): void
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

        // NonPermissiveNoContextFreeEntity has only contextual filters. Vendor is active but
        // not in any filter's context → denied.
        $result = $resolver->resolve(NonPermissiveNoContextFreeEntity::class, 't0');

        $this->assertSame('1 = 0', $result);
    }


    public function testNonPermissiveNoActiveContextUsesContextFreeFilters(): void
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

        // NonPermissiveEntity: no context active → only context-free filters, no denial
        $result = $resolver->resolve(NonPermissiveEntity::class, 't0');

        $this->assertSame('t0.store_id = 42', $result);
    }


    public function testNonPermissiveMultipleActiveContextsOneCovered(): void
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

        // NonPermissiveNoContextFreeEntity: customer covered, vendor NOT → denied
        $result = $resolver->resolve(NonPermissiveNoContextFreeEntity::class, 't0');

        $this->assertSame(
            't0.id IN(SELECT book_id FROM customer_purchase WHERE customer_id = 7) AND 1 = 0',
            $result,
        );
    }


    public function testNonPermissiveAmbientContextIsSkipped(): void
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

        // NonPermissiveNoContextFreeEntity: customer covered and active. Ambients (role, user)
        // active but skipped during coverage check. No denial.
        $result = $resolver->resolve(NonPermissiveNoContextFreeEntity::class, 't0');

        $this->assertSame(
            't0.id IN(SELECT book_id FROM customer_purchase WHERE customer_id = 7)',
            $result,
        );
    }


    public function testNonPermissiveAmbientContextDoesNotMaskUncovered(): void
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

        // NonPermissiveNoContextFreeEntity: role is ambient (skipped), customer covered,
        // but vendor is active and uncovered → denied
        $result = $resolver->resolve(NonPermissiveNoContextFreeEntity::class, 't0');

        $this->assertSame(
            't0.id IN(SELECT book_id FROM customer_purchase WHERE customer_id = 7) AND 1 = 0',
            $result,
        );
    }


    public function testPrivilegedContextSuppressesDenial(): void
    {
        $listener = $this->createListener(
            [],
            [
                new StubPrivilegedContextProvider('task', true),
                new StubContextProvider('customer', false),
                new StubContextProvider('publisher', false),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // NonPermissiveNoContextFreeEntity covers neither of the task's contexts, but a privileged
        // provider is not a scope the entity could have covered, so it is left out of the
        // census.  Without the privileged provider this same setup denies with 1 = 0.
        $result = $resolver->resolve(NonPermissiveNoContextFreeEntity::class, 't0');

        $this->assertSame('', $result);
    }


    public function testPrivilegedContextSkipsContextFreeFilter(): void
    {
        $listener = $this->createListener(
            [new StubValueHolder('storeId', '42')],
            [new StubPrivilegedContextProvider('task', true)],
        );
        $resolver = new ConditionResolver($listener);

        // Book has the one context-free filter, which applies to every tenant.  The task is
        // not one of them, so nothing is applied.
        $result = $resolver->resolve(Book::class, 't0');

        $this->assertSame('', $result);
    }


    public function testPrivilegedContextSkipsContextFreeFilterWhenNonPermissive(): void
    {
        $listener = $this->createListener(
            [new StubValueHolder('storeId', '42')],
            [
                new StubPrivilegedContextProvider('task', true),
                new StubContextProvider('staff', false),
                new StubContextProvider('customer', false),
                new StubContextProvider('publisher', false),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // NonPermissiveEntity's context-free filter is skipped, none of its contexts are the task's,
        // and no other context is active, so the task is unrestricted here
        $result = $resolver->resolve(NonPermissiveEntity::class, 't0');

        $this->assertSame('', $result);
    }


    public function testPrivilegedNamedFilterIsApplied(): void
    {
        $listener = $this->createListener(
            [
                new StubValueHolder('storeId', '42'),
                new StubValueHolder('publisherId', '15'),
                new StubValueHolder('customerId', '7'),
            ],
            [
                new StubPrivilegedContextProvider('task', true),
                new StubContextProvider('customer', true),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // PrivilegedScopedEntity names the task on a filter of its own, so that one applies.
        // The context-free filter is skipped, and so is the customer filter, contextual as it
        // is, since it says nothing about the task.
        $result = $resolver->resolve(PrivilegedScopedEntity::class, 't0');

        $this->assertSame('t0.publisher_id = 15', $result);
    }


    public function testPrivilegedNamedIgnoredFilterShortCircuitsUnderFirstMatch(): void
    {
        $listener = $this->createListener(
            [new StubValueHolder('storeId', '42')],
            [new StubPrivilegedContextProvider('task', true)],
        );
        $resolver = new ConditionResolver($listener);

        // PrivilegedIgnoredEntity: the ignored task filter comes first and matches, so
        // FirstMatch breaks there and the task filter behind it is never evaluated
        $result = $resolver->resolve(PrivilegedIgnoredEntity::class, 't0');

        $this->assertSame('', $result);
    }


    public function testPrivilegedContextFreeFilterNoLongerCoversOtherContexts(): void
    {
        $listener = $this->createListener(
            [],
            [
                new StubPrivilegedContextProvider('task', true),
                new StubContextProvider('staff', false),
                new StubContextProvider('customer', false),
                new StubContextProvider('publisher', false),
                new StubContextProvider('vendor', true),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // NonPermissiveEntity's context-free filter is not applied here, so it cannot stand in as
        // coverage either.  Vendor is active and named nowhere, and is denied as it would be
        // on an entity with no context-free filter at all.
        $result = $resolver->resolve(NonPermissiveEntity::class, 't0');

        $this->assertSame('1 = 0', $result);
    }


    public function testPrivilegedContextLeavesACoveredContextUnrestricted(): void
    {
        $listener = $this->createListener(
            [new StubValueHolder('customerId', '7')],
            [
                new StubPrivilegedContextProvider('task', true),
                new StubContextProvider('staff', false),
                new StubContextProvider('customer', true),
                new StubContextProvider('publisher', false),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // Customer is active and covered, so there is nothing to deny, and its filter is
        // skipped along with every other filter that says nothing about the task
        $result = $resolver->resolve(NonPermissiveEntity::class, 't0');

        $this->assertSame('', $result);
    }


    public function testWithoutAPrivilegedProviderNothingChanges(): void
    {
        $listener = $this->createListener(
            [
                new StubValueHolder('storeId', '42'),
                new StubValueHolder('publisherId', '15'),
                new StubValueHolder('customerId', '7'),
            ],
            [
                new StubContextProvider('task', false),
                new StubContextProvider('customer', true),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // The same entity, resolved for a customer with no privileged provider involved
        $result = $resolver->resolve(PrivilegedScopedEntity::class, 't0');

        $this->assertSame(
            't0.store_id = 42 AND t0.id IN(SELECT book_id FROM customer_purchase WHERE customer_id = 7)',
            $result,
        );
    }


    public function testNonContextualPrivilegedProviderChangesNothing(): void
    {
        $listener = $this->createListener(
            [
                new StubValueHolder('storeId', '42'),
                new StubValueHolder('publisherId', '15'),
                new StubValueHolder('customerId', '7'),
            ],
            [
                new StubPrivilegedContextProvider('task', false),
                new StubContextProvider('customer', true),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // Identical to the run above, privileged provider registered but not contextual
        $result = $resolver->resolve(PrivilegedScopedEntity::class, 't0');

        $this->assertSame(
            't0.store_id = 42 AND t0.id IN(SELECT book_id FROM customer_purchase WHERE customer_id = 7)',
            $result,
        );
    }


    public function testNonContextualPrivilegedProviderStillDeniesUncoveredContexts(): void
    {
        $listener = $this->createListener(
            [],
            [
                new StubPrivilegedContextProvider('task', false),
                new StubContextProvider('customer', false),
                new StubContextProvider('publisher', false),
                new StubContextProvider('vendor', true),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // Vendor is active and uncovered, and the registered privileged provider, contextual
        // to nothing, does not spare it
        $result = $resolver->resolve(NonPermissiveNoContextFreeEntity::class, 't0');

        $this->assertSame('1 = 0', $result);
    }


    public function testRestrictedContextDeniesAnUndeclaredEntity(): void
    {
        $listener = $this->createListener(
            [new StubValueHolder('storeId', '42')],
            [new StubRestrictedContextProvider('visitor', true)],
        );
        $resolver = new ConditionResolver($listener);

        // Book has the one context-free filter, which scopes the tenants it serves rather than
        // saying it serves a visitor.  Nothing names the visitor, so the store filter that would
        // otherwise have applied is displaced by a denial.
        $result = $resolver->resolve(Book::class, 't0');

        $this->assertSame('1 = 0', $result);
    }


    public function testRestrictedContextDeniesANonPermissiveEntity(): void
    {
        $listener = $this->createListener(
            [new StubValueHolder('storeId', '42')],
            [
                new StubRestrictedContextProvider('visitor', true),
                new StubContextProvider('staff', false),
                new StubContextProvider('customer', false),
                new StubContextProvider('publisher', false),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // The same denial on a non-permissive entity.  The flag has no say either way; a restricted
        // actor is denied by its own rule rather than by the coverage census.
        $result = $resolver->resolve(NonPermissiveEntity::class, 't0');

        $this->assertSame('1 = 0', $result);
    }


    public function testRestrictedContextDeniesAlongsideACoveredContext(): void
    {
        $listener = $this->createListener(
            [
                new StubValueHolder('storeId', '42'),
                new StubValueHolder('customerId', '7'),
            ],
            [
                new StubRestrictedContextProvider('visitor', true),
                new StubContextProvider('staff', false),
                new StubContextProvider('customer', true),
                new StubContextProvider('publisher', false),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // Customer is active and named, and would ordinarily contribute its clause.  The visitor
        // is named nowhere, and one restricted actor being contextual denies the whole query.
        $result = $resolver->resolve(NonPermissiveEntity::class, 't0');

        $this->assertSame('1 = 0', $result);
    }


    public function testRestrictedNamedFilterIsApplied(): void
    {
        $listener = $this->createListener(
            [
                new StubValueHolder('storeId', '42'),
                new StubValueHolder('customerId', '7'),
            ],
            [
                new StubRestrictedContextProvider('visitor', true),
                new StubContextProvider('customer', false),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // RestrictedScopedEntity names the visitor on a filter of its own, so it serves one, and
        // every filter then applies as it would for anybody else: the context-free one scopes
        // the store, the visitor's own filter scopes what a visitor may see, and the customer
        // filter is skipped for not being contextual.
        $result = $resolver->resolve(RestrictedScopedEntity::class, 't0');

        $this->assertSame('t0.store_id = 42 AND t0.is_published = 1', $result);
    }


    public function testRestrictedContextDoesNotDenyADisabledEntity(): void
    {
        $listener = $this->createListener(
            [],
            [new StubRestrictedContextProvider('visitor', true)],
        );
        $resolver = new ConditionResolver($listener);

        // ExternalCatalog has multi-tenancy disabled, which is an entity saying it is not
        // scoped by tenant at all.  There are no actors to serve there, so none to deny.
        $result = $resolver->resolve(ExternalCatalog::class, 't0');

        $this->assertSame('', $result);
    }


    public function testRestrictedNamedIgnoredFilterShortCircuitsUnderFirstMatch(): void
    {
        $listener = $this->createListener(
            [new StubValueHolder('storeId', '42')],
            [new StubRestrictedContextProvider('visitor', true)],
        );
        $resolver = new ConditionResolver($listener);

        // RestrictedIgnoredEntity: the ignored visitor filter comes first and matches, so
        // FirstMatch breaks there and the visitor filter behind it is never evaluated.  Naming
        // the context on an ignored filter is how an entity leaves the actor unrestricted.
        $result = $resolver->resolve(RestrictedIgnoredEntity::class, 't0');

        $this->assertSame('', $result);
    }


    public function testWithoutARestrictedProviderNothingChanges(): void
    {
        $listener = $this->createListener(
            [
                new StubValueHolder('storeId', '42'),
                new StubValueHolder('customerId', '7'),
            ],
            [
                new StubContextProvider('visitor', false),
                new StubContextProvider('customer', true),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // The same entity, resolved for a customer with no restricted provider involved
        $result = $resolver->resolve(RestrictedScopedEntity::class, 't0');

        $this->assertSame(
            't0.store_id = 42 AND t0.id IN(SELECT book_id FROM customer_purchase WHERE customer_id = 7)',
            $result,
        );
    }


    public function testNonContextualRestrictedProviderChangesNothing(): void
    {
        $listener = $this->createListener(
            [
                new StubValueHolder('storeId', '42'),
                new StubValueHolder('customerId', '7'),
            ],
            [
                new StubRestrictedContextProvider('visitor', false),
                new StubContextProvider('customer', true),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // Identical to the run above, restricted provider registered but not contextual
        $result = $resolver->resolve(RestrictedScopedEntity::class, 't0');

        $this->assertSame(
            't0.store_id = 42 AND t0.id IN(SELECT book_id FROM customer_purchase WHERE customer_id = 7)',
            $result,
        );
    }


    public function testRestrictedContextDeniesAlongsideAPrivilegedOne(): void
    {
        $listener = $this->createListener(
            [new StubValueHolder('storeId', '42')],
            [
                new StubPrivilegedContextProvider('task', true),
                new StubRestrictedContextProvider('visitor', true),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // Denial wins.  The task alone leaves Book unrestricted, resolving to an empty string,
        // and the visitor being contextual as well denies instead.
        $result = $resolver->resolve(Book::class, 't0');

        $this->assertSame('1 = 0', $result);
    }


    public function testRestrictedContextIsServedAlongsideAPrivilegedOne(): void
    {
        $listener = $this->createListener(
            [
                new StubValueHolder('storeId', '42'),
                new StubValueHolder('customerId', '7'),
            ],
            [
                new StubPrivilegedContextProvider('task', true),
                new StubRestrictedContextProvider('visitor', true),
                new StubContextProvider('customer', false),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // Denial winning makes the actor the restricted one throughout, so this resolves exactly
        // as it does for a visitor on its own.  Were it the privileged actor instead, every
        // filter that says nothing about the task would have been skipped, leaving an empty
        // string and an unrestricted query.
        $result = $resolver->resolve(RestrictedScopedEntity::class, 't0');

        $this->assertSame('t0.store_id = 42 AND t0.is_published = 1', $result);
    }


    public function testPermissiveEntityIsUnrestrictedByAnUncoveredContext(): void
    {
        $listener = $this->createListener(
            [new StubValueHolder('customerId', '7')],
            [
                new StubContextProvider('customer', true),
                new StubContextProvider('publisher', false),
                new StubContextProvider('vendor', true),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // PermissiveEntity is NonPermissiveNoContextFreeEntity with the flag the other way up.
        // Vendor is active and named nowhere, and is served rather than denied.  The identical
        // setup on the non-permissive entity appends 1 = 0.
        $result = $resolver->resolve(PermissiveEntity::class, 't0');

        $this->assertSame(
            't0.id IN(SELECT book_id FROM customer_purchase WHERE customer_id = 7)',
            $result,
        );
    }


    public function testLegacyStrictArgumentDeniesAndIsDeprecated(): void
    {
        $listener = $this->createListener(
            [new StubValueHolder('customerId', '7')],
            [
                new StubContextProvider('customer', true),
                new StubContextProvider('publisher', false),
                new StubContextProvider('vendor', true),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // strict: true still resolves exactly as permissive: false does, and says so
        $result = null;
        $deprecations = $this->collectDeprecations(function () use ($resolver, &$result): void {
            $result = $resolver->resolve(LegacyStrictEntity::class, 't0');
        });

        $this->assertSame(
            't0.id IN(SELECT book_id FROM customer_purchase WHERE customer_id = 7) AND 1 = 0',
            $result,
        );
        $this->assertCount(1, $deprecations);
        $this->assertStringContainsString('"strict" argument is deprecated', $deprecations[0]);
        $this->assertStringContainsString('Use "permissive: false"', $deprecations[0]);
    }


    public function testPermissiveDefaultOfFalseDeniesAnEntityDeclaringNeitherWay(): void
    {
        $listener = $this->createListener(
            [new StubValueHolder('customerId', '7')],
            [
                new StubContextProvider('customer', true),
                new StubContextProvider('publisher', false),
                new StubContextProvider('vendor', true),
            ],
        );
        $listener->setPermissiveDefault(false);
        $resolver = new ConditionResolver($listener);

        $result = null;
        $deprecations = $this->collectDeprecations(function () use ($resolver, &$result): void {
            $result = $resolver->resolve(UndeclaredEntity::class, 't0');
        });

        $this->assertSame(
            't0.id IN(SELECT book_id FROM customer_purchase WHERE customer_id = 7) AND 1 = 0',
            $result,
        );
        $this->assertSame([], $deprecations);
    }


    public function testPermissiveDefaultOfTrueServesAnEntityDeclaringNeitherWay(): void
    {
        $listener = $this->createListener(
            [new StubValueHolder('customerId', '7')],
            [
                new StubContextProvider('customer', true),
                new StubContextProvider('publisher', false),
                new StubContextProvider('vendor', true),
            ],
        );
        $listener->setPermissiveDefault(true);
        $resolver = new ConditionResolver($listener);

        // The same entity, the same fall-through, and a consumer who has said this is what they
        // want.  Nothing to warn about, now or in 3.0.
        $result = null;
        $deprecations = $this->collectDeprecations(function () use ($resolver, &$result): void {
            $result = $resolver->resolve(UndeclaredEntity::class, 't0');
        });

        $this->assertSame(
            't0.id IN(SELECT book_id FROM customer_purchase WHERE customer_id = 7)',
            $result,
        );
        $this->assertSame([], $deprecations);
    }


    public function testFallThroughIsDeprecatedWhileNoDefaultHasBeenSet(): void
    {
        $listener = $this->createListener(
            [new StubValueHolder('customerId', '7')],
            [
                new StubContextProvider('customer', true),
                new StubContextProvider('publisher', false),
                new StubContextProvider('vendor', true),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // No decision made, so the entity falls through on a default that is about to change,
        // and the notice names what fell through and where
        $result = null;
        $deprecations = $this->collectDeprecations(function () use ($resolver, &$result): void {
            $result = $resolver->resolve(UndeclaredEntity::class, 't0');
        });

        $this->assertSame(
            't0.id IN(SELECT book_id FROM customer_purchase WHERE customer_id = 7)',
            $result,
        );
        $this->assertCount(1, $deprecations);
        $this->assertStringContainsString(
            'UndeclaredEntity resolved with no filter covering the active "vendor" context',
            $deprecations[0],
        );
        $this->assertStringContainsString('Listener::setPermissiveDefault()', $deprecations[0]);
    }


    public function testFallThroughIsNotDeprecatedForAnEntityDeclaringItselfPermissive(): void
    {
        $listener = $this->createListener(
            [new StubValueHolder('customerId', '7')],
            [
                new StubContextProvider('customer', true),
                new StubContextProvider('publisher', false),
                new StubContextProvider('vendor', true),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // The entity has made the choice itself and resolves the same way in 3.0, so there is
        // nothing for it to act on
        $deprecations = $this->collectDeprecations(function () use ($resolver): void {
            $resolver->resolve(PermissiveEntity::class, 't0');
        });

        $this->assertSame([], $deprecations);
    }


    public function testFallThroughIsDeprecatedOncePerEntityAndContextPair(): void
    {
        $listener = $this->createListener(
            [],
            [
                new StubContextProvider('staff', false),
                new StubContextProvider('customer', false),
                new StubContextProvider('publisher', false),
                new StubContextProvider('vendor', true),
                new StubContextProvider('guest', true),
            ],
        );
        $resolver = new ConditionResolver($listener);

        // Two uncovered contexts on one entity is two notices, and running the same query again
        // is none: what a consumer wants is the migration list, not a line per query
        $deprecations = $this->collectDeprecations(function () use ($resolver): void {
            $resolver->resolve(UndeclaredEntity::class, 't0');
            $resolver->resolve(UndeclaredEntity::class, 't0');
            $resolver->resolve(Review::class, 't0');
            $resolver->resolve(Review::class, 't0');
        });

        $this->assertCount(4, $deprecations);
        $this->assertSame(
            [
                'UndeclaredEntity::vendor',
                'UndeclaredEntity::guest',
                'Review::vendor',
                'Review::guest',
            ],
            array_map($this->summarizeFallThrough(...), $deprecations),
        );
    }
}
