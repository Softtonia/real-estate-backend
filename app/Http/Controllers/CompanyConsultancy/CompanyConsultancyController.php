<?php

namespace App\Http\Controllers\CompanyConsultancy;

use App\Http\Controllers\Controller;
use App\Models\JoinRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CompanyConsultancyController extends Controller
{

    // for consultancy agent listings
    public function getCompanyConsultancyListing(Request $request)
    {
        try {
            // Retrieve authenticated user
            $user = auth()->user();
            $userId = $user->id;

            // Fetch users created by the authenticated company
            $consultancy_ids_from_users_created_by = User::where('created_by', $userId)
                ->pluck('id')
                ->toArray();

            // Check if there's a relevant column linking join requests to companies
            $consultancy_ids_from_join_requests = JoinRequest::where([
                'user_id' => $userId, // Adjust if `user_id` isn't the correct company reference
                'status' => 2 // Status 2 = Accepted
            ])
                ->pluck('user_id') // Ensure this column exists and stores user IDs
                ->toArray();

            // Merge and remove duplicates
            $final_consultancy_id_arr = array_unique(
                array_merge($consultancy_ids_from_users_created_by, $consultancy_ids_from_join_requests)
            );

            // Retrieve users with role and details
            $users = User::whereIn('id', $final_consultancy_id_arr)
                ->with(['role', 'userDetails'])
                ->get();

            return response()->json($users, 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


     public function searchConsultancyById(Request $request)
    {
        try {
            // Get authenticated user (set by the middleware)
            $user = Auth::user();
            $userId = $user->id;

            // Fetch users with the role name "Consultancy" and created_by = 0
            $userDataList = User::whereHas('role', function ($query) {
                $query->where('name', 'Consultancy'); // Checking by role name
            })->where('created_by', 0)
                ->with([
                    'userDetails',
                    'joinRequest' => function ($query) use ($userId) {
                        $query->where('user_id', $userId);
                    }
                ])->get();
            $user = User::find($userId);
            $userDetails = $user->userDetails; // This should return the related user_details

            $returnArr = [];

            foreach ($userDataList as $user) {
                // Get business name from user_details
                $business_name = optional($user->userDetails)->business_name ?? '';

                // Get join request status
                $joinRequestData = $user->joinRequest->first();
                $user_status = match (optional($joinRequestData)->status) {
                    'accepted' => 'connected',
                    'requested' => 'requested',
                    default => 'normal',
                };

                // **Allowed fields only: business_name, unique_id, email_id**
                $returnArr[] = [
                    'business_name' => $userDetails->bussiness_name,
                    'bussiness_address' => $userDetails->bussiness_address,
                    'unique_id' => $user->unique_id,
                    'email_id' => $user->email,
                ];
            }

            return response()->json(['status' => true, 'data' => $returnArr], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed: ' . $e->getMessage()], 500);
        }
    }


     // for send join request to consultancy
    public function sendRequestByCompanyToConsultancy(Request $request)
    {
        try {

            $companyUser = Auth::user();
            $companyId = $companyUser->id;

            if (!$companyId) {
                return response()->json(['error' => 'Company not found'], 404);
            }
            $userId = $companyUser->id;

            // Validate that the consultancy user exists in the database
            if (!User::where('id', $request->consultancy_id)->exists()) {
                return response()->json(['error' => 'Consultancy not found'], 404);
            }

            // Check if the request already exists
            $check = JoinRequest::where([
                'user_id' => $request->consultancy_id,
                'type' => 'company-consultancy'
            ])->first();

            if ($check) {
                JoinRequest::where([
                    'user_id' => $request->consultancy_id,
                    'type' => 'company-consultancy'
                ])->delete();

                return response()->json(['status' => true, 'message' => 'Request removed successfully.'], 201);
            } else {
                JoinRequest::insert([
                    'user_id' => $request->consultancy_id,
                    'type' => 'company-consultancy',
                    'status' => 1, // Changed from 'requested' to 1
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                return response()->json(['status' => true, 'message' => 'Request sent successfully.'], 201);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e->getMessage());
            return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
        }
    }


    // for get all join requests of consultancy
    public function getConsultancyAllJoinRequest(Request $request)
    {
        try {
            $consultancyUser = Auth::user();

            if (!$consultancyUser) {
                return response()->json(['error' => 'Consultancy not found'], 404);
            }

            $userId = $consultancyUser->id;

            // Fetch join requests with related company and user details
            $joinRequests = JoinRequest::with(['company.userDetail'])
                ->where('type', 'company-consultancy')
                ->where('user_id', $userId)
                ->get();

            if ($joinRequests->isEmpty()) {
                return response()->json(['error' => 'No request found for this consultancy'], 404);
            }

            // Ensure userDetail exists before accessing its properties
            $userDetails = $consultancyUser->userDetail ?? null;

            $returnData = [
                'consultancy' => [
                    'id' => $consultancyUser->id,
                    'fullname' => $consultancyUser->fullname,
                    'email' => $consultancyUser->email,
                    'phone' => $consultancyUser->phone,
                    'role_id' => $consultancyUser->role_id,
                    'role_name' => $consultancyUser->role_name,
                    'unique_id' => $consultancyUser->unique_id,
                    'api_token' => $consultancyUser->api_token,
                    'profile_photo' => $userDetails?->profile_photo,
                    'business_name' => $userDetails?->bussiness_name,
                    'business_address' => $userDetails?->bussiness_address,
                    'business_email' => $userDetails?->bussiness_email,
                    'business_phone' => $userDetails?->business_phone,
                    'address' => $userDetails?->address,
                ],
                'join_requests' => []
            ];

            foreach ($joinRequests as $row) {
                $company = $row->company ?? null; // Check if company exists

                if (!$company) {
                    // Log this error instead of stopping execution
                    \Log::error("Company not found for join request ID: " . $row->id);
                    continue; // Skip this request and move to the next one
                }

                $companyDetails = $company->userDetail ?? null; // Check if userDetail exists

                $returnData['join_requests'][] = [
                    'status' => $row->status,
                    'company' => [
                        'id' => $company->id ?? null,
                        'fullname' => $company->fullname ?? null,
                        'email' => $company->email ?? null,
                        'phone' => $company->phone ?? null,
                        'role_id' => $company->role_id ?? null,
                        'role_name' => $company->role_name ?? null,
                        'unique_id' => $company->unique_id ?? null,
                        'api_token' => $company->api_token ?? null,
                        'profile_photo' => $companyDetails?->profile_photo,
                        'business_name' => $companyDetails?->bussiness_name,
                        'business_address' => $companyDetails?->bussiness_address,
                        'business_email' => $companyDetails?->bussiness_email,
                        'business_phone' => $companyDetails?->business_phone,
                        'address' => $companyDetails?->address,
                    ],
                ];
            }


            return response()->json($returnData, 200);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    // for accept and decline the request

    public function acceptDeclineCompanyRequestByConsultancy(Request $request)
    {
        try {
            $authUser = Auth::user();

            if (!$authUser) {
                return response()->json(['error' => 'User not found'], 404);
            }

            // Determine user type
            $userRole = $authUser->role->name;
            $isCompany = $userRole === 'company';
            $isConsultancy = $userRole === 'consultancy';
            $isAgent = $userRole === 'agent';

            if (!$isCompany && !$isConsultancy && !$isAgent) {
                return response()->json(['error' => 'Unauthorized action'], 403);
            }

            // Determine the target user and request type
            if ($isCompany) {
                $targetUserId = $request->consultancy_id;
                $requestType = 'company-consultancy';
            } elseif ($isAgent) {
                $targetUserId = $request->consultancy_id;
                $requestType = 'consultancy-agent';
            } elseif ($isConsultancy) {
                if ($request->company_id) {
                    $targetUserId = $request->company_id;
                    $requestType = 'company-consultancy';
                } elseif ($request->agent_id) {
                    $targetUserId = $request->agent_id;
                    $requestType = 'consultancy-agent';
                } else {
                    return response()->json(['error' => 'Missing target user ID.'], 400);
                }
            }

            // Ensure the target user exists
            $targetUser = User::find($targetUserId);
            if (!$targetUser) {
                return response()->json(['error' => 'Target user not found'], 404);
            }

            // Validate status
            if (!in_array($request->status, [1, 2, 3])) {
                return response()->json([
                    'error' => 'Invalid status. Allowed values: 1 (Requested), 2 (Accepted), 3 (Rejected).'
                ], 422);
            }

            // Update JoinRequest with correct where conditions
            $update = JoinRequest::where('type', $requestType)
                ->where(function ($query) use ($authUser, $targetUserId) {
                    $query->where('user_id', $authUser->id)
                        ->orWhere('user_id', $targetUserId);
                })
                ->update(['status' => $request->status]);



            if ($update) {
                return response()->json([
                    'status' => true,
                    'message' => "Request status updated successfully."
                ], 201);
            } else {
                return response()->json(['error' => 'No matching request found.'], 404);
            }

        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
        }
    }


     // for leave the company
    public function leaveTheComapnyByConsultancy(Request $request)
    {
        try {
            $consultancyUser = Auth::user();

            if (!$consultancyUser) {
                return response()->json(['error' => 'Consultancy not found'], 404);
            }

            $userId = $consultancyUser->id;

            // Validate that the user exists
            if (!User::where('id', $request->user_id)->exists()) {
                return response()->json(['error' => 'Company not found'], 404);
            }

            // Check if consultancy_id exists in join_requests, otherwise use user_id or consultant_id
            $update = JoinRequest::where(['user_id' => $userId,])
                ->update(['status' => '5']); // Change if 'status' is an integer

            if ($update) {
                return response()->json(['status' => true, 'message' => 'You left the company successfully.'], 201);
            } else {
                return response()->json(['error' => 'No matching record found.'], 404);
            }

        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
        }
    }


    // for get consultancy details
    public function getConsultancyDetailsWithCompany(Request $request)
    {
        try {
            $consultancyUser = Auth::user();

            if (!$consultancyUser) {
                return response()->json(['error' => 'Consultancy not found'], 404);
            }

            $userId = $consultancyUser->id;

            // Get the IDs of users who have accepted join requests where the consultancy is stored under 'user_id'
            $user_ids_from_join_requests = JoinRequest::where([
                'user_id' => $userId,  // Changed from 'consultancy_id' to 'user_id'
                'status' => 2, // 2 = Accepted
                'type' => 'company-consultancy'
            ])->pluck('user_id')->toArray();

            // Get the count of users whose IDs are in the array from join requests
            $companyCountFromJoinRequests = count($user_ids_from_join_requests);

            // Get user data for the associated users
            $companyData = User::whereIn('id', array_unique($user_ids_from_join_requests))->get();

            // Fetch user details from multiple tables
            $userData = DB::table('users')
                ->join('user_details', 'users.id', '=', 'user_details.user_id')
                ->join('roles', 'users.role_id', '=', 'roles.id')
                ->where('users.id', $userId)
                ->where('users.role_id', '!=', 1)
                ->select('users.*', 'user_details.*', 'roles.name as role_name')
                ->first();

            // Check if user data exists
            if (!$userData) {
                return response()->json(['error' => 'No data found on this user.'], 404);
            }

            return response()->json([
                'id' => $userData->id,
                'fullname' => $userData->fullname ?? $userData->first_name, // Use correct column
                'email' => $userData->email,
                'phone' => $userData->phone,
                'role_id' => $userData->role_id,
                'role_name' => $userData->role_name,
                'unique_id' => $userData->unique_id,
                'isapproved' => $userData->isapproved,
                'bussiness_name' => $userData->bussiness_name,
                'bussiness_address' => $userData->bussiness_address,
                'bussiness_email' => $userData->bussiness_email,
                'business_phone' => $userData->business_phone,
                'country' => $userData->country_id,
                'state' => $userData->state_id,
                'city' => $userData->city_id,
                'address' => $userData->address,
                'pin_code' => $userData->pin_code,
                'profile_photo' => url($userData->profile_photo),
                'license_number' => $userData->license_number,
                'alternate_number' => $userData->alternate_number,
                'no_of_employees' => $userData->no_of_employees,
                // 'purpose_id' => explode(',', $userData->purpose_id),
                // 'property_id' => explode(',', $userData->property_id),
                // 'property_type_id' => explode(',', $userData->property_type_id),
                'created_at' => $userData->created_at,
                'updated_at' => $userData->updated_at,
                'total_company' => $companyCountFromJoinRequests,
                'company' => $companyData
            ], 200);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }




}
