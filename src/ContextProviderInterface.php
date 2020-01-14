<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy;

/**
 * Interface for a context provider when being stored with the MultiTenancy\Listener
 *
 * Essentially the context provider is responsible for determining whether or not something is
 * in context.  For example, you might use the identifier "admin".  Then, your `isContextual`
 * method would determine if that is true or false.
 *
 * @author Jacob Thomason <jacob@rentpost.com>
 */
interface ContextProviderInterface
{

    /**
     * Gets the key/identifier for the context provider
     */
    public function getIdentifier(): string;


    /**
     * This determines if the identifier, for whatever context it represents is considered to be
     * in context, or contextual.
     */
    public function isContextual(): bool;
}
