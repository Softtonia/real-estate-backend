<?php

namespace App\Http\Controllers\Admin\ApiClient;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use Illuminate\Http\Request;

class ApiClientController extends Controller
{
    // List all clients
    public function index()
    {
        return response()->json(ApiClient::all());
    }

    // Create new client with auto-generated ID & secret
    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_name' => 'required|string|max:255',
            'app-type' => ['nullable', Rule::in(['admin', 'business', 'website', 'mobile-app', 'custom'])],
            'status' => ['required', Rule::in(['0', '1'])],
            'allowed_domain' => 'required|url',
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
            'allowed_domain'  => $validated['allowed_domain'],
        ]);

        return response()->json($client, 201);
    }

    // Show one client
    public function show($id)
    {
        $client = ApiClient::findOrFail($id);
        return response()->json($client);
    }

    // Update an existing client
    public function update(Request $request, $id)
    {
        $client = ApiClient::findOrFail($id);

        $validated = $request->validate([
            'client_name' => 'sometimes|required|string|max:255',
            'app-type' => ['nullable', Rule::in(['admin', 'business', 'website', 'mobile-app', 'custom'])],
            'status' => ['sometimes', Rule::in(['0', '1'])],
            'allowed_domain' => 'sometimes|required|url',
        ]);

        $client->update($validated);

        return response()->json($client);
    }

    // Delete a client
    public function destroy($id)
    {
        $client = ApiClient::findOrFail($id);
        $client->delete();

        return response()->json(['message' => 'Client deleted successfully']);
    }
}
