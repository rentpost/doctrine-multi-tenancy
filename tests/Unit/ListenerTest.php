<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy\Tests\Unit;

use Doctrine\Common\EventArgs;
use PHPUnit\Framework\TestCase;
use Rentpost\Doctrine\MultiTenancy\KeyValueException;
use Rentpost\Doctrine\MultiTenancy\Listener;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\StubContextProvider;
use Rentpost\Doctrine\MultiTenancy\Tests\Fixture\StubValueHolder;

class ListenerTest extends TestCase
{

    public function testGetSubscribedEvents(): void
    {
        $listener = new Listener();

        $this->assertSame(['loadClassMetadata'], $listener->getSubscribedEvents());
    }


    public function testLoadClassMetadataIsNoOp(): void
    {
        $listener = new Listener();
        $eventArgs = $this->createMock(EventArgs::class);

        // Should not throw
        $listener->loadClassMetadata($eventArgs);
        $this->assertTrue(true);
    }


    public function testAddAndGetValueHolder(): void
    {
        $listener = new Listener();
        $vh = new StubValueHolder('storeId', '42');

        $listener->addValueHolder($vh);

        $this->assertSame($vh, $listener->getValueHolder('storeId'));
    }


    public function testGetValueHolders(): void
    {
        $listener = new Listener();
        $vh1 = new StubValueHolder('storeId', '42');
        $vh2 = new StubValueHolder('authorId', '7');

        $listener->addValueHolder($vh1);
        $listener->addValueHolder($vh2);

        $holders = $listener->getValueHolders();
        $this->assertCount(2, $holders);
        $this->assertSame($vh1, $holders['storeId']);
        $this->assertSame($vh2, $holders['authorId']);
    }


    public function testAddAndGetContextProvider(): void
    {
        $listener = new Listener();
        $cp = new StubContextProvider('staff', true);

        $listener->addContextProvider($cp);

        $this->assertSame($cp, $listener->getContextProvider('staff'));
    }


    public function testGetContextProviders(): void
    {
        $listener = new Listener();
        $cp1 = new StubContextProvider('staff', true);
        $cp2 = new StubContextProvider('customer', false);

        $listener->addContextProvider($cp1);
        $listener->addContextProvider($cp2);

        $providers = $listener->getContextProviders();
        $this->assertCount(2, $providers);
        $this->assertSame($cp1, $providers['staff']);
        $this->assertSame($cp2, $providers['customer']);
    }


    public function testGetContextProviderNotFoundThrowsException(): void
    {
        $listener = new Listener();

        $this->expectException(KeyValueException::class);
        $this->expectExceptionMessage('Unable to find a ContextProvider for "nonexistent"');

        $listener->getContextProvider('nonexistent');
    }


    public function testValueHolderOverwrite(): void
    {
        $listener = new Listener();
        $vh1 = new StubValueHolder('storeId', '42');
        $vh2 = new StubValueHolder('storeId', '99');

        $listener->addValueHolder($vh1);
        $listener->addValueHolder($vh2);

        $this->assertSame($vh2, $listener->getValueHolder('storeId'));
        $this->assertCount(1, $listener->getValueHolders());
    }
}
