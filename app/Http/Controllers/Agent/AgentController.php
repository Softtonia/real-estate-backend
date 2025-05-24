<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;
use App\Models\Agent;
use App\Models\User;
use App\Models\UniqueID;
use App\Models\UserHasUniqueID;
use App\Models\JoinRequest;
use App\Models\AgentUniqueId;
use App\Models\Location;
use App\Models\CompanyConsultancyProject;
use Storage;
use DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Password;
class AgentController extends Controller
{

    // for consultancy agent listings
    public function getConsultancyAgentListing(Request $request)
    {
        try {
            // Check if API token is present
            if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
                return response()->json(['error' => 'Please provide an API token.'], 422);
            }

            // Retrieve the Authorization header
            $authorizationHeader = $request->header('Authorization');

            // Check if the header starts with "Bearer "
            if (!str_starts_with($authorizationHeader, 'Bearer ')) {
                return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
            }

            // Extract the token by removing the "Bearer " prefix
            $requestToken = substr($authorizationHeader, 7);

            // Check if the token is empty after removing "Bearer "
            if (empty($requestToken)) {
                return response()->json(['error' => 'Token is missing.'], 422);
            }

            // Verify the token dynamically (check in the database)
            $user = User::where('api_token', $requestToken)->first();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
            }

            $userId = $user->id;

            $role = Role::find($user->role_id);
            if (!$role || $role->name !== 'consultancy') {
                return response()->json(['error' => 'User does not have the required role.'], 400);
            }
            $userId = $user->id;


            $agent_ids_from_users_created_by = User::where('created_by', $userId)->pluck('id')->toArray();


            $agent_ids_from_join_requests = JoinRequest::where(['consultancy_id' => $userId, 'status' => 'accepted'])->pluck('agent_id')->toArray();

            $final_agent_id_arr = array_merge($agent_ids_from_join_requests, $agent_ids_from_users_created_by);


            $users = User::whereIn('id', array_unique($final_agent_id_arr))
                ->with('role')
                ->with('userDetails')
                ->get();

            // Extract the necessary information from each user
            $userList = $users->map(function ($user) {
                $roleName = $user->role ? $user->role->name : null;
                $userDetails = $user->userDetails ?? null;
                return [
                    'id' => $user->id,
                    'fullname' => $user->fullname,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role_name' => $roleName,
                    'user_id' => $user->id,
                    'role_id' => $user->role_id,
                    'api_token' => $user->api_token,
                    'uid' => $user->uid,
                    'unique_id' => $user->unique_id,
                    'isapproved' => $user->isapproved,
                    'created_by' => $userDetails ? $userDetails->created_by : null,
                    'bussiness_name' => $userDetails ? $userDetails->bussiness_name : null,
                    'bussiness_address' => $userDetails ? $userDetails->bussiness_address : null,
                    'bussiness_email' => $userDetails ? $userDetails->bussiness_email : null,
                    'business_phone' => $userDetails ? $userDetails->business_phone : null,
                    'profile_photo' => $userDetails ? url($userDetails->profile_photo) : null,
                    'address' => $userDetails ? $userDetails->address : null,
                    'country' => $userDetails ? $userDetails->country : null,
                    'state' => $userDetails ? $userDetails->state : null,
                    'city' => $userDetails ? $userDetails->city : null,
                    'pin_code' => $userDetails ? $userDetails->pin_code : null,
                    'license_number' => $userDetails ? $userDetails->license_number : null,
                    'alternate_number' => $userDetails ? $userDetails->alternate_number : null,
                    'no_of_employees' => $userDetails ? $userDetails->no_of_employees : null,
                    'purpose_id' => $userDetails ? explode(',', $userDetails->purpose_id) : null,
                    'property_id' => $userDetails ? explode(',', $userDetails->property_id) : null,
                    'property_type_id' => $userDetails ? explode(',', $userDetails->property_type_id) : null,
                ];
            });


            // Return the user list as JSON response
            return response()->json($userList, 200);
        } catch (\Throwable $th) {
            // Handle any exceptions and return an error response
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    // for search agent by id
    // public function searchAgentByID(Request $request)
    // {
    //     try {

    //          if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
    //             return response()->json(['error' => 'Please provide an API token.'], 422);
    //         }

    //         // Retrieve the Authorization header
    //         $authorizationHeader = $request->header('Authorization');

    //         // Check if the header starts with "Bearer "
    //         if (!str_starts_with($authorizationHeader, 'Bearer ')) {
    //             return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
    //         }

    //         // Extract the token by removing the "Bearer " prefix
    //         $requestToken = substr($authorizationHeader, 7);

    //         // Check if the token is empty after removing "Bearer "
    //         if (empty($requestToken)) {
    //             return response()->json(['error' => 'Token is missing.'], 422);
    //         }

    //         // Verify the token dynamically (check in the database)
    //         $user = User::where('api_token', $requestToken)->first();

    //         if (!$user) {
    //             return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
    //         }

    //         $userId = $user->id;

    //         $role = Role::find($user->role_id);
    //         if (!$role || $role->name !== 'consultancy') {
    //             return response()->json(['error' => 'User does not have the required role.'], 400);
    //         }
    //         $userId = $user->id;


    //         $userData = User::where('role_id', 3)->where('created_by', $user->id)->get();

    //         $returnArr = [];

    //         if (count($userData)) {

    //             foreach ($userData as $user) {

    //                 $joinRequestData = JoinRequest::where('agent_id', $user->id)->where('consultancy_id', $userId)->first();

    //                 if (!$joinRequestData) {
    //                     $user_status = 'normal';
    //                 } else {

    //                     if ($joinRequestData->status == 'accepted') {
    //                         $user_status = 'conneted';
    //                     } elseif ($joinRequestData->status == 'requested') {
    //                         $user_status = 'requested';
    //                     } else {
    //                         $user_status = 'normal';
    //                     }

    //                 }

    //                 $returnArr[] = [
    //                     'id' => $user->id,
    //                     'name' => $user->fullname,
    //                     'email' => $user->email,
    //                     'phone' => $user->phone,
    //                     'role_id' => $user->role_id,
    //                     'unique_id' => $user->unique_id,
    //                     'user_status' => $user_status,
    //                 ];

    //             }

    //         }

    //         return response()->json(['status' => true, 'data' => $returnArr], 201);

    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         \Log::error($e->getMessage()); // Log the exception message
    //         return response()->json(['error' => 'Failed.' . $e->getMessage()], 500);
    //     }
    // }

    public function searchAgentByID(Request $request)
    {
        try {
            // Validate and extract API token
            if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
                return response()->json(['error' => 'Please provide an API token.'], 422);
            }

            // Retrieve the Authorization header
            $authorizationHeader = $request->header('Authorization');

            // Check if the header starts with "Bearer "
            if (!str_starts_with($authorizationHeader, 'Bearer ')) {
                return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
            }

            // Extract the token by removing the "Bearer " prefix
            $requestToken = substr($authorizationHeader, 7);

            // Check if the token is empty after removing "Bearer "
            if (empty($requestToken)) {
                return response()->json(['error' => 'Token is missing.'], 422);
            }

            // Verify the token dynamically (check in the database)
            $user = User::where('api_token', $requestToken)->first();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
            }

            $userId = $user->id;

            $role = Role::find($user->role_id);
            if (!$role || $role->name !== 'consultancy') {
                return response()->json(['error' => 'User does not have the required role.'], 400);
            }
            $userId = $user->id;


            // Check if user has the required "consultancy" role
            $role = Role::find($user->role_id);
            if (!$role || $role->name !== 'consultancy') {
                return response()->json(['error' => 'User does not have the required role.'], 400);
            }

            // Validate unique_id parameter
            $uniqueId = $request->input('uniqueId');
            if (!$uniqueId) {
                return response()->json(['error' => 'Unique ID is required.'], 422);
            }

            // Search for the agent with the given unique_id
            $agent = User::where('unique_id', $uniqueId)->first();
            if (!$agent) {
                return response()->json(['error' => 'No agent found with the given Unique ID.'], 404);
            }

            // Check the relationship between agent and consultancy
            $joinRequestData = JoinRequest::where('agent_id', $agent->id)
                ->where('consultancy_id', $user->id)
                ->first();

            $userStatus = 'normal';
            if ($joinRequestData) {
                $userStatus = match ($joinRequestData->status) {
                    'accepted' => 'connected',
                    'requested' => 'requested',
                    default => 'normal',
                };
            }

            // Prepare response data
            $responseData = [
                'id' => $agent->id,
                'name' => $agent->fullname,
                'email' => $agent->email,
                'phone' => $agent->phone,
                'role_id' => $agent->role_id,
                'unique_id' => $agent->unique_id,
                'user_status' => $userStatus,
            ];

            return response()->json(['status' => true, 'data' => $responseData], 200);
        } catch (\Exception $e) {
            \Log::error($e->getMessage()); // Log the exception message
            return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }

    // for send join request to agent
    public function sendRequestByConsultancyToAgent(Request $request)
    {
        try {

            if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
                return response()->json(['error' => 'Please provide an API token.'], 422);
            }

            // Retrieve the Authorization header
            $authorizationHeader = $request->header('Authorization');

            // Check if the header starts with "Bearer "
            if (!str_starts_with($authorizationHeader, 'Bearer ')) {
                return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
            }

            // Extract the token by removing the "Bearer " prefix
            $requestToken = substr($authorizationHeader, 7);

            // Check if the token is empty after removing "Bearer "
            if (empty($requestToken)) {
                return response()->json(['error' => 'Token is missing.'], 422);
            }

            // Verify the token dynamically (check in the database)
            $user = User::where('api_token', $requestToken)->first();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
            }

            $userId = $user->id;

            $role = Role::find($user->role_id);
            if (!$role || $role->name !== 'consultancy') {
                return response()->json(['error' => 'User does not have the required role.'], 400);
            }

            $userId = $user->id;


            // Validate that the user exists in the database
            if (!User::where('id', $request->agent_id)->exists()) {
                return response()->json(['error' => 'Agent not found'], 404);
            }

            $check = JoinRequest::where(['agent_id' => $request->agent_id, 'consultancy_id' => $userId])->first();

            if ($check) {

                JoinRequest::where(['agent_id' => $request->agent_id, 'consultancy_id' => $userId])->delete();

                return response()->json(['status' => true, 'message' => 'Request removed successfully.'], 201);

            } else {

                JoinRequest::insert(['agent_id' => $request->agent_id, 'consultancy_id' => $userId, 'status' => 'requested']);

                return response()->json(['status' => true, 'message' => 'Request sent successfully.'], 201);
            }



        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e->getMessage()); // Log the exception message
            return response()->json(['error' => 'Failed.' . $e->getMessage()], 500);
        }
    }


    // for get all join requests
    public function getAllJoinRequestList(Request $request)
    {
        try {
            if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
                return response()->json(['error' => 'Please provide an API token.'], 422);
            }

            // Retrieve the Authorization header
            $authorizationHeader = $request->header('Authorization');

            // Check if the header starts with "Bearer "
            if (!str_starts_with($authorizationHeader, 'Bearer ')) {
                return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
            }

            // Extract the token by removing the "Bearer " prefix
            $requestToken = substr($authorizationHeader, 7);

            // Check if the token is empty after removing "Bearer "
            if (empty($requestToken)) {
                return response()->json(['error' => 'Token is missing.'], 422);
            }

            // Verify the token dynamically (check in the database)
            $user = User::where('api_token', $requestToken)->first();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
            }

            $userData = User::where('api_token', $requestToken)->first();

            // Validate that the user exists in the database
            if (!$userData) {
                return response()->json(['error' => 'Agent not found'], 404);
            }

            $userId = $userData->id;

            $joinRequests = JoinRequest::with(['consultancy.userDetail'])
                ->where('type', 'consultancy-agent')
                ->where('agent_id', $userId)
                ->get();

            if ($joinRequests->isEmpty()) {
                return response()->json(['error' => 'No request found for this agent'], 404);
            }

            $userDetails = $userData->userDetail;

            $returnData = [
                'agent' => [
                    'id' => $userData->id,
                    'fullname' => $userData->fullname,
                    'email' => $userData->email,
                    'phone' => $userData->phone,
                    'role_id' => $userData->role_id,
                    'role_name' => $userData->role_name,
                    'unique_id' => $userData->unique_id,
                    'api_token' => $userData->api_token,
                    'profile_photo' => $userDetails ? $userDetails->profile_photo : null,
                    'business_name' => $userDetails ? $userDetails->bussiness_name : null,
                    'business_address' => $userDetails ? $userDetails->bussiness_address : null,
                    'business_email' => $userDetails ? $userDetails->bussiness_email : null,
                    'business_phone' => $userDetails ? $userDetails->business_phone : null,
                    'profile_photo' => $userDetails ? $userDetails->profile_photo : null,
                    'address' => $userDetails ? $userDetails->address : null,
                ],
                'join_requests' => []
            ];

            foreach ($joinRequests as $row) {
                $consultancy = $row->consultancy;
                $consultancyDetails = $consultancy->userDetail;

                $returnData['join_requests'][] = [
                    'status' => $row->status,
                    'consultancy' => [
                        'id' => $consultancy->id,
                        'fullname' => $consultancy->fullname,
                        'email' => $consultancy->email,
                        'phone' => $consultancy->phone,
                        'role_id' => $consultancy->role_id,
                        'role_name' => $consultancy->role_name,
                        'unique_id' => $consultancy->unique_id,
                        'api_token' => $consultancy->api_token,
                        'profile_photo' => $userDetails ? $userDetails->profile_photo : null,
                        'business_name' => $consultancyDetails ? $consultancyDetails->bussiness_name : null,
                        'business_address' => $consultancyDetails ? $consultancyDetails->bussiness_address : null,
                        'business_email' => $consultancyDetails ? $consultancyDetails->bussiness_email : null,
                        'business_phone' => $consultancyDetails ? $consultancyDetails->business_phone : null,
                        'profile_photo' => $consultancyDetails ? $consultancyDetails->profile_photo : null,
                        'address' => $consultancyDetails ? $consultancyDetails->address : null,
                    ],
                ];
            }

            return response()->json($returnData, 200);
        } catch (\Throwable $th) {
            // Handle any exceptions and return an error response
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }



    // for accept and decline the request
    public function AcceptDeclineRequestByConsultancyToAgent(Request $request)
    {
        try {
            // Validate the presence of the API token
            if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
                return response()->json(['error' => 'Please provide an API token.'], 422);
            }

            // Retrieve the Authorization header
            $authorizationHeader = $request->header('Authorization');

            // Check if the header starts with "Bearer "
            if (!str_starts_with($authorizationHeader, 'Bearer ')) {
                return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
            }

            // Extract the token by removing the "Bearer " prefix
            $requestToken = substr($authorizationHeader, 7);

            // Check if the token is empty after removing "Bearer "
            if (empty($requestToken)) {
                return response()->json(['error' => 'Token is missing.'], 422);
            }

            // Verify the token dynamically (check in the database)
            $user = User::where('api_token', $requestToken)->first();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
            }        // Validate that the user exists in the database
            if (!$user) {
                return response()->json(['error' => 'Agent not found.'], 404);
            }
    
            $userId = $user->id;
    
            // Validate that the consultancy exists in the database
            if (!User::where('id', $request->consultancy_id)->exists()) {
                return response()->json(['error' => 'Consultancy not found.'], 404);
            }
    
            // Check if a join request exists for the given agent and consultancy
            $joinRequest = JoinRequest::where([
                'agent_id' => $userId,
                'consultancy_id' => $request->consultancy_id
            ])->first();
    
            if (!$joinRequest) {
                return response()->json(['error' => 'Join request not available.'], 404);
            }
    
            // Update the status of the join request
            $joinRequest->update(['status' => $request->status]);
    
            return response()->json([
                'status' => true,
                'message' => "Request status updated to {$request->status} successfully."
            ], 201);
    
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }
    

    // for leave the consultancy
    public function leaveTheConsultancy(Request $request)
    {
        try {

            if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
                return response()->json(['error' => 'Please provide an API token.'], 422);
            }

            // Retrieve the Authorization header
            $authorizationHeader = $request->header('Authorization');

            // Check if the header starts with "Bearer "
            if (!str_starts_with($authorizationHeader, 'Bearer ')) {
                return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
            }

            // Extract the token by removing the "Bearer " prefix
            $requestToken = substr($authorizationHeader, 7);

            // Check if the token is empty after removing "Bearer "
            if (empty($requestToken)) {
                return response()->json(['error' => 'Token is missing.'], 422);
            }

            // Verify the token dynamically (check in the database)
            $user = User::where('api_token', $requestToken)->first();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
            }
            // Validate that the user exists in the database
            if (!$user) {
                return response()->json(['error' => 'Agent not found'], 404);
            }

            $userId = $user->id;


            // Validate that the user exists in the database
            if (!User::where('id', $request->consultancy_id)->exists()) {
                return response()->json(['error' => 'Consultancy not found'], 404);
            }


            JoinRequest::where(['agent_id' => $userId, 'consultancy_id' => $request->consultancy_id])->update(['status' => 'leaved']);

            return response()->json(['status' => true, 'message' => 'You leaved the consultancy successfully.'], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e->getMessage());
            return response()->json(['error' => 'Failed.' . $e->getMessage()], 500);
        }
    }


    // for get agent details
    public function getAgentDetails(Request $request)
    {
        try {

            if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
                return response()->json(['error' => 'Please provide an API token.'], 422);
            }

            // Retrieve the Authorization header
            $authorizationHeader = $request->header('Authorization');

            // Check if the header starts with "Bearer "
            if (!str_starts_with($authorizationHeader, 'Bearer ')) {
                return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
            }

            // Extract the token by removing the "Bearer " prefix
            $requestToken = substr($authorizationHeader, 7);

            // Check if the token is empty after removing "Bearer "
            if (empty($requestToken)) {
                return response()->json(['error' => 'Token is missing.'], 422);
            }

            // Verify the token dynamically (check in the database)
            $user = User::where('api_token', $requestToken)->first();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
            }

            $userId = $user->id;

            // $role = Role::find($user->role_id);
            // if (!$role || $role->name !== 'consultancy') {
            //     return response()->json(['error' => 'User does not have the required role.'], 400);
            // }

            $userId = $user->id;

            $consultancy_id_arr = JoinRequest::where(['agent_id' => $userId, 'status' => 'accepted'])->pluck('consultancy_id')->toArray();
// dd($consultancy_id_arr);
            $consultancyData = User::whereIn('id', $consultancy_id_arr)->get();

            $userData = DB::table('users')
                ->join('user_details', 'users.id', '=', 'user_details.user_id')
                ->join('roles', 'users.role_id', '=', 'roles.id')
                ->where('users.id', $userId)
                ->where('users.role_id', '!=', 1)
                ->select('users.*', 'user_details.*', 'roles.name as role_name')
                ->first();
// dd($userData );
            // Check if user data exists
            if (!$userData) {
                return response()->json(['error' => 'No data found on this user not found.'], 404);
            }


            $loginUserData = User::where('id', $request->login_id)->first();

            // Validate that the user exists in the database
            if (!$loginUserData) {
                return response()->json(['error' => 'Login User not found'], 404);
            }


            if ($userData->role_id == 5 && $loginUserData->role_id == 4) {
                $total_project_assign = CompanyConsultancyProject::where('company_id', $loginUserData->id)->where('consultancy_id', $userData->user_id)->where('type', 'company-consultancy')->count();
            }


            if ($userData->role_id == 3 && $loginUserData->role_id == 5) {
                $total_project_assign = CompanyConsultancyProject::where('consultancy_id', $loginUserData->id)->where('agent_id', $userData->user_id)->where('type', 'consultancy-agent')->count();
            }

            return response()->json([
                'id' => $userData->id,
                'user_id' => $userData->user_id,
                'first_name' => $userData->first_name,
                'last_name' => $userData->last_name,
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
                'country' => $userData->country,
                'state' => $userData->state,
                'city' => $userData->city,
                'address' => $userData->address,
                'pin_code' => $userData->pin_code,
                'profile_photo' => url($userData->profile_photo),
                'license_number' => $userData->license_number,
                'alternate_number' => $userData->alternate_number,
                'no_of_employees' => $userData->no_of_employees,
                'purpose_id' => explode(',', $userData->purpose_id),
                'property_id' => explode(',', $userData->property_id),
                'property_type_id' => explode(',', $userData->property_type_id),
                'created_at' => $userData->created_at,
                'updated_at' => $userData->updated_at,
                'about_us' => $userData->about_us,
                'total_project_assign' => $total_project_assign ?? Null,
                'consultancy' => $consultancyData
            ], 200);
        } catch (\Throwable $th) {
            // Handle any exceptions and return an error response
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    // for get consultancy details
    public function getConsultancyDetails(Request $request)
    {
        try {

            if ($request->header('api-token') == '') {
                return response()->json(['error' => 'Please enter api token first.'], 422);
            }

            $requestToken = $request->header('api-token');

            $userId = null;

            $userData = User::where('api_token', $requestToken)->first();

            // Validate that the user exists in the database
            if (!$userData) {
                return response()->json(['error' => 'Consultancy not found'], 404);
            }

            $userId = $userData->id;

            // Get the IDs of agents who have accepted join requests with the current user's consultancy
            $agent_ids_from_join_requests = JoinRequest::where(['consultancy_id' => $userId, 'status' => 'accepted'])->pluck('agent_id')->toArray();

            // Get the count of agents created by the current user
            $createdAgentCount = User::where('created_by', $userId)->count();

            // Get the count of agents whose IDs are in the array from join requests
            $agentCountFromJoinRequests = count($agent_ids_from_join_requests);

            // Calculate the total count of agents
            $totalAgentCount = $createdAgentCount + $agentCountFromJoinRequests;

            $agent_ids_from_users_created_by = User::where('created_by', $userId)->pluck('id')->toArray();

            $final_agent_id_arr = array_merge($agent_ids_from_join_requests, $agent_ids_from_users_created_by);

            $agentData = User::whereIn('id', array_unique($final_agent_id_arr))->get();


            $userData = DB::table('users')
                ->join('user_details', 'users.id', '=', 'user_details.user_id')
                ->join('roles', 'users.role_id', '=', 'roles.id')
                ->where('users.id', $userId)
                ->where('users.role_id', '!=', 1)
                ->select('users.*', 'user_details.*', 'roles.name as role_name')
                ->first();

            // Check if user data exists
            if (!$userData) {
                return response()->json(['error' => 'No data found on this user not found.'], 404);
            }

            return response()->json([
                'id' => $userData->id,
                'fullname' => $userData->fullname,
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
                'country' => $userData->country,
                'state' => $userData->state,
                'city' => $userData->city,
                'address' => $userData->address,
                'pin_code' => $userData->pin_code,
                'profile_photo' => url($userData->profile_photo),
                'license_number' => $userData->license_number,
                'alternate_number' => $userData->alternate_number,
                'no_of_employees' => $userData->no_of_employees,
                'purpose_id' => explode(',', $userData->purpose_id),
                'property_id' => explode(',', $userData->property_id),
                'property_type_id' => explode(',', $userData->property_type_id),
                'created_at' => $userData->created_at,
                'updated_at' => $userData->updated_at,
                'total_agents' => $totalAgentCount,
                'agent' => $agentData
            ], 200);
        } catch (\Throwable $th) {
            // Handle any exceptions and return an error response
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }
    public function store(Request $request)
    {
        // Get the bearer token from the request header
        $bearerToken = $request->bearerToken();

        // Check if the bearer token exists
        if (!$bearerToken) {
            return response()->json(['error' => 'Bearer token missing'], 401);
        }

        // Find the user based on the bearer token
        $user = User::where('api_token', $bearerToken)->first();

        // Check if user exists
        if (!$user) {
            return response()->json(['error' => 'Token not authenticated'], 401);
        }

        // Define validation rules
        $rules = [
            'name' => 'nullable|string|unique:agents,name|max:255',
            'email' => 'nullable|email|unique:agents,email',
            'phone_number' => 'nullable|string|unique:agents,phone_number|max:255',
            'rera' => 'nullable|string|max:255', // Make "rera" optional
            'bussiness_name' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state_province' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'projects_name' => 'nullable|array',
            'projects_name.*' => 'max:255',
            'profile_picture' => 'nullable|image|max:2048',
            'status' => 'required|in:active,inactive',
            'location_ids' => 'required|array',
            'location_ids.*' => 'exists:locations,id',
            'user_id' => 'required|exists:users,id,role,agent'
        ];

        // Custom validation error messages
        $messages = [
            'email.unique' => 'The email has already been taken.',
            'location_ids.*.exists' => 'One or more provided location IDs are invalid.',
            'user_id.exists' => 'The specified user does not exist or is not an agent.'
        ];

        // Validate agent creation data
        $validator = Validator::make($request->all(), $rules, $messages);

        // Check if validation fails
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $userId = $request->input('user_id');

        // Find the user by their ID or fail with a 404 error if not found
        $userById = User::findOrFail($userId);

        if ($userById->role != 'agent') {
            return response()->json(['error' => 'User ID not found in agents table'], 404);
        }

        // Find the existing agent record by user ID
        $agent = Agent::where('user_id', $userId)->first();

        // If agent record doesn't exist, create a new one
        if (!$agent) {
            $agent = new Agent();
            $agent->user_id = $userId;
        }

        // Validate location IDs before processing
        $validLocationIds = [];
        foreach ($request->input('location_ids', []) as $locationId) {
            $location = Location::find($locationId);
            if ($location) {
                $validLocationIds[] = $locationId;
            }
        }

        // If no valid location IDs found, return an error response
        if (empty($validLocationIds)) {
            return response()->json(['error' => 'No valid location IDs provided'], 422);
        }

        // Update or create agent details
        $agent->fill($request->all());
        $agent->location_ids = implode(',', $validLocationIds);
        // Convert projects_name array to a string
        $projectsName = $request->input('projects_name', []);
        $agent->projects_name = implode(',', $projectsName);

        // Handle profile picture upload
        if ($request->hasFile('profile_picture')) {
            $image = $request->file('profile_picture');
            $filename = time() . '.' . $image->getClientOriginalExtension();
            $path = 'agents/profiles/' . $filename;
            Storage::putFileAs('public', $image, $path);
            $agent->profile_picture = $path;
        }

        // Save the agent record
        $agent->save();

        // Create a record in the agents_unique_id table only if it doesn't already exist
        $existingUniqueId = AgentUniqueId::where('agent_id', $agent->id)->first();
        if (!$existingUniqueId) {
            $uniqueId = 'URA' . str_pad($agent->id, 3, '0', STR_PAD_LEFT); // Example: URA001, URA002, etc.
            AgentUniqueId::create([
                'agent_id' => $agent->id,
                'agents_unique_id' => $uniqueId
            ]);
        }

        // Send password reset link to the provided email
        $response = $this->sendResetLinkEmail($userById->email);

        // Check if the password reset link was successfully sent
        if ($response == Password::RESET_LINK_SENT) {
            return response()->json(['success' => 'Agent details updated successfully. Password reset link sent to the provided email.'], 201);
        }
        // } else {
        //     return response()->json(['error' => 'Failed to send password reset link.'], 500);
        // }
    }

    private function sendResetLinkEmail($email)
    {
        // Generate a password reset token and send the reset link
        return Password::broker()->sendResetLink(['email' => $email]);
    }

    //     public function update(Request $request)
// {

    //     // Find the agent by ID
//     $id = $request->id;

    //     $agent = Agent::findOrFail($id);

    //     // Validate the request data
//     $request->validate([
//         'name' => 'nullable|string|max:255',
//         'email' => 'nullable|email|unique:agents,email,'.$id,
//         'phone_number' => 'nullable|string|max:255|unique:agents,phone_number,'.$id,
//         'rera' => 'nullable|string|max:255|unique:agents,rera,'.$id,
//         'bussiness_name' => 'nullable|string|max:255',
//         'address' => 'nullable|string|max:255',
//         'city' => 'nullable|string|max:255',
//         'state_province' => 'nullable|string|max:255',
//         'country' => 'nullable|string|max:255',
//         'profile_picture' => 'nullable|image|max:2048',

    //         'status' => 'required|in:active,inactive',
//     ]);

    //     // Update agent data
//     $agent->name = $request->name;
//     $agent->email = $request->email;
//     $agent->phone_number = $request->phone_number;
//     $agent->rera = $request->rera;
//     $agent->bussiness_name = $request->bussiness_name;
//     $agent->address = $request->address;
//     $agent->city = $request->city;
//     $agent->state_province = $request->state_province;
//     $agent->country = $request->country;

    //     $agent->status = $request->status;

    //     // Handle profile picture update
//     if ($request->hasFile('profile_picture')) {
//         $image = $request->file('profile_picture');
//         $filename = time() . '.' . $image->getClientOriginalExtension();
//         $path = 'agents/profiles/' . $filename;
//         Storage::putFileAs('public', $image, $path);
//         $agent->profile_picture = $path;
//     }

    //     // Save the updated agent to the database
//     $agent->save();

    //     return response()->json(['success' => 'Agent updated successfully.']);
// }

    public function destroy(Request $request)
    {
        try {
            // Find the agent by ID
            $id = $request->id;
            $agent = Agent::findOrFail($id);

            // Delete the profile picture if it exists
            if ($agent->profile_picture) {
                Storage::delete('public/' . $agent->profile_picture);
            }

            // Delete the agent
            $agent->delete();

            return response()->json(['success' => 'Agent deleted successfully.']);
        } catch (Exception $e) {
            return response()->json(['error' => 'An error occurred while updating the status.'], 500);

        }

    }



    public function toggleStatus(Request $request)
    {
        try {
            $id = $request->input('agents_status_id');
            $status = $request->input('status');

            // Validate the status input
            $validatedStatus = $status == 0 ? 'inactive' : ($status == 1 ? 'active' : null);

            if (!$validatedStatus) {
                return response()->json(['error' => 'Invalid status value.'], 400);
            }

            // Update the status in the database
            DB::table('agent_details')->where('id', $id)->update(['status' => $validatedStatus]);

            return response()->json(['status' => $validatedStatus]);

        } catch (Exception $e) {
            return response()->json(['error' => 'An error occurred while updating the status.'], 500);

        }

    }




}
