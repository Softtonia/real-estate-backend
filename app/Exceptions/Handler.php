<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        $this->renderable(function (Throwable $e, $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                $status = $this->isHttpException($e) ? $e->getStatusCode() : 500;
                $message = $status === 500 ? 'An internal server error occurred.' : $e->getMessage();

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'error' => $e->getMessage(),
                ], $status);
            }
        });
    }
}
