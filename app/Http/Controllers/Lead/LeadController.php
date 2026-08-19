<?php

namespace App\Http\Controllers\Lead;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\DynamicPost;
use App\Models\PostType;
use App\Models\User;
use App\Models\MediaFile;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use App\Jobs\SendLeadOtpEmailJob;
use Throwable;

class LeadController extends Controller
{
    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Validation failed'
            ], 422);
        }

        $phone = $request->phone;
        $email = $request->email;
        $otp = rand(100000, 999999);
        $now = now();

        DB::table('lead_otps')->updateOrInsert(
            ['phone' => $phone],
            [
                'otp' => $otp,
                'email' => $email,
                'expires_at' => $now->copy()->addMinutes(5),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        if ($email) {
            try {
                $settings = Cache::store('redis')->remember('active_mail_config', 300, function () {
                    return DB::table('mail_configs')->where('status', 1)->first();
                });

                if ($settings) {
                    config([
                        'mail.mailers.smtp.host' => $settings->host,
                        'mail.mailers.smtp.port' => $settings->port,
                        'mail.mailers.smtp.username' => $settings->username,
                        'mail.mailers.smtp.password' => $settings->password,
                        'mail.mailers.smtp.encryption' => $settings->encryption,
                        'mail.from.address' => $settings->from_address,
                        'mail.from.name' => $settings->from_name,
                    ]);
                }

                $emailData = [
                    'otp' => $otp,
                    'phone' => $phone,
                    'expiry' => '5 minutes',
                    'fullName' => $request->name ?? 'User'
                ];

                SendLeadOtpEmailJob::dispatch($emailData, $email);
            } catch (\Exception $e) {
                return response()->json([
                    'status' => false,
                    'message' => 'Failed to send OTP email',
                    'error' => $e->getMessage(),
                ], 500);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'OTP sent successfully',
            'data' => [
                'phone' => $phone,
                'email_sent' => !empty($email)
            ]
        ]);
    }

    public function index()
    {
        try {
            $leads = Lead::with([
                'leadType',
                'dynamicPost.postType',
                'dynamicPost.currentFeaturedPromotion',
                'postType',
            ])
            ->latest()
            ->get();

            $allUserIds = $leads->pluck('user_ids')
                ->flatten()
                ->filter()
                ->unique()
                ->values();

            $users = User::whereIn('id', $allUserIds)
                ->select('id', 'first_name', 'last_name', 'email', 'phone', 'area_locality', 'role_id')
                ->get()
                ->keyBy('id');

            $leads->transform(function ($lead) use ($users) {
                return $this->formatLeadResponse($lead, $users);
            });

            return response()->json([
                'success' => true,
                'message' => 'Leads retrieved successfully',
                'data' => $leads,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve leads.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $user = auth('sanctum')->user();
        if (!$user && $request->bearerToken()) {
            $user = User::where('api_token', $request->bearerToken())->first();
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:20',
            'message' => 'nullable|string',
            'lead_type_id' => 'required|exists:lead_types,id',
            'dynamic_post_id' => 'nullable|exists:dynamic_posts,id',
            'post_type_id' => 'nullable|exists:post_types,id',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
            'otp' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Validation failed'
            ], 422);
        }

        $dynamicPostId = $request->dynamic_post_id;

        if (empty($dynamicPostId) && empty($request->user_ids)) {
            return response()->json([
                'success' => false,
                'errors' => ['relation_error' => 'At least one of dynamic_post_id or user_ids must be selected.'],
                'message' => 'Validation failed'
            ], 422);
        }

        if (!$user) {
            if (empty($request->otp)) {
                return response()->json([
                    'success' => false,
                    'errors' => ['otp' => 'OTP is required for guest users.'],
                    'message' => 'Validation failed'
                ], 422);
            }

            $otpData = DB::table('lead_otps')
                ->where('phone', $request->phone)
                ->where('otp', trim($request->otp))
                ->first();

            if (!$otpData) {
                return response()->json([
                    'success' => false,
                    'errors' => ['otp' => 'Invalid OTP'],
                    'message' => 'OTP verification failed'
                ], 422);
            }

            if ($otpData->expires_at < now()) {
                return response()->json([
                    'success' => false,
                    'errors' => ['otp' => 'OTP has expired'],
                    'message' => 'OTP verification failed'
                ], 422);
            }

            DB::table('lead_otps')->where('phone', $request->phone)->delete();
        }

        $postTypeId = $request->post_type_id;
        $userIds = $request->user_ids ?? [];

        if ($dynamicPostId) {
            $dynamicPost = DynamicPost::find($dynamicPostId);
            if ($dynamicPost) {
                $postTypeId = $dynamicPost->post_type_id;
                if (empty($userIds) && !empty($dynamicPost->author_id)) {
                    $userIds[] = (int) $dynamicPost->author_id;
                }
            }
        }

        $lead = Lead::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'message' => $request->message,
            'dynamic_post_id' => $dynamicPostId ? (int) $dynamicPostId : null,
            'post_type_id' => $postTypeId ? (int) $postTypeId : null,
            'user_ids' => array_values(array_unique(array_filter($userIds))),
            'lead_type_id' => (int) $request->lead_type_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Lead created successfully',
            'data' => $this->formatLeadResponse($lead->load(['leadType', 'dynamicPost.postType'])),
        ], 201);
    }

    public function storeByAdmin(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'phone' => 'required|string|max:20',
                'message' => 'nullable|string',
                'lead_type_id' => 'required|exists:lead_types,id',
                'dynamic_post_id' => 'nullable|exists:dynamic_posts,id',
                'post_type_id' => 'nullable|exists:post_types,id',
                'user_ids' => 'nullable|array',
                'user_ids.*' => 'exists:users,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                    'message' => 'Validation failed',
                ], 422);
            }

            $dynamicPostId = $request->dynamic_post_id;

            if (empty($dynamicPostId) && empty($request->user_ids)) {
                return response()->json([
                    'success' => false,
                    'errors' => ['relation_error' => 'At least one of dynamic_post_id or user_ids must be selected.'],
                    'message' => 'Validation failed'
                ], 422);
            }

            $postTypeId = $request->post_type_id;
            $userIds = $request->user_ids ?? [];

            if ($dynamicPostId) {
                $dynamicPost = DynamicPost::find($dynamicPostId);
                if ($dynamicPost) {
                    $postTypeId = $dynamicPost->post_type_id;
                    if (empty($userIds) && !empty($dynamicPost->author_id)) {
                        $userIds[] = (int) $dynamicPost->author_id;
                    }
                }
            }

            $lead = Lead::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'message' => $request->message,
                'dynamic_post_id' => $dynamicPostId ? (int) $dynamicPostId : null,
                'post_type_id' => $postTypeId ? (int) $postTypeId : null,
                'lead_type_id' => (int) $request->lead_type_id,
                'user_ids' => array_values(array_unique(array_filter($userIds))),
                'created_by_admin' => auth('sanctum')->id() ?? null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Lead created successfully (Admin)',
                'data' => $this->formatLeadResponse($lead->load(['leadType', 'dynamicPost.postType'])),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $lead = Lead::with([
                'leadType',
                'dynamicPost.postType',
                'dynamicPost.currentFeaturedPromotion',
                'postType',
                'createdByAdmin',
            ])->find($id);

            if (!$lead) {
                return response()->json([
                    'success' => false,
                    'message' => 'Lead not found'
                ], 404);
            }

            $userIds = $lead->user_ids ?? [];
            $users = User::whereIn('id', $userIds)
                ->with(['userDetail', 'role'])
                ->get()
                ->keyBy('id');

            $formatted = $this->formatLeadResponse($lead, $users);

            return response()->json([
                'success' => true,
                'message' => 'Lead retrieved successfully',
                'data' => $formatted,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve lead details.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $lead = Lead::find($id);
        if (!$lead) {
            return response()->json([
                'success' => false,
                'message' => 'Lead not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|max:255',
            'phone' => 'sometimes|required|string|max:20',
            'message' => 'nullable|string',
            'lead_type_id' => 'sometimes|exists:lead_types,id',
            'dynamic_post_id' => 'nullable|exists:dynamic_posts,id',
            'post_type_id' => 'nullable|exists:post_types,id',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Validation failed'
            ], 422);
        }

        $dynamicPostId = $request->dynamic_post_id ?? $lead->dynamic_post_id;
        $postTypeId = $request->post_type_id ?? $lead->post_type_id;
        $userIds = $request->has('user_ids') ? ($request->user_ids ?? []) : ($lead->user_ids ?? []);

        if ($dynamicPostId) {
            $dynamicPost = DynamicPost::find($dynamicPostId);
            if ($dynamicPost) {
                $postTypeId = $dynamicPost->post_type_id;
                if (empty($userIds) && !empty($dynamicPost->author_id)) {
                    $userIds[] = (int) $dynamicPost->author_id;
                }
            }
        }

        $lead->update([
            'name' => $request->name ?? $lead->name,
            'email' => $request->email ?? $lead->email,
            'phone' => $request->phone ?? $lead->phone,
            'message' => $request->message ?? $lead->message,
            'dynamic_post_id' => $dynamicPostId ? (int) $dynamicPostId : null,
            'post_type_id' => $postTypeId ? (int) $postTypeId : null,
            'user_ids' => array_values(array_unique(array_filter($userIds))),
            'lead_type_id' => $request->lead_type_id ? (int) $request->lead_type_id : $lead->lead_type_id,
            'updated_at' => Carbon::now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Lead updated successfully',
            'data' => $this->formatLeadResponse($lead->fresh(['leadType', 'dynamicPost.postType'])),
        ], 200);
    }

    public function destroy($id)
    {
        $lead = Lead::find($id);

        if (!$lead) {
            return response()->json([
                'success' => false,
                'message' => 'Lead not found'
            ], 404);
        }

        $lead->delete();

        return response()->json([
            'success' => true,
            'message' => 'Lead deleted successfully'
        ]);
    }

    public function assignUserLead(Request $request)
    {
        $user = auth('sanctum')->user();
        if (!$user && $request->bearerToken()) {
            $user = User::where('api_token', $request->bearerToken())->first();
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $role = DB::table('roles')->where('id', $user->role_id)->first();
        $filterUserId = $request->user_id;

        $query = Lead::with(['leadType', 'dynamicPost.postType', 'postType']);

        if ($role && strtolower($role->name) === 'admin') {
            if ($filterUserId) {
                $query->where(function ($q) use ($filterUserId) {
                    $q->whereJsonContains('user_ids', (string) $filterUserId)
                        ->orWhereJsonContains('user_ids', (int) $filterUserId);
                });
            }
        } else {
            $query->where(function ($q) use ($user) {
                $q->whereJsonContains('user_ids', (string) $user->id)
                    ->orWhereJsonContains('user_ids', (int) $user->id);
            });
        }

        $leads = $query->latest()->get();

        $allUserIds = $leads->pluck('user_ids')->flatten()->filter()->unique()->values();
        $users = User::whereIn('id', $allUserIds)
            ->select('id', 'first_name', 'last_name', 'email', 'phone', 'area_locality')
            ->get()
            ->keyBy('id');

        $leads->transform(function ($lead) use ($users) {
            return $this->formatLeadResponse($lead, $users);
        });

        return response()->json([
            'success' => true,
            'message' => 'Leads retrieved successfully',
            'data' => $leads,
        ]);
    }

    private function formatLeadResponse(Lead $lead, $users = null): array
    {
        $data = $lead->toArray();

        $dynamicPost = $lead->dynamicPost;
        $postData = null;

        if ($dynamicPost) {
            $featuredMedia = null;
            if (!empty($dynamicPost->featured_image_id)) {
                $media = MediaFile::find($dynamicPost->featured_image_id);
                if ($media) {
                    $featuredMedia = [
                        'id' => (int) $media->id,
                        'url' => $media->url ?? ($media->path ? asset('storage/' . $media->path) : null),
                    ];
                }
            }

            $postData = [
                'id' => (int) $dynamicPost->id,
                'title' => $dynamicPost->title ?? null,
                'slug' => $dynamicPost->slug ?? null,
                'listing_code' => $dynamicPost->listing_code ?? null,
                'status' => $dynamicPost->status ?? null,
                'live_status' => $dynamicPost->live_status ?? null,
                'post_type' => $dynamicPost->postType ? [
                    'id' => (int) $dynamicPost->postType->id,
                    'name' => $dynamicPost->postType->name,
                    'slug' => $dynamicPost->postType->slug,
                ] : null,
                'author_id' => $dynamicPost->author_id ? (int) $dynamicPost->author_id : null,
                'featured_image_url' => $featuredMedia['url'] ?? null,
                'area_locality' => $dynamicPost->area_locality ?? null,
            ];
        }

        $data['dynamic_post_id'] = $lead->dynamic_post_id ? (int) $lead->dynamic_post_id : null;
        $data['post_type_id'] = $lead->post_type_id ? (int) $lead->post_type_id : null;
        $data['dynamic_post'] = $postData;

        if ($users) {
            $data['users'] = collect($lead->user_ids ?? [])
                ->map(fn($id) => $users->get((int) $id))
                ->filter()
                ->values()
                ->toArray();
        }

        return $data;
    }
}
