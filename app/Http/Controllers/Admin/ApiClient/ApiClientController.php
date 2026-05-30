<?php

namespace App\Http\Controllers\Admin\ApiClient;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Database\QueryException;
use App\Services\ApiClientService;

class ApiClientController extends Controller
{
    protected $apiClientService;

    public function __construct(ApiClientService $service)
    {
        $this->apiClientService = $service;
    }

    // List all clients
    public function index()
    {
        $clients = ApiClient::all();
        return response()->json([
            'status' => 200,
            'message' => 'List of all API clients',
            'data' => $clients
        ]);
    }

    // Create new client
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'client_name' => 'required|string|max:255',
            'client_id' => 'required|string|size:15|unique:api_clients,client_id',
            'client_secret' => 'required|string|size:15|unique:api_clients,client_secret',
            'app_type' => ['required', Rule::in(['admin','business','website','mobile-app','custom'])],
            'status' => ['required', Rule::in(['0','1'])],
            'allowed_domain' => 'required|array',
            'allowed_domain.*' => 'required|string',
            'nextjs_internal_key' => [
                'nullable', 'string', 'size:50', Rule::unique('api_clients','nextjs_internal_key')
            ]
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $client = ApiClient::create($validator->validated());

            // Invalidate cache immediately
            $this->apiClientService->invalidateCache($client->client_id);

            return response()->json([
                'success' => true,
                'message' => 'Client created successfully',
                'data' => $client
            ], 201);

        } catch (QueryException $e) {
            return $this->handleDbException($e);
        }
    }

    // Show a single client
    public function show($id)
    {
        $client = ApiClient::find($id);
        if (!$client) {
            return response()->json(['error' => 'Client not found'], 404);
        }
        return response()->json(['status'=>200, 'data'=>$client]);
    }

    // Update existing client
    public function update(Request $request, $id)
    {
        $client = ApiClient::find($id);
        if (!$client) return response()->json(['success'=>false,'message'=>'Client not found'], 404);

        $validator = Validator::make($request->all(), [
            'client_name' => 'sometimes|required|string|max:255',
            'client_id' => ['sometimes','required','string','size:15', Rule::unique('api_clients','client_id')->ignore($client->id)],
            'client_secret' => ['sometimes','required','string','size:15', Rule::unique('api_clients','client_secret')->ignore($client->id)],
            'app_type' => ['sometimes', Rule::in(['admin','business','website','mobile-app','custom'])],
            'status' => ['sometimes', Rule::in(['0','1'])],
            'allowed_domain' => 'sometimes|required|array',
            'nextjs_internal_key' => ['nullable','string','size:50', Rule::unique('api_clients','nextjs_internal_key')->ignore($client->id)]
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success'=>false,
                'message'=>'Validation failed',
                'errors'=>$validator->errors()
            ], 422);
        }

        try {
            $client->update($validator->validated());

            // Invalidate cache immediately
            $this->apiClientService->invalidateCache($client->client_id);

            return response()->json(['success'=>true,'message'=>'Client updated successfully','data'=>$client]);

        } catch (QueryException $e) {
            return $this->handleDbException($e);
        }
    }

    // Delete client
    public function destroy($id)
    {
        $client = ApiClient::find($id);
        if (!$client) return response()->json(['error'=>'Client not found'], 404);

        $client->delete();

        // Invalidate cache immediately
        $this->apiClientService->invalidateCache($client->client_id);

        return response()->json(['success'=>true,'message'=>'Client deleted successfully']);
    }

    // Utility: handle DB exceptions for unique constraints
    private function handleDbException(QueryException $e)
    {
        if ($e->getCode() == "23000") {
            $msg = 'Duplicate entry found for a unique field.';
            $error = $e->getMessage();
            return response()->json(['success'=>false,'message'=>$msg,'error'=>$error], 409);
        }
        return response()->json(['success'=>false,'message'=>'Database error.'], 500);
    }

    // Helpers to generate random client_id, secret, or Next.js key
    public function generateApiClientId() { return substr(strtoupper(bin2hex(random_bytes(8))),0,15); }
    public function generateApiClientSecret() { return substr(strtoupper(bin2hex(random_bytes(8))),0,15); }
    public function generateNextJsInternalKey() { return 'NEXTJSKEY'.substr(strtoupper(bin2hex(random_bytes(10))),0,50); }

    // Fetch clients by app type
    public function showByAppType($appType)
    {
        $clients = ApiClient::where('app_type', $appType)
                            ->active()
                            ->get();
        return response()->json(['status'=>200,'data'=>$clients]);
    }

    // Export CSV for a client
    public function exportCsvApiClient($id)
    {
        $client = ApiClient::find($id);
        if (!$client) return response()->json(['error'=>'Client not found'],404);

        $filename = "api_client_{$id}.csv";
        $columns = ['client_name','client_id','client_secret','app_type','status','allowed_domain','nextjs_internal_key'];

        $callback = function() use ($client,$columns) {
            $file = fopen('php://output','w');
            fputcsv($file,$columns);
            fputcsv($file, [
                $client->client_name,
                $client->client_id,
                $client->client_secret,
                $client->app_type,
                $client->status,
                is_array(json_decode($client->allowed_domain,true)) ? implode(',',json_decode($client->allowed_domain,true)) : $client->allowed_domain,
                $client->nextjs_internal_key
            ]);
            fclose($file);
        };

        return response()->stream($callback, 200, [
            "Content-Type" => "text/csv",
            "Content-Disposition" => "attachment; filename={$filename}"
        ]);
    }
}