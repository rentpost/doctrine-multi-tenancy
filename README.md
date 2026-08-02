# Doctrine MultiTenancy

A Doctrine 3 extension that provides advanced multi-tenancy support, declared per entity and per context.

## Why?

Multi-tenancy is rarely a single rule. Users hold several roles, and they often belong to more than one organization. Some actors, such as a background task, belong to no tenant at all.

Much of that can be handled in your repositories, and it should be wherever your business logic allows for it. Repository rules stop being enough as soon as entities are reached through their relationships. The problem gets worse when those entities are exposed over an API such as GraphQL, because the end user then decides which relationships get traversed. A rule declared on the entity itself holds no matter where the entity is reached from.

## The Model

The rest of the library follows from two sentences:

> An entity declares which contexts it serves. Every other context is denied.
> A `ContextProvider` declares one context, and whether it holds right now.

A context is a condition that either holds at this moment or does not. It usually says who is acting, such as a manager or a customer, but it can just as well be a permission level, an impersonation state, or anything else your business logic treats as a distinct case.

An entity declares a context by naming it on one of its filters. The filter then says how that context is scoped, for example "a manager sees the rows belonging to their company". If no filter names a context, then the entity was never written to serve it, and anyone acting in that context gets nothing.

Each half is declared once. The question of which contexts hold belongs to the `ContextProvider`s, and it is answered globally rather than on every entity in turn. That leaves an entity's filters with a single job, which is to express how each context is scoped.

### The Permissive Default

Starting in version 3.0, this plugin will deny any context that an entity has not declared. The current version, 2.x, defaults the other way, so that entities written before any of this keep behaving as they did. The choice is yours to make:

```php
$multiTenancyListener->setPermissiveDefault(false);
```

Call it during setup, before any query is built. Until you call it, any entity that resolves permissively, only because no choice was made, emits an `E_USER_DEPRECATED` notice. The notice fires once for each entity and context pair, so your logs hand you the migration list:

```
App\Entity\Invoice resolved with no filter covering the active "customer" context. This
currently grants unrestricted access and will deny by default in 3.0. Declare a filter for it,
or call Listener::setPermissiveDefault() to make the choice explicit.
```

Calling `setPermissiveDefault()` with either value silences the notice for good. What is deprecated, is leaving the outcome to a default that is about to change. Permissiveness, itself, is not deprecated, and it stays supported indefinitely, both through this setter and through `permissive: true` on an individual entity.

## The Two Parts of a Filter

A filter has two moving parts. It names the contexts it serves, and it interpolates the values its condition needs. A `ContextProvider` answers for a context, and a `ValueHolder` supplies a value. You register both on the `Listener` once, and every entity's filters draw on them from there.

### What a ContextProvider Is

A `ContextProvider` answers one question: does this context hold right now? Most often that comes down to who is acting. Your application knows the answer, and the library does not. A provider is the piece you write to tell it.

One provider stands for one context, such as a manager, a customer, or a background task. The provider gives that context a name, and it says whether the context holds at this moment. A filter names the same context by the same name, and that is how a filter and a context find each other. Without providers, a filter could describe a scope, but nothing could say whose scope was in force.

### What a ValueHolder Is

A `ValueHolder` answers the other question: what value gets substituted into a filter's where clause? A filter such as `$this.company_id = {companyId}` is a template, and the `companyId` in it has to come from somewhere. A `ValueHolder` is where it comes from.

One holder covers one identifier, and it returns the current value for that identifier. It reads that value from your application, usually from the acting user or the current tenant. The `Listener` collects those values when you refresh, and it keeps them until you refresh again. One filter is therefore written once, and it scopes one tenant on this request and another tenant on the next.

## Kinds of ContextProvider

A `ContextProvider` implements `Rentpost\Doctrine\MultiTenancy\ContextProviderInterface`. It names a context, and it reports whether that context holds right now. A context that holds right now is "contextual":

```php
public function getIdentifier(): string;
public function isContextual(): bool;
```

A context might be a role, an authorization level, an impersonation state, or whatever else your business logic treats as a distinct case. An admin provider would return `admin` as its identifier, and it might answer `isContextual()` from a `User` object that was passed to its constructor.

A provider implements one of four interfaces. The interface it implements decides two things: how the provider drives filters, and what happens to a query when the provider is contextual but no filter names it.

**Primary access contexts** implement `ContextProviderInterface`. This is the ordinary kind, and it suits anything that is a tenancy scope in its own right, such as a manager, a customer, or a guest. A filter that names the context applies while the provider is contextual. If no filter on the entity names the context, the entity denies it.

**Ambient contexts** implement `AmbientContextProviderInterface`. Use this interface for a standing fact that runs alongside an access level rather than acting as one, such as "a user is logged in" or "any role at all is active". Ambient contexts drive filters exactly as a primary context does. They are left out of the coverage census, because they hold true no matter which access context is in force.

**Privileged contexts** implement `PrivilegedContextProviderInterface`. Use this interface for a context that sits outside tenancy altogether rather than in a scope within it, such as a background task, a maintenance process, or a system importer. While a privileged context is contextual, only the filters that name a privileged context are applied. Every other filter is skipped, including context-free filters, because a context-free filter scopes rows to the tenants that the privileged actor does not belong to. The result is a context that is unrestricted by default, and that is scoped only where an entity names it.

**Restricted contexts** implement `RestrictedContextProviderInterface`, which mirrors the privileged kind. Use this interface for a context that no entity should serve by oversight alone. A public or unauthenticated visitor is the usual case. An entity serves a restricted context when one of its filters names it, and that filter applies exactly as it otherwise would, including `ignore: true` and `FirstMatch`. If no filter names the context, the entity denies it, whether or not the entity is permissive. When a privileged context and a restricted context are contextual at the same time, denial wins, and the restricted context governs throughout.

| Interface | Drives filters | Contextual, but named by no filter |
| --- | --- | --- |
| `ContextProviderInterface` | Filters naming the context apply | Denied, unless the entity is permissive |
| `AmbientContextProviderInterface` | Filters naming the context apply | No effect on its own |
| `PrivilegedContextProviderInterface` | Filters naming the context apply, and every filter that does not name a privileged context is skipped | Unrestricted |
| `RestrictedContextProviderInterface` | Filters naming the context apply | Denied, permissive or not |

```php
use Rentpost\Doctrine\MultiTenancy\PrivilegedContextProviderInterface;

class TaskContextProvider implements PrivilegedContextProviderInterface
{
    // ...
}
```

An entity scopes a privileged actor only by naming that actor's context. In the example below, every other actor is scoped to their company by the context-free filter, which the task never sees. The task is scoped by the second filter, which names it.

```php
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
}
```

Two points are worth stating precisely. Both of them follow from the rules above, rather than acting as exceptions to them:

- A contextual privileged context exempts itself, and nothing else. If an entity does not name one of the other active contexts, that other context is still denied.
- A context-free filter counts as coverage only for as long as it is applied. It is skipped while a privileged context is contextual, and a filter that is not applied cannot cover anything.

## Getting Started

### Prerequisites

This extension is compatible with [Doctrine 3](https://github.com/doctrine/orm) and PHP >= 8.2.

*If you're looking for PHP >= 7.4 support, please use `1.0.3`, the last version to support it*

### Installation

```bash
composer require rentpost/doctrine-multi-tenancy
```

### Wiring It Up

Subscribe the `Listener` to Doctrine's `EventManager`, and add the `Filter` to the `EntityManager`'s configuration. The surrounding setup depends on your application, so see [Doctrine's configuration documentation](https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/configuration.html) for the particulars.

```php
use Doctrine\ORM\Configuration;
use Doctrine\DBAL\Connection;
use App\Adapter\Doctrine\ORM\MultiTenancy\ContextProvider; // Your namespace for ContextProviders
use App\Adapter\Doctrine\ORM\MultiTenancy\ValueHolder; // Your namespace for ValueHolders
use Rentpost\Doctrine\MultiTenancy\Listener as MultiTenancyListener;

$connection = new Connection($dbalParams, new MySQLDriver());
$config = new Configuration();

$eventManager = $connection->getEventManager();

$multiTenancyListener = new MultiTenancyListener();
// The ValueHolders your filters interpolate values from
$multiTenancyListener->addValueHolder(new ValueHolder\Company());
$multiTenancyListener->addValueHolder(new ValueHolder\User());
// And the ContextProviders that say which kind of actor is acting
$multiTenancyListener->addContextProvider(new ContextProvider\Admin());
$multiTenancyListener->addContextProvider(new ContextProvider\Manager());
$multiTenancyListener->addContextProvider(new ContextProvider\Guest());
// Say what becomes of a context an entity has not declared
$multiTenancyListener->setPermissiveDefault(false);

$eventManager->addEventSubscriber($multiTenancyListener);

// Add the filter to the EntityManager config
$config->addFilter('multi-tenancy', 'Rentpost\Doctrine\MultiTenancy\Filter');

$entityManager = EntityManager::create($connection, $config, $eventManager);

// Lastly, you need to be sure you've enabled the filter
$entityManager->getFilters()->enable('multi-tenancy');
```

Both registrations are optional in themselves. If you register no `ValueHolder`s and no `ContextProvider`s, you still get context-free filters, and they apply to everybody.

Registering a `ValueHolder` does not give it a value. Setting the values is a separate step, and it belongs wherever your application learns who it is acting as, not here. See [Values](#values).

## Filters and Contexts

A filter is a SQL `WHERE` fragment declared on the entity, with two conveniences:

- `$this` stands for the current table's alias, as Doctrine defines it.
- `{identifier}` interpolates a value held by the `Listener`. See [Values](#values).

```php
use Doctrine\ORM\Mapping as ORM;
use Rentpost\Doctrine\MultiTenancy\Attribute\MultiTenancy;

#[ORM\Entity]
#[MultiTenancy(filters: [
    new MultiTenancy\Filter(where: '$this.company_id = {companyId}'),
])]
class Product
{
}
```

`context:` names the contexts that a filter serves, and the filter applies while any one of them is contextual. You can declare several filters, and every filter that applies is AND'd together. A filter with no `context:` applies in every context, so it also counts as coverage for every context. An entity that carries such a filter serves anybody, and nothing on it is left undeclared.

In the example below, a manager is scoped to their own company, a visitor sees only published products, and an admin is served with nothing scoping it. Every other actor is denied.

```php
#[ORM\Entity]
#[MultiTenancy(filters: [
    new MultiTenancy\Filter(
        context: ['manager'],
        where: '$this.company_id = {companyId}',
    ),
    new MultiTenancy\Filter(
        context: ['visitor'],
        where: '$this.id IN(
            SELECT product_id
            FROM product_group
            WHERE status = \'published\'
        )',
    ),
    new MultiTenancy\Filter(
        context: ['admin'],
        ignore: true,
    ),
])]
class Product
{
}
```

The visitor's filter uses a sub-select because the `product` table does not itself carry what the rule needs. This is how a filter reaches relational tables.

`ignore: true` names a context without placing any condition on it. That is how an entity declares a context that it serves unrestricted.

### Permissive Entities

`permissive: true` serves a context that the entity has not declared, rather than denying it. It is the documented escape hatch, and it is greppable as one:

```php
#[MultiTenancy(permissive: true, filters: [
    new MultiTenancy\Filter(context: ['manager'], where: '$this.company_id = {companyId}'),
])]
class Product
{
}
```

Reach for it when an entity genuinely is not scoped by who is asking, and prefer `enable: false` when the entity is not scoped at all. `permissive: false` denies, whatever the default is set to. If you declare neither, the entity falls back to [the permissive default](#the-permissive-default).

`strict: true` is the former spelling of `permissive: false`. It still works, but it emits an `E_USER_DEPRECATED` notice, and it is removed in 3.0. Passing both is contradictory, and it throws.

## Filter Strategies

When several filters apply at once, the `FilterStrategy` decides how they combine:

- **`FilterStrategy::AnyMatch`** (default): every matching filter is AND'd together.
- **`FilterStrategy::FirstMatch`**: only the first matching filter is applied, and the rest are skipped.

A filter with no context always matches, so under `FirstMatch` nothing declared after it is ever evaluated. An `ignore: true` filter matches in exactly the same way, which is what makes the two useful together. Declare the ignored filters first, naming the contexts you mean to exempt, then declare the scoped filters after them.

```php
#[ORM\Entity]
#[MultiTenancy(
    strategy: MultiTenancy\FilterStrategy::FirstMatch,
    filters: [
        new MultiTenancy\Filter(
            context: ['admin'],
            ignore: true,
        ),
        new MultiTenancy\Filter(
            context: ['manager', 'staff'],
            where: '$this.company_id = {companyId}',
        ),
    ],
)]
class Product
{
}
```

## Values

A `ValueHolder` implements `Rentpost\Doctrine\MultiTenancy\ValueHolderInterface` and supplies the value behind one identifier:

```php
public function getIdentifier(): string;
public function getValue(): ?string;
```

A `ValueHolder` for the acting user might return `userId` as its identifier, and that user's id as its value. A `ValueHolder` is a producer. It is asked for its value when you refresh, and never while a query is being built.

Values live on the `Listener`. Registering a `ValueHolder` does not set a value. Refreshing sets the values, by asking every registered `ValueHolder` for its current value:

```php
$multiTenancyListener->refreshValues();
```

Call this whenever the state your `ValueHolder`s read changes. Establishing the acting user at the start of a request is the usual case, and a tenant switch part way through is another. This is not a setup step. Values follow your application's context, so a single call while wiring the `Listener` up would only read whatever happened to be true before a request had begun.

You can also set a value on its own, with no `ValueHolder` involved:

```php
$multiTenancyListener->setValue('companyId', '42');
```

The identifier is the same one you use in a filter, without the curly brackets, and the value is a string that can be included in a SQL query. Either way, the value is declared to Doctrine as it is set, and that is what keeps Doctrine's memoized SQL in step with it.

If a filter references an identifier that has no value set, resolving it throws a `KeyValueException` naming that identifier. A missed refresh therefore shows up at the first query, rather than as a condition you did not intend.

### Changing Values Within a Process

Doctrine memoizes the SQL that its persisters generate, and it rebuilds that SQL only when the hash of the filter collection changes. The hash is derived from the filters' parameters. Every value set on the `Listener` is declared to Doctrine as a filter parameter, so setting a value moves the hash, and any SQL memoized against the previous value is rebuilt. Conditions are built from these values and nothing else, so a value cannot reach a query unless Doctrine has been told about it first.

This is what makes the library safe wherever one process serves several tenants in turn. That covers a queue worker, a CLI task that iterates tenants, and an application that switches the acting tenant part way through. It also covers a worker mode runtime such as FrankenPHP, RoadRunner, or Swoole, where the process and the `EntityManager` outlive a single request. Call `refreshValues()` when your context changes, and the rest follows.

Values live on the `Listener` rather than on the filter, so they also outlive Doctrine rebuilding the filter, which it does every time the filter is enabled after having been disabled. The new filter picks the values up on its own the first time it builds a condition.

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

The `resolve()` method reads the `#[MultiTenancy]` attribute from the entity class, evaluates the current context through the registered `ContextProvider`s, substitutes the values held by the `Listener`, and returns the composed WHERE clause fragment. It uses the same logic as the `Filter`, without requiring Doctrine's DQL layer.

## Upgrading

Version 2.0 changed when `ValueHolder`s are read, and renamed `strict` to `permissive`. See [UPGRADING.md](UPGRADING.md) for what breaks and what to change.

## Development

### Running Tests

```bash
make test
```

Or directly via PHPUnit:

```bash
vendor/bin/phpunit
```

### Installing Dependencies

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
