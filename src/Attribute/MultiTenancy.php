<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy\Attribute;

use Attribute;
use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy\FilterStrategy;
use Rentpost\Doctrine\MultiTenancy\AttributeException;

/**
 * Attribute service for Multi-tenancy
 *
 * @author Jacob Thomason <jacob@rentpost.com>
 */

#[Attribute(Attribute::TARGET_CLASS)]

final class MultiTenancy
{

    private readonly ?bool $permissive;


    /**
     * Constructor
     *
     * An entity declares the actors it serves by naming their contexts on its filters, and
     * $permissive says what becomes of an actor it has not declared: true serves it unrestricted,
     * false denies it.  Declaring neither leaves that to Listener::setPermissiveDefault().
     *
     * $strict is the deprecated spelling of the same thing, inverted, kept for one release.  It
     * holds its place in the signature so that positional use keeps its meaning.
     *
     * @param MultiTenancy\Filter[] $filters
     *
     * @throws AttributeException  When both $strict and $permissive are given
     */
    public function __construct(
        private readonly bool $enable = true,
        private readonly array $filters = [],
        private readonly FilterStrategy $strategy = FilterStrategy::AnyMatch,
        ?bool $strict = null,
        ?bool $permissive = null,
    ) {
        if ($strict !== null && $permissive !== null) {
            throw new AttributeException(
                'The MultiTenancy attribute was given both "strict" and "permissive", which are ' .
                'inverses of one another. Use "permissive" on its own; "strict" is deprecated.',
            );
        }

        if ($strict !== null) {
            trigger_error(sprintf(
                'The MultiTenancy attribute\'s "strict" argument is deprecated and is removed in ' .
                '3.0. Use "permissive: %s" instead.',
                $strict ? 'false' : 'true',
            ), E_USER_DEPRECATED);
        }

        $this->permissive = $permissive ?? ($strict === null ? null : !$strict);
    }


    /**
     * Checks if MultiTenancy is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enable;
    }


    /**
     * Gets the filters associated with this attribute
     *
     * @return MultiTenancy\Filter[]
     */
    public function getFilters(): array
    {
        return $this->filters;
    }


    /**
     * Gets the filter strategy
     */
    public function getFilterStrategy(): FilterStrategy
    {
        return $this->strategy;
    }


    /**
     * Whether an actor this entity has not declared is served unrestricted
     *
     * Null where the entity declares neither way, the default held by the Listener applying to it
     * instead.  That is a state of its own rather than a permissive one: an entity that declares
     * itself permissive keeps that behaviour whatever the default becomes.
     *
     * Works alongside any FilterStrategy.
     */
    public function isPermissive(): ?bool
    {
        return $this->permissive;
    }


    /**
     * Whether uncovered active contexts are denied (appending 1 = 0)
     *
     * @deprecated Use isPermissive(), which tells an entity that has declared nothing apart from
     *             one that has declared itself permissive.  Removed in 3.0.
     */
    public function isStrict(): bool
    {
        return $this->permissive === false;
    }
}
