# Upgrading

## 1.x to 2.0

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
