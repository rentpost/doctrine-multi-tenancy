<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy\Attribute;

use Attribute;
use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy\FilterStrategy;

/**
 * Attribute service for Multi-tenancy
 *
 * @author Jacob Thomason <jacob@rentpost.com>
 */

#[Attribute(Attribute::TARGET_CLASS)]

final class MultiTenancy
{

    /**
     * Constructor
     *
     * @param MultiTenancy\Filter[] $filters
     */
    public function __construct(
        private bool $enable = true,
        private array $filters = [],
        private FilterStrategy $filterStrategy = FilterStrategy::AnyMatch,
    ) {}


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
        return $this->filterStrategy;
    }
}
