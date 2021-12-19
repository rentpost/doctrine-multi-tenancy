# Doctrine MultiTenancy

Doctrine 2 extension providing advanced multi-tenancy support. The purpose of this extension is to allow flexibility in how multi-tenancy is defined on a per entity basis, as well as within contexts.

## Why?
Often times multi-tenancy is handled differently depending on a number of different business concerns. Maybe each user has different roles, or is part of multiple organizations, etc.

Now, generally speaking, you could handle much of these concerns within repositories, and if your business logic allows for such organization, you should consider this approach instead. However, this is not always possible or ideal in many scenarios, especially when accessing relational entities and even more-so when exposing your entities and relationships over something like a GraphQL API where relationships can be traversed in a user-defined manner.

This advanced approach to multi-tenancy aims to address these concerns, providing
flexibility to define how multi-tenancy is handled across *contexts* on a per-entity basis.

## Getting Started

Use the following instructions to get started with this Doctrine extension.

### Prerequisites

This extension is compatible with [Doctrine 2](https://github.com/doctrine/orm) and PHP >= 7.4.

### Installation

```
composer install rentpost/doctrine-multi-tenancy
```

### Setup

In order for this extension to work, you will need to register it with Doctrine's `EntityManager` and `EventManager`. To do so, you'll want to add the following to your configuration and setup for Doctrine. How this is done will depend on your implementation. See [Doctrine's installation and configuration documentation](https://www.doctrine-project.org/projects/doctrine-orm/en/2.7/reference/configuration.html) for further details.


```php
use Doctrine\ORM\Configuration;
use Doctrine\DBAL\Connection;
use App\Adapter\Doctrine\ORM\MultiTenancy\ContextProvider; // Your namespace for ContextProviders
use App\Adapter\Doctrine\ORM\MultiTenancy\ValueHolder; // Your namespace for ValueHolders
use Rentpost\Doctrine\MultiTenancy\Listener as MultiTenancyListener;

$connection = Connection($dbalParams, new MySQLDriver())
$config = new Configuration();
...

$eventManager = $connection->getEventManager();

// Instantiate the MultiTenancy\Listener
$multiTenancyListener = new MultiTenancyListener();
// Now add any ValueHolders you wish to use
$multiTenancyListener->addValueHolder(new ValueHolder\Company());
$multiTenancyListener->addValueHolder(new ValueHolder\User());
$multiTenancyListener->addValueHolder(new ValueHolder\Role());
// And any contexts you may wish to use
$multiTenancyListener->addContextProvider(new ContextProvider\Admin();
$multiTenancyListener->addContextProvider(new ContextProvider\Manager());
$multiTenancyListener->addContextProvider(new ContextProvider\Guest();
// Subscribe the listener to the EventManager now
$eventManager->addEventSubscriber($multiTenancyListener);

// Add the filter to the EntityManager config
$config->addFilter('multi-tenancy', 'Rentpost\Doctrine\MultiTenancy\Filter');

$entityManager = EntityManager::create($connection, $config, $eventManager);

// Lastly, you need to be sure you've enabled the filter
$entityManager->getFilters()->enable('multi-tenancy');
```

Now, let's break this down, if you're not familiar with Doctrine's configuration/setup. Depending on how your application is configured, the above may vary. We won't go into the particulars of Doctrine's configuration here.

The first part you need to be concerned with here is subscribing the listener to the `EventManager`. If, for whatever reason, you do not wish to have any `ValueHolders` or `ContextProviders`, you can actually skip this step entirely, and only add the filter. Let's assume that you want to use both though.

#### What is a ValueHolder?

A `ValueHolder` is a class that `implements Rentpost\Doctrine\MultiTenancy\ValueHolderInterface`. The primary purpose of a `ValueHolder` is to define a value for a given "identifier".

In the configuration above, we've added `ValueHolder`s for `Company`, `User`, and `Role`. These are going to provide parameters and values you'll want to use within an SQL query. The `ValueHolderInterface` defines 2 methods:

```php
public function getIdentifier(): string;
```

```php
public function getValue(): ?string;
```

The example, `User`, above might return `userId` as the "identifier" and the id of that User, represented as a string. It's effectively acting as a key/value store that's lazily loaded, such that, the value can mutate state.

The purpose of this will be more clear when viewing the example annotation below.

#### What is a ContextProvider?

A `ContextProvider` is a class that `implements Rentpost\Doctrine\MultiTenancy\ContextProviderInterface`. The primary purpose of a `ContextProvider` is to define "contexts" with a way to validate if that context is currently within context, or "contextual".

A "context" might, for example, be the "roles" for Users, or, it could be an authorization level, or any other use you may find to be fitting for your business logic. It's intended to be flexible, so as to accommodate any number of use cases.

In the configuration example above, we added `ContextProvider`s for `Admin`, `Manager`, and `Guest`. Each of these `ContextProvider`s will expose a "context".

The `ContextProviderInterface` defines 2 methods:

```php
public function getIdentifier(): string;
```

```php
public function isContextual(): bool;
```

Using the `Admin` example above, we might return `admin` as an "identifier". The `isContextual` method is responsible for determining if this particular `admin` identifier is consider to be within context, or contextual. In this situation, you might construct this class with a `User` object that has a method called `isAdmin`.

As with the `ValueHolder`, this will all be more clear when viewing the example annotation below.

#### Additional Setup *(recommended)*

We also recommend using the cached annotation reader with the MultiTenancy extension. This is important since the annotations are used for every entity SQL query.

```php
// Doctrine doesn't make accessing the Filter easy
$entityManager->getFilters()
    ->getEnabledFilters()['multi-tenancy']
    ->setAnnotationReader($cachedAnnotationReader);
```

## Usage

After you've gotten everything setup, the hard part is out of the way. Taking the time to properly evaludate how you'll setup your `ValueHolder` and `ContextProvider` classes will go a long way in making the usage clean and simple.

### Examples

There are a couple things to note first.

- `$this` represents the alias for the current table, as defined by Doctrine.
- "Identifiers" of a `ValueHolder` are enclosed in filters with curly brackets, `{myIdentifier}`.
- Multiple filters can be applied.  Adding multiple fitlers will execute all that are "in context".
- Filters without an explicitly defined context, even if you have added `ContextProvider`s, will be applied for all contexts.  Basically, it will always be executed.
- Multiple "contexts" can be defined for a filter. If any context defined is "contextual", the filter will be applied.

#### Simple example without any context

In this example, it's assumed that the `Product` table has a column called `company_id`, which is used for multi-tenancy to associate products with a given company. The `{companyId}` parameter here is defined in our `ValueHolder\Company` in the example configuration above. `companyId` would be the "identifier" and the value would be the id, of the current company.

```php
use Doctrine\ORM\Mapping as ORM;
use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy;

#[ORM\Entity]
#[MultiTenancy(filters: [
    new MultiTenancy\Filter(where: '$this.company_id = {companyId}'),
])]

class Product
{
  // Whatever
}
```

#### Another example with multiple filters and context

In this example, we've added multiple filters.  The first filter would always be applied.  The second filter, with the "manager" context, would only be applied if the "identifier", `manager`, as defined in the respective `ContextProvider` is considered to be "contextual", via the `isContextual()` method. If so, it would be applied as well.

In the second filter, the `product` table doesn't have access to the necessary information we need to properly apply multi-tenancy filtering. Therefore, we execute a sub-select query. This allows for us to perform queries on relational tables. In this case, we're effectively saying that a `manager` context only has access to a `Product` that's in a `product_group` with a status that is "published". If isn't true, the `Product` wouldn't be returned.

```php
use Doctrine\ORM\Mapping as ORM;
use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy;

/**
 * @ORM\Entity
 *
 * @MultiTenancy(filters={
 *      @MultiTenancy\Filter(
 *          where="$this.company_id = {companyId}"
 *      ),
 *      @MultiTenancy\Filter(
 *          context={"manager"},
 *          where="$this.id IN(
 *              SELECT product_id
 *              FROM product_group
 *              WHERE status = 'published'
 *          )"
 *      )
 * })
 */
class Product
{
  // Whatever
}
```

## Issues / Bugs / Questions

Please feel free to raise an issue against this repository if you have any questions or problems.

## Contributing

New contributors to this project are welcome. If you are interested in contributing please send a courtesy email to [dev@rentpost.com](mailto:dev@rentpost.com).

## Authors and Maintainers

Jacob Thomason [jacob@rentpost.com](mailto:jacob@rentpost.com)

## License

This library is released under the [MIT license](LICENSE).
