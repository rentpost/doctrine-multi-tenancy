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
     * @param string[] $context
     */
    public function __construct(protected array $context = [], protected string $where = '') {}


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
}
