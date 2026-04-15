<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity;

use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy;
use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy\FilterStrategy;

#[MultiTenancy(strategy: FilterStrategy::FirstMatch, filters: [
    new MultiTenancy\Filter(
        context: ['staff'],
        where: '$this.store_id = {storeId}',
    ),
    new MultiTenancy\Filter(
        context: ['customer'],
        where: '$this.book_id IN(SELECT book_id FROM customer_review WHERE customer_id = {customerId})',
    ),
])]
class Review
{
}
