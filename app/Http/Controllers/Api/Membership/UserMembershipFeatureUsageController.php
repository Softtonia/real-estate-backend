<?php

namespace App\Http\Controllers\Api\Membership;

use App\Http\Controllers\Controller;
use App\Http\Requests\Membership\ConsumeMembershipFeatureRequest;
use App\Http\Requests\Membership\UnlockMembershipLeadRequest;
use App\Models\DynamicPost;
use App\Models\Membership\MembershipCreditBalance;
use App\Models\PropertyFeaturedPromotion;
use App\Models\User;
use App\Services\FeaturedProperty\FeaturedPropertyService;
use App\Services\Membership\MembershipCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class UserMembershipFeatureUsageController extends Controller
{
    public function unlockLead(
        UnlockMembershipLeadRequest $request,
        MembershipCreditService $creditService
    ): JsonResponse {
        try {
            $user = $this->authenticatedUserOrFail($request);
            $data = $request->validated();

            $unlock = $creditService->unlockLeadOnce(
                user: $user,
                leadReferenceType: $data['lead_reference_type'],
                leadReferenceId: (int) $data['lead_reference_id'],
                performedBy: $user,
                metadata: $data['metadata'] ?? []
            );

            return response()->json([
                'status' => true,
                'message' => $unlock['was_already_unlocked'] ?? false
                    ? 'Lead was already unlocked.'
                    : 'Lead unlocked successfully.',
                'data' => $unlock,
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to unlock lead.', $e);
        }
    }

    public function consumeFeature(
        ConsumeMembershipFeatureRequest $request,
        MembershipCreditService $creditService
    ): JsonResponse {
        try {
            $user = $this->authenticatedUserOrFail($request);
            $data = $request->validated();

            $this->guardReferenceType(
                creditType: $data['credit_type'],
                referenceType: $data['reference_type']
            );

            $transaction = $creditService->consumeFeatureCreditOnce(
                user: $user,
                creditType: $data['credit_type'],
                referenceType: $data['reference_type'],
                referenceId: (int) $data['reference_id'],
                quantity: (int) ($data['quantity'] ?? 1),
                performedBy: $user,
                reason: $data['reason'] ?? $this->defaultReason($data['credit_type']),
                metadata: $data['metadata'] ?? []
            );

            return response()->json([
                'status' => true,
                'message' => 'Membership feature credit consumed successfully.',
                'data' => [
                    'transaction_id' => (int) $transaction->id,
                    'credit_type' => $transaction->credit_type,
                    'transaction_type' => $transaction->transaction_type,
                    'quantity' => (int) $transaction->quantity,
                    'balance_before' => $transaction->balance_before !== null ? (int) $transaction->balance_before : null,
                    'balance_after' => $transaction->balance_after !== null ? (int) $transaction->balance_after : null,
                    'reference_type' => $transaction->reference_type,
                    'reference_id' => $transaction->reference_id ? (int) $transaction->reference_id : null,
                    'created_at' => optional($transaction->created_at)->toDateTimeString(),
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to consume membership feature credit.', $e);
        }
    }

    private function guardReferenceType(string $creditType, string $referenceType): void
    {
        $allowed = [
            MembershipCreditBalance::TYPE_FEATURED_LISTING => [
                'listing',
                'dynamic_post',
                'frontend_listing',
            ],

            MembershipCreditBalance::TYPE_BOOST => [
                'listing',
                'dynamic_post',
                'frontend_listing',
            ],

            MembershipCreditBalance::TYPE_LEAD_VIEW => [
                'lead',
                'property_lead',
                'inquiry',
                'contact_request',
            ],

            MembershipCreditBalance::TYPE_VIDEO_UPLOAD => [
                'listing_video',
                'dynamic_post_video',
                'frontend_listing_video',
            ],

            MembershipCreditBalance::TYPE_VIRTUAL_TOUR => [
                'listing_virtual_tour',
                'dynamic_post_virtual_tour',
                'frontend_listing_virtual_tour',
            ],

            MembershipCreditBalance::TYPE_AI_DESCRIPTION => [
                'listing_ai_description',
                'dynamic_post_ai_description',
                'frontend_listing_ai_description',
            ],
        ];

        if (!in_array($referenceType, $allowed[$creditType] ?? [], true)) {
            throw ValidationException::withMessages([
                'reference_type' => ['This reference type is not allowed for selected credit type.'],
            ]);
        }
    }

    private function defaultReason(string $creditType): string
    {
        return match ($creditType) {
            MembershipCreditBalance::TYPE_FEATURED_LISTING => 'Featured listing credit used.',
            MembershipCreditBalance::TYPE_BOOST => 'Listing boost credit used.',
            MembershipCreditBalance::TYPE_LEAD_VIEW => 'Lead view credit used.',
            MembershipCreditBalance::TYPE_VIDEO_UPLOAD => 'Video upload credit used.',
            MembershipCreditBalance::TYPE_VIRTUAL_TOUR => 'Virtual tour credit used.',
            MembershipCreditBalance::TYPE_AI_DESCRIPTION => 'AI description credit used.',
            default => 'Membership feature credit used.',
        };
    }

    private function authenticatedUserOrFail(Request $request): User
    {
        $user = $this->resolveCurrentUser($request);

        if (!$user) {
            throw ValidationException::withMessages([
                'auth' => ['Unauthenticated user.'],
            ]);
        }

        if ($this->isAdminUser($user)) {
            throw ValidationException::withMessages([
                'auth' => ['Admin token is not allowed for frontend membership feature API.'],
            ]);
        }

        return $user;
    }

    private function resolveCurrentUser(Request $request): ?User
    {
        $token = $request->bearerToken()
            ?: $request->header('api-token')
            ?: $request->header('api_token')
            ?: $request->input('api_token');

        if ($token && Schema::hasColumn('users', 'api_token')) {
            $user = User::query()->where('api_token', $token)->first();

            if ($user) {
                return $user;
            }
        }

        $authUser = $request->user() ?: Auth::user();

        return $authUser instanceof User ? $authUser : null;
    }

    private function isAdminUser(User $user): bool
    {
        if ((int) $user->id === 1 || (string) $user->role_id === '1') {
            return true;
        }

        if (!Schema::hasTable('roles') || !$user->role_id || !is_numeric($user->role_id)) {
            return false;
        }

        $role = \App\Models\Role::query()->find((int) $user->role_id);

        if (!$role) {
            return false;
        }

        foreach (['name', 'role_name', 'title'] as $column) {
            if (Schema::hasColumn('roles', $column) && isset($role->{$column})) {
                $roleName = strtolower(str_replace([' ', '_', '-'], '', (string) $role->{$column}));

                return in_array($roleName, [
                    'admin',
                    'administrator',
                    'superadmin',
                    'superadministrator',
                ], true);
            }
        }

        return false;
    }

    public function featureListing(
        Request $request,
        FeaturedPropertyService $featuredService,
        MembershipCreditService $creditService
    ): JsonResponse {
        try {
            $user = $this->authenticatedUserOrFail($request);

            $request->validate([
                'listing_id' => ['nullable', 'integer'],
                'dynamic_post_id' => ['nullable', 'integer'],
            ]);

            $listingId = (int) ($request->input('listing_id') ?? $request->input('dynamic_post_id'));

            if ($listingId <= 0) {
                return response()->json([
                    'status' => false,
                    'message' => 'Valid listing_id or dynamic_post_id is required.',
                ], 422);
            }

            $listing = DynamicPost::query()->find($listingId);

            if (!$listing) {
                return response()->json([
                    'status' => false,
                    'message' => 'Property listing not found.',
                ], 404);
            }

            if (Schema::hasColumn('dynamic_posts', 'author_id') && (int) $listing->author_id !== (int) $user->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'You can only feature your own property listing.',
                ], 403);
            }

            $activePromotion = PropertyFeaturedPromotion::query()
                ->where('dynamic_post_id', $listing->id)
                ->whereNull('cancelled_at')
                ->where('status', PropertyFeaturedPromotion::STATUS_ACTIVE)
                ->where(function ($query) {
                    $query->whereNull('ends_at')
                        ->orWhere('ends_at', '>', now());
                })
                ->first();

            if ($activePromotion) {
                return response()->json([
                    'status' => true,
                    'message' => 'Property listing is already featured.',
                    'data' => [
                        'is_featured' => true,
                        'promotion_id' => (int) $activePromotion->id,
                        'dynamic_post_id' => (int) $listing->id,
                        'promotion' => $activePromotion,
                    ],
                ]);
            }

            $balance = $creditService->activeBalance($user, MembershipCreditBalance::TYPE_FEATURED_LISTING);

            if (!$balance || (!$balance->is_unlimited && (int) $balance->remaining_credits < 1)) {
                return response()->json([
                    'status' => false,
                    'message' => 'You do not have any available featured listing credits in your membership plan.',
                    'error' => [
                        'credits' => ['Insufficient featured listing credits. Please upgrade your membership plan or purchase a featured listing add-on.'],
                    ],
                ], 422);
            }

            $transaction = $creditService->consumeCredit(
                user: $user,
                creditType: MembershipCreditBalance::TYPE_FEATURED_LISTING,
                quantity: 1,
                referenceType: 'dynamic_post',
                referenceId: (int) $listing->id,
                reason: 'Property featured (starred) by user.',
                performedBy: $user,
                metadata: ['action' => 'user_star_featured']
            );

            $endsAt = $balance->expires_at
                ? $balance->expires_at->toDateTimeString()
                : now()->addDays(30)->toDateTimeString();

            $promotion = $featuredService->createForMembership([
                'dynamic_post_id' => (int) $listing->id,
                'promotion_type' => PropertyFeaturedPromotion::TYPE_FEATURED,
                'show_on_home' => true,
                'show_on_search' => true,
                'show_on_detail' => true,
                'starts_at' => now()->toDateTimeString(),
                'ends_at' => $endsAt,
                'priority' => 10,
            ], $user);

            $remainingCredits = $balance->is_unlimited ? 'unlimited' : max(0, (int) $balance->remaining_credits - 1);

            return response()->json([
                'status' => true,
                'message' => 'Property featured (starred) successfully.',
                'data' => [
                    'is_featured' => true,
                    'promotion_id' => (int) $promotion->id,
                    'dynamic_post_id' => (int) $listing->id,
                    'remaining_featured_credits' => $remainingCredits,
                    'promotion' => $promotion,
                    'transaction' => [
                        'id' => (int) $transaction->id,
                        'quantity' => (int) $transaction->quantity,
                        'balance_after' => $transaction->balance_after,
                    ],
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to feature property listing.', $e);
        }
    }

    public function unfeatureListing(
        Request $request,
        FeaturedPropertyService $featuredService
    ): JsonResponse {
        try {
            $user = $this->authenticatedUserOrFail($request);

            $request->validate([
                'listing_id' => ['nullable', 'integer'],
                'dynamic_post_id' => ['nullable', 'integer'],
            ]);

            $listingId = (int) ($request->input('listing_id') ?? $request->input('dynamic_post_id'));

            if ($listingId <= 0) {
                return response()->json([
                    'status' => false,
                    'message' => 'Valid listing_id or dynamic_post_id is required.',
                ], 422);
            }

            $listing = DynamicPost::query()->find($listingId);

            if (!$listing) {
                return response()->json([
                    'status' => false,
                    'message' => 'Property listing not found.',
                ], 404);
            }

            if (Schema::hasColumn('dynamic_posts', 'author_id') && (int) $listing->author_id !== (int) $user->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'You can only unfeature your own property listing.',
                ], 403);
            }

            $activePromotion = PropertyFeaturedPromotion::query()
                ->where('dynamic_post_id', $listing->id)
                ->whereNull('cancelled_at')
                ->where('status', PropertyFeaturedPromotion::STATUS_ACTIVE)
                ->where(function ($query) {
                    $query->whereNull('ends_at')
                        ->orWhere('ends_at', '>', now());
                })
                ->first();

            if (!$activePromotion) {
                return response()->json([
                    'status' => true,
                    'message' => 'Property listing is not currently featured.',
                    'data' => [
                        'is_featured' => false,
                        'dynamic_post_id' => (int) $listing->id,
                    ],
                ]);
            }

            $cancelled = $featuredService->cancel($activePromotion, $user, 'Unstarred by user');

            return response()->json([
                'status' => true,
                'message' => 'Property unfeatured (unstarred) successfully.',
                'data' => [
                    'is_featured' => false,
                    'dynamic_post_id' => (int) $listing->id,
                    'promotion' => $cancelled,
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to unfeature property listing.', $e);
        }
    }

    public function toggleFeaturedListing(
        Request $request,
        FeaturedPropertyService $featuredService,
        MembershipCreditService $creditService
    ): JsonResponse {
        $listingId = (int) ($request->input('listing_id') ?? $request->input('dynamic_post_id'));

        if ($listingId <= 0) {
            return response()->json([
                'status' => false,
                'message' => 'Valid listing_id or dynamic_post_id is required.',
            ], 422);
        }

        $activePromotion = PropertyFeaturedPromotion::query()
            ->where('dynamic_post_id', $listingId)
            ->whereNull('cancelled_at')
            ->where('status', PropertyFeaturedPromotion::STATUS_ACTIVE)
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->first();

        if ($activePromotion) {
            return $this->unfeatureListing($request, $featuredService);
        }

        return $this->featureListing($request, $featuredService, $creditService);
    }

    public function featuredStatus(
        Request $request,
        int $listingId,
        MembershipCreditService $creditService
    ): JsonResponse {
        try {
            $user = $this->authenticatedUserOrFail($request);

            $listing = DynamicPost::query()->find($listingId);

            if (!$listing) {
                return response()->json([
                    'status' => false,
                    'message' => 'Property listing not found.',
                ], 404);
            }

            if (Schema::hasColumn('dynamic_posts', 'author_id') && (int) $listing->author_id !== (int) $user->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'You can only view status of your own property listing.',
                ], 403);
            }

            $activePromotion = PropertyFeaturedPromotion::query()
                ->where('dynamic_post_id', $listing->id)
                ->whereNull('cancelled_at')
                ->where('status', PropertyFeaturedPromotion::STATUS_ACTIVE)
                ->where(function ($query) {
                    $query->whereNull('ends_at')
                        ->orWhere('ends_at', '>', now());
                })
                ->first();

            $balance = $creditService->activeBalance($user, MembershipCreditBalance::TYPE_FEATURED_LISTING);

            $hasCredits = $balance && ($balance->is_unlimited || (int) $balance->remaining_credits > 0);
            $remaining = $balance
                ? ($balance->is_unlimited ? 'unlimited' : (int) $balance->remaining_credits)
                : 0;

            return response()->json([
                'status' => true,
                'message' => 'Featured status fetched successfully.',
                'data' => [
                    'dynamic_post_id' => (int) $listing->id,
                    'is_featured' => $activePromotion !== null,
                    'can_feature' => $activePromotion === null && $hasCredits,
                    'remaining_featured_credits' => $remaining,
                    'promotion' => $activePromotion,
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch featured status.', $e);
        }
    }

    private function validationError(ValidationException $e): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'Validation failed.',
            'error' => $e->errors(),
        ], 422);
    }

    private function serverError(string $message, Throwable $e): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'error' => config('app.debug') ? $e->getMessage() : 'Server error',
        ], 500);
    }
}