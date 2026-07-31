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

    private ?Filter $filter = null;

    /**
     * Private so that setValue() stays the only way in, subclasses included
     *
     * @var array<string, string|null>
     */
    private array $values = [];

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
     * Sets the Filter that carries the values into Doctrine
     *
     * The Filter calls this itself, the first time it looks up the Listener, so that values set
     * from then on can be declared to Doctrine straight away.  Doctrine builds a new Filter every
     * time the filter is enabled after having been disabled, and the new instance takes over here.
     *
     * @param Filter $filter
     */
    public function setFilter(Filter $filter): void
    {
        $this->filter = $filter;
    }


    /**
     * Sets the value for a given identifier
     *
     * This is the only way a value ever changes, and conditions are built from these values
     * alone, so there is no path by which a value can reach a query without Doctrine being told
     * about it.  The value is held here, where it outlives any single Filter instance, and is
     * declared to Doctrine so that SQL memoized for the previous value gets rebuilt.  See
     * Filter::declareValue() for why that matters.
     *
     * @param string $identifier    The identifier, as used in a filter's where clause, without
     *                              the enclosing curly brackets
     * @param string|null $value    Value that can be included in a SQL query
     */
    public function setValue(string $identifier, ?string $value): void
    {
        $this->values[$identifier] = $value;
        $this->filter?->declareValue($identifier, $value);
    }


    /**
     * Gets all of the values, keyed by their identifier
     *
     * @return array<string, string|null>
     */
    public function getValues(): array
    {
        return $this->values;
    }


    /**
     * Sets the value of every ValueHolder
     *
     * Call this whenever the ambient context changes, a tenant switch within a long lived process
     * being the case that matters.  This is the one moment the ValueHolders are asked for a value;
     * they are producers, never read while a query is being built.
     */
    public function refreshValues(): void
    {
        foreach ($this->valueHolders as $valueHolder) {
            assert($valueHolder instanceof ValueHolderInterface);

            $this->setValue($valueHolder->getIdentifier(), $valueHolder->getValue());
        }
    }


    /**
     * Add a ValueHolder with a given key to the state
     *
     * Adding one does not set its value; call refreshValues() for that.
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
