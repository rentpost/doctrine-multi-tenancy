<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy;

/**
 * Interface for a key/value "value" when being stored with the MultiTenancy\Listener
 *
 * @author Jacob Thomason <jacob@rentpost.com>
 */
interface ValueHolderInterface
{

    /**
     * Gets the key/identifier for this ValueHolder
     */
    public function getIdentifier(): string;


    /**
     * Gets the actual value
     * We force this to be a string return value for simplicty, and type checking integrity.
     *
     * @return string|null      String value that can be included in a SQL query
     */
    public function getValue(): ?string;
}
