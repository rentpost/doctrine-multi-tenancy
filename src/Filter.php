<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\Reader;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Rentpost\Doctrine\MultiTenancy\Annotation\MultiTenancy;

/**
 * Multi-tenancy filter to handle filtering by the company
 *
 * @author Jacob Thomason <jacob@rentpost.com>
 */
class Filter extends SQLFilter
{

    protected ?Listener $listener = null;
    protected ?EntityManagerInterface $entityManager = null;
    protected ?Reader $annotationReader = null;


    /**
     * Gets the annotation reader
     */
    protected function getAnnotationReader(): Reader
    {
        return $this->annotationReader ?: new AnnotationReader();
    }


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
    protected function getValueHolderIdentifiersAndValues(): array
    {
        $identifiers = array_keys($this->getListener()->getValueHolders());
        foreach ($identifiers as &$identifier) {
            // Add braces to prevent replacing unwanted syntax
            $identifier = '/\{' . $identifier . '\}/';
        }

        $values = array_values($this->getListener()->getValueHolders());
        foreach ($values as &$value) {
            $value = $value->getValue();
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
     * Parses the annotation where clause, replacing identifiers with values
     *
     * @param string[] $identifiers
     * @param string[] $values
     */
    protected function parseWhereClause(string $filter, array $identifiers, array $values): string
    {
        return \preg_replace($identifiers, $values, $filter);
    }


    /**
     * Sets the annotation reader to be used.  This is beneficial for setting a cached annotation
     * reader.  Unfortunately Doctrine doesn't allow access to the constructor for SQLFilters and
     * also does not provide many good ways of managing dependencies within filters.
     */
    public function setAnnotationReader(Reader $annotationReader): void
    {
        $this->annotationReader = $annotationReader;
    }


    /**
     * Adds a SQL query filter based on the annotation syntax and the ValueHolders values
     * supplied to the MultiTenancy\Listener and identifier by their "identifier".
     *
     * These ValueHolders expose a string value that can be used within the annotation syntax.
     *
     * @param string $targetTableAlias
     */
    public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias)
    {
        // Get our multi-tenancy annotation from the class
        $multiTenancy = $this->getAnnotationReader()->getClassAnnotation(
            $targetEntity->reflClass,
            MultiTenancy::class,
        );

        // We get null if the annotation hasn't been defined. That's an issue since, for security
        // reasons, we want to ensure that the entity has explicitly been disabled for multi-tenancy.
        if (!$multiTenancy instanceof MultiTenancy) {
            throw new \LogicException(sprintf(
                '%s must have the MultiTenancy annotation added to the class docblock.',
                $targetEntity->rootEntityName,
            ));
        }

        if (!$multiTenancy->isEnabled()) {
            return '';
        }

        $filters = $multiTenancy->getFilters();
        if (!$filters) {
            throw new \LogicException(sprintf(
                '%s is enabled for MultiTenancy, but there were not any added filters.',
                $targetEntity->rootEntityName,
            ));
        }

        $defaultMap = $this->getDefaultMap($targetTableAlias);
        [$identifiers, $values] = $this->getMergedMaps($defaultMap, ...$this->getValueHolderIdentifiersAndValues());

        $whereClauses = [];
        foreach ($filters as $filter) {
            if (!$this->isContextual($filter->getContext())) {
                continue;
            }

            $whereClauses[] = $this->parseWhereClause($filter->getWhereClause(), $identifiers, $values);
        }

        return implode(' AND ', $whereClauses);
    }
}
