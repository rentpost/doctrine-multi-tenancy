<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy\Annotation\MultiTenancy;

use Doctrine\Common\Annotations\Annotation;
use Doctrine\Common\Annotations\Annotation\Target;

/**
 * Annotation service for Multi-tenancy filters
 *
 * @Annotation
 * @Target({"ANNOTATION"})
 *
 * @author Jacob Thomason <jacob@rentpost.com>
 */
final class Filter
{

    protected array $context;
    protected string $whereClause;


    /**
     * Constructor.
     *
     * @param string[] $values
     */
    public function __construct(array $values)
    {
        $this->context = $values['context'] ?? [];
        $this->whereClause = $values['where'] ?? '';
    }


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
        return $this->whereClause;
    }
}
