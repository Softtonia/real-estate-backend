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
                if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                    $modelName = class_basename($e->getModel());
                    $friendlyName = trim(preg_replace('/(?<!\ )[A-Z]/', ' $0', $modelName));

                    return response()->json([
                        'status' => false,
                        'success' => false,
                        'message' => $friendlyName . ' not found.',
                        'error' => 'No record found with the specified identifier.',
                    ], 404);
                }

                if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                    $previous = $e->getPrevious();
                    if ($previous instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                        $modelName = class_basename($previous->getModel());
                        $friendlyName = trim(preg_replace('/(?<!\ )[A-Z]/', ' $0', $modelName));

                        return response()->json([
                            'status' => false,
                            'success' => false,
                            'message' => $friendlyName . ' not found.',
                            'error' => 'No record found with the specified identifier.',
                        ], 404);
                    }

                    return response()->json([
                        'status' => false,
                        'success' => false,
                        'message' => 'The requested endpoint or record was not found.',
                        'error' => 'Not Found',
                    ], 404);
                }

                $status = $this->isHttpException($e) ? $e->getStatusCode() : 500;
                $message = $status === 500 ? 'An internal server error occurred.' : $e->getMessage();

                return response()->json([
                    'status' => false,
                    'success' => false,
                    'message' => $message,
                    'error' => $e->getMessage(),
                ], $status);
            }
        });
    }
}
