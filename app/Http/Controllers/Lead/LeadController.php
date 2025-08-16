<?php

namespace App\Http\Controllers\Lead;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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

        $otp = rand(100000, 999999); // 6 digit OTP

        // OTP ko DB me save karo (temporary table or leads_otp table)
        DB::table('lead_otps')->updateOrInsert(
            ['phone' => $request->phone],
            [
                'otp' => $otp,
                'expires_at' => now()->addMinutes(5),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Yahan SMS/Email service se OTP bhejna hoga
        // Example: SmsService::send($request->phone, "Your OTP is $otp");

        return response()->json([
            'success' => true,
            'message' => 'OTP sent successfully'
        ]);
    }



    public function index()
    {
        // Fetch all leads with relationships
        $leads = Lead::with(['property', 'project', 'developer'])->get();

        // Collect all user_ids from all leads (already array because of casts)
        $allUserIds = $leads->pluck('user_ids')
            ->flatten()
            ->unique()
            ->values();

        // Fetch users only once
        $users = User::whereIn('id', $allUserIds)
            ->select('id', 'first_name', 'last_name', 'email', 'phone', 'area_locality')
            ->get()
            ->keyBy('id');

        // Inject users per lead
        $leads->map(function ($lead) use ($users) {
            $userIds = $lead->user_ids ?? []; // ✅ no json_decode needed
            $lead->users = collect($userIds)
                ->map(fn($id) => $users->get($id))
                ->filter()
                ->values(); // ensure clean array
            return $lead;
        });

        return response()->json([
            'success' => true,
            'message' => 'Leads retrieved successfully',
            'data' => $leads,
        ]);
    }





    public function store(Request $request)
    {
        // Detect logged-in user
        $user = auth('sanctum')->user();
        if (!$user && $request->bearerToken()) {
            $user = User::where('api_token', $request->bearerToken())->first();
        }

        // Common validation first
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:20',
            'message' => 'nullable|string',
            'property_id' => 'nullable|exists:properties_listing,id',
            'project_id' => 'nullable|exists:project_listings,id',
            'developer_id' => 'nullable|exists:developer_listings,id',
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

        // Ensure at least one relation
        if (
            empty($request->property_id) &&
            empty($request->project_id) &&
            empty($request->developer_id) &&
            empty($request->user_ids)
        ) {
            return response()->json([
                'success' => false,
                'errors' => ['relation_error' => 'At least one of Property, Project, Developer or User must be selected.'],
                'message' => 'Validation failed'
            ], 422);
        }

        // Guest → must provide OTP
        if (!$user) {
            if (empty($request->otp)) {
                return response()->json([
                    'success' => false,
                    'errors' => ['otp' => 'OTP is required for guest users.'],
                    'message' => 'Validation failed'
                ], 422);
            }

            // Check if OTP exists for given phone
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

            // Check expiration
            if ($otpData->expires_at < now()) {
                return response()->json([
                    'success' => false,
                    'errors' => ['otp' => 'OTP has expired'],
                    'message' => 'OTP verification failed'
                ], 422);
            }

            // Delete OTP after success
            DB::table('lead_otps')->where('phone', $request->phone)->delete();
        }

        // Collect user_ids
        $userIds = $request->user_ids ?? [];
        if ($user) {
            $userIds[] = $user->id;
        }

        $lead = Lead::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'message' => $request->message,
            'property_id' => $request->property_id,
            'project_id' => $request->project_id,
            'developer_id' => $request->developer_id,
            'user_ids' => array_values(array_unique($userIds)),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Lead created successfully',
            'data' => $lead,
        ], 201);
    }



    /**
     * Display the specified resource by ID.
     */
    public function show($id)
    {
        // Find lead with relationships
        $lead = Lead::with(['property', 'project', 'developer'])->find($id);

        if (!$lead) {
            return response()->json([
                'success' => false,
                'message' => 'Lead not found'
            ], 404);
        }

        // Get user_ids (already array because of casts)
        $userIds = $lead->user_ids ?? [];

        // Fetch related users
        $users = User::whereIn('id', $userIds)
            ->select('id', 'first_name', 'last_name', 'email', 'phone', 'area_locality')
            ->get()
            ->keyBy('id');

        // Attach users to this lead
        $lead->users = collect($userIds)
            ->map(fn($id) => $users->get($id))
            ->filter()
            ->values(); // clean array

        return response()->json([
            'success' => true,
            'message' => 'Lead retrieved successfully',
            'data' => $lead,
        ]);
    }





    public function update(Request $request, $id)
    {
        // Find the lead
        $lead = Lead::find($id);
        if (!$lead) {
            return response()->json([
                'success' => false,
                'message' => 'Lead not found'
            ], 404);
        }

        // Validation
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:20',
            'message' => 'nullable|string',
            'property_id' => 'nullable|exists:properties_listing,id',
            'project_id' => 'nullable|exists:project_listings,id',
            'developer_id' => 'nullable|exists:developer_listings,id',
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

        // Ensure at least one relation
        if (
            empty($request->property_id) &&
            empty($request->project_id) &&
            empty($request->developer_id) &&
            empty($request->user_ids)
        ) {
            return response()->json([
                'success' => false,
                'errors' => ['relation_error' => 'At least one of Property, Project, Developer or User must be selected.'],
                'message' => 'Validation failed'
            ], 422);
        }

        // Merge existing user_ids with new ones if needed
        // Merge existing user_ids with new ones
        $userIds = $request->user_ids ?? [];
        $existingUserIds = $lead->user_ids ?? []; // ✅ Already array (model casts)
        $allUserIds = array_values(array_unique(array_merge($existingUserIds, $userIds)));

        // Update lead
        $lead->update([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'message' => $request->message,
            'property_id' => $request->property_id,
            'project_id' => $request->project_id,
            'developer_id' => $request->developer_id,
            'user_ids' => $allUserIds,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Lead updated successfully',
            'data' => $lead,
        ]);
    }





    /**
     * Remove the specified resource by ID.
     */
    public function destroy($id)
    {
        $lead = Lead::find($id);

        if (!$lead) {
            return response()->json([
                'success' => false,
                'message' => 'Lead not found'
            ], 200);
        }

        $lead->delete();

        return response()->json([
            'success' => true,
            'message' => 'Lead deleted successfully'
        ]);
    }





    // public function assignUserLead(Request $request)
    // {
    //     $user = auth('sanctum')->user();
    //     if (!$user && $request->bearerToken()) {
    //         $user = User::where('api_token', $request->bearerToken())->first();
    //     }
    //     if (!$user) {
    //         return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
    //     }

    //     $filterUserId = $request->user_id;

    //     $query = Lead::with(['property', 'project', 'developer']);

    //     if ($user->role !== 'admin') {
    //         // Normal user: apne leads
    //         $query->whereRaw('JSON_CONTAINS(user_ids, ?)', [json_encode($user->id)]);
    //     } elseif ($filterUserId) {
    //         // Admin: filter by user_id if provided
    //         $query->whereRaw('JSON_CONTAINS(user_ids, ?)', [json_encode($filterUserId)]);
    //     }

    //     $leads = $query->get();

    //     // Extract all unique user IDs from leads
    //     $allUserIds = $leads->pluck('user_ids')
    //         ->map(function ($ids) {
    //             // First try to decode as JSON
    //             $decoded = json_decode($ids, true);

    //             // If decoding fails, try to parse as string
    //             if (json_last_error() !== JSON_ERROR_NONE) {
    //                 // Handle cases where the format might be different
    //                 $cleaned = trim($ids, '[]"\'');
    //                 $decoded = explode(',', $cleaned);
    //             }

    //             // Ensure we return an array of integers
    //             return array_map('intval', array_filter($decoded));
    //         })
    //         ->flatten()
    //         ->unique()
    //         ->values();

    //     // Get users data
    //     $users = User::whereIn('id', $allUserIds)
    //         ->select('id', 'first_name', 'last_name', 'email', 'phone', 'area_locality')
    //         ->get()
    //         ->keyBy('id');

    //     // Map users to leads
    //     $leads->map(function ($lead) use ($users) {
    //         $userIds = json_decode($lead->user_ids, true);

    //         if (json_last_error() !== JSON_ERROR_NONE) {
    //             // Fallback if JSON decode fails
    //             $cleaned = trim($lead->user_ids, '[]"\'');
    //             $userIds = explode(',', $cleaned);
    //         }

    //         $userIds = array_map('intval', (array) $userIds);

    //         $lead->users = collect($userIds)
    //             ->map(fn($id) => $users->get($id))
    //             ->filter()
    //             ->values();

    //         return $lead;
    //     });

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Leads retrieved successfully',
    //         'data' => $leads,
    //     ]);
    // }



  public function assignUserLead(Request $request)
{
    $user = auth('sanctum')->user();

    // Agar sanctum se user na mile to api_token check karo
    if (!$user && $request->bearerToken()) {
        $user = User::where('api_token', $request->bearerToken())->first();
    }

    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized'
        ], 401);
    }

    $filterUserId = $request->user_id;

    $query = Lead::with(['property', 'project', 'developer']);

    if ($user->role !== 'admin') {
        // Normal user: sirf apne leads
        $query->whereJsonContains('user_ids', (string) $user->id);
    } elseif ($filterUserId) {
        // Admin: specific user ke leads dekhna chahe
        $query->whereJsonContains('user_ids', (string) $filterUserId);
    }

    $leads = $query->get();

    // Extract all unique user_ids
    $allUserIds = $leads->pluck('user_ids')->flatten()->unique()->values();

    // Fetch all users in one query
    $users = User::whereIn('id', $allUserIds)
        ->select('id', 'first_name', 'last_name', 'email', 'phone', 'area_locality')
        ->get()
        ->keyBy('id');

    // Attach users
    $leads->map(function ($lead) use ($users) {
        $lead->users = collect($lead->user_ids)
            ->map(fn($id) => $users->get((int) $id))
            ->filter()
            ->values();
        return $lead;
    });

    return response()->json([
        'success' => true,
        'message' => 'Leads retrieved successfully',
        'data' => $leads,
    ]);
}







}
