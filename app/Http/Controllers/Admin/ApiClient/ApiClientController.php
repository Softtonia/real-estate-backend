<?php

namespace App\Http\Controllers\Admin\ApiClient;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;


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
        $validated = $request->validate([
            'client_name' => 'required|string|max:255',
            'app-type' => ['nullable', Rule::in(['admin', 'business', 'website', 'mobile-app', 'custom'])],
            'status' => ['required', Rule::in(['0', '1'])],
            'allowed_domain' => 'required|array',
            'allowed_domain.*' => 'url',
        ]);

        // Ensure unique client_id
        do {
            $clientId = Str::random(15);
        } while (ApiClient::where('client_id', $clientId)->exists());

        $clientSecret = Str::random(15);

        $client = ApiClient::create([
            'client_name'     => $validated['client_name'],
            'client_id'       => $clientId,
            'client_secret'   => $clientSecret,
            'app-type'        => $validated['app-type'] ?? null,
            'status'          => $validated['status'],
            'allowed_domain'  => $validated['allowed_domain'], // array saved as JSON
        ]);

        return response()->json($client, 201);
    }

    // Show one client
    public function show($id)
    {
        $client = ApiClient::find($id);

        if(!$client){
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
       if(!$client){
            return response()->json(['error' => 'Client not found'], 200);
        }
        $validated = $request->validate([
            'client_name' => 'sometimes|required|string|max:255',
            'app-type' => ['nullable', Rule::in(['admin', 'business', 'website', 'mobile-app', 'custom'])],
            'status' => ['sometimes', Rule::in(['0', '1'])],
            'allowed_domain' => 'sometimes|required|array',
            'allowed_domain.*' => 'url',
        ]);

        $client->update($validated);

        return response()->json($client);
    }

    // Delete a client
    public function destroy($id)
    {
        $client = ApiClient::find($id);
         if(!$client){
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
}
