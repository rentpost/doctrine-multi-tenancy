<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy;

/**
 * Marker interface for context providers that represent an actor outside of tenancy
 * altogether, rather than a scope within it.
 *
 * Use this for system actors, a background task or a maintenance process being the usual
 * cases, which no tenancy scope describes. While such a provider is contextual:
 *
 * - Only filters naming one of the privileged contexts are applied. Every other filter is
 *   skipped, context-free filters included, since a context-free filter scopes the tenants
 *   the actor is not one of.
 * - The provider is excluded from the coverage census, as ambient providers are, so its being
 *   active never denies anything on its own.
 *
 * The result is an actor that is unrestricted by default, and scoped only where an entity
 * names its context on a filter.
 *
 * @author Jacob Thomason <jacob@rentpost.com>
 */
interface PrivilegedContextProviderInterface extends ContextProviderInterface {}
