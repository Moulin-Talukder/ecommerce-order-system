<?php

namespace App\Exceptions;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a record is still referenced and must be archived, blocked or
 * deactivated instead of deleted.
 */
class CannotDeleteException extends RuntimeException
{
    /**
     * Whether a failed query was refused because the row is still
     * referenced (foreign key RESTRICT). MySQL reports SQLSTATE 23000 with
     * error 1451, or 1217 when it omits the constraint details. Any other
     * database error (e.g. a failed audit insert) is not a deletion refusal.
     */
    public static function isReferencedRowError(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23000'
            && in_array((int) ($exception->errorInfo[1] ?? 0), [1451, 1217], true);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], Response::HTTP_CONFLICT);
        }

        return back()->with('error', $this->getMessage());
    }
}
