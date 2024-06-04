<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy;

use Attribute;

/**
 * Attribute service for Multi-tenancy filters
 *
 * @author Jacob Thomason <jacob@rentpost.com>
 */

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]

final class Filter
{

    /**
     * Constructor
     *
     * @param string[] $context     The contexts for which to apply the filter
     * @param string $where         The SQL where clause (use $this, for the current Entity's table)
     * @param bool $ignore          Whether to ignore this filter and not apply any conditions
     */
    public function __construct(
        private readonly array $context = [],
        private readonly string $where = '',
        private bool $ignore = false,
    ) {}


    /**
     * Gets the filter context
     *
     * @return string[]
     */
    public function getContext(): array
    {
        return $this->context;
    }


    /**
     * Gets the SQL where clause
     */
    public function getWhereClause(): string
    {
        return $this->where;
    }


    /**
     * Whether this filter is ignored and won't apply any conditions
     */
    public function isIgnored(): bool
    {
        return $this->ignore;
    }
}
