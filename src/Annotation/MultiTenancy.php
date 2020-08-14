<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy\Annotation;

use Doctrine\Common\Annotations\Annotation;
use Doctrine\Common\Annotations\Annotation\Target;

/**
 * Annotation service for Multi-tenancy
 *
 * @Annotation
 * @Target({"CLASS"})
 *
 * @author Jacob Thomason <jacob@rentpost.com>
 */
final class MultiTenancy
{

    protected bool $isEnabled;
    protected array $filters;


    /**
     * Constructor.
     *
     * @param string[] $values
     */
    public function __construct(array $values)
    {
        $this->enable = $values['enable'] ?? $values['enabled'] ?? true;
        $this->filters = $values['filters'] ?? [];
    }


    /**
     * Checks if MultiTenancy is enabled
     */
    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }


    /**
     * Gets the filter string that's used in the actual SQLFilter
     *
     * @return string[]
     */
    public function getFilters(): array
    {
        return $this->filters;
    }
}
