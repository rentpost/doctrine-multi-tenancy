<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy;

/**
 * Marker interface for context providers that represent ambient environmental
 * state rather than primary access contexts.
 *
 * Context providers implementing this interface are excluded from the Strict
 * strategy's coverage enforcement. Use this for "always on" contexts
 * (e.g., "any role active", "user logged in") that don't represent discrete
 * access levels.
 *
 * @author Jacob Thomason <jacob@rentpost.com>
 */
interface AmbientContextProviderInterface extends ContextProviderInterface {}
