<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy\Attribute;

use Attribute;

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
    public function __construct(protected array $filters = [], protected bool $enable = true) {}


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
}
