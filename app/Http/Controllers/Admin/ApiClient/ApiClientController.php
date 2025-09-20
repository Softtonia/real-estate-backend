<?php

namespace App\Http\Controllers\Admin\ApiClient;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;

class ApiClientController extends Controller
{
    // List all clients
    public function index()
    {
        $client = ApiClient::all();
        return response()->json([
            'status' => 200,
            'message' => 'List of all clients',
            'data' => $client
        ]);
    }

    // Create new client with auto-generated ID & secret
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'client_name' => 'required|string|max:255',
            'app_type' => [
                    'required',
                    Rule::in(['admin', 'business', 'website', 'mobile-app', 'custom']),
                ],

            'status' => ['required', Rule::in(['0', '1'])],
            'allowed_domain' => 'required|array',
            // 'allowed_domain.*' => 'url',
            'allowed_domain.*' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    //  regex: domains + localhost + IP addresses allow
                    $pattern = '/^(https?:\/\/)?' . // optional http/https
                            '((localhost)|' .    // localhost allow
                            '(\d{1,3}(\.\d{1,3}){3})|' . // IPv4 (127.0.0.1)
                            '([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,})' . // domain.com
                            '(:\d+)?' . // optional port
                            '(\/.*)?$/'; // optional path

                    if (!preg_match($pattern, $value)) {
                        $fail("The {$attribute} value '{$value}' is not a valid domain or URL.");
                    }
                },
            ],
            'client_id' => 'required|string|size:15|unique:api_clients,client_id',
            'client_secret' => 'required|string|size:15|unique:api_clients,client_secret',
            'nextjs_internal_key' => [
                'nullable',
                'string',
                'size:50',
                Rule::requiredIf(fn () => $request->app_type === 'website'),
                Rule::unique('api_clients', 'nextjs_internal_key'),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $validated = $validator->validated();

            $client = ApiClient::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Client created successfully.',
                'data' => $client
            ], 201);

        } catch (QueryException $e) {
            if ($e->getCode() == "23000") {
                // map error to readable field
                $errorMsg = 'Duplicate entry. One of the unique fields already exists.';
                if (str_contains($e->getMessage(), 'api_clients_client_id_unique')) {
                    $errorMsg = 'client_id already exists.';
                } elseif (str_contains($e->getMessage(), 'api_clients_client_secret_unique')) {
                    $errorMsg = 'client_secret already exists.';
                } elseif (str_contains($e->getMessage(), 'api_clients_nextjs_internal_key_unique')) {
                    $errorMsg = 'nextjs_internal_key already exists.';
                }

                return response()->json([
                    'success' => false,
                    'message' => $errorMsg,
                ], 409); // 409 Conflict
            }

            return response()->json([
                'success' => false,
                'message' => 'Database error occurred.',
            ], 500);
        }
    }


    // Show one client
    public function show($id)
    {
        $client = ApiClient::find($id);

        if (!$client) {
            return response()->json(['error' => 'Client not found'], 200);
        }
        return response()->json([
            'status' => 200,
            'message' => 'Client details',
            'data' => $client
        ]);
    }

    // Update an existing client
    public function update(Request $request, $id)
    {
        $client = ApiClient::find($id);
        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'Client not found'
            ], 200);
        }

        $validator = Validator::make($request->all(), [
            'client_name' => 'sometimes|required|string|max:255',
            'app_type' => [
                'sometimes',
                Rule::in(['admin', 'business', 'website', 'mobile-app', 'custom']),
                
            ],

            'status' => ['sometimes', Rule::in(['0', '1'])],
            'allowed_domain' => 'sometimes|required|array',
            'used_by_origin' => 'nullable|string|max:255',
            'allowed_domain.*' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    //  regex: domains + localhost + IP addresses allow
                    $pattern = '/^(https?:\/\/)?' . // optional http/https
                            '((localhost)|' .    // localhost allow
                            '(\d{1,3}(\.\d{1,3}){3})|' . // IPv4 (127.0.0.1)
                            '([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,})' . // domain.com
                            '(:\d+)?' . // optional port
                            '(\/.*)?$/'; // optional path

                    if (!preg_match($pattern, $value)) {
                        $fail("The {$attribute} value '{$value}' is not a valid domain or URL.");
                    }
                },
            ],
            'client_id' => [
                'sometimes', 'required', 'string', 'size:15',
                Rule::unique('api_clients', 'client_id')->ignore($client->id),
            ],
            'client_secret' => [
                'sometimes', 'required', 'string', 'size:15',
                Rule::unique('api_clients', 'client_secret')->ignore($client->id),
            ],
            'nextjs_internal_key' => [
                'nullable',
                'string',
                'size:50',
                Rule::requiredIf(fn () => $request->app_type === 'website'),
                Rule::unique('api_clients', 'nextjs_internal_key')->ignore($client->id),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $validated = $validator->validated();
            $client->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Client updated successfully.',
                'data' => $client
            ]);

        } catch (QueryException $e) {
            if ($e->getCode() == "23000") {
                $errorMsg = 'Duplicate entry. One of the unique fields already exists.';
                if (str_contains($e->getMessage(), 'api_clients_client_id_unique')) {
                    $errorMsg = 'client_id already exists.';
                } elseif (str_contains($e->getMessage(), 'api_clients_client_secret_unique')) {
                    $errorMsg = 'client_secret already exists.';
                } elseif (str_contains($e->getMessage(), 'api_clients_nextjs_internal_key_unique')) {
                    $errorMsg = 'nextjs_internal_key already exists.';
                }
               

                return response()->json([
                    'success' => false,
                    'message' => $errorMsg,
                ], 409);
            }

            return response()->json([
                'success' => false,
                'message' => 'Database error occurred.',
            ], 500);
        }
    }




    // Delete a client
    public function destroy($id)
    {
        $client = ApiClient::find($id);
        if (!$client) {
            return response()->json(['error' => 'Client not found'], 200);
        }
        $client->delete();

        return response()->json(['message' => 'Client deleted successfully']);
    }


    public function generateApiClientId()
    {
        $clientId = $this->generateUniqueValue('client_id', 15);
        return response()->json(['client_id' => $clientId]);
    }

    public function generateApiClientSecret()
    {
        $secretId = $this->generateUniqueValue('client_secret', 15);
        return response()->json(['client_secret' => $secretId]);
    }

    private function generateUniqueValue($column, $length = 15)
    {
        do {
            $random = Str::upper(Str::random($length)); // Capital letters + numbers
        } while (ApiClient::where($column, $random)->exists());

        return $random;
    }


    public function generateNextJsInternalKey()
    {
        do {
            // 50 characters random uppercase string
            $random = Str::upper(Str::random(50));
        } while (ApiClient::where('nextjs_internal_key', $random)->exists());


        return response()->json([
            'nextjs_internal_key' => $random
        ]);
    }

    public function getAppTypes()
    {
        try {
            $type = DB::select("SHOW COLUMNS FROM api_clients WHERE Field = 'app_type'")[0]->Type;

            preg_match('/enum\((.*)\)/', $type, $matches);
            $enumValues = [];

            if (isset($matches[1])) {
                $values = explode(',', $matches[1]);
                foreach ($values as $value) {
                    $enumValues[] = trim($value, "'");
                }
            }

            return response()->json([
                'success' => true,
                'data' => $enumValues
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch app-types.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function showByAppType($appType)
    {
        $client = ApiClient::where('app_type', $appType)->first();

        if (!$client) {
            return response()->json([
                'error'  => 'Client not found for this app_type'
            ], 200);
        }

        return response()->json([
            'status'  => 200,
            'message' => 'Client details',
            'data'    => $client
        ]);
    }


    public function exportCsvApiClient($id)
{
    $client = ApiClient::find($id);

    if (!$client) {
        return response()->json(['error' => 'Client not found'], 200);
    }

    // Required fields
    $data = $client->only([
        'id',
        'client_name',
        'client_id',
        'client_secret',
        'app_type',
        'nextjs_internal_key',
        'allowed_domain',
    ]);

    // Convert allowed_domain array to string if it's stored as array
    if (is_array($data['allowed_domain'])) {
        $data['allowed_domain'] = implode(',', $data['allowed_domain']);
    }

    // File name
    $fileName = "client_{$id}.csv";

    // Callback function for stream download
    $callback = function () use ($data) {
        $file = fopen('php://output', 'w');

        // Column headers
        fputcsv($file, array_keys($data));

        // Row data
        fputcsv($file, array_values($data));

        fclose($file);
    };

    return response()->stream($callback, 200, [
        'Content-Type' => 'text/csv',
        'Content-Disposition' => "attachment; filename={$fileName}",
    ]);
}




}
