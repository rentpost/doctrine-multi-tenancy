<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy;

use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy;
use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy\Filter as FilterAttribute;
use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy\FilterStrategy;

/**
 * Resolves multi-tenancy SQL conditions for a given entity class.
 *
 * This class extracts the SQL generation logic from the Doctrine SQLFilter so it can be
 * used independently — e.g. in raw SQL repository queries that bypass Doctrine's DQL layer.
 *
 * @author Jacob Thomason <jacob@rentpost.com>
 */
class ConditionResolver
{

    public function __construct(
        private readonly Listener $listener,
    ) {}


    /**
     * The default map of syntax constructs to replace in filter strings
     *
     * @return string[]
     */
    private function getDefaultMap(string $tableAlias): array
    {
        return [
            '/\$this/' => $tableAlias,
            '/\\n\s+\*/' => '',
        ];
    }


    /**
     * Gets the identifiers and values array maps from the ValueHolders
     *
     * @return string[][]
     *
     * @throws KeyValueException When a filter contains an identifier but the ValueHolder has no value
     */
    private function getValueHolderIdentifiersAndValues(FilterAttribute $filter, string $entityClassName): array
    {
        $identifiers = [];
        $values = [];
        foreach ($this->listener->getValueHolders() as $valueHolder) {
            assert($valueHolder instanceof ValueHolderInterface);

            $placeholder = '{' . $valueHolder->getIdentifier() . '}';
            if (!\str_contains($filter->getWhereClause(), $placeholder)) {
                continue;
            }

            $value = $valueHolder->getValue();

            if ($value === null) {
                throw new KeyValueException(\sprintf(
                    'Filter identifier, {%s}, in "%s" where clause, for %s, evaluates to null. ' .
                    'Ensure the ValueHolder has a value set before the filter is applied.',
                    $valueHolder->getIdentifier(),
                    $filter->getWhereClause(),
                    $entityClassName,
                ));
            }

            $identifiers[] = '/\{' . $valueHolder->getIdentifier() . '\}/';
            $values[] = $value;
        }

        return [$identifiers, $values];
    }


    /**
     * Gets the merged maps
     *
     * @param string[] $defaultMap
     * @param string[] $identifiers
     * @param string[] $values
     *
     * @return string[][]
     */
    private function getMergedMaps(array $defaultMap, array $identifiers, array $values): array
    {
        return [
            array_merge(array_keys($defaultMap), $identifiers),
            array_merge(array_values($defaultMap), $values),
        ];
    }


    /**
     * Checks to see if the given context is considered to be in context, or contextual
     *
     * @param string[] $context   An array of all the contexts that apply
     */
    private function isContextual(array $context): bool
    {
        if (!count($context)) {
            return true;
        }

        foreach ($context as $c) {
            $contextProvider = $this->listener->getContextProvider($c);
            if ($contextProvider->isContextual()) {
                return true;
            }
        }

        return false;
    }


    /**
     * Parses the attribute where clause, replacing identifiers with values
     *
     * @param string[] $identifiers
     * @param string[] $values
     */
    private function parseWhereClause(string $filter, array $identifiers, array $values): string
    {
        return \preg_replace($identifiers, $values, $filter);
    }


    /**
     * Resolves the multi-tenancy WHERE conditions for the given entity class.
     *
     * @param class-string $entityClass   The fully-qualified entity class name
     * @param string       $tableAlias    The SQL table alias to substitute for $this
     *
     * @return string  The WHERE clause fragment (empty string if disabled or no applicable filters)
     *
     * @throws AttributeException  If entity lacks the #[MultiTenancy] attribute
     * @throws KeyValueException   If a required ValueHolder has no value
     */
    public function resolve(string $entityClass, string $tableAlias): string
    {
        $reflClass = new \ReflectionClass($entityClass);
        $attributes = $reflClass->getAttributes(MultiTenancy::class);

        if (count($attributes) === 0) {
            throw new AttributeException(sprintf(
                '%s must have the MultiTenancy attribute added to the class docblock.',
                $entityClass,
            ));
        }

        $multiTenancy = $attributes[0]->newInstance();
        assert($multiTenancy instanceof MultiTenancy);

        if (!$multiTenancy->isEnabled()) {
            return '';
        }

        $filters = $multiTenancy->getFilters();
        if (!$filters) {
            throw new AttributeException(sprintf(
                '%s is enabled for MultiTenancy, but there were not any added filters.',
                $entityClass,
            ));
        }

        $whereClauses = [];
        $defaultMap = $this->getDefaultMap($tableAlias);
        foreach ($filters as $filter) {
            assert($filter instanceof FilterAttribute);

            if (!$this->isContextual($filter->getContext())) {
                continue;
            }

            if ($filter->isIgnored()) {
                if ($multiTenancy->getFilterStrategy() === FilterStrategy::FirstMatch) {
                    break;
                }

                continue;
            }

            [$identifiers, $values] = $this->getMergedMaps(
                $defaultMap,
                ...$this->getValueHolderIdentifiersAndValues($filter, $entityClass),
            );

            $whereClauses[] = $this->parseWhereClause($filter->getWhereClause(), $identifiers, $values);

            if ($multiTenancy->getFilterStrategy() === FilterStrategy::FirstMatch) {
                break;
            }
        }

        if ($multiTenancy->getFilterStrategy() === FilterStrategy::Strict
            && $this->hasUncoveredContexts($filters)
        ) {
            $whereClauses[] = '1 = 0';
        }

        return implode(' AND ', $whereClauses);
    }


    /**
     * Checks whether any active context is not covered by a filter's context array
     *
     * @param FilterAttribute[] $filters
     */
    private function hasUncoveredContexts(array $filters): bool
    {
        $coveredContexts = [];
        foreach ($filters as $filter) {
            foreach ($filter->getContext() as $context) {
                $coveredContexts[$context] = true;
            }
        }

        foreach ($this->listener->getContextProviders() as $contextProvider) {
            if ($contextProvider instanceof AmbientContextProviderInterface) {
                continue;
            }

            if ($contextProvider->isContextual() && !isset($coveredContexts[$contextProvider->getIdentifier()])) {
                return true;
            }
        }

        return false;
    }
}
