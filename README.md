# Doctrine MultiTenancy

Doctrine 3 extension providing advanced multi-tenancy support. The purpose of this extension is to allow flexibility in how multi-tenancy is defined on a per entity basis, as well as within contexts.

## Why?
Often times multi-tenancy is handled differently depending on a number of different business concerns. Maybe each user has different roles, or is part of multiple organizations, etc.

Now, generally speaking, you could handle much of these concerns within repositories, and if your business logic allows for such organization, you should consider this approach instead. However, this is not always possible or ideal in many scenarios, especially when accessing relational entities and even more-so when exposing your entities and relationships over something like a GraphQL API where relationships can be traversed in an end-user defined manner.

This advanced approach to multi-tenancy aims to address these concerns, providing
flexibility to define how multi-tenancy is handled across *contexts* on a per-entity basis.

## Getting Started

Use the following instructions to get started with this Doctrine extension.

### Prerequisites

This extension is compatible with [Doctrine 3](https://github.com/doctrine/orm) and PHP >= 8.2.

*If you're looking for PHP >= 7.4 support, please use `1.0.3`, the last version to support it*

### Installation

```bash
composer require rentpost/doctrine-multi-tenancy
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

The example, `User`, above might return `userId` as the "identifier" and the id of that User, represented as a string. A `ValueHolder` is a producer: it is asked for its value when you refresh, and never while a query is being built.

The purpose of this will be more clear when viewing the example attributes below.

#### Setting values

Values live on the `Listener`. Registering a `ValueHolder` does not set one; refreshing does, by asking every registered `ValueHolder` for its current value:

```php
$multiTenancyListener->refreshValues();
```

Call this once your `ValueHolder`s are registered, and again whenever the state they read changes, a tenant switch being the usual case. A value can also be set on its own, with no `ValueHolder` involved:

```php
$multiTenancyListener->setValue('companyId', '42');
```

The identifier is the same one you use in a filter, without the curly brackets, and the value is a string that can be included in a SQL query. Either way, the value is declared to Doctrine as it is set, which is what keeps Doctrine's memoized SQL in step with it. See [Changing Values Within a Process](#changing-values-within-a-process).

If a filter references an identifier that has no value set, resolving it throws a `KeyValueException` naming that identifier, so a missed refresh shows up at the first query rather than as a condition you did not intend.

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

There are three kinds of `ContextProvider`, the one above being the ordinary kind. See [Kinds of ContextProvider](#kinds-of-contextprovider) for the other two and when to reach for them.

As with the `ValueHolder`, this will all be more clear when viewing the example attributes below.

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

#[ORM\Entity]
#[MultiTenancy(filters: [
    new MuiltiTenancy\Filter(where: '$this.company_id = {companyId}'),
    new MultiTenancy\Filter(
        context: ['visitor'],
        where: '$this.id IN(
            SELECT product_id
            FROM product_group
            WHERE status = 'published'
        )'
    ),
])]
class Product
{
  // Whatever
}
```

### Filter Strategies

When multiple filters match the current context, the `FilterStrategy` determines how they combine:

- **`FilterStrategy::AnyMatch`** (default) — All matching filters are AND'd together.
- **`FilterStrategy::FirstMatch`** — Only the first matching filter is applied; subsequent filters are skipped.

**Keep in mind, if you do not provide a context, it's assumed to be in context, and that filter will be applied, meaning any subsequent filters will never be evaluated (under `FirstMatch`).**

```php
use Doctrine\ORM\Mapping as ORM;
use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy;

#[ORM\Entity]
#[MultiTenancy(
    strategy: MultiTenancy\FilterStrategy::FirstMatch,
    filters: [
        new MultiTenancy\Filter(
            context: ['admin'],
            where: '$this.company_id = {companyId}',
        ),
        new MultiTenancy\Filter(
            context: ['other'],
            ignore: true,
        ),
        new MultiTenancy\Filter(
            context: ['visitor'],
            where: '$this.id IN(
                SELECT product_id
                FROM product_group
                WHERE status = \'published\'
            )',
        ),
    ],
)]
class Product
{
  // Whatever
}
```

The `ignore` parameter above allows you to specify a context where no filter conditions will be applied. This can be especially useful in combination with `FilterStrategy::FirstMatch`, allowing you to entirely, or selectively, ignore all multi-tenancy for an entity — for a given context.

### Strict Mode

Setting `strict: true` flips to a "deny by default" model. Filters are still processed per the chosen `FilterStrategy`, but after processing, if any active `ContextProvider` is not covered by any filter's `context:` array, `1 = 0` is appended — denying all results.

This eliminates the need to explicitly deny every uncovered context. Only contexts that are declared in a filter (even with `ignore: true`) are permitted.

Note: `strict` is orthogonal to `FilterStrategy` — it works with either `AnyMatch` or `FirstMatch`.

#### Context-free filters

A filter with no `context:` array applies to all contexts (as documented earlier), and consistent with that semantic, it also **covers** all contexts for the strict check. So mixing a context-free filter with `strict: true` effectively disables the coverage enforcement — every active context is implicitly covered.

For strict mode to be meaningful, declare a `context:` array on every filter so coverage is explicit. The following example scopes access contextually: managers get company-wide access, admins are permitted without extra conditions (`ignore: true`), and anyone else is denied.

```php
use Doctrine\ORM\Mapping as ORM;
use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy;

#[ORM\Entity]
#[MultiTenancy(
    strict: true,
    filters: [
        new MultiTenancy\Filter(
            context: ['manager'],
            where: '$this.company_id = {companyId}',
        ),
        new MultiTenancy\Filter(
            context: ['admin'],
            ignore: true,
        ),
    ],
)]
class Product
{
  // Whatever
}
```

In this example:
- A `manager` sees products scoped to their company
- An `admin` sees everything (ignored filter, but the context is acknowledged as covered)
- Any other active context (e.g. `guest`) is automatically denied — no need to add explicit deny filters

#### Ambient Context Providers

Some context providers represent ambient environmental state rather than primary access contexts — for example, "is any role active?" or "is a user logged in?". These are always active and don't represent discrete access levels.

When using `strict: true`, ambient contexts would cause universal denial since they're never listed in a filter's `context:` array. To prevent this, implement `AmbientContextProviderInterface` (a marker interface extending `ContextProviderInterface`) on those providers. Strict mode will skip them during its coverage check.

```php
use Rentpost\Doctrine\MultiTenancy\AmbientContextProviderInterface;

class UserContextProvider implements AmbientContextProviderInterface
{
    // ...
}
```

### Kinds of ContextProvider

Which interface a `ContextProvider` implements decides two separate things: how it drives filters, and whether `strict: true` counts it when checking coverage.

**Primary access contexts** implement `ContextProviderInterface`. This is the ordinary kind, for anything that is a tenancy scope in its own right: a manager, a customer, a guest. A filter naming the context applies while the provider is contextual, and under `strict: true` some filter has to name it or the entity denies it.

**Ambient contexts** implement `AmbientContextProviderInterface`. Use it for an always on, orthogonal fact rather than an access level: "a user is logged in", "any role at all is active". These drive filters exactly as a primary context does, and are simply left out of the strict census, being true regardless of which scope the actor is acting in.

**Privileged contexts** implement `PrivilegedContextProviderInterface`. Use it for an actor that is outside of tenancy altogether rather than a scope within it: a background task, a maintenance process, a system importer. Such an actor has no answer to the question the strict census asks, so it is left out of it too. Beyond that, while a privileged context is contextual, only filters naming a privileged context are applied. Every other filter is skipped, context-free filters included, since a context-free filter scopes the tenants the actor is not one of. The result is an actor that is unrestricted by default, and scoped only where an entity names its context.

| Interface | Drives filters | Counted in the strict census |
| --- | --- | --- |
| `ContextProviderInterface` | Filters naming the context apply | Yes |
| `AmbientContextProviderInterface` | Filters naming the context apply | No |
| `PrivilegedContextProviderInterface` | Filters naming the context apply, and every filter that does not name one is skipped | No |

```php
use Rentpost\Doctrine\MultiTenancy\PrivilegedContextProviderInterface;

class TaskContextProvider implements PrivilegedContextProviderInterface
{
    // ...
}
```

An entity scopes such an actor by naming its context, and only then. Below, everyone else is scoped to their company by the context-free filter, which the task never sees; the task is scoped by the second filter, which names it.

```php
use Doctrine\ORM\Mapping as ORM;
use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy;

#[ORM\Entity]
#[MultiTenancy(filters: [
    new MultiTenancy\Filter(where: '$this.company_id = {companyId}'),
    new MultiTenancy\Filter(
        context: ['task'],
        where: '$this.company_id = {companyId}',
    ),
])]
class CreditReport
{
  // Whatever
}
```

`ignore: true` and `FilterStrategy::FirstMatch` behave as they always do for a filter that names a privileged context, so an ignored filter listed first still stops the ones behind it.

Two things worth being precise about, both of them consequences of the above rather than exceptions to it:

- Contexts other than the privileged one are censused as they always are. A privileged context being contextual spares itself, not everything else, so a strict entity that names no active primary context still denies.
- A context-free filter covers every context for the strict census only for as long as it is applied. While a privileged context is contextual it is skipped, and a filter that is not applied cannot stand in as coverage for anything.

## Changing Values Within a Process

Doctrine memoizes the SQL its persisters generate and rebuilds it only when the hash of the filter collection changes, and that hash is derived from the filters' parameters. Every value set on the `Listener` is declared to Doctrine as a filter parameter, so setting one moves that hash and any SQL memoized against the previous value is rebuilt. Conditions are built from these values and nothing else, so a value cannot reach a query without Doctrine having been told about it first.

This is what makes the library safe wherever one process serves several tenants in turn: a queue worker, a CLI task iterating tenants, an application that switches the acting tenant part way through, or a worker mode runtime such as FrankenPHP, RoadRunner or Swoole, where the process and the `EntityManager` outlive a single request. Call `refreshValues()` when your context changes and the rest follows.

Values live on the `Listener` rather than on the filter, so they also outlive Doctrine rebuilding the filter, which it does every time the filter is enabled after having been disabled. The new filter picks them up on its own the first time it builds a condition.

## Using ConditionResolver for Raw SQL Queries

The `Filter` class (Doctrine's `SQLFilter`) only applies to DQL queries. For raw SQL queries in repositories, use `ConditionResolver` directly to get the same multi-tenancy conditions:

```php
use Rentpost\Doctrine\MultiTenancy\ConditionResolver;

// The Listener is the same one registered with the EventManager during setup
$resolver = new ConditionResolver($listener);

// Resolve conditions for a given entity class and table alias
$condition = $resolver->resolve(Product::class, 'p');
// Returns e.g.: "p.company_id = 42 AND p.id IN(SELECT product_id FROM ...)"

// Use in a raw SQL query
$sql = "SELECT p.* FROM product p WHERE {$condition} AND p.status = 'active'";
```

The `resolve()` method reads the `#[MultiTenancy]` attribute from the entity class, evaluates the current context via `ContextProviders`, substitutes the values held by the `Listener`, and returns the composed WHERE clause fragment. It uses the same logic as the `Filter`, just without requiring Doctrine's DQL layer.

## Upgrading

Version 2.0 changed when `ValueHolder`s are read. See [UPGRADING.md](UPGRADING.md) for what breaks and what to change. Version 2.1 added `PrivilegedContextProviderInterface` and nothing else, so there is nothing to do for it.

## Development

### Running Tests

```bash
make test
```

Or directly via PHPUnit:

```bash
vendor/bin/phpunit
```

### Setup

```bash
make init
```

This installs all Composer dependencies, including PHPUnit for running the test suite.

## Issues / Bugs / Questions

Please feel free to raise an issue against this repository if you have any questions or problems.

## Contributing

New contributors to this project are welcome. If you are interested in contributing please send a courtesy email to [dev@rentpost.com](mailto:dev@rentpost.com).

## Authors and Maintainers

Jacob Thomason [jacob@rentpost.com](mailto:jacob@rentpost.com)

## License

This library is released under the [MIT license](LICENSE).
