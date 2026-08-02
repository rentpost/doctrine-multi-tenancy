<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity;

use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy;

/**
 * Uses the deprecated strict argument, which stays functional for one release
 */
#[MultiTenancy(
    strict: true,
    filters: [
        new MultiTenancy\Filter(
            context: ['customer'],
            where: '$this.id IN(SELECT book_id FROM customer_purchase WHERE customer_id = {customerId})',
        ),
        new MultiTenancy\Filter(
            context: ['publisher'],
            where: '$this.publisher_id = {publisherId}',
        ),
    ],
)]
class LegacyStrictEntity
{
}
