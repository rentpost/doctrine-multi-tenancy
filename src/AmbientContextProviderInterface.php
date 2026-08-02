<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy;

/**
 * Marker interface for context providers that represent ambient environmental
 * state rather than primary access contexts.
 *
 * Context providers implementing this interface are excluded from the coverage
 * census, so an entity that has not declared them denies nothing on their
 * account. Use this for "always on" contexts (e.g., "any role active", "user
 * logged in") that don't represent discrete access levels.
 *
 * @author Jacob Thomason <jacob@rentpost.com>
 */
interface AmbientContextProviderInterface extends ContextProviderInterface {}
