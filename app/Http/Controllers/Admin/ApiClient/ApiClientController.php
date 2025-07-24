<?php

namespace App\Http\Controllers\Admin\ApiClient;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;


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
        try {
            $validated = $request->validate([
                'client_name' => 'required|string|max:255',
                'app_type' => ['nullable', Rule::in(['admin', 'business', 'website', 'mobile-app', 'custom'])],
                'status' => ['required', Rule::in(['0', '1'])],
                'allowed_domain' => 'required|array',
                'allowed_domain.*' => 'url',
                'client_id' => 'required|string|size:15',
                'client_secret' => 'required|string|size:15',
            ]);

            $client = ApiClient::create([
                'client_name' => $validated['client_name'],
                'client_id' => $validated['client_id'],
                'client_secret' => $validated['client_secret'],
                'app_type' => $validated['app_type'] ?? null,
                'status' => $validated['status'],
                'allowed_domain' => $validated['allowed_domain'], // array saved as JSON
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Client created successfully.',
                'data' => $client
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);
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
            ], 404);
        }

        try {
            $validated = $request->validate([
                'client_name' => 'sometimes|required|string|max:255',
                'app_type' => ['nullable', Rule::in(['admin', 'business', 'website', 'mobile-app', 'custom'])],
                'status' => ['sometimes', Rule::in(['0', '1'])],
                'allowed_domain' => 'sometimes|required|array',
                'allowed_domain.*' => 'url',
                'client_id' => 'sometimes|required|string|size:15',
                'client_secret' => 'sometimes|required|string|size:15',
            ]);

            $client->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Client updated successfully.',
                'data' => $client
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);
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
}
