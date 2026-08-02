<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity;

use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy;
use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy\FilterStrategy;

#[MultiTenancy(
    strategy: FilterStrategy::FirstMatch,
    filters: [
        new MultiTenancy\Filter(
            context: ['visitor'],
            ignore: true,
        ),
        new MultiTenancy\Filter(
            context: ['visitor'],
            where: '$this.store_id = {storeId}',
        ),
    ],
)]
class RestrictedIgnoredEntity
{
}
