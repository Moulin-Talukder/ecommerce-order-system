<?php

namespace App\Exceptions\Users;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A user-management change was refused (e.g. it would leave no active Super
 * Admin). The message is shown to the Super Admin.
 */
class UserManagementException extends RuntimeException
{
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], Response::HTTP_CONFLICT);
        }

        return back()->with('error', $this->getMessage());
    }
}
