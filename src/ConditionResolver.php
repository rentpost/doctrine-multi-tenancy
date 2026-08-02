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
 * used independently, e.g. in raw SQL repository queries that bypass Doctrine's DQL layer.
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
     * Gets the identifier of every contextual provider implementing the given marker interface
     *
     * @param class-string<ContextProviderInterface> $markerInterface
     *
     * @return string[]
     */
    private function getContextualIdentifiers(string $markerInterface): array
    {
        $identifiers = [];
        foreach ($this->listener->getContextProviders() as $contextProvider) {
            if (!$contextProvider instanceof $markerInterface) {
                continue;
            }

            if ($contextProvider->isContextual()) {
                $identifiers[] = $contextProvider->getIdentifier();
            }
        }

        return $identifiers;
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
        return $this->getContextualIdentifiers(PrivilegedContextProviderInterface::class);
    }


    /**
     * Gets the identifier of every restricted context provider that is currently contextual
     *
     * A restricted provider represents an actor an entity has to serve deliberately, so while
     * one of them is contextual the entity either names it on a filter or denies it outright.
     * See RestrictedContextProviderInterface.
     *
     * @return string[]
     */
    private function getRestrictedContexts(): array
    {
        return $this->getContextualIdentifiers(RestrictedContextProviderInterface::class);
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

        $privilegedContexts = $this->getPrivilegedContexts();
        $restrictedContexts = $this->getRestrictedContexts();

        if (count($restrictedContexts)) {
            if (!$this->hasRestrictedCoverage($filters, $restrictedContexts)) {
                return '1 = 0';
            }

            // Denial wins where an actor is privileged and restricted at once.  This entity
            // names the restricted context, so it serves the actor, and it does so on the same
            // terms as any other: nothing here is exempt from a filter the way a privileged
            // actor is.
            $privilegedContexts = [];
        }

        $whereClauses = [];
        $defaultMap = $this->getDefaultMap($tableAlias);
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

        $permissive = $multiTenancy->isPermissive();
        $uncoveredContexts = $this->getUncoveredContexts($filters, $privilegedContexts);

        if (count($uncoveredContexts)) {
            if (!($permissive ?? $this->listener->isPermissiveDefault())) {
                $whereClauses[] = '1 = 0';
            } elseif ($permissive === null) {
                // Only an entity declaring neither way is falling through on a default that is
                // about to change.  One declaring itself permissive resolves the same way in 3.0
                // and has nothing to be warned about.
                foreach ($uncoveredContexts as $context) {
                    $this->listener->deprecatePermissiveFallThrough($entityClass, $context);
                }
            }
        }

        return implode(' AND ', $whereClauses);
    }


    /**
     * Checks whether any filter names one of the restricted contexts that are contextual
     *
     * Naming a context is how an entity declares the actors it serves, so naming is all that is
     * looked for here, not what the filter goes on to do.  An ignore: true filter counts as much
     * as one with a where clause, that being how an entity says the actor is unrestricted on it,
     * and so does a filter FirstMatch never reaches, the entity having said who it serves either
     * way.
     *
     * A context-free filter names nothing and so covers nothing.  It scopes whichever tenants an
     * entity serves, and whether the restricted actor is one of them is the question being asked.
     *
     * @param FilterAttribute[] $filters
     * @param string[] $restrictedContexts
     */
    private function hasRestrictedCoverage(array $filters, array $restrictedContexts): bool
    {
        foreach ($filters as $filter) {
            assert($filter instanceof FilterAttribute);

            if (count(array_intersect($filter->getContext(), $restrictedContexts))) {
                return true;
            }
        }

        return false;
    }


    /**
     * Gets every active context that no filter's context array names
     *
     * These are the actors the entity has not declared, which it either denies or, being
     * permissive, serves unrestricted.
     *
     * A filter with no context array (context-free) is considered to apply to all contexts,
     * consistent with query-application semantics, so it covers every active context.  That
     * holds only for as long as it is applied: while a privileged context is contextual,
     * context-free filters are skipped, and a filter that is not applied cannot stand in as
     * coverage for anything.  The remaining contexts are then censused as they always are, so a
     * context that is contextual and named nowhere is still uncovered.
     *
     * Privileged providers are themselves excluded from the census, as ambient ones are.  An
     * actor outside of tenancy is not a scope the entity could have covered.
     *
     * @param FilterAttribute[] $filters
     * @param string[] $privilegedContexts
     *
     * @return string[]
     */
    private function getUncoveredContexts(array $filters, array $privilegedContexts): array
    {
        $coveredContexts = [];
        foreach ($filters as $filter) {
            if (!count($filter->getContext())) {
                if (!count($privilegedContexts)) {
                    return [];
                }

                continue;
            }

            foreach ($filter->getContext() as $context) {
                $coveredContexts[$context] = true;
            }
        }

        $uncoveredContexts = [];
        foreach ($this->listener->getContextProviders() as $contextProvider) {
            if ($contextProvider instanceof AmbientContextProviderInterface) {
                continue;
            }

            if ($contextProvider instanceof PrivilegedContextProviderInterface) {
                continue;
            }

            if ($contextProvider->isContextual() && !isset($coveredContexts[$contextProvider->getIdentifier()])) {
                $uncoveredContexts[] = $contextProvider->getIdentifier();
            }
        }

        return $uncoveredContexts;
    }
}
