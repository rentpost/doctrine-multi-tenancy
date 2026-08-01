<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Doctrine SQLFilter adapter for multi-tenancy.
 *
 * Delegates condition resolution to ConditionResolver, which can also be used
 * independently for raw SQL queries outside of Doctrine's DQL layer.
 *
 * @author Jacob Thomason <jacob@rentpost.com>
 */
class Filter extends SQLFilter
{

    /** @var array<string, string|null> */
    private array $declaredValues = [];

    private ?Listener $listener = null;
    private ?EntityManagerInterface $entityManager = null;


    /**
     * Declares every value held by the Listener
     *
     * Doctrine builds a new Filter every time the filter is enabled after having been disabled,
     * and the new instance starts out with no parameters.  Declaring the Listener's values before
     * each constraint is built lets that instance recover them on its own, so a value only ever
     * has to be set once, on the Listener.
     */
    private function declareListenerValues(Listener $listener): void
    {
        foreach ($listener->getValues() as $identifier => $value) {
            $this->declareValue($identifier, $value);
        }
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
            $this->entityManager = $refl->getValue($this);
        }

        return $this->entityManager;
    }


    /**
     * Gets the MultiTenancy listener which carries our values and ValueHolders
     *
     * The Listener is given this Filter on the way through, so that a value set on it from here
     * on is declared to Doctrine straight away.
     */
    protected function getListener(): Listener
    {
        if ($this->listener instanceof Listener) {
            return $this->listener;
        }

        $evm = $this->getEntityManager()->getEventManager();
        foreach ($evm->getAllListeners() as $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof Listener) {
                    $this->listener = $listener;
                    $listener->setFilter($this);

                    return $listener;
                }
            }
        }

        throw new FilterException(
            'Listener "MultiTenancy\Listener" was not added to the EventManager!',
        );
    }


    /**
     * Declares a value to Doctrine as a filter parameter
     *
     * Call MultiTenancy\Listener::setValue() rather than this; the Listener holds the values and
     * calls this for each of them.
     *
     * Declaring the value is what makes a change of value visible to Doctrine.  Doctrine memoizes
     * the SQL its persisters generate and only rebuilds it when the filter collection's hash
     * changes, and that hash is derived solely from the filters' parameters.  Setting the
     * parameter both marks the collection dirty and changes the hash.  A value that has not
     * changed is skipped, so repeated calls leave the hash alone.
     *
     * @param string $identifier    The identifier, as used in a filter's where clause, without
     *                              the enclosing curly brackets
     * @param string|null $value    Value that can be included in a SQL query
     */
    public function declareValue(string $identifier, ?string $value): void
    {
        if (array_key_exists($identifier, $this->declaredValues) && $this->declaredValues[$identifier] === $value) {
            return;
        }

        $this->declaredValues[$identifier] = $value;
        $this->setParameter($identifier, $value);
    }


    /**
     * Adds a SQL query filter based on the attribute syntax and the values set on the
     * MultiTenancy\Listener, each identified by their "identifier".
     *
     * Every value is declared to Doctrine before the condition is built, so no value can reach a
     * query without having moved the filter hash first.
     *
     * @param string $targetTableAlias
     */
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!$this->getEntityManager()->getFilters()->isEnabled('multi-tenancy')) {
            return '';
        }

        $listener = $this->getListener();
        $this->declareListenerValues($listener);

        $resolver = new ConditionResolver($listener);

        return $resolver->resolve($targetEntity->getName(), $targetTableAlias);
    }
}
