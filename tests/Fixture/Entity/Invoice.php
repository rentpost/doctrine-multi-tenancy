<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy\Tests\Fixture\Entity;

use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy;

#[MultiTenancy(filters: [
    new MultiTenancy\Filter(where: '$this.store_id = {storeId} AND $this.author_id = {authorId}'),
])]
class Invoice
{
}
