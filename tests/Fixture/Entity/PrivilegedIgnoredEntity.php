<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity;

use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy;
use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy\FilterStrategy;

#[MultiTenancy(
    strict: true,
    strategy: FilterStrategy::FirstMatch,
    filters: [
        new MultiTenancy\Filter(
            context: ['task'],
            ignore: true,
        ),
        new MultiTenancy\Filter(
            context: ['task'],
            where: '$this.store_id = {storeId}',
        ),
    ],
)]
class PrivilegedIgnoredEntity
{
}
