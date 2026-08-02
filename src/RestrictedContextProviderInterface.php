<?php

declare(strict_types = 1);

namespace Rentpost\Doctrine\MultiTenancy;

/**
 * Marker interface for context providers that represent an actor an entity has to serve
 * deliberately, rather than one it serves unless it says otherwise.
 *
 * Use this for an actor no entity should serve by having been overlooked, a public or
 * unauthenticated visitor being the usual case.  While such a provider is contextual:
 *
 * - An entity naming one of the restricted contexts on a filter serves the actor, and that
 *   filter applies exactly as it otherwise would, ignore: true and FirstMatch included.  This
 *   is how an entity opts in, and the only way it ever does.
 * - An entity naming none of them denies, resolving to 1 = 0, permissive or not.  Denial is the
 *   restricted actor's own rule rather than the coverage census, so the flag has no say in it.
 *   A context-free filter is no help either; it scopes whichever tenants an entity serves, and
 *   whether this actor is one of them is the question being asked.
 *
 * The result is the mirror of PrivilegedContextProviderInterface: an actor that is denied by
 * default, and served only where an entity names its context on a filter.
 *
 * Where a privileged and a restricted provider are contextual at once, denial wins.  The actor
 * is the restricted one throughout and carries none of the privileged actor's freedom from
 * filters; an entity naming its restricted context still serves it, that being what naming a
 * context means everywhere else.
 *
 * @author Jacob Thomason <jacob@rentpost.com>
 */
interface RestrictedContextProviderInterface extends ContextProviderInterface {}
