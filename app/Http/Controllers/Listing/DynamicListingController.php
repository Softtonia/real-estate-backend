<?php

namespace App\Http\Controllers\Listing;

use App\Http\Controllers\Controller;
use App\Models\ProjectList;
use App\Models\PropertyList;
use App\Models\Developerlist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DynamicListingController extends Controller
{
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:project,property,developer',
            'search' => 'nullable|string|max:255',
            'country_id' => 'nullable|integer',
            'state_id' => 'nullable|integer',
            'city_id' => 'nullable|integer',
            'purpose_id' => 'nullable|integer',
            'property_id' => 'nullable|integer',
            'property_type_id' => 'nullable|integer',
            'property_status_id' => 'nullable|integer',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $type = $request->type;
        $perPage = $request->per_page ?? 12;

        $query = $this->getModelQuery($type);

        $this->applyCommonFilters($query, $request);

        $listings = $query
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => ucfirst($type) . ' listings fetched successfully.',
            'type' => $type,
            'data' => $listings,
        ]);
    }

    private function getModelQuery(string $type)
    {
        return match ($type) {
            'project' => ProjectList::with([
                'purpose',
                'property',
                'propertyType',
                'propertystatus',
                'developer',
                'country',
                'state',
                'city',
                'gallery',
                'customFieldValues',
                'customFieldRepeaterValues',
            ]),

            'property' => PropertyList::with([
                'purpose',
                'property',
                'propertyType',
                'propertystatus',
                'project',
                'country',
                'state',
                'city',
                'gallery',
                'customFieldValues',
            ]),

            'developer' => Developerlist::with([
                'purpose',
                'property',
                'propertyType',
                'propertystatus',
                'country',
                'state',
                'city',
                'customFieldValues',
            ]),
        };
    }

    private function applyCommonFilters($query, Request $request): void
    {
        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('name', 'LIKE', "%{$search}%")
                    ->orWhere('slug', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        $filters = [
            'country_id',
            'state_id',
            'city_id',
            'purpose_id',
            'property_id',
            'property_type_id',
            'property_status_id',
        ];

        foreach ($filters as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->$filter);
            }
        }
    }
}