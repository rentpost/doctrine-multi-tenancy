# Upgrading

## 1.x to 2.0

Two things changed: when `ValueHolder`s are read, and how an entity declares the actors it serves.

### What changed

`ValueHolder`s are no longer read while a query is being built. They are producers now, asked for their value when you call `Listener::refreshValues()`. Conditions are built from the values held by the `Listener`, and each of those values is declared to Doctrine as a filter parameter as it is set.

The reason is Doctrine's SQL memoization. Doctrine caches the SQL its persisters generate and rebuilds it only when the hash of the filter collection changes, and that hash is derived from the filters' parameters. A value read lazily, during SQL generation, is read after Doctrine has already decided whether to reuse what it had, so it cannot affect that decision. In a process serving a single tenant this goes unnoticed, which is why it went unnoticed for so long. In a long lived one, a queue worker or a worker mode runtime such as FrankenPHP, the association joins inside memoized SQL could stay pinned to an earlier value, and rows that should have been returned were not. Under 2.0 that is structurally impossible: there is no path by which a value can reach a query without having moved the hash first.

### What breaks

Nothing about how you write filters or `ValueHolder`s. The `#[MultiTenancy]` attribute syntax is untouched, `ValueHolderInterface` is unchanged, and your implementations of it stay exactly as they are. Only the moment they are invoked has changed.

What breaks is relying on that invocation happening by itself. If you register `ValueHolder`s and never refresh, a filter referencing their identifiers now throws a `KeyValueException` at the first query, naming the identifier, where before it resolved from whatever the `ValueHolder` returned at that instant.

### What to change

Refresh once your `ValueHolder`s are registered, and again whenever the state they read changes:

```php
$multiTenancyListener->refreshValues();
```

Where you would rather not go through a `ValueHolder`, set the value directly:

```php
$multiTenancyListener->setValue('companyId', '42');
```

Both declare the value to Doctrine, so either is enough on its own.

For where to put the refresh: anywhere your application already knows its context has changed. If it keeps a context stack or a scope object of its own, that is the place. The call is cheap, and setting a value that has not changed does nothing at all, so calling it more often than strictly necessary costs nothing.

### Also worth knowing

- A filter referencing an identifier with no value set now throws `KeyValueException`. Previously the placeholder was left in the SQL, which failed later and far less clearly.
- `ConditionResolver` keeps its signature, `new ConditionResolver($listener)`, and now reads the same values, so conditions built for raw SQL and conditions built for DQL cannot disagree.
- Values live on the `Listener`, so they outlive Doctrine rebuilding the filter, which happens every time the filter is enabled after having been disabled. Nothing has to be set again after such a cycle.

### `strict` is now `permissive`, inverted

`strict: true` becomes `permissive: false`, and `strict: false` becomes `permissive: true`:

```php
#[MultiTenancy(permissive: false, filters: [...])]
```

The rename is not cosmetic. The safe declaration is the shorter one now, where the secure choice used to cost the extra typing, and `permissive: true` greps as the security exception it is, where `strict: false` read as an absence and blended in.

`strict` keeps working for this release, emitting an `E_USER_DEPRECATED` notice, and is removed in 3.0. It holds its place in the attribute's signature, so positional use keeps its meaning. Passing both `strict` and `permissive` throws an `AttributeException`, the two being inverses of one another.

`MultiTenancy::isStrict()` is deprecated alongside it. `isPermissive()` replaces it and returns `null` for an entity that declares neither way, which `isStrict()` cannot express.

### What an entity that declares neither way gets

`Listener::setPermissiveDefault(bool)` says what becomes of an actor an entity has not declared, for every entity that declares neither way itself:

```php
$multiTenancyListener->setPermissiveDefault(false);
```

`true` serves such an actor unrestricted, which is the 2.x default and preserves the behaviour of every entity written before any of this. `false` denies it, which is what an entity's filters read as declaring, and becomes the default in 3.0. The setter remains in 3.0 as the escape.

Nothing changes until you call it. While you have not, an entity resolving permissively only for the want of a decision emits an `E_USER_DEPRECATED` notice naming itself and the context it fell through on, once for each entity and context pair:

```
App\Entity\Invoice resolved with no filter covering the active "customer" context. This
currently grants unrestricted access and will deny by default in 3.0. Declare a filter for it,
or call Listener::setPermissiveDefault() to make the choice explicit.
```

Read as a list, those notices are the migration, including the cases no static read of your entities can find. Declare a filter for what should be scoped, mark `permissive: true` what genuinely is not scoped by who is asking, then call `setPermissiveDefault()` to say you are done.

Calling it with either value silences the notice for good. What is deprecated is leaving the outcome to a default that is about to change, not permissiveness itself: an explicit `true` is a decision, and stays supported indefinitely.
