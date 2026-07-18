<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class FrontendLocationController extends Controller
{
    public function countries(Request $request): JsonResponse
    {
        if (!Schema::hasTable('countries')) {
            return response()->json([
                'status' => false,
                'message' => 'countries table not found.',
            ], 500);
        }

        $query = DB::table('countries')
            ->select('id', 'name');

        if (Schema::hasColumn('countries', 'status')) {
            $query->where('status', 1);
        }

        if (Schema::hasColumn('countries', 'sort_order')) {
            $query->orderBy('sort_order', 'asc');
        }

        $countries = $query
            ->orderBy('name', 'asc')
            ->get()
            ->map(fn ($country) => [
                'id' => (int) $country->id,
                'value' => (int) $country->id,
                'label' => $country->name,
                'name' => $country->name,
            ])
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'Countries fetched successfully.',
            'count' => $countries->count(),
            'data' => $countries,
        ]);
    }

    public function states(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'country_id' => ['required', 'integer', 'exists:countries,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (!Schema::hasTable('states')) {
            return response()->json([
                'status' => false,
                'message' => 'states table not found.',
            ], 500);
        }

        $query = DB::table('states')
            ->select('id', 'name', 'country_id')
            ->where('country_id', (int) $request->country_id);

        if (Schema::hasColumn('states', 'status')) {
            $query->where('status', 1);
        }

        if (Schema::hasColumn('states', 'sort_order')) {
            $query->orderBy('sort_order', 'asc');
        }

        $states = $query
            ->orderBy('name', 'asc')
            ->get()
            ->map(fn ($state) => [
                'id' => (int) $state->id,
                'value' => (int) $state->id,
                'label' => $state->name,
                'name' => $state->name,
                'country_id' => (int) $state->country_id,
            ])
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'States fetched successfully.',
            'country_id' => (int) $request->country_id,
            'count' => $states->count(),
            'data' => $states,
        ]);
    }

    public function cities(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'state_id' => ['required', 'integer', 'exists:states,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (!Schema::hasTable('cities')) {
            return response()->json([
                'status' => false,
                'message' => 'cities table not found.',
            ], 500);
        }

        $query = DB::table('cities')
            ->select('id', 'name', 'state_id')
            ->where('state_id', (int) $request->state_id);

        if (Schema::hasColumn('cities', 'status')) {
            $query->where('status', 1);
        }

        if (Schema::hasColumn('cities', 'sort_order')) {
            $query->orderBy('sort_order', 'asc');
        }

        $cities = $query
            ->orderBy('name', 'asc')
            ->get()
            ->map(fn ($city) => [
                'id' => (int) $city->id,
                'value' => (int) $city->id,
                'label' => $city->name,
                'name' => $city->name,
                'state_id' => (int) $city->state_id,
            ])
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'Cities fetched successfully.',
            'state_id' => (int) $request->state_id,
            'count' => $cities->count(),
            'data' => $cities,
        ]);
    }

    public function selected(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'state_id' => ['nullable', 'integer', 'exists:states,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $country = $request->filled('country_id')
            ? DB::table('countries')->where('id', (int) $request->country_id)->value('name')
            : null;

        $state = $request->filled('state_id')
            ? DB::table('states')->where('id', (int) $request->state_id)->value('name')
            : null;

        $city = $request->filled('city_id')
            ? DB::table('cities')->where('id', (int) $request->city_id)->value('name')
            : null;

        return response()->json([
            'status' => true,
            'message' => 'Selected location fetched successfully.',
            'data' => [
                'country' => $country ?: '-',
                'state' => $state ?: '-',
                'city' => $city ?: '-',
                'full_location' => collect([
                    $city,
                    $state,
                    $country,
                ])->filter()->values()->implode(', ') ?: '-',
            ],
        ]);
    }
}