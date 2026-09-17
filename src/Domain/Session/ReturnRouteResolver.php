<?php

declare(strict_types=1);

namespace App\Domain\Session;

use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves the optional "come back here, not to Heute" hint (return_route/
 * return_route_id) a start/edit/mark-done form can carry - set when the
 * action was launched from a Routine's own "starten" page, so finishing,
 * discarding, saving an edit, or just resuming should land back on that
 * Routine instead of always bouncing to diary_index. Deliberately returns
 * plain route+params rather than a Response, so it stays framework-router-
 * agnostic and every caller decides its own fallback.
 *
 * Shared by DiaryController, ExerciseSessionController, and the 5 tool
 * controllers rather than duplicating this one-clause allowlist check
 * everywhere - same "small, focused, constructor-free" shape as
 * ExercisePlanLink.
 */
final class ReturnRouteResolver
{
    /**
     * @return array{route: string, id: int}|null
     */
    public function resolve(?string $returnRoute, mixed $returnRouteId): ?array
    {
        if ($returnRoute !== 'routine_show' || $returnRouteId === null || $returnRouteId === '') {
            return null;
        }

        return ['route' => 'routine_show', 'id' => (int) $returnRouteId];
    }

    /**
     * Convenience for the common case: read both values off a Request's
     * POST body (a form field pair every action below embeds identically).
     *
     * @return array{route: string, id: int}|null
     */
    public function resolveFromRequestBody(Request $request): ?array
    {
        return $this->resolve(
            $request->request->get('return_route'),
            $request->request->get('return_route_id'),
        );
    }

    /**
     * Same, but reading the query string instead - used where the context
     * arrives via a GET redirect/link rather than a form POST (e.g. the
     * exercise-session running page, reached via a redirect from starten).
     *
     * @return array{route: string, id: int}|null
     */
    public function resolveFromQuery(Request $request): ?array
    {
        return $this->resolve(
            $request->query->get('return_route'),
            $request->query->get('return_route_id'),
        );
    }
}
