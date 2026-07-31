<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DynamicPost;
use App\Models\User;
use App\Services\PropertyVerification\PropertyWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class UserPropertyVerificationController extends Controller
{
    public function __construct(
        private readonly PropertyWorkflowService $workflow
    ) {
    }

    public function status(
        Request $request,
        int $property
    ): JsonResponse {
        try {
            $user = $this->resolveCurrentUser($request);

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthenticated user.',
                ], 401);
            }

            $listing = $this->ownedProperty($property, $user);

            if (!$listing) {
                return response()->json([
                    'status' => false,
                    'message' => 'Property not found.',
                ], 404);
            }

            $revision = $this->workflow->latestRevision($listing);

            return response()->json([
                'status' => true,
                'message' => 'Property verification status fetched successfully.',
                'data' => [
                    'property_id' => (int) $listing->id,
                    'property_status' => $listing->status ?? null,
                    'live_status' => $listing->live_status ?? null,
                    'verification' => $revision ? [
                        'revision_id' => (int) $revision->id,
                        'version' => (int) $revision->version,
                        'source' => $revision->source,
                        'status' => $revision->status,
                        'rejection_reason' => $revision->rejection_reason,
                        'submitted_at' => optional(
                            $revision->submitted_at
                        )->toDateTimeString(),
                        'assigned_at' => optional(
                            $revision->assigned_at
                        )->toDateTimeString(),
                        'verification_started_at' => optional(
                            $revision->verification_started_at
                        )->toDateTimeString(),
                        'decided_at' => optional(
                            $revision->decided_at
                        )->toDateTimeString(),
                    ] : null,
                ],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch property verification status.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function timeline(
        Request $request,
        int $property
    ): JsonResponse {
        try {
            $user = $this->resolveCurrentUser($request);

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthenticated user.',
                ], 401);
            }

            $listing = $this->ownedProperty($property, $user);

            if (!$listing) {
                return response()->json([
                    'status' => false,
                    'message' => 'Property not found.',
                ], 404);
            }

            $timeline = $this->workflow
                ->timeline($listing)
                ->map(function ($event) {
                    $actorName = null;

                    if ($event->actor) {
                        $actorName = trim(
                            ($event->actor->first_name ?? '')
                            . ' '
                            . ($event->actor->last_name ?? '')
                        ) ?: ($event->actor->email ?? null);
                    }

                    return [
                        'id' => (int) $event->id,
                        'event' => $event->event,
                        'from_status' => $event->from_status,
                        'to_status' => $event->to_status,
                        'message' => $event->message,
                        'metadata' => $event->metadata,
                        'actor' => $event->actor ? [
                            'id' => (int) $event->actor->id,
                            'name' => $actorName,
                        ] : null,
                        'created_at' => optional(
                            $event->created_at
                        )->toDateTimeString(),
                    ];
                })
                ->values();

            return response()->json([
                'status' => true,
                'message' => 'Property verification timeline fetched successfully.',
                'data' => $timeline,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch property verification timeline.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function resolveCurrentUser(
        Request $request
    ): ?User {
        $token = $request->bearerToken()
            ?: $request->input('api_token');

        if (
            !$token
            || !Schema::hasColumn('users', 'api_token')
        ) {
            return null;
        }

        return User::query()
            ->where('api_token', $token)
            ->first();
    }

    private function ownedProperty(
        int $propertyId,
        User $user
    ): ?DynamicPost {
        $propertyPostTypeId = DB::table('post_types')
            ->where('slug', 'property-listing')
            ->value('id');

        if (!$propertyPostTypeId) {
            return null;
        }

        return DynamicPost::query()
            ->where('id', $propertyId)
            ->where('post_type_id', $propertyPostTypeId)
            ->where('author_id', $user->id)
            ->first();
    }
}
