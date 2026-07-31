<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy\Tests\Fixture;

use Rentpost\Doctrine\MultiTenancy\ValueHolderInterface;

class StubValueHolder implements ValueHolderInterface
{

    public function __construct(
        private readonly string $identifier,
        private ?string $value,
    ) {}


    public function getIdentifier(): string
    {
        return $this->identifier;
    }


    public function getValue(): ?string
    {
        return $this->value;
    }


    /**
     * Stands in for the ambient state a real ValueHolder reads changing
     */
    public function setValue(?string $value): void
    {
        $this->value = $value;
    }
}
