<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

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



        $countries = $query
            ->orderByRaw('LOWER(name) ASC')
            ->get()
            ->map(function ($country) {
                $name = $this->formatLocationName($country->name);

                return [
                    'id' => (int) $country->id,
                    'value' => (int) $country->id,
                    'label' => $name,
                    'name' => $name,
                ];
            })
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

        $states = $query
            ->orderByRaw('LOWER(name) ASC')
            ->get()
            ->map(function ($state) {
                $name = $this->formatLocationName($state->name);

                return [
                    'id' => (int) $state->id,
                    'value' => (int) $state->id,
                    'label' => $name,
                    'name' => $name,
                    'country_id' => (int) $state->country_id,
                ];
            })
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

        $cities = $query
            ->orderByRaw('LOWER(name) ASC')
            ->get()
            ->map(function ($city) {
                $name = $this->formatLocationName($city->name);

                return [
                    'id' => (int) $city->id,
                    'value' => (int) $city->id,
                    'label' => $name,
                    'name' => $name,
                    'state_id' => (int) $city->state_id,
                ];
            })
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
    private function formatLocationName(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        return Str::ucfirst(Str::lower(trim($name)));
    }

    private function mapCityFormat(object $city): array
    {
        $name = $this->formatLocationName($city->name);

        return [
            'id' => (int) $city->id,
            'value' => (int) $city->id,
            'label' => $name,
            'name' => $name,
            'state_id' => (int) $city->state_id,
            'state_name' => $this->formatLocationName($city->state_name ?? null),
            'country_id' => (int) $city->country_id,
            'country_name' => $this->formatLocationName($city->country_name ?? null),
        ];
    }

    private function resolveIndiaCountryId(?int $requestCountryId): ?int
    {
        if ($requestCountryId) {
            return $requestCountryId;
        }

        return (int) (DB::table('countries')
            ->where(function ($q) {
                $q->whereRaw('LOWER(name) = ?', ['india'])
                    ->orWhereRaw('LOWER(name) = ?', ['in']);
            })
            ->value('id') ?: 0) ?: null;
    }

    public function popularCities(Request $request): JsonResponse
    {
        if (!Schema::hasTable('cities')) {
            return response()->json([
                'status' => false,
                'message' => 'cities table not found.',
            ], 500);
        }

        $search = trim((string) $request->input('search', ''));
        $countryId = $this->resolveIndiaCountryId($request->filled('country_id') ? (int) $request->country_id : null);
        $stateId = $request->input('state_id');

        $isAll = $request->boolean('all') || $request->input('limit') === 'all' || $request->input('limit') === '-1';
        $limit = $isAll ? null : min(max((int) $request->input('limit', 100), 1), 500);

        $hasPopularColumn = Schema::hasColumn('cities', 'is_popular');

        /*
         * 1. Fetch Popular Cities for India
         */
        $popularQuery = DB::table('cities')
            ->join('states', 'cities.state_id', '=', 'states.id')
            ->join('countries', 'states.country_id', '=', 'countries.id')
            ->select(
                'cities.id',
                'cities.name',
                'cities.state_id',
                'states.name as state_name',
                'states.country_id',
                'countries.name as country_name'
            );

        if (Schema::hasColumn('cities', 'status')) {
            $popularQuery->where('cities.status', 1);
        }

        if ($countryId) {
            $popularQuery->where('states.country_id', $countryId);
        }

        if ($stateId) {
            $popularQuery->where('cities.state_id', (int) $stateId);
        }

        if ($hasPopularColumn) {
            $popularQuery->where('cities.is_popular', 1);
        }

        if ($search !== '') {
            $popularQuery->where('cities.name', 'like', "%{$search}%");
        }

        $popularCities = $popularQuery
            ->orderByRaw('LOWER(cities.name) ASC')
            ->get()
            ->map(fn($c) => $this->mapCityFormat($c))
            ->values();

        if ($popularCities->isEmpty() && $search === '') {
            $majorCityNames = [
                'ahmedabad',
                'bangalore',
                'bengaluru',
                'chennai',
                'delhi',
                'new delhi',
                'ghaziabad',
                'gurgaon',
                'gurugram',
                'hyderabad',
                'indore',
                'jaipur',
                'kolkata',
                'lucknow',
                'mumbai',
                'navi mumbai',
                'noida',
                'greater noida',
                'pune',
                'thane'
            ];

            $fallbackQuery = DB::table('cities')
                ->join('states', 'cities.state_id', '=', 'states.id')
                ->join('countries', 'states.country_id', '=', 'countries.id')
                ->select(
                    'cities.id',
                    'cities.name',
                    'cities.state_id',
                    'states.name as state_name',
                    'states.country_id',
                    'countries.name as country_name'
                )
                ->whereIn(DB::raw('LOWER(cities.name)'), $majorCityNames);

            if ($countryId) {
                $fallbackQuery->where('states.country_id', $countryId);
            }

            if ($stateId) {
                $fallbackQuery->where('cities.state_id', (int) $stateId);
            }

            $popularCities = $fallbackQuery
                ->orderByRaw('LOWER(cities.name) ASC')
                ->get()
                ->map(fn($c) => $this->mapCityFormat($c))
                ->values();
        }

        /*
         * 2. Fetch Other Cities in India
         */
        $popularIds = $popularCities->pluck('id')->all();

        $otherQuery = DB::table('cities')
            ->join('states', 'cities.state_id', '=', 'states.id')
            ->join('countries', 'states.country_id', '=', 'countries.id')
            ->select(
                'cities.id',
                'cities.name',
                'cities.state_id',
                'states.name as state_name',
                'states.country_id',
                'countries.name as country_name'
            );

        if (Schema::hasColumn('cities', 'status')) {
            $otherQuery->where('cities.status', 1);
        }

        if ($countryId) {
            $otherQuery->where('states.country_id', $countryId);
        }

        if ($stateId) {
            $otherQuery->where('cities.state_id', (int) $stateId);
        }

        if (!empty($popularIds)) {
            $otherQuery->whereNotIn('cities.id', $popularIds);
        }

        if ($search !== '') {
            $otherQuery->where(function ($q) use ($search) {
                $q->where('cities.name', 'like', "%{$search}%")
                    ->orWhere('states.name', 'like', "%{$search}%");
            });
        }

        $otherQuery->orderByRaw('LOWER(cities.name) ASC');

        if ($limit !== null) {
            $otherQuery->limit($limit);
        }

        $otherCities = $otherQuery
            ->get()
            ->map(fn($c) => $this->mapCityFormat($c))
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'Indian popular and other cities fetched successfully.',
            'country_id' => $countryId,
            'popular_count' => $popularCities->count(),
            'other_count' => $otherCities->count(),
            'data' => [
                'popular_cities' => $popularCities,
                'other_cities' => $otherCities,
            ],
        ]);
    }

    public function headerCities(Request $request): JsonResponse
    {
        if (!Schema::hasTable('cities')) {
            return response()->json([
                'status' => false,
                'message' => 'cities table not found.',
            ], 500);
        }

        $countryId = $this->resolveIndiaCountryId($request->filled('country_id') ? (int) $request->country_id : null);
        $search = trim((string) $request->input('search', ''));

        $isAll = $request->boolean('all') || $request->input('limit') === 'all' || $request->input('limit') === '-1';
        $limit = $isAll ? null : min(max((int) $request->input('limit', 100), 1), 500);

        $hasPopularColumn = Schema::hasColumn('cities', 'is_popular');
        $hasNearbyColumn = Schema::hasColumn('cities', 'is_nearby');

        $baseQuery = DB::table('cities')
            ->join('states', 'cities.state_id', '=', 'states.id')
            ->join('countries', 'states.country_id', '=', 'countries.id')
            ->select(
                'cities.id',
                'cities.name',
                'cities.state_id',
                'states.name as state_name',
                'states.country_id',
                'countries.name as country_name',
                $hasPopularColumn ? DB::raw('IFNULL(cities.is_popular, 0) as is_popular') : DB::raw('0 as is_popular'),
                $hasNearbyColumn ? DB::raw('IFNULL(cities.is_nearby, 0) as is_nearby') : DB::raw('0 as is_nearby')
            );

        if (Schema::hasColumn('cities', 'status')) {
            $baseQuery->where('cities.status', 1);
        }

        if ($countryId) {
            $baseQuery->where('states.country_id', $countryId);
        }

        if ($search !== '') {
            $baseQuery->where(function ($q) use ($search) {
                $q->where('cities.name', 'like', "%{$search}%")
                    ->orWhere('states.name', 'like', "%{$search}%");
            });
        }

        $allCities = $baseQuery->orderByRaw('LOWER(cities.name) ASC')->get();

        $nearbyCities = $allCities->where('is_nearby', 1)->map(fn($c) => $this->mapCityFormat($c))->values();
        $popularCities = $allCities->where('is_popular', 1)->map(fn($c) => $this->mapCityFormat($c))->values();

        if ($popularCities->isEmpty() && $search === '') {
            $majorCityNames = [
                'ahmedabad',
                'bangalore',
                'bengaluru',
                'chennai',
                'delhi',
                'new delhi',
                'ghaziabad',
                'gurgaon',
                'gurugram',
                'hyderabad',
                'indore',
                'jaipur',
                'kolkata',
                'lucknow',
                'mumbai',
                'navi mumbai',
                'noida',
                'greater noida',
                'pune',
                'thane'
            ];

            $popularCities = $allCities
                ->filter(fn($c) => in_array(strtolower($c->name), $majorCityNames, true))
                ->map(fn($c) => $this->mapCityFormat($c))
                ->values();
        }

        $popularIds = $popularCities->pluck('id')->all();
        $nearbyIds = $nearbyCities->pluck('id')->all();
        $excludeIds = array_unique(array_merge($popularIds, $nearbyIds));

        $otherCitiesQuery = $allCities->reject(fn($c) => in_array((int) $c->id, $excludeIds, true));

        if ($limit !== null) {
            $otherCitiesQuery = $otherCitiesQuery->take($limit);
        }

        $otherCities = $otherCitiesQuery->map(fn($c) => $this->mapCityFormat($c))->values();

        return response()->json([
            'status' => true,
            'message' => 'Header location cities fetched successfully.',
            'country_id' => $countryId,
            'data' => [
                'nearby_cities' => $nearbyCities,
                'popular_cities' => $popularCities,
                'other_cities' => $otherCities,
            ],
        ]);
    }
}
