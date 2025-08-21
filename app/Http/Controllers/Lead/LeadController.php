<?php

namespace App\Http\Controllers\Lead;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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

        DB::table('lead_otps')->updateOrInsert(
            ['phone' => $request->phone],
            [
                'otp' => $otp,
                'email' => $request->email,
                'expires_at' => now()->addMinutes(5),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );


        $settings = DB::table('mail_configs')->where('status', 1)->first();
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


        if ($request->email) {
            try {
                $emailData = [
                    'otp' => $otp,
                    'phone' => $request->phone,
                    'expiry' => '5 minutes',
                    'fullName' => $request->name ?? 'User'
                ];

                Mail::send('emails.otp', $emailData, function ($message) use ($request) {
                    $message->to($request->email)
                        ->subject('Your Mobile OTP Verification Code');
                });

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
                'phone' => $request->phone,
                'email_sent' => !empty($request->email)
            ]
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

            // ✅ Add full URL for property image
            if ($lead->property) {
                $lead->property->featured_image_url = $lead->property->featured_image
                    ? url($lead->property->featured_image)
                    : null;
            }

            // ✅ Add full URL for project image
            if ($lead->project) {
                $lead->project->featured_image_url = $lead->project->featured_image
                    ? url($lead->project->featured_image)
                    : null;
            }

            // ✅ Add full URL for developer image
            if ($lead->developer) {
                $lead->developer->featured_image_url = $lead->developer->featured_image
                    ? url($lead->developer->featured_image)
                    : null;
            }

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
    // public function show($id)
    // {
    //     // Find lead with relationships
    //     $lead = Lead::with(['property', 'project', 'developer'])->find($id);

    //     if (!$lead) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Lead not found'
    //         ], 200);
    //     }

    //     // Get user_ids (already array because of casts)
    //     $userIds = $lead->user_ids ?? [];

    //     // Fetch related users
    //     $users = User::whereIn('id', $userIds)
    //         ->select('id', 'first_name', 'last_name', 'email', 'phone', 'area_locality')
    //         ->get()
    //         ->keyBy('id');

    //     // Attach users to this lead
    //     $lead->users = collect($userIds)
    //         ->map(fn($id) => $users->get($id))
    //         ->filter()
    //         ->values(); // clean array

    //     //  Add featured_image_url for property
    //     if ($lead->property) {
    //         $lead->property->featured_image_url = $lead->property->featured_image
    //             ? url($lead->property->featured_image)
    //             : null;
    //     }

    //     //  Add featured_image_url for project
    //     if ($lead->project) {
    //         $lead->project->featured_image_url = $lead->project->featured_image
    //             ? url($lead->project->featured_image)
    //             : null;
    //     }

    //     //  Add featured_image_url for developer
    //     if ($lead->developer) {
    //         $lead->developer->featured_image_url = $lead->developer->featured_image
    //             ? url($lead->developer->featured_image)
    //             : null;
    //     }

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Lead retrieved successfully',
    //         'data' => $lead,
    //     ]);
    // }



    public function show($id)
    {
        // Lead with relations
        $lead = Lead::with([
            'property.country',
            'property.state',
            'property.city',
            'property.purpose',
            'property.propertyType',
            'property.propertystatus',
            'property.createdBy',
            'property.updatedBy',
            'property.createdBy.role',
            'property.updatedBy.role',

            'project.country',
            'project.state',
            'project.city',
            'project.purpose',
            'project.propertyType',
            'project.propertystatus',
            'project.createdBy',
            'project.updatedBy',

            'developer.country',
            'developer.state',
            'developer.city',
            'developer.purpose',
            'developer.propertyType',
            'developer.propertystatus',
            'developer.createdBy',
            'developer.updatedBy',


        ])->find($id);

        if (!$lead) {
            return response()->json([
                'success' => false,
                'message' => 'Lead not found'
            ], 200);
        }

        // Get user_ids
        $userIds = $lead->user_ids ?? [];

        // Users with details + relations
        $users = User::whereIn('id', $userIds)
            ->with(['userDetails.country', 'userDetails.state', 'userDetails.city', 'role'])
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'unique_id' => $user->unique_id,
                    'fullName' => trim($user->first_name . ' ' . $user->last_name),
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'country' => $user->country ? [
                        'id' => $user->country->id,
                        'name' => $user->country->name,
                    ] : null,
                    'state' => $user->state ? [
                        'id' => $user->state->id,
                        'name' => $user->state->name,
                    ] : null,
                    'city' => $user->city ? [
                        'id' => $user->city->id,
                        'name' => $user->city->name,
                    ] : null,
                    'area_locality' => $user->area_locality,
                    'colony' => $user->colony,
                    'street_address' => $user->street_address,
                    'pin_code' => $user->pin_code,
                    'role_id' => $user->role_id,
                    'role' => $user->role->name,
                    'userDetails' => $user->userDetails ? [

                        'profile_photo' => $user->userDetails->profile_photo,
                        'profile_photo_url' => $user->userDetails->profile_photo ? url($user->userDetails->profile_photo) : null,

                        'business_name' => $user->userDetails->business_name,
                        'business_phone' => $user->userDetails->business_phone,
                        'business_email' => $user->userDetails->business_email,
                        'alternate_number' => $user->userDetails->alternate_number,
                        'license_number' => $user->userDetails->license_number,
                        'rera_number' => $user->userDetails->rera_number,
                        'no_of_employees' => $user->userDetails->no_of_employees,
                        'about_us' => $user->userDetails->about_us,

                        'business_address' => $user->userDetails->business_address,

                        'business_country' => $user->userDetails->country ? [
                            'id' => $user->userDetails->country->id,
                            'name' => $user->userDetails->country->name,
                        ] : null,
                        'business_state' => $user->userDetails->state ? [
                            'id' => $user->userDetails->state->id,
                            'name' => $user->userDetails->state->name,
                        ] : null,
                        'business_city' => $user->userDetails->city ? [
                            'id' => $user->userDetails->city->id,
                            'name' => $user->userDetails->city->name,
                        ] : null,

                        'business_area_locality' => $user->userDetails->area_locality,
                        'business_colony' => $user->userDetails->colony,
                        'business_street_address' => $user->userDetails->street_address,
                        'business_pin_code' => $user->userDetails->pin_code,

                    ] : null,
                ];
            });

        // Custom response banaya
        $response = [
            'id' => $lead->id,
            'name' => $lead->name,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'message' => $lead->message,

            'property' => $lead->property ? [
                'id' => $lead->property->id,
                'property_unique_id' => $lead->property->property_unique_id,
                'name' => $lead->property->name,
                'description' => $lead->property->description,
                'featured_image' => $lead->property->featured_image,
                'featuredImageUrl' => $lead->property->featured_image ? url($lead->property->featured_image) : null,
                'country' => $lead->property->country ? [
                    'id' => $lead->property->country->id,
                    'name' => $lead->property->country->name,
                ] : null,
                'state' => $lead->property->state ? [
                    'id' => $lead->property->state->id,
                    'name' => $lead->property->state->name,
                ] : null,
                'city' => $lead->property->city ? [
                    'id' => $lead->property->city->id,
                    'name' => $lead->property->city->name,
                ] : null,
                'property_address' => $lead->property->property_address,
                'area_locality' => $lead->property->area_locality,
                'colony' => $lead->property->colony,
                'street_address' => $lead->property->street_address,
                'pin_code' => $lead->property->pin_code,
                'purpose_id' => $lead->property->purpose_id,
                'purpose_name' => $lead->property->purpose->name,
                'property_id' => $lead->property->property_id,
                'property_name' => $lead->property->name,
                'property_type_id' => $lead->property->property_type_id,
                // 'property_type_name' => $lead->property->propertyType->name,
                'property_type_name' => $lead->property->property_type_id
                    ? implode(', ', \App\Models\PropertyType::whereIn('id', explode(',', $lead->property->property_type_id))->pluck('name')->toArray())
                    : null,
                'property_status_id' => $lead->property->property_status_id,
                'property_status_name' => $lead->property->propertystatus->name,
                'created_by' => $lead->property->createdBy ? [
                    'id' => $lead->property->createdBy->id,
                    'unique_id' => $lead->property->createdBy->unique_id,
                    'fullName' => trim($lead->property->createdBy->first_name . ' ' . $lead->property->createdBy->last_name),
                    'first_name' => $lead->property->createdBy->first_name,
                    'last_name' => $lead->property->createdBy->last_name,
                    'email' => $lead->property->createdBy->email,
                    'phone' => $lead->property->createdBy->phone,
                    'country' => $lead->property->createdBy->country ? [
                        'id' => $lead->property->createdBy->country->id,
                        'name' => $lead->property->createdBy->country->name,
                    ] : null,
                    'state' => $lead->property->createdBy->state ? [
                        'id' => $lead->property->createdBy->state->id,
                        'name' => $lead->property->createdBy->state->name,
                    ] : null,
                    'city' => $lead->property->createdBy->city ? [
                        'id' => $lead->property->createdBy->city->id,
                        'name' => $lead->property->createdBy->city->name,
                    ] : null,
                    'area_locality' => $lead->property->createdBy->area_locality,
                    'colony' => $lead->property->createdBy->colony,
                    'street_address' => $lead->property->createdBy->street_address,
                    'pin_code' => $lead->property->createdBy->pin_code,
                    'role_id' => $lead->property->createdBy->role_id,
                    'role_name' => $lead->property->createdBy->role->name,

                    'userDetails' => $lead->property->createdBy->userDetails ? [

                        'profile_photo' => $lead->property->createdBy->userDetails->profile_photo,
                        'profile_photo_url' => $lead->property->createdBy->userDetails->profile_photo ? url($lead->property->createdBy->userDetails->profile_photo) : null,

                        'business_name' => $lead->property->createdBy->userDetails->business_name,
                        'business_phone' => $lead->property->createdBy->userDetails->business_phone,
                        'business_email' => $lead->property->createdBy->userDetails->business_email,
                        'alternate_number' => $lead->property->createdBy->userDetails->alternate_number,
                        'license_number' => $lead->property->createdBy->userDetails->license_number,
                        'rera_number' => $lead->property->createdBy->userDetails->rera_number,
                        'no_of_employees' => $lead->property->createdBy->userDetails->no_of_employees,
                        'about_us' => $lead->property->createdBy->userDetails->about_us,

                        'business_address' => $lead->property->createdBy->userDetails->business_address,

                        'business_country' => $lead->property->createdBy->userDetails->country ? [
                            'id' => $lead->property->createdBy->userDetails->country->id,
                            'name' => $lead->property->createdBy->userDetails->country->name,
                        ] : null,
                        'business_state' => $lead->property->createdBy->userDetails->state ? [
                            'id' => $lead->property->createdBy->userDetails->state->id,
                            'name' => $lead->property->createdBy->userDetails->state->name,
                        ] : null,
                        'business_city' => $lead->property->createdBy->userDetails->city ? [
                            'id' => $lead->property->createdBy->userDetails->city->id,
                            'name' => $lead->property->createdBy->userDetails->city->name,
                        ] : null,

                        'business_area_locality' => $lead->property->createdBy->userDetails->area_locality,
                        'business_colony' => $lead->property->createdBy->userDetails->colony,
                        'business_street_address' => $lead->property->createdBy->userDetails->street_address,
                        'business_pin_code' => $lead->property->createdBy->userDetails->pin_code,

                    ] : null,
                ] : null,
                'updated_by' => $lead->property->updatedBy ? [
                    'id' => $lead->property->updatedBy->id,
                    'unique_id' => $lead->property->updatedBy->unique_id,
                    'fullName' => trim($lead->property->updatedBy->first_name . ' ' . $lead->property->updatedBy->last_name),
                    'first_name' => $lead->property->updatedBy->first_name,
                    'last_name' => $lead->property->updatedBy->last_name,
                    'email' => $lead->property->updatedBy->email,
                    'phone' => $lead->property->updatedBy->phone,
                    'country' => $lead->property->updatedBy->country ? [
                        'id' => $lead->property->updatedBy->country->id,
                        'name' => $lead->property->updatedBy->country->name,
                    ] : null,
                    'state' => $lead->property->updatedBy->state ? [
                        'id' => $lead->property->updatedBy->state->id,
                        'name' => $lead->property->updatedBy->state->name,
                    ] : null,
                    'city' => $lead->property->updatedBy->city ? [
                        'id' => $lead->property->updatedBy->city->id,
                        'name' => $lead->property->updatedBy->city->name,
                    ] : null,
                    'area_locality' => $lead->property->updatedBy->area_locality,
                    'colony' => $lead->property->updatedBy->colony,
                    'street_address' => $lead->property->updatedBy->street_address,
                    'pin_code' => $lead->property->updatedBy->pin_code,
                    'role_id' => $lead->property->updatedBy->role_id,
                    'role_name' => $lead->property->updatedBy->role->name,
                    'userDetails' => $lead->property->updatedBy->userDetails ? [

                        'profile_photo' => $lead->property->updatedBy->userDetails->profile_photo,
                        'profile_photo_url' => $lead->property->updatedBy->userDetails->profile_photo ? url($lead->property->updatedBy->userDetails->profile_photo) : null,

                        'business_name' => $lead->property->updatedBy->userDetails->business_name,
                        'business_phone' => $lead->property->updatedBy->userDetails->business_phone,
                        'business_email' => $lead->property->updatedBy->userDetails->business_email,
                        'alternate_number' => $lead->property->updatedBy->userDetails->alternate_number,
                        'license_number' => $lead->property->updatedBy->userDetails->license_number,
                        'rera_number' => $lead->property->updatedBy->userDetails->rera_number,
                        'no_of_employees' => $lead->property->updatedBy->userDetails->no_of_employees,
                        'about_us' => $lead->property->updatedBy->userDetails->about_us,

                        'business_address' => $lead->property->updatedBy->userDetails->business_address,

                        'business_country' => $lead->property->updatedBy->userDetails->country ? [
                            'id' => $lead->property->updatedBy->userDetails->country->id,
                            'name' => $lead->property->updatedBy->userDetails->country->name,
                        ] : null,
                        'business_state' => $lead->property->updatedBy->userDetails->state ? [
                            'id' => $lead->property->updatedBy->userDetails->state->id,
                            'name' => $lead->property->updatedBy->userDetails->state->name,
                        ] : null,
                        'business_city' => $lead->property->updatedBy->userDetails->city ? [
                            'id' => $lead->property->updatedBy->userDetails->city->id,
                            'name' => $lead->property->updatedBy->userDetails->city->name,
                        ] : null,

                        'business_area_locality' => $lead->property->updatedBy->userDetails->area_locality,
                        'business_colony' => $lead->property->updatedBy->userDetails->colony,
                        'business_street_address' => $lead->property->updatedBy->userDetails->street_address,
                        'business_pin_code' => $lead->property->updatedBy->userDetails->pin_code,

                    ] : null,
                ] : null,
                'created_at' => $lead->property->created_at,
                'updated_at' => $lead->property->updated_at,




            ] : null,

            'project' => $lead->project ? [
                'id' => $lead->project->id,
                'project_unique_id' => $lead->project->project_unique_id,
                'name' => $lead->project->name,
                'description' => $lead->project->description,
                'featured_image' => $lead->project->featured_image,
                'featuredImageUrl' => $lead->project->featured_image ? url($lead->project->featured_image) : null,
                'country' => $lead->project->country ? [
                    'id' => $lead->project->country->id,
                    'name' => $lead->project->country->name,
                ] : null,
                'state' => $lead->project->state ? [
                    'id' => $lead->project->state->id,
                    'name' => $lead->project->state->name,
                ] : null,
                'city' => $lead->project->city ? [
                    'id' => $lead->project->city->id,
                    'name' => $lead->project->city->name,
                ] : null,

                'address' => $lead->project->address,
                'area_locality' => $lead->project->area_locality,
                'colony' => $lead->project->colony,
                'street_address' => $lead->project->street_address,
                'pin_code' => $lead->project->pin_code,
                'purpose_id' => $lead->project->purpose_id,
                'purpose_name' => $lead->project->purpose->name,
                'property_id' => $lead->project->property_id,
                'property_name' => $lead->project->name,
                'property_type_id' => $lead->project->property_type_id,
                // 'property_type_name' => $lead->property->propertyType->name,
                'property_type_name' => $lead->project->property_type_id
                    ? implode(', ', \App\Models\PropertyType::whereIn('id', explode(',', $lead->project->property_type_id))->pluck('name')->toArray())
                    : null,
                'property_status_id' => $lead->project->property_status_id,
                'property_status_name' => $lead->project->propertystatus->name,
                'created_by' => $lead->project->createdBy ? [
                    'id' => $lead->project->createdBy->id,
                    'unique_id' => $lead->project->createdBy->unique_id,
                    'fullName' => trim($lead->project->createdBy->first_name . ' ' . $lead->project->createdBy->last_name),
                    'first_name' => $lead->project->createdBy->first_name,
                    'last_name' => $lead->project->createdBy->last_name,
                    'email' => $lead->project->createdBy->email,
                    'phone' => $lead->project->createdBy->phone,
                    'country' => $lead->project->createdBy->country ? [
                        'id' => $lead->project->createdBy->country->id,
                        'name' => $lead->project->createdBy->country->name,
                    ] : null,
                    'state' => $lead->project->createdBy->state ? [
                        'id' => $lead->project->createdBy->state->id,
                        'name' => $lead->project->createdBy->state->name,
                    ] : null,
                    'city' => $lead->project->createdBy->city ? [
                        'id' => $lead->project->createdBy->city->id,
                        'name' => $lead->project->createdBy->city->name,
                    ] : null,
                    'area_locality' => $lead->project->createdBy->area_locality,
                    'colony' => $lead->project->createdBy->colony,
                    'street_address' => $lead->project->createdBy->street_address,
                    'pin_code' => $lead->project->createdBy->pin_code,
                    'role_id' => $lead->project->createdBy->role_id,
                    'role_name' => $lead->project->createdBy->role->name,
                    'userDetails' => $lead->project->createdBy->userDetails ? [

                        'profile_photo' => $lead->project->createdBy->userDetails->profile_photo,
                        'profile_photo_url' => $lead->project->createdBy->userDetails->profile_photo ? url($lead->project->createdBy->userDetails->profile_photo) : null,

                        'business_name' => $lead->project->createdBy->userDetails->business_name,
                        'business_phone' => $lead->project->createdBy->userDetails->business_phone,
                        'business_email' => $lead->project->createdBy->userDetails->business_email,
                        'alternate_number' => $lead->project->createdBy->userDetails->alternate_number,
                        'license_number' => $lead->project->createdBy->userDetails->license_number,
                        'rera_number' => $lead->project->createdBy->userDetails->rera_number,
                        'no_of_employees' => $lead->project->createdBy->userDetails->no_of_employees,
                        'about_us' => $lead->project->createdBy->userDetails->about_us,

                        'business_address' => $lead->project->createdBy->userDetails->business_address,

                        'business_country' => $lead->project->createdBy->userDetails->country ? [
                            'id' => $lead->project->createdBy->userDetails->country->id,
                            'name' => $lead->project->createdBy->userDetails->country->name,
                        ] : null,
                        'business_state' => $lead->project->createdBy->userDetails->state ? [
                            'id' => $lead->project->createdBy->userDetails->state->id,
                            'name' => $lead->project->createdBy->userDetails->state->name,
                        ] : null,
                        'business_city' => $lead->project->createdBy->userDetails->city ? [
                            'id' => $lead->project->createdBy->userDetails->city->id,
                            'name' => $lead->project->createdBy->userDetails->city->name,
                        ] : null,

                        'business_area_locality' => $lead->project->createdBy->userDetails->area_locality,
                        'business_colony' => $lead->project->createdBy->userDetails->colony,
                        'business_street_address' => $lead->project->createdBy->userDetails->street_address,
                        'business_pin_code' => $lead->project->createdBy->userDetails->pin_code,

                    ] : null,
                ] : null,
                'updated_by' => $lead->project->updatedBy ? [
                    'id' => $lead->project->updatedBy->id,
                    'unique_id' => $lead->project->updatedBy->unique_id,
                    'fullName' => trim($lead->project->updatedBy->first_name . ' ' . $lead->project->updatedBy->last_name),
                    'first_name' => $lead->project->updatedBy->first_name,
                    'last_name' => $lead->project->updatedBy->last_name,
                    'email' => $lead->project->updatedBy->email,
                    'phone' => $lead->project->updatedBy->phone,
                    'country' => $lead->project->updatedBy->country ? [
                        'id' => $lead->project->updatedBy->country->id,
                        'name' => $lead->project->updatedBy->country->name,
                    ] : null,
                    'state' => $lead->project->updatedBy->state ? [
                        'id' => $lead->project->updatedBy->state->id,
                        'name' => $lead->project->updatedBy->state->name,
                    ] : null,
                    'city' => $lead->project->updatedBy->city ? [
                        'id' => $lead->project->updatedBy->city->id,
                        'name' => $lead->project->updatedBy->city->name,
                    ] : null,
                    'area_locality' => $lead->project->updatedBy->area_locality,
                    'colony' => $lead->project->updatedBy->colony,
                    'street_address' => $lead->project->updatedBy->street_address,
                    'pin_code' => $lead->project->updatedBy->pin_code,
                    'role_id' => $lead->project->updatedBy->role_id,
                    'role_name' => $lead->project->updatedBy->role->name,
                    'userDetails' => $lead->project->updatedBy->userDetails ? [

                        'profile_photo' => $lead->project->updatedBy->userDetails->profile_photo,
                        'profile_photo_url' => $lead->project->updatedBy->userDetails->profile_photo ? url($lead->project->updatedBy->userDetails->profile_photo) : null,

                        'business_name' => $lead->project->updatedBy->userDetails->business_name,
                        'business_phone' => $lead->project->updatedBy->userDetails->business_phone,
                        'business_email' => $lead->project->updatedBy->userDetails->business_email,
                        'alternate_number' => $lead->project->updatedBy->userDetails->alternate_number,
                        'license_number' => $lead->project->updatedBy->userDetails->license_number,
                        'rera_number' => $lead->project->updatedBy->userDetails->rera_number,
                        'no_of_employees' => $lead->project->updatedBy->userDetails->no_of_employees,
                        'about_us' => $lead->project->updatedBy->userDetails->about_us,

                        'business_address' => $lead->project->updatedBy->userDetails->business_address,

                        'business_country' => $lead->project->updatedBy->userDetails->country ? [
                            'id' => $lead->project->updatedBy->userDetails->country->id,
                            'name' => $lead->project->updatedBy->userDetails->country->name,
                        ] : null,
                        'business_state' => $lead->project->updatedBy->userDetails->state ? [
                            'id' => $lead->project->updatedBy->userDetails->state->id,
                            'name' => $lead->project->updatedBy->userDetails->state->name,
                        ] : null,
                        'business_city' => $lead->project->updatedBy->userDetails->city ? [
                            'id' => $lead->project->updatedBy->userDetails->city->id,
                            'name' => $lead->project->updatedBy->userDetails->city->name,
                        ] : null,

                        'business_area_locality' => $lead->project->updatedBy->userDetails->area_locality,
                        'business_colony' => $lead->project->updatedBy->userDetails->colony,
                        'business_street_address' => $lead->project->updatedBy->userDetails->street_address,
                        'business_pin_code' => $lead->project->updatedBy->userDetails->pin_code,

                    ] : null,
                ] : null,
                'created_at' => $lead->project->created_at,
                'updated_at' => $lead->project->updated_at,
            ] : null,

            'developer' => $lead->developer ? [
                'id' => $lead->developer->id,
                'developer_unique_id' => $lead->developer->developer_unique_id,
                'name' => $lead->developer->name,
                'description' => $lead->developer->description,
                'featured_image' => $lead->developer->featured_image,
                'featuredImageUrl' => $lead->developer->featured_image ? url($lead->developer->featured_image) : null,
                'country' => $lead->developer->country ? [
                    'id' => $lead->developer->country->id,
                    'name' => $lead->developer->country->name,
                ] : null,
                'state' => $lead->developer->state ? [
                    'id' => $lead->developer->state->id,
                    'name' => $lead->developer->state->name,
                ] : null,
                'city' => $lead->developer->city ? [
                    'id' => $lead->developer->city->id,
                    'name' => $lead->developer->city->name,
                ] : null,

                'address' => $lead->developer->address,
                'area_locality' => $lead->developer->area_locality,
                'colony' => $lead->developer->colony,
                'street_address' => $lead->developer->street_address,
                'pin_code' => $lead->developer->pin_code,
                'purpose_id' => $lead->developer->purpose_id,
                'purpose_name' => $lead->developer->purpose->name,
                'property_id' => $lead->developer->property_id,
                'property_name' => $lead->developer->name,
                'property_type_id' => $lead->developer->property_type_id,
                // 'property_type_name' => $lead->property->propertyType->name,
                'property_type_name' => $lead->developer->property_type_id
                    ? implode(', ', \App\Models\PropertyType::whereIn('id', explode(',', $lead->developer->property_type_id))->pluck('name')->toArray())
                    : null,
                'property_status_id' => $lead->developer->property_status_id,
                'property_status_name' => $lead->developer->propertystatus->name,
                'created_by' => $lead->developer->createdBy ? [
                    'id' => $lead->developer->createdBy->id,
                    'unique_id' => $lead->developer->createdBy->unique_id,
                    'fullName' => trim($lead->developer->createdBy->first_name . ' ' . $lead->developer->createdBy->last_name),
                    'first_name' => $lead->developer->createdBy->first_name,
                    'last_name' => $lead->developer->createdBy->last_name,
                    'email' => $lead->developer->createdBy->email,
                    'phone' => $lead->developer->createdBy->phone,
                    'country' => $lead->developer->createdBy->country ? [
                        'id' => $lead->developer->createdBy->country->id,
                        'name' => $lead->developer->createdBy->country->name,
                    ] : null,
                    'state' => $lead->developer->createdBy->state ? [
                        'id' => $lead->developer->createdBy->state->id,
                        'name' => $lead->developer->createdBy->state->name,
                    ] : null,
                    'city' => $lead->developer->createdBy->city ? [
                        'id' => $lead->developer->createdBy->city->id,
                        'name' => $lead->developer->createdBy->city->name,
                    ] : null,
                    'area_locality' => $lead->developer->createdBy->area_locality,
                    'colony' => $lead->developer->createdBy->colony,
                    'street_address' => $lead->developer->createdBy->street_address,
                    'pin_code' => $lead->developer->createdBy->pin_code,
                    'role_id' => $lead->developer->createdBy->role_id,
                    'role_name' => $lead->developer->createdBy->role->name,
                    'userDetails' => $lead->developer->createdBy->userDetails ? [

                        'profile_photo' => $lead->developer->createdBy->userDetails->profile_photo,
                        'profile_photo_url' => $lead->developer->createdBy->userDetails->profile_photo ? url($lead->developer->createdBy->userDetails->profile_photo) : null,

                        'business_name' => $lead->developer->createdBy->userDetails->business_name,
                        'business_phone' => $lead->developer->createdBy->userDetails->business_phone,
                        'business_email' => $lead->developer->createdBy->userDetails->business_email,
                        'alternate_number' => $lead->developer->createdBy->userDetails->alternate_number,
                        'license_number' => $lead->developer->createdBy->userDetails->license_number,
                        'rera_number' => $lead->developer->createdBy->userDetails->rera_number,
                        'no_of_employees' => $lead->developer->createdBy->userDetails->no_of_employees,
                        'about_us' => $lead->developer->createdBy->userDetails->about_us,

                        'business_address' => $lead->developer->createdBy->userDetails->business_address,

                        'business_country' => $lead->developer->createdBy->userDetails->country ? [
                            'id' => $lead->developer->createdBy->userDetails->country->id,
                            'name' => $lead->developer->createdBy->userDetails->country->name,
                        ] : null,
                        'business_state' => $lead->developer->createdBy->userDetails->state ? [
                            'id' => $lead->developer->createdBy->userDetails->state->id,
                            'name' => $lead->developer->createdBy->userDetails->state->name,
                        ] : null,
                        'business_city' => $lead->developer->createdBy->userDetails->city ? [
                            'id' => $lead->developer->createdBy->userDetails->city->id,
                            'name' => $lead->developer->createdBy->userDetails->city->name,
                        ] : null,

                        'business_area_locality' => $lead->developer->createdBy->userDetails->area_locality,
                        'business_colony' => $lead->developer->createdBy->userDetails->colony,
                        'business_street_address' => $lead->developer->createdBy->userDetails->street_address,
                        'business_pin_code' => $lead->developer->createdBy->userDetails->pin_code,

                    ] : null,
                ] : null,
                'updated_by' => $lead->developer->updatedBy ? [
                    'id' => $lead->developer->updatedBy->id,
                    'unique_id' => $lead->developer->updatedBy->unique_id,
                    'fullName' => trim($lead->developer->updatedBy->first_name . ' ' . $lead->developer->updatedBy->last_name),
                    'first_name' => $lead->developer->updatedBy->first_name,
                    'last_name' => $lead->developer->updatedBy->last_name,
                    'email' => $lead->developer->updatedBy->email,
                    'phone' => $lead->developer->updatedBy->phone,
                    'country' => $lead->developer->updatedBy->country ? [
                        'id' => $lead->developer->updatedBy->country->id,
                        'name' => $lead->developer->updatedBy->country->name,
                    ] : null,
                    'state' => $lead->developer->updatedBy->state ? [
                        'id' => $lead->developer->updatedBy->state->id,
                        'name' => $lead->developer->updatedBy->state->name,
                    ] : null,
                    'city' => $lead->developer->updatedBy->city ? [
                        'id' => $lead->developer->updatedBy->city->id,
                        'name' => $lead->developer->updatedBy->city->name,
                    ] : null,
                    'area_locality' => $lead->developer->updatedBy->area_locality,
                    'colony' => $lead->developer->updatedBy->colony,
                    'street_address' => $lead->developer->updatedBy->street_address,
                    'pin_code' => $lead->developer->updatedBy->pin_code,
                    'role_id' => $lead->developer->updatedBy->role_id,
                    'role_name' => $lead->developer->updatedBy->role->name,
                    'userDetails' => $lead->developer->updatedBy->userDetails ? [

                        'profile_photo' => $lead->developer->updatedBy->userDetails->profile_photo,
                        'profile_photo_url' => $lead->developer->updatedBy->userDetails->profile_photo ? url($lead->developer->updatedBy->userDetails->profile_photo) : null,

                        'business_name' => $lead->developer->updatedBy->userDetails->business_name,
                        'business_phone' => $lead->developer->updatedBy->userDetails->business_phone,
                        'business_email' => $lead->developer->updatedBy->userDetails->business_email,
                        'alternate_number' => $lead->developer->updatedBy->userDetails->alternate_number,
                        'license_number' => $lead->developer->updatedBy->userDetails->license_number,
                        'rera_number' => $lead->developer->updatedBy->userDetails->rera_number,
                        'no_of_employees' => $lead->developer->updatedBy->userDetails->no_of_employees,
                        'about_us' => $lead->developer->updatedBy->userDetails->about_us,

                        'business_address' => $lead->developer->updatedBy->userDetails->business_address,

                        'business_country' => $lead->developer->updatedBy->userDetails->country ? [
                            'id' => $lead->developer->updatedBy->userDetails->country->id,
                            'name' => $lead->developer->updatedBy->userDetails->country->name,
                        ] : null,
                        'business_state' => $lead->developer->updatedBy->userDetails->state ? [
                            'id' => $lead->developer->updatedBy->userDetails->state->id,
                            'name' => $lead->developer->updatedBy->userDetails->state->name,
                        ] : null,
                        'business_city' => $lead->developer->updatedBy->userDetails->city ? [
                            'id' => $lead->developer->updatedBy->userDetails->city->id,
                            'name' => $lead->developer->updatedBy->userDetails->city->name,
                        ] : null,

                        'business_area_locality' => $lead->developer->updatedBy->userDetails->area_locality,
                        'business_colony' => $lead->developer->updatedBy->userDetails->colony,
                        'business_street_address' => $lead->developer->updatedBy->userDetails->street_address,
                        'business_pin_code' => $lead->developer->updatedBy->userDetails->pin_code,

                    ] : null,
                ] : null,
                'created_at' => $lead->developer->created_at,
                'updated_at' => $lead->developer->updated_at,
            ] : null,

            'users' => $users->values(),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Lead retrieved successfully',
            'data' => $response,
        ]);
    }


    public function update(Request $request, $id)
{
    // Detect logged-in user
    $user = auth('sanctum')->user();
    if (!$user && $request->bearerToken()) {
        $user = User::where('api_token', $request->bearerToken())->first();
    }

    // Find existing lead
    $lead = Lead::find($id);
    if (!$lead) {
        return response()->json([
            'success' => false,
            'message' => 'Lead not found'
        ], 404);
    }

    // Validation rules
    $validator = Validator::make($request->all(), [
        'name' => 'sometimes|required|string|max:255',
        'email' => 'sometimes|required|email|max:255',
        'phone' => 'sometimes|required|string|max:20',
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
        empty($request->user_ids) &&
        !$lead->property_id && !$lead->project_id && !$lead->developer_id && !$lead->user_ids
    ) {
        return response()->json([
            'success' => false,
            'errors' => ['relation_error' => 'At least one of Property, Project, Developer or User must be selected.'],
            'message' => 'Validation failed'
        ], 422);
    }

    // Collect user_ids
    $userIds = $request->user_ids ?? $lead->user_ids ?? [];
    if ($user) {
        $userIds[] = $user->id;
    }

    // Update lead
    $lead->update([
        'name' => $request->name ?? $lead->name,
        'email' => $request->email ?? $lead->email,
        'phone' => $request->phone ?? $lead->phone,
        'message' => $request->message ?? $lead->message,
        'property_id' => $request->property_id ?? $lead->property_id,
        'project_id' => $request->project_id ?? $lead->project_id,
        'developer_id' => $request->developer_id ?? $lead->developer_id,
        'user_ids' => array_values(array_unique($userIds)),
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Lead updated successfully',
        'data' => $lead,
    ], 200);
}



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

        //
        $role = DB::table('roles')->where('id', $user->role_id)->first();

        $filterUserId = $request->user_id;

        $query = Lead::with(['property', 'project', 'developer']);

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
