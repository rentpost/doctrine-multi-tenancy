<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy\Tests\Fixture;

use Rentpost\Doctrine\MultiTenancy\PrivilegedContextProviderInterface;

class StubPrivilegedContextProvider implements PrivilegedContextProviderInterface
{

    public function __construct(
        private readonly string $identifier,
        private readonly bool $contextual,
    ) {}


    public function getIdentifier(): string
    {
        return $this->identifier;
    }


    public function isContextual(): bool
    {
        return $this->contextual;
    }
}
