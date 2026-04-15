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

    private ?Listener $listener = null;
    private ?EntityManagerInterface $entityManager = null;


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
     * Gets the MultiTenancy listener which carries our ValueHolders
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

                    return $listener;
                }
            }
        }

        throw new FilterException(
            'Listener "MultiTenancy\Listener" was not added to the EventManager!',
        );
    }


    /**
     * Adds a SQL query filter based on the attribute syntax and the ValueHolders values
     * supplied to the MultiTenancy\Listener and identified by their "identifier".
     *
     * @param string $targetTableAlias
     */
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!$this->getEntityManager()->getFilters()->isEnabled('multi-tenancy')) {
            return '';
        }

        $resolver = new ConditionResolver($this->getListener());

        return $resolver->resolve($targetEntity->getName(), $targetTableAlias);
    }
}
