<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\MailConfig;

class MailConfigController extends Controller
{
    // Store new mail configuration
    public function store(Request $request)
    {
        $request->validate([
            'mailer' => 'required|string',
            'host' => 'required|string',
            'port' => 'required|integer',
            'username' => 'required|string',
            'password' => 'required|string',
            'encryption' => 'nullable|string',
            'from_address' => 'required|email',
            'from_name' => 'required|string',
            'status' => 'required|boolean',
        ]);

        $mailConfig = MailConfig::create($request->all());

        if ($mailConfig && $request->status == 1) {
            $mailConfigStatus = MailConfig::where('status', 1)
                ->where('id', '!=', $mailConfig->id)
                ->update([
                    'status' => 0
                ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Mail configuration created successfully',
            'data' => $mailConfig
        ], 201);
    }

    // Update existing mail configuration
    public function update(Request $request, $id)
    {
        $request->validate([
            'mailer' => 'sometimes|string',
            'host' => 'sometimes|string',
            'port' => 'sometimes|integer',
            'username' => 'sometimes|string',
            'password' => 'sometimes|string',
            'encryption' => 'nullable|string',
            'from_address' => 'sometimes|email',
            'from_name' => 'sometimes|string',
            'status' => 'required|boolean',
        ]);

        $mailConfig = MailConfig::findOrFail($id);
        if ($mailConfig && $request->status == 1) {
            $mailConfigStatus = MailConfig::where('status', 1)
                ->where('id', '!=', $mailConfig->id)
                ->update([
                    'status' => 0
                ]);
        }
        $mailConfig->update($request->all());

        return response()->json([
            'status' => 'success',
            'message' => 'Mail configuration updated successfully',
            'data' => $mailConfig
        ]);
    }

    public function getMailConfig(Request $request)
    {
        // Check if a specific mail configuration ID is provided
        if ($request->has('mail_config_id')) {
            $singleMailGet = MailConfig::find($request->mail_config_id);

            if (!$singleMailGet) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No mail configuration found'
                ], 400);
            }

            return response()->json([
                'status' => 'success',
                'data' => $singleMailGet
            ], 200);
        }

        // If no ID is provided, return all mail configurations
        $getMailConfig = MailConfig::all();

        return response()->json([
            'status' => 'success',
            'data' => $getMailConfig
        ], 200);
    }

    public function ActiveMailConfig(Request $request)
    {
        $request->validate([
            'mail_config_id' => 'required|integer'
        ]);

        $mailConfig = MailConfig::find($request->mail_config_id);
        if ($mailConfig) {
            $mailConfigStatus = MailConfig::where('status', 1)
                ->where('id', '!=', $request->mail_config_id)
                ->update([
                    'status' => 0
                ]);
        }
        $mailConfig->status = $request->status;
        $mailConfig->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Mail configuration status updated successfully',
            'data' => $mailConfig
        ], 200);
    }

    public function deleteMailConfig($id)
    {
        $mailConfig = MailConfig::find($id);
        if ($mailConfig) {
            $mailConfig->delete();
            return response()->json([
                'status' => 'success',
                'message' => 'Mail configuration deleted successfully'
            ], 200);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'No mail configuration found'
            ], 200);
        }
    }

    ### bulk delete mail config 14-07-2025 ###

    public function bulkDeleteMailConfigs(Request $request)
    {
        try {
            // Validate the input
            $request->validate([
                'ids' => 'required|array|min:1',
                'ids.*' => 'integer',
            ]);

            $ids = $request->ids;

            // Fetch only existing mail config IDs
            $existingIds = MailConfig::whereIn('id', $ids)->pluck('id');

            if ($existingIds->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No valid mail configuration IDs found'
                ], 200);
            }

            // Delete the matching records
            MailConfig::whereIn('id', $existingIds)->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Mail configurations deleted successfully',
                'deleted_ids' => $existingIds
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Bulk delete mail config error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Something went wrong during bulk delete'
            ], 500);
        }
    }

    ### search mail config 14-07-2025 ###
    public function searchMailConfigs(Request $request)
    {
        try {
            $query = MailConfig::query();

            // Keyword search (on multiple fields)
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('mailer', 'like', "%$search%")
                        ->orWhere('host', 'like', "%$search%")
                        ->orWhere('username', 'like', "%$search%")
                        ->orWhere('from_address', 'like', "%$search%")
                        ->orWhere('from_name', 'like', "%$search%");
                });
            }

            // Filter by individual fields
            if ($request->filled('mailer')) {
                $query->where('mailer', $request->mailer);
            }

            if ($request->filled('host')) {
                $query->where('host', $request->host);
            }

            if ($request->filled('port')) {
                $query->where('port', $request->port);
            }

            if ($request->filled('username')) {
                $query->where('username', $request->username);
            }

            if ($request->filled('from_address')) {
                $query->where('from_address', $request->from_address);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('encryption')) {
                $query->where('encryption', $request->encryption);
            }

            // Paginate with query string for proper link-based pagination
            $perPage = $request->get('per_page', 10);
            $results = $query->paginate($perPage)->withQueryString();

            return response()->json([
                'status' => 'success',
                'data' => $results
            ], 200);

        } catch (\Exception $e) {
            \Log::error('MailConfig search error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Something went wrong while searching'
            ], 500);
        }
    }




}
