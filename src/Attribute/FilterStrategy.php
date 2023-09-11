<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy\Attribute;

/**
 * Determines the strategy for evaluating filters
 *
 * @author Jacob Thomason <jacob@rentpost.com>
 */
enum FilterStrategy
{

    case FirstMatch;
    case AnyMatch;
}
