<?php
namespace App\Http\Controllers\Admin;

use App\Models\Keyword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ImportKeyword;
use App\Models\ProjectList;
use App\Models\PropertyList;
use App\Models\TicketStatus;
use App\Models\Ticket;
use App\Models\Role;
use Auth;
use Str;
use Hash;
use App\Imports\ImportKeywordsImport;
use App\Exports\ImportKeywordsExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
// use Illuminate\Support\Carbon;
use Carbon\Carbon;

class AdminController extends Controller
{

    public function index()
    {
        $users = DB::table('users')->where('role', '!=', 'admin')->get();

        // Initialize an empty array to store user data
        $userData = [];

        // Iterate over each user and extract necessary properties
        foreach ($users as $user) {
            $userData[] = [
                'id' => $user->id,
                'fullname' => $user->fullname,
                'email' => $user->email,
                'phone' => $user->phone
            ];
        }

        // Return the user data as JSON response
        return response()->json($userData, 200);
    }


    public function login(Request $request)
    {
        try {
            // Validate the login request
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'password' => 'required|min:6',
            ]);

            // Return validation errors, if any
            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()], 422);
            }

            // Retrieve the user by email
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json(['error' => ['email' => 'Email not found.']], 404);
            }

            // Verify the password
            if (!Hash::check($request->password, $user->password)) {
                return response()->json(['error' => ['password' => 'Incorrect password.']], 401);
            }

            // Retrieve the role associated with the user
            $role_name = $user->role->name ?? 'Unknown'; // Use null-safe operator

            // Check if the token needs to be updated (only if it's older than 24 hours)
            if (!$user->api_token || !$user->token_created_at || $user->token_created_at < now()->subHours(24)) {
                $user->api_token = Str::random(80);
                $user->token_created_at = now();
                $user->save();
            }

            return response()->json([
                'message' => 'User logged in successfully',
                'token' => $user->api_token,
                'role_name' => $role_name,
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed. ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request)
    {
        try {
            // Validate the incoming request data for first_name, last_name, and phone_number
            $validatedData = $request->validate([
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'phone' => 'required|string|max:20', // Add validation for phone number
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Handle the validation error and return the errors
            return response()->json(['errors' => $e->errors()], 422);
        }

        // Retrieve the currently authenticated user
        $user = Auth::user();
        // dd( $user);
        // Check if the user is found
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Update the fields with validated data
        $user->first_name = $validatedData['first_name'];
        $user->last_name = $validatedData['last_name'];
        $user->phone = $validatedData['phone']; // Update phone number

        // Save the updated user
        $user->save();

        // Get the role name from the Role table using the role_id
        $role = Role::find($user->role_id); // Assuming 'Role' is the name of the role model

        // Get the role name, or default to 'Unknown' if the role doesn't exist
        $role_name = $role ? $role->name : 'Unknown';

        // Determine the status based on the 'isapproved' field value
        $status = 'UnderReview'; // Default status
        if ($user->isapproved == 1) {
            $status = 'Active';
        } elseif ($user->isapproved == 2) {
            $status = 'Deactive';
        }

        // Return the updated user data along with the additional fields
        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email, // Include email
                'phone' => $user->phone, // Include phone number
                'role_name' => $role_name, // Include role name
                'isapproved' => $status, // Include approval status code
            ]
        ]);
    }


    public function fetchProjectKeywordList()
    {
        try {
            $importKeywordData = ImportKeyword::where('keyword_type', 'project_keyword')->get()->groupBy('keyword_name');

            // Convert the grouped collection to an array
            $result = [];
            foreach ($importKeywordData as $keyword => $items) {
                // Get the first item from the group as a representative
                $item = $items->first();
                $result[] = [
                    'id' => $item->id,
                    'keyword_name' => $item->keyword_name,
                    'slug' => $item->slug,
                    'keyword_type' => $item->keyword_type,
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at
                ];
            }

            return response()->json(['data' => $result], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
        }
    }


    public function fetchPropertyKeywordList()
    {
        try {
            $importKeywordData = ImportKeyword::where('keyword_type', 'property_keyword')->get()->groupBy('keyword_name');

            // Convert the grouped collection to an array
            $result = [];
            foreach ($importKeywordData as $keyword => $items) {
                // Get the first item from the group as a representative
                $item = $items->first();
                $result[] = [
                    'id' => $item->id,
                    'keyword_name' => $item->keyword_name,
                    'slug' => $item->slug,
                    'keyword_type' => $item->keyword_type,
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at
                ];
            }

            return response()->json(['data' => $result], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
        }
    }


    public function fetchDeveloperKeywordList()
    {
        try {
            $importKeywordData = ImportKeyword::where('keyword_type', 'developer_keyword')->get()->groupBy('keyword_name');

            // Convert the grouped collection to an array
            $result = [];
            foreach ($importKeywordData as $keyword => $items) {
                // Get the first item from the group as a representative
                $item = $items->first();
                $result[] = [
                    'id' => $item->id,
                    'keyword_name' => $item->keyword_name,
                    'slug' => $item->slug,
                    'keyword_type' => $item->keyword_type,
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at
                ];
            }

            return response()->json(['data' => $result], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
        }
    }



    // this is for overview
    public function overviewOfDashboard(Request $request)
    {
        try {


            $totalPropertyCount = PropertyList::count();
            $approvedPropertyCount = PropertyList::where('live_status', 'Approve')->count();
            $rejectPropertyCount = PropertyList::where('live_status', 'Reject')->count();
            $underReviewPropertyCount = PropertyList::where('live_status', 'Under Review')->count();
            $disapprovePropertyCount = PropertyList::where('live_status', 'Disapprove')->count();
            $modifyReviewPropertyCount = PropertyList::where('live_status', 'Modify Review')->count();


            $totalProjectCount = ProjectList::count();
            $approvedProjectCount = ProjectList::where('live_status', 'Approve')->count();
            $rejectProjectCount = ProjectList::where('live_status', 'Reject')->count();
            $underReviewProjectCount = ProjectList::where('live_status', 'Under Review')->count();
            $disapproveProjectCount = ProjectList::where('live_status', 'Disapprove')->count();
            $modifyReviewProjectCount = ProjectList::where('live_status', 'Modify Review')->count();



            $ownerCount = User::where('role_id', 2)->count();
            $agentCount = User::where('role_id', 3)->count();
            $companyCount = User::where('role_id', 4)->count();
            $consultancyCount = User::where('role_id', 5)->count();
            $developerCount = User::where('role_id', 6)->count();

            $ticket_status = TicketStatus::get();

            foreach ($ticket_status as $row) {
                $ticketCount = Ticket::where('status_id', $row->id)->count();
                $row->ticket_count = $ticketCount;
            }

            // Construct the return data
            $return = [
                'total_property_count' => $totalPropertyCount,
                'approved_property_count' => $approvedPropertyCount,
                'reject_property_count' => $rejectPropertyCount,
                'under_review_property_count' => $underReviewPropertyCount,
                'disapprove_property_count' => $disapprovePropertyCount,
                'modify_review_property_count' => $modifyReviewPropertyCount,

                'total_project_count' => $totalProjectCount,
                'approved_project_count' => $approvedProjectCount,
                'reject_project_count' => $rejectProjectCount,
                'under_review_project_count' => $underReviewProjectCount,
                'disapprove_project_count' => $disapproveProjectCount,
                'modify_review_project_count' => $modifyReviewProjectCount,

                'owner_count' => $ownerCount,
                'agent_count' => $agentCount,
                'company_count' => $companyCount,
                'consultancy_count' => $consultancyCount,
                'developer_count' => $developerCount,

                'ticket_status' => $ticket_status,
            ];

            return response()->json($return);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    public function LoginActiveInactive(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'login_status' => 'required',
            'user_id' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $user = User::where('id', $request->user_id)->first();

        if (!$user) {
            return response()->json(['error' => 'User not found.'], 404);
        }

        $user->isapproved = $request->login_status;

        if ($user->save()) {
            return response()->json([
                'status' => true,
                'message' => 'User login restricted apply successful'
            ], 200);
        } else {
            return response()->json(['error' => 'Failed to update user status.'], 500);
        }
    }
    public function getAdminProfile(Request $request)
    {
        // Retrieve the currently authenticated user
        $user = Auth::user(); // This should now return the authenticated user

        // Check if user is found
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Get the role associated with the user
        $role = $user->role; // Assuming there's a 'role' relationship in the User model

        // Proceed with returning the required fields
        return response()->json([
            'message' => 'Admin profile retrieved successfully',
            'user' => [
                'first_name' => $user->first_name,  // First Name
                'last_name' => $user->last_name,    // Last Name
                'email' => $user->email,            // Email
                'phone' => $user->phone,            // Phone
                'role_name' => $role ? $role->name : null,  // Role Name
                'isapproved' => $user->isapproved,  // Account approval status
            ]
        ]);
    }

    public function userAllRecordBulksDelete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_ids' => 'required|array', // Ensure user_ids is an array
            //'user_ids.*' => 'integer|exists:users,id', // Each ID must be valid and exist in users table
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $userIds = $request->user_ids;

        DB::transaction(function () use ($userIds) {
            // Delete related data first
            DB::table('user_details')->whereIn('user_id', $userIds)->delete();
            DB::table('user_has_unique_ids')->whereIn('user_id', $userIds)->delete();
            DB::table('otps')->whereIn('user_id', $userIds)->delete();

            // Delete from users table
            DB::table('users')->whereIn('id', $userIds)->delete();
        });

        return response()->json([
            'status' => true,
            'message' => 'Users and all related records deleted successfully'
        ], 200);
    }


    // import keywords store function

    public function import(Request $request)
    {
        //   Validate file input
        Validator::validate($request->all(), [
            'csv_file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        try {
            $file = $request->file('csv_file');
            $handle = fopen($file->getRealPath(), 'r');

            /* Skip header row – delete this line if your CSV has no header */
            fgetcsv($handle);

            $created = 0;
            $updated = 0;
            $errors = [];

            // Allowed keyword types
            $allowedTypes = ['property_keyword', 'project_keyword', 'developer_keyword'];

            while (($cols = fgetcsv($handle, 0, ',')) !== false) {

                // ── Column 1: keyword_name (required)
                $keywordName = trim($cols[0] ?? '');

                // ── Column 2: keyword_type (optional)
                $keywordType = trim($cols[1] ?? '');

                // Auto-detect if second column missing
                if ($keywordType === '') {
                    if (Str::startsWith($keywordName, 'Project '))
                        $keywordType = 'project_keyword';
                    elseif (Str::startsWith($keywordName, 'Developer '))
                        $keywordType = 'developer_keyword';
                    else
                        $keywordType = 'property_keyword';
                }

                // Basic validations
                if ($keywordName === '') {
                    $errors[] = 'Empty keyword_name – row skipped';
                    continue;
                }

                if (!in_array($keywordType, $allowedTypes)) {
                    $errors[] = "Invalid keyword_type '{$keywordType}' for '{$keywordName}' – row skipped";
                    continue;
                }

                // Unique slug
                $slug = Str::slug($keywordName);

                //   Upsert via Eloquent (slug is the unique key)
                $model = ImportKeyword::updateOrCreate(
                    ['slug' => $slug],
                    ['keyword_name' => $keywordName, 'keyword_type' => $keywordType]
                );

                $model->wasRecentlyCreated ? $created++ : $updated++;
            }

            fclose($handle);

            return response()->json([
                'status' => true,
                'message' => 'CSV processed successfully.',
                'created' => $created,
                'updated' => $updated,
                'skipped' => count($errors),
                'errors' => $errors,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Import failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // export keywords csv

    public function export()
    {
        $fileName = 'import_keywords_' . now()->format('Ymd_His') . '.csv';
        $keywords = ImportKeyword::all();

        if ($keywords->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No keywords found to export.',
            ], 404);
        }

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$fileName\"",
        ];

        $columns = ['ID', 'Keyword Name', 'Slug', 'Keyword Type', 'Created At', 'Updated At'];

        $callback = function () use ($keywords, $columns) {
            $file = fopen('php://output', 'w');

            // Add CSV header
            fputcsv($file, $columns);

            // Add rows
            foreach ($keywords as $keyword) {
                fputcsv($file, [
                    $keyword->id,
                    $keyword->keyword_name,
                    $keyword->slug,
                    $keyword->keyword_type,
                    $keyword->created_at,
                    $keyword->updated_at
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }


    // fetch all keywords

    // public function fetchKeywordList()
    // {
    //     $keywords = ImportKeyword::whereIn('id', function ($query) {
    //         $query->select('keyword')->from('keywords');
    //     })->orderBy('keyword_name')->get(['id', 'keyword_name', 'slug', 'keyword_type']);

    //     return response()->json([
    //         'status' => true,
    //         'message' => $keywords->isEmpty()
    //             ? '⚠️ No keywords found.'
    //             : '✅ Keywords fetched successfully.',
    //         'total' => $keywords->count(),
    //         'data' => $keywords,
    //     ]);
    // }

    public function fetchKeywordList()
    {
        $keywords = ImportKeyword::select('id', 'keyword_name', 'slug', 'keyword_type')
            ->orderBy('keyword_name')
            ->get();

        return response()->json([
            'status' => true,
            'message' => $keywords->isEmpty()
                ? ' No import keywords found.'
                : ' Import keywords fetched successfully.',
            'total' => $keywords->count(),
            'data' => $keywords,
        ]);
    }


}
