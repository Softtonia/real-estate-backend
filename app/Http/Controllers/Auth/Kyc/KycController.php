<?php

namespace App\Http\Controllers\Auth\Kyc;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Role;
use App\Models\UserDetail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;


class KycController extends Controller
{
    public function completeKyc(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'User not authenticated.'], 401);
        }

        // Role-based restriction
        $rolesForKYC = ['agent', 'company', 'consultancy', 'developer'];
        $userRole = strtolower($user->role->name);

        if (!in_array($userRole, $rolesForKYC)) {
            return response()->json([
                'error' => 'Your role is not allowed to complete KYC.'
            ], 403);
        }

        // Validation
        try {
            $request->validate([
                // Users table fields
                'first_name' => ['required', 'string', 'max:255'],
                'last_name' => ['required', 'string', 'max:255'],
                'user_name' => [
                    'required',
                    'string',
                    'min:3',
                    'max:20',
                    'regex:/^[a-zA-Z0-9._]+$/',
                    Rule::unique('users', 'user_name')->ignore($user->id),
                ],
                'email' => ['nullable', 'email'],
                'phone' => ['nullable', 'string'],
                'country_id' => ['required', 'exists:countries,id'],
                'state_id' => ['required', 'exists:states,id'],
                'city_id' => ['required', 'exists:cities,id'],
                'area_locality' => ['nullable', 'string'],
                'colony' => ['nullable', 'string'],
                'street_address' => ['nullable', 'string'],
                'pin_code' => ['required', 'numeric', 'digits:6'],
                'about' => ['nullable', 'string'],

                // Password
                'password' => [
                    'nullable',
                    'min:8',
                    'regex:/[A-Z]/',        
                    'regex:/[a-z]/',        
                    'regex:/[0-9]/',        
                    'regex:/[@$!%*#?&]/',   
                ],

                // UserDetails table fields
                'bussiness_name' => ['nullable', 'string'],
                'bussiness_address' => ['nullable', 'string'],
                'bussiness_email' => ['nullable', 'email'],
                'business_phone' => ['nullable', 'string'],
                'business_country_id' => ['nullable', 'exists:countries,id'],
                'business_state_id' => ['nullable', 'exists:states,id'],
                'business_city_id' => ['nullable', 'exists:cities,id'],
                'address' => ['nullable', 'string'],
                'business_pin_code' => ['nullable', 'numeric', 'digits:6'],
                'license_number' => ['nullable', 'string'],
                'alternate_number' => ['nullable', 'string'],
                'no_of_employees' => ['nullable', 'numeric'],
                'about_us' => ['nullable', 'string'],
                'business_area_locality' => ['nullable', 'string'],
                'business_colony' => ['nullable', 'string'],
                'business_street_address' => ['nullable', 'string'],

                // KYC fields
                'aadhaar_number' => [
                    'required',
                    'digits:12',
                    Rule::unique('user_details', 'aadhaar_number')->ignore($user->id, 'user_id')
                ],
                'aadhaar_front' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'min:10','max:5120'],
                'aadhaar_back' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'min:10','max:5120'],
                'business_proof' => ['required', 'file', 'mimes:pdf', 'min:10','max:5120'],

            ], [
                'user_name.regex' => 'Only letters, numbers, dot, and underscore are allowed in username.',
                'password.regex' => 'Password must include uppercase, lowercase, number, and special character.',
                'aadhaar_number.digits' => 'Aadhaar number must be 12 digits.',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => $e->errors()], 400);
        }

        DB::beginTransaction();
        try {
            // Update users table
            $user->first_name = $request->first_name;
            $user->last_name = $request->last_name;
            $user->user_name = $request->user_name;
            $user->email = $request->email ?? $user->email;
            $user->phone = $request->phone ?? $user->phone;
            $user->country_id = $request->country_id;
            $user->state_id = $request->state_id;
            $user->city_id = $request->city_id;
            $user->area_locality = $request->area_locality;
            $user->colony = $request->colony;
            $user->street_address = $request->street_address;
            $user->pin_code = $request->pin_code;
            $user->about = $request->about;

            if ($request->filled('password')) {
                $user->password = Hash::make($request->password);
            }

            // Set KYC status to In Progress
            $user->kyc = 1;
            $user->isapproved = 1; 
            $user->save();

            // Update user_details table
            $userDetail = [
                'user_id' => $user->id,
                'bussiness_name' => $request->bussiness_name,
                'bussiness_address' => $request->bussiness_address,
                'bussiness_email' => $request->bussiness_email,
                'business_phone' => $request->business_phone,
                'country_id' => $request->business_country_id ?? null,
                'state_id' => $request->business_state_id ?? null,
                'city_id' => $request->business_city_id ?? null,
                'address' => $request->address,
                'pin_code' => $request->business_pin_code,
                'license_number' => $request->license_number,
                'alternate_number' => $request->alternate_number,
                'no_of_employees' => $request->no_of_employees,
                'about_us' => $request->about_us,
                'area_locality' => $request->business_area_locality,
                'colony' => $request->business_colony,
                'street_address' => $request->business_street_address,
                'aadhaar_number' => $request->aadhaar_number,
            ];

            // File uploads
            if ($request->hasFile('profile_photo')) {
                $file = $request->file('profile_photo');
                $fileName = time() . '_' . str_replace(' ', '_', $file->getClientOriginalName());
                $file->move(public_path('uploads/users'), $fileName);
                $userDetail['profile_photo'] = 'uploads/users/' . $fileName;
            }

            if ($request->hasFile('aadhaar_front')) {
                $file = $request->file('aadhaar_front');
                $fileName = time() . '_aadhaar_front_' . $file->getClientOriginalName();
                $file->move(public_path('uploads/kyc/aadhaarFront'), $fileName);
                $userDetail['aadhaar_front'] = 'uploads/kyc/aadhaarFront/' . $fileName;
            }

            if ($request->hasFile('aadhaar_back')) {
                $file = $request->file('aadhaar_back');
                $fileName = time() . '_aadhaar_back_' . $file->getClientOriginalName();
                $file->move(public_path('uploads/kyc/aadhaarBack'), $fileName);
                $userDetail['aadhaar_back'] = 'uploads/kyc/aadhaarBack/' . $fileName;
            }

            if ($request->hasFile('business_proof')) {
                $file = $request->file('business_proof');
                $fileName = time() . '_business_proof_' . $file->getClientOriginalName();
                $file->move(public_path('uploads/kyc/businessProof'), $fileName);
                $userDetail['business_proof'] = 'uploads/kyc/businessProof/' . $fileName;
            }

            UserDetail::updateOrCreate(
                ['user_id' => $user->id],
                $userDetail
            );

            DB::commit();
            return response()->json([
                'status' => true,
                'message' => 'KYC and profile updated successfully. Awaiting approval.',
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e->getMessage());
            return response()->json(['error' => 'Failed to complete KYC. ' . $e->getMessage()], 500);
        }
    }



     /**
     * Update KYC status of a user
     * status: 2 = Approved, 0 = Rejected
     */
//    public function updateKycStatus(Request $request)
// {
//     try {
//         // Validation
//         $request->validate([
//             'user_id' => ['required', 'exists:users,id'],
//             'status' => ['required', 'in:0,1,2,3'], // 0 = Pending, 1 = In Progress, 2 = Approved, 3 = Rejected
//             'reject_reason' => ['nullable', 'string']
//         ], [
//             'user_id.required' => 'User ID is required.',
//             'user_id.exists' => 'User not found.',
//             'status.required' => 'Status is required.',
//             'status.in' => 'Invalid status. Only 0 (Pending), 1 (In Progress) or 2 (Approved) or 3 (Rejected) allowed .',
//         ]);

//         $user = User::find($request->user_id);

//         if (!$user) {
//             return response()->json(['status' => false, 'error' => 'User not found.'], 200);
//         }

//         // Update KYC and isapproved mapping
//         $user->kyc = $request->status;

//         if ($request->status == 2) {
//             $user->isapproved = 1; // Active
//             $user->reject_reason = null;
//         } elseif ($request->status == 1) {
//             $user->isapproved = 3; // Under Review
//             $user->reject_reason = null;
//         } else {
//             $user->isapproved = 4; // Rejected
//             $user->reject_reason = $request->reject_reason ?? 'KYC rejected by admin';
//         }

//         $user->save();

//         $statusText = match ($request->status) {
//             0 => 'KYC set to Pending / Rejected.',
//             1 => 'KYC set to In Progress.',
//             2 => 'KYC approved successfully.',
//             default => 'KYC status updated.',
//         };

//         return response()->json([
//             'status' => true,
//             'message' => $statusText,
//             'user' => [
//                 'id' => $user->id,
//                 'kyc' => $user->kyc,
//                 'isapproved' => $user->isapproved,
//                 'reject_reason' => $user->reject_reason,
//             ]
//         ], 200);

//     } catch (\Illuminate\Validation\ValidationException $e) {
//         return response()->json([
//             'status' => false,
//             'error' => $e->errors()
//         ], 422);
//     } catch (\Exception $e) {
//         return response()->json([
//             'status' => false,
//             'error' => $e->getMessage()
//         ], 500);
//     }
// }

public function updateKycStatus(Request $request)
{
    try {
        // ✅ Validate incoming data
        $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'status' => ['required', 'in:0,1,2,3'], // 0 = Pending, 1 = In Progress, 2 = Approved, 3 = Rejected
            'reject_reason' => ['nullable', 'string']
        ], [
            'user_id.required' => 'User ID is required.',
            'user_id.exists' => 'User not found.',
            'status.required' => 'Status is required.',
            'status.in' => 'Invalid status. Allowed values: 0 (Pending), 1 (In Progress), 2 (Approved), 3 (Rejected).',
        ]);

        $user = User::find($request->user_id);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found.',
            ], 404);
        }

        // ✅ Update only KYC fields
        $user->kyc = $request->status;
        $user->reject_reason = $request->status == 3
            ? ($request->reject_reason ?? 'KYC rejected by admin')
            : null;

        $user->save();

        // ✅ Prepare message based on status
        $statusText = match ($request->status) {
            0 => 'KYC set to Pending.',
            1 => 'KYC set to In Progress.',
            2 => 'KYC approved successfully.',
            3 => 'KYC rejected.',
            default => 'KYC status updated.',
        };

        return response()->json([
            'status' => true,
            'message' => $statusText,
            'user' => [
                'id' => $user->id,
                'kyc_status' => $user->kyc,
                'reject_reason' => $user->reject_reason,
            ]
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => false,
            'error' => $e->errors()
        ], 422);
    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'error' => $e->getMessage()
        ], 500);
    }
}




}
