<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy;

/**
 * Throw this exception when there is an issue with the MultiTenancy key/value state.
 *
 * @author Jacob Thomason <jacob@rentpost.com>
 */
class KeyValueException extends \Exception
{
}
