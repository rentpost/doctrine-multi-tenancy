<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity;

use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy;

#[MultiTenancy(filters: [
    new MultiTenancy\Filter(where: '$this.store_id = {storeId}'),
    new MultiTenancy\Filter(
        context: ['customer'],
        where: '$this.id IN(SELECT product_id FROM customer_purchase WHERE customer_id = {customerId})',
    ),
    new MultiTenancy\Filter(
        context: ['publisher'],
        where: '$this.publisher_id = {publisherId}',
    ),
])]
class Product
{
}
