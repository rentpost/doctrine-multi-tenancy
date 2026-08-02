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
     * Gets the identifier of every placeholder used in the given where clause
     *
     * @return string[]
     */
    private function getPlaceholderIdentifiers(string $whereClause): array
    {
        \preg_match_all('/\{([^{}]+)\}/', $whereClause, $matches);

        return \array_unique($matches[1]);
    }


    /**
     * Gets the identifiers and values array maps for the placeholders a filter uses
     *
     * Values come from those set on the Listener and from nowhere else.  ValueHolders are not
     * read here; they are producers, invoked only by Listener::refreshValues(), so that every
     * value a condition can be built from has already been declared to Doctrine.
     *
     * @return string[][]
     *
     * @throws KeyValueException When a placeholder's identifier has no value set
     */
    private function getIdentifiersAndValues(FilterAttribute $filter, string $entityClassName): array
    {
        $setValues = $this->listener->getValues();
        $identifiers = [];
        $values = [];
        foreach ($this->getPlaceholderIdentifiers($filter->getWhereClause()) as $identifier) {
            if (!\array_key_exists($identifier, $setValues)) {
                throw new KeyValueException(\sprintf(
                    'Filter identifier, {%s}, in "%s" where clause, for %s, has no value set. ' .
                    'Call Listener::refreshValues() whenever the context changes, or set the ' .
                    'value for this identifier with Listener::setValue().',
                    $identifier,
                    $filter->getWhereClause(),
                    $entityClassName,
                ));
            }

            $value = $setValues[$identifier];

            if ($value === null) {
                throw new KeyValueException(\sprintf(
                    'Filter identifier, {%s}, in "%s" where clause, for %s, evaluates to null. ' .
                    'Ensure a value has been set for the identifier before the filter is applied.',
                    $identifier,
                    $filter->getWhereClause(),
                    $entityClassName,
                ));
            }

            $identifiers[] = '/\{' . $identifier . '\}/';
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
     * Gets the identifier of every privileged context provider that is currently contextual
     *
     * A privileged provider represents an actor outside of tenancy rather than a scope within
     * it, so while one of them is contextual the entity decides what applies to it, filter by
     * filter.  See PrivilegedContextProviderInterface.
     *
     * @return string[]
     */
    private function getPrivilegedContexts(): array
    {
        $privilegedContexts = [];
        foreach ($this->listener->getContextProviders() as $contextProvider) {
            if (!$contextProvider instanceof PrivilegedContextProviderInterface) {
                continue;
            }

            if ($contextProvider->isContextual()) {
                $privilegedContexts[] = $contextProvider->getIdentifier();
            }
        }

        return $privilegedContexts;
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
     * Checks whether a filter applies, given whichever privileged contexts are contextual
     *
     * With none of them contextual, nothing changes: a filter applies whenever any of its
     * contexts is contextual, a context-free one always.  With one of them contextual, only a
     * filter naming a privileged context applies, context-free filters included in what gets
     * skipped, so the actor carries no tenancy scoping beyond what the entity asks of it.
     *
     * @param string[] $privilegedContexts
     */
    private function isApplicable(FilterAttribute $filter, array $privilegedContexts): bool
    {
        if (!$this->isContextual($filter->getContext())) {
            return false;
        }

        if (!count($privilegedContexts)) {
            return true;
        }

        return (bool) count(array_intersect($filter->getContext(), $privilegedContexts));
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
     * @throws KeyValueException   If a placeholder's identifier has no value set on the Listener
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
        $privilegedContexts = $this->getPrivilegedContexts();
        foreach ($filters as $filter) {
            assert($filter instanceof FilterAttribute);

            if (!$this->isApplicable($filter, $privilegedContexts)) {
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
                ...$this->getIdentifiersAndValues($filter, $entityClass),
            );

            $whereClauses[] = $this->parseWhereClause($filter->getWhereClause(), $identifiers, $values);

            if ($multiTenancy->getFilterStrategy() === FilterStrategy::FirstMatch) {
                break;
            }
        }

        if ($multiTenancy->isStrict() && $this->hasUncoveredContexts($filters, $privilegedContexts)) {
            $whereClauses[] = '1 = 0';
        }

        return implode(' AND ', $whereClauses);
    }


    /**
     * Checks whether any active context is not covered by a filter's context array.
     *
     * A filter with no context array (context-free) is considered to apply to all
     * contexts — consistent with query-application semantics — so it covers every
     * active context for the purpose of the strict coverage check.  That holds only for as
     * long as it is applied: while a privileged context is contextual, context-free filters
     * are skipped, and a filter that is not applied cannot stand in as coverage for anything.
     * The remaining contexts are then censused as they always are, so a context that is
     * contextual and named nowhere is still denied.
     *
     * Privileged providers are themselves excluded from the census, as ambient ones are.  An
     * actor outside of tenancy is not a scope the entity could have covered.
     *
     * @param FilterAttribute[] $filters
     * @param string[] $privilegedContexts
     */
    private function hasUncoveredContexts(array $filters, array $privilegedContexts): bool
    {
        $coveredContexts = [];
        foreach ($filters as $filter) {
            if (!count($filter->getContext())) {
                if (!count($privilegedContexts)) {
                    return false;
                }

                continue;
            }

            foreach ($filter->getContext() as $context) {
                $coveredContexts[$context] = true;
            }
        }

        foreach ($this->listener->getContextProviders() as $contextProvider) {
            if ($contextProvider instanceof AmbientContextProviderInterface) {
                continue;
            }

            if ($contextProvider instanceof PrivilegedContextProviderInterface) {
                continue;
            }

            if ($contextProvider->isContextual() && !isset($coveredContexts[$contextProvider->getIdentifier()])) {
                return true;
            }
        }

        return false;
    }
}
