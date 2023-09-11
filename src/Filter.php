<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy;
use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy\Filter as FilterAttribute;
use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy\FilterStrategy;

/**
 * Multi-tenancy filter to handle filtering by the company
 *
 * @author Jacob Thomason <jacob@rentpost.com>
 */
class Filter extends SQLFilter
{

    protected ?Listener $listener = null;
    protected ?EntityManagerInterface $entityManager = null;


    /**
     * Gets the EntityManager.
     *
     * @note we have to do this since the $em is private in the SQLFilter class!
     */
    protected function getEntityManager(): EntityManagerInterface
    {
        if ($this->entityManager === null) {
            $refl = new \ReflectionProperty('Doctrine\ORM\Query\Filter\SQLFilter', 'em');
            $refl->setAccessible(true);
            $this->entityManager = $refl->getValue($this);
        }

        return $this->entityManager;
    }


    /**
     * Gets the MultiTenancy listener which carries our ValueHolders
     */
    protected function getListener(): Listener
    {
        if ($this->listener instanceof Listener) {
            return $this->listener;
        }

        $evm = $this->getEntityManager()->getEventManager();
        foreach ($evm->getListeners() as $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof Listener) {
                    $this->listener = $listener;

                    return $listener;
                }
            }
        }

        throw new FilterException(
            'Listener "MultiTenancy\Listener" was not added to the EventManager!',
        );
    }


    /**
     * The default map are things we want to replace in the filter string by default.  These
     * are syntax constrcuts.  Merge in our defaultMapping last, making sure this isn't cached
     * since we don't want the $targetTableAlias to be cached as the first value.
     *
     * @return string[][]
     */
    protected function getDefaultMap(string $targetTableAlias): array
    {
        return [
            '/\$this/' => $targetTableAlias,
            '/\\n\s+\*/' => '', // Regex pattern to replace: \n *
        ];
    }


    /**
     * Gets the identifiers and values array maps from the ValueHolders
     *
     * @return string[][]
     */
    protected function getValueHolderIdentifiersAndValues(FilterAttribute $filter): array
    {
        $identifiers = [];
        $values = [];
        foreach ($this->getListener()->getValueHolders() as $valueHolder) {
            assert($valueHolder instanceof ValueHolderInterface);

            // Only build values for the identifiers in the filter where clause if it contains the
            // identifier.  Otherwise it's not needed and in some scopes may not be available at all.
            if (!\str_contains($filter->getWhereClause(), $valueHolder->getIdentifier())) {
                continue;
            }

            $identifiers[] = '/\{' . $valueHolder->getIdentifier() . '\}/';
            $values[] = $valueHolder->getValue();
        }

        return [$identifiers, $values];
    }


    /**
     * Gets the merged maps
     *
     * @param string[] $defaultMap
     * @param string[][] $identifiers
     * @param string[][] $values
     *
     * @return string[][]
     */
    protected function getMergedMaps(array $defaultMap, array $identifiers, array $values): array
    {
        return [
            array_merge(array_keys($defaultMap), $identifiers),
            array_merge(array_values($defaultMap), $values),
        ];
    }


    /**
     * Checks to see if the given context is considered to be in context, or contexual
     *
     * @param string[] $context   An array of all the contexts that apply
     */
    protected function isContextual(array $context): bool
    {
        // If we don't have any contexts, it applies to all by default
        if (!count($context)) {
            return true;
        }

        foreach ($context as $c) {
            $contextProvider = $this->getListener()->getContextProvider($c);
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
    protected function parseWhereClause(string $filter, array $identifiers, array $values): string
    {
        return \preg_replace($identifiers, $values, $filter);
    }


    /**
     * Adds a SQL query filter based on the attribute syntax and the ValueHolders values
     * supplied to the MultiTenancy\Listener and identifier by their "identifier".
     *
     * These ValueHolders expose a string value that can be used within the attribute syntax.
     *
     * @param string $targetTableAlias
     */
    public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias)
    {
        // If we're explicitly disabling multi-tenancy, there is nothing to do here
        if (!$this->getEntityManager()->getFilters()->isEnabled('multi-tenancy')) {
            return '';
        }

        $attributes = $targetEntity->reflClass->getAttributes(MultiTenancy::class);

        // If no attributes have been defined, this is an issue. For security reasons, we want
        // to ensure that the entity has explicitly been disabled for multi-tenancy.
        if (count($attributes) === 0) {
            throw new AttributeException(sprintf(
                '%s must have the MultiTenancy attribute added to the class docblock.',
                $targetEntity->rootEntityName,
            ));
        }

        $multiTenancy = $attributes[0]->newInstance();
        assert($multiTenancy instanceof MultiTenancy);

        // Check to see if multi-tenancy is enabled on the entity
        if (!$multiTenancy->isEnabled()) {
            return '';
        }

        $filters = $multiTenancy->getFilters();
        if (!$filters) {
            throw new AttributeException(sprintf(
                '%s is enabled for MultiTenancy, but there were not any added filters.',
                $targetEntity->rootEntityName,
            ));
        }

        $whereClauses = [];
        $defaultMap = $this->getDefaultMap($targetTableAlias);
        foreach ($filters as $filter) {
            assert($filter instanceof FilterAttribute);

            if (!$this->isContextual($filter->getContext())) {
                continue;
            }

            [$identifiers, $values] = $this->getMergedMaps(
                $defaultMap,
                ...$this->getValueHolderIdentifiersAndValues($filter),
            );

            $whereClauses[] = $this->parseWhereClause($filter->getWhereClause(), $identifiers, $values);

            // At this point we've processed at least one contextual filter.  For a FirstMatch filter
            // strategy, this is where we break
            if ($multiTenancy->getFilterStrategy() === FilterStrategy::FirstMatch) {
                break;
            }
        }

        return implode(' AND ', $whereClauses);
    }
}
