<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy;

use Doctrine\Common\EventArgs;
use Doctrine\Common\EventSubscriber;
use Rentpost\Doctrine\MultiTenancy\ContextProviderInterface;

/**
 * MultiTenancy listener
 *
 * This listener is simply being used for state management.  If there are better ways of
 * handling state for exposing within a SQLFilter, please open an issue ticket.
 *
 * @author Jacob Thomason <jacob@rentpost.com>
 */
class Listener implements EventSubscriber
{

    /** @var ValueHolderInterface */
    protected array $valueHolders = [];

    /** @var ContextProviderInterface */
    protected array $contextProviders = [];


    /**
     * {@inheritdoc}
     */
    public function getSubscribedEvents()
    {
        return ['loadClassMetadata']; // Must have an event for the Listener to become registered
    }


    /**
     * Maps additional metadata
     *
     * @param EventArgs $eventArgs
     *
     * @return void
     */
    public function loadClassMetadata(EventArgs $eventArgs)
    {
        // Just need to have this method for now b/c of the subscribed evnt
    }


    /**
     * Add a ValueHolder with a given key to the state
     *
     * @param ValueHolderInterface $valueHolder
     */
    public function addValueHolder(ValueHolderInterface $valueHolder): void
    {
        $this->valueHolders[$valueHolder->getIdentifier()] = $valueHolder;
    }


    /**
     * Gets all of the ValueHolders
     *
     * @return array<ValueHolderInterface>
     */
    public function getValueHolders(): array
    {
        return $this->valueHolders;
    }


    /**
     * Gets a value "holder" for the given key
     *
     * @param string $key
     */
    public function getValueHolder(string $key): ValueHolderInterface
    {
        return $this->valueHolders[$key];
    }


    /**
     * Adds a ContextProvider with a given key to the state
     *
     * @param ContextProviderInterface $contextProvider
     */
    public function addContextProvider(ContextProviderInterface $contextProvider): void
    {
        $this->contextProviders[$contextProvider->getIdentifier()] = $contextProvider;
    }


    /**
     * Gets all of the ContextProviders
     *
     * @return array<ContextProviderInterface>
     */
    public function getContextProviders(): array
    {
        return $this->contextProviders;
    }


    /**
     * Gets the ContextProvider for a given key
     *
     * @param string $key
     */
    public function getContextProvider(string $key): ContextProviderInterface
    {
        if (!isset($this->contextProviders[$key])) {
            throw new KeyValueException(sprintf('Unable to find a ContextProvider for "%s"', $key));
        }

        return $this->contextProviders[$key];
    }
}
