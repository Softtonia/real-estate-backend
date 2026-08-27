<?php

namespace App\Http\Controllers\Location;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\CityVisit;
use App\Models\Country;
use App\Models\DynamicPost;
use App\Models\PostType;
use App\Models\State;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class LocationController extends Controller
{
    public function getCountries()
    {
        $countries = Country::query()
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return response()->json($countries, 200);
    }

    public function getStatesByCountry($countryId)
    {
        $country = Country::find($countryId);

        if (!$country) {
            return response()->json(['error' => 'Country not found.'], 404);
        }

        $states = State::query()
            ->where('country_id', $countryId)
            ->select('id', 'name', 'country_id')
            ->orderBy('name')
            ->get();

        return response()->json($states, 200);
    }

    public function getCitiesByState($stateId)
    {
        $state = State::find($stateId);

        if (!$state) {
            return response()->json(['error' => 'State not found.'], 404);
        }

        $cities = City::query()
            ->where('state_id', $stateId)
            ->select('id', 'name', 'state_id')
            ->orderBy('name')
            ->get();

        return response()->json($cities, 200);
    }

    public function bulkUploadCSC(Request $request)
    {
        set_time_limit(0);

        try {
            $request->validate([
                'file' => ['required', 'file', 'mimes:csv,txt'],
            ]);

            $file = $request->file('file');
            $path = $file->getRealPath();

            $handle = fopen($path, 'r');

            if (!$handle) {
                throw ValidationException::withMessages([
                    'file' => ['Unable to read uploaded file.'],
                ]);
            }

            $header = fgetcsv($handle);

            if (!$header) {
                throw ValidationException::withMessages([
                    'file' => ['CSV file is empty.'],
                ]);
            }

            $header = array_map(function ($value) {
                return strtolower(trim((string) $value));
            }, $header);

            $requiredHeaders = ['country', 'state', 'city'];
            $missingHeaders = array_diff($requiredHeaders, $header);

            if (!empty($missingHeaders)) {
                throw ValidationException::withMessages([
                    'file' => [
                        'Missing required CSV headers: ' . implode(', ', $missingHeaders),
                    ],
                ]);
            }

            $rows = [];

            while (($row = fgetcsv($handle)) !== false) {
                if ($this->isEmptyCsvRow($row)) {
                    continue;
                }

                $row = array_pad($row, count($header), null);
                $rows[] = array_combine($header, array_slice($row, 0, count($header)));
            }

            fclose($handle);

            if (empty($rows)) {
                throw ValidationException::withMessages([
                    'file' => ['CSV file has no data rows.'],
                ]);
            }

            DB::beginTransaction();

            $countries = Country::query()
                ->pluck('id', 'name')
                ->mapWithKeys(fn ($id, $name) => [strtolower(trim($name)) => $id])
                ->toArray();

            $states = State::query()
                ->select('id', 'name', 'country_id')
                ->get()
                ->mapWithKeys(fn ($state) => [
                    strtolower(trim($state->name)) . '-' . $state->country_id => $state->id,
                ])
                ->toArray();

            $cities = City::query()
                ->select('id', 'name', 'state_id')
                ->get()
                ->mapWithKeys(fn ($city) => [
                    strtolower(trim($city->name)) . '-' . $city->state_id => $city->id,
                ])
                ->toArray();

            $now = now();

            $newCountries = [];
            $newStates = [];
            $newCities = [];
            $updateCities = [];

            foreach ($rows as $data) {
                $countryName = $this->cleanName($data['country'] ?? '');

                if ($countryName === '') {
                    continue;
                }

                $countryKey = strtolower($countryName);

                if (!isset($countries[$countryKey])) {
                    $newCountries[$countryKey] = [
                        'name' => $countryName,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if (!empty($newCountries)) {
                Country::insert(array_values($newCountries));

                $countries = Country::query()
                    ->pluck('id', 'name')
                    ->mapWithKeys(fn ($id, $name) => [strtolower(trim($name)) => $id])
                    ->toArray();
            }

            foreach ($rows as $data) {
                $countryName = $this->cleanName($data['country'] ?? '');
                $stateName = $this->cleanName($data['state'] ?? '');

                if ($countryName === '' || $stateName === '') {
                    continue;
                }

                $countryKey = strtolower($countryName);

                if (!isset($countries[$countryKey])) {
                    continue;
                }

                $countryId = $countries[$countryKey];
                $stateKey = strtolower($stateName) . '-' . $countryId;

                if (!isset($states[$stateKey])) {
                    $newStates[$stateKey] = [
                        'name' => $stateName,
                        'country_id' => $countryId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if (!empty($newStates)) {
                State::insert(array_values($newStates));

                $states = State::query()
                    ->select('id', 'name', 'country_id')
                    ->get()
                    ->mapWithKeys(fn ($state) => [
                        strtolower(trim($state->name)) . '-' . $state->country_id => $state->id,
                    ])
                    ->toArray();
            }

            foreach ($rows as $data) {
                $countryName = $this->cleanName($data['country'] ?? '');
                $stateName = $this->cleanName($data['state'] ?? '');
                $cityName = $this->cleanName($data['city'] ?? '');

                if ($countryName === '' || $stateName === '' || $cityName === '') {
                    continue;
                }

                $countryKey = strtolower($countryName);

                if (!isset($countries[$countryKey])) {
                    continue;
                }

                $countryId = $countries[$countryKey];
                $stateKey = strtolower($stateName) . '-' . $countryId;

                if (!isset($states[$stateKey])) {
                    continue;
                }

                $stateId = $states[$stateKey];
                $cityKey = strtolower($cityName) . '-' . $stateId;

                $isPopular = $this->toBooleanFlag($data['is_popular'] ?? 0);
                $isNearby = $this->toBooleanFlag($data['is_nearby'] ?? 0);

                if (!isset($cities[$cityKey])) {
                    $newCities[$cityKey] = [
                        'name' => $cityName,
                        'state_id' => $stateId,
                        'is_popular' => $isPopular,
                        'is_nearby' => $isNearby,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                } else {
                    $updateCities[$cities[$cityKey]] = [
                        'is_popular' => $isPopular,
                        'is_nearby' => $isNearby,
                        'updated_at' => $now,
                    ];
                }
            }

            if (!empty($newCities)) {
                City::insert(array_values($newCities));
            }

            foreach ($updateCities as $cityId => $values) {
                City::query()
                    ->where('id', $cityId)
                    ->update($values);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data uploaded successfully.',
                'summary' => [
                    'countries_created' => count($newCountries),
                    'states_created' => count($newStates),
                    'cities_created' => count($newCities),
                    'cities_updated' => count($updateCities),
                ],
            ]);

        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);

        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Upload failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function locationList()
    {
        $data = DB::table('countries')
            ->join('states', 'states.country_id', '=', 'countries.id')
            ->join('cities', 'cities.state_id', '=', 'states.id')
            ->select(
                'countries.id as country_id',
                'countries.name as country_name',
                'states.id as state_id',
                'states.name as state_name',
                'cities.id as city_id',
                'cities.name as city_name',
                'cities.is_popular',
                'cities.is_nearby'
            )
            ->orderBy('countries.name')
            ->orderBy('states.name')
            ->orderBy('cities.name')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'All location data fetched successfully',
            'data' => $data,
        ]);
    }

    public function getCityGroups(Request $request)
    {
        try {
            $baseCityId = $request->input('city_id');
            $countryId = $request->input('country_id');
            $search = $request->input('search');
            $userId = auth()->id();

            $filterCity = null;
            $countryName = null;

            $searchFilter = function ($query) use ($search) {
                if ($search) {
                    $query->where(function ($subQuery) use ($search) {
                        $subQuery->where('cities.name', 'LIKE', "%{$search}%")
                            ->orWhere('states.name', 'LIKE', "%{$search}%");
                    });
                }
            };

            if ($baseCityId) {
                $filterCity = City::query()
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
                    ->where('cities.id', $baseCityId)
                    ->when($countryId, fn ($query) => $query->where('countries.id', $countryId))
                    ->first();

                if ($filterCity) {
                    $this->incrementCityVisit($filterCity->id, $userId);
                    $countryId = $countryId ?: $filterCity->country_id;
                    $countryName = $filterCity->country_name;
                }
            }

            if ($search && !$baseCityId) {
                $searchedCities = City::query()
                    ->join('states', 'cities.state_id', '=', 'states.id')
                    ->join('countries', 'states.country_id', '=', 'countries.id')
                    ->where($searchFilter)
                    ->when($countryId, fn ($query) => $query->where('states.country_id', $countryId))
                    ->select(
                        'cities.id',
                        'cities.name',
                        'cities.state_id',
                        'states.name as state_name',
                        'states.country_id',
                        'countries.name as country_name'
                    )
                    ->get();

                if ($searchedCities->count() === 1) {
                    $filterCity = $searchedCities->first();
                    $this->incrementCityVisit($filterCity->id, $userId);
                    $countryId = $countryId ?: $filterCity->country_id;
                    $countryName = $filterCity->country_name;
                } elseif ($searchedCities->count() > 1) {
                    return response()->json([
                        'status' => true,
                        'country_id' => $countryId,
                        'country_name' => $countryName,
                        'cities' => [
                            'filter_city' => null,
                            'nearby' => [],
                            'popular' => [],
                            'other' => $searchedCities->toArray(),
                        ],
                    ]);
                }
            }

            if ($countryId && !$countryName) {
                $country = DB::table('countries')
                    ->select('id', 'name')
                    ->where('id', $countryId)
                    ->first();

                if ($country) {
                    $countryName = $country->name;
                }
            }

            $nearbyCities = !$search ? City::query()
                ->join('states', 'cities.state_id', '=', 'states.id')
                ->join('countries', 'states.country_id', '=', 'countries.id')
                ->when($countryId, fn ($query) => $query->where('states.country_id', $countryId))
                ->when($baseCityId, fn ($query) => $query->where('cities.id', '!=', $baseCityId))
                ->where('cities.is_nearby', 1)
                ->select(
                    'cities.id',
                    'cities.name',
                    'cities.state_id',
                    'states.name as state_name',
                    'states.country_id',
                    'countries.name as country_name'
                )
                ->orderBy('cities.name')
                ->get() : collect([]);

            $popularCities = !$search ? City::query()
                ->join('states', 'cities.state_id', '=', 'states.id')
                ->join('countries', 'states.country_id', '=', 'countries.id')
                ->when($countryId, fn ($query) => $query->where('states.country_id', $countryId))
                ->when($baseCityId, fn ($query) => $query->where('cities.id', '!=', $baseCityId))
                ->where('cities.is_popular', 1)
                ->select(
                    'cities.id',
                    'cities.name',
                    'cities.state_id',
                    'states.name as state_name',
                    'states.country_id',
                    'countries.name as country_name'
                )
                ->orderBy('cities.name')
                ->get() : collect([]);

            $otherCities = !$search ? City::query()
                ->leftJoin('city_visits', 'cities.id', '=', 'city_visits.city_id')
                ->join('states', 'cities.state_id', '=', 'states.id')
                ->join('countries', 'states.country_id', '=', 'countries.id')
                ->when($countryId, fn ($query) => $query->where('states.country_id', $countryId))
                ->when($baseCityId, fn ($query) => $query->where('cities.id', '!=', $baseCityId))
                ->where('cities.is_popular', 0)
                ->where('cities.is_nearby', 0)
                ->select(
                    'cities.id',
                    'cities.name',
                    'cities.state_id',
                    'states.name as state_name',
                    'states.country_id',
                    'countries.name as country_name',
                    DB::raw('COALESCE(SUM(city_visits.count), 0) as total_visits')
                )
                ->groupBy(
                    'cities.id',
                    'cities.name',
                    'cities.state_id',
                    'states.name',
                    'states.country_id',
                    'countries.name'
                )
                ->orderByDesc('total_visits')
                ->limit(30)
                ->get() : collect([]);

            return response()->json([
                'status' => true,
                'country_id' => $countryId,
                'country_name' => $countryName,
                'cities' => [
                    'filter_city' => $filterCity ? $filterCity->toArray() : null,
                    'nearby' => $nearbyCities->toArray(),
                    'popular' => $popularCities->toArray(),
                    'other' => $otherCities->toArray(),
                ],
            ]);

        } catch (Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    private function incrementCityVisit($cityId, $userId): void
    {
        $query = CityVisit::query()->where('city_id', $cityId);

        if ($userId) {
            $query->where('user_id', $userId);
        } else {
            $query->whereNull('user_id');
        }

        $visit = $query->first();

        if ($visit) {
            $visit->increment('count');
            return;
        }

        CityVisit::create([
            'city_id' => $cityId,
            'user_id' => $userId,
            'count' => 1,
        ]);
    }

    public function getLocationCountries(Request $request)
    {
        try {
            $request->validate([
                'country_id' => ['nullable', 'integer', 'exists:countries,id'],
                'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
                'post_type_slug' => ['nullable', 'string'],
                'post_type' => ['nullable', 'string'],
                'search' => ['nullable', 'string', 'max:255'],
            ]);

            $postType = $this->validateAndResolvePostType($request);

            $countries = $this->countriesQuery(
                postType: $postType,
                countryId: $request->filled('country_id') ? (int) $request->country_id : null,
                search: $request->input('search')
            )->get();

            return response()->json([
                'status' => true,
                'message' => 'Countries fetched successfully',
                'post_type' => $this->postTypePayload($postType),
                'data' => $countries,
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);

        } catch (Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    public function getLocationStates(Request $request)
    {
        try {
            $request->validate([
                'country_id' => ['nullable', 'integer', 'exists:countries,id'],
                'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
                'post_type_slug' => ['nullable', 'string'],
                'post_type' => ['nullable', 'string'],
                'search' => ['nullable', 'string', 'max:255'],
            ]);

            $countryId = $request->filled('country_id')
                ? (int) $request->country_id
                : (int) (DB::table('countries')->whereRaw('LOWER(name) = ?', ['india'])->orWhereRaw('LOWER(name) = ?', ['in'])->value('id') ?? 101);

            $postType = $this->validateAndResolvePostType($request);

            $states = $this->statesQuery(
                postType: $postType,
                countryId: $countryId,
                search: $request->input('search')
            )->get();

            return response()->json([
                'status' => true,
                'message' => 'States fetched successfully',
                'post_type' => $this->postTypePayload($postType),
                'data' => $states,
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);

        } catch (Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    public function getLocationCities(Request $request)
    {
        try {
            $request->validate([
                'state_id' => ['required_without:city_id', 'nullable', 'integer', 'exists:states,id'],
                'city_id' => ['nullable', 'integer', 'exists:cities,id'],
                'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
                'post_type_slug' => ['nullable', 'string'],
                'post_type' => ['nullable', 'string'],
                'search' => ['nullable', 'string', 'max:255'],
            ]);

            $postType = $this->validateAndResolvePostType($request);

            $cities = $this->citiesQuery(
                postType: $postType,
                stateId: $request->filled('state_id') ? (int) $request->state_id : null,
                cityId: $request->filled('city_id') ? (int) $request->city_id : null,
                search: $request->input('search')
            )->get();

            return response()->json([
                'status' => true,
                'message' => 'Cities fetched successfully',
                'post_type' => $this->postTypePayload($postType),
                'data' => $cities,
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);

        } catch (Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    public function updateCityFlags(Request $request, $id)
    {
        try {
            $request->validate([
                'is_popular' => ['nullable', 'boolean'],
                'is_nearby' => ['nullable', 'boolean'],
            ]);

            $city = City::findOrFail($id);

            if ($request->has('is_popular')) {
                $city->is_popular = $request->boolean('is_popular');
            }

            if ($request->has('is_nearby')) {
                $city->is_nearby = $request->boolean('is_nearby');
            }

            $city->save();

            return response()->json([
                'status' => true,
                'message' => 'City flags updated successfully',
                'data' => [
                    'id' => $city->id,
                    'name' => $city->name,
                    'is_popular' => (bool) $city->is_popular,
                    'is_nearby' => (bool) $city->is_nearby,
                ],
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);

        } catch (Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    public function locationExportToCSV()
    {
        try {
            $fileName = 'locations_export.csv';

            $response = new StreamedResponse(function () {
                $handle = fopen('php://output', 'w');

                fputcsv($handle, ['country', 'state', 'city', 'is_popular', 'is_nearby']);

                $countries = Country::with('states.cities')->orderBy('name')->get();

                foreach ($countries as $country) {
                    foreach ($country->states as $state) {
                        foreach ($state->cities as $city) {
                            fputcsv($handle, [
                                $country->name,
                                $state->name,
                                $city->name,
                                $city->is_popular ? 1 : 0,
                                $city->is_nearby ? 1 : 0,
                            ]);
                        }
                    }
                }

                fclose($handle);
            });

            $response->headers->set('Content-Type', 'text/csv');
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $fileName . '"');

            return $response;

        } catch (Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    public function getAreaLocalities(Request $request)
    {
        try {
            $this->sanitizeRequestNumericParams($request, [
                'country_id',
                'state_id',
                'city_id',
                'location_id',
                'post_type_id',
                'status_id',
            ]);

            $request->validate([
                'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
                'post_type_slug' => ['nullable', 'string'],
                'post_type' => ['nullable', 'string'],

                'country_id' => ['nullable', 'integer', 'exists:countries,id'],
                'state_id' => ['nullable', 'integer', 'exists:states,id'],
                'city_id' => ['nullable', 'integer', 'exists:cities,id'],
                'location_id' => ['nullable', 'integer'],
                'status_id' => ['nullable'],
                'search' => ['nullable', 'string', 'max:255'],
            ]);

            if (!$this->dynamicPostsHasColumn('area_locality')) {
                return response()->json([
                    'status' => false,
                    'message' => 'area_locality column does not exist in dynamic_posts table.',
                    'data' => [],
                ], 422);
            }

            $postType = $this->validateAndResolvePostType($request);

            $query = DynamicPost::query();

            if ($postType) {
                $query->where('post_type_id', $postType->id);
            }

            if ($request->filled('country_id') && $this->dynamicPostsHasColumn('country_id')) {
                $query->where('country_id', $request->country_id);
            }

            if ($request->filled('state_id') && $this->dynamicPostsHasColumn('state_id')) {
                $query->where('state_id', $request->state_id);
            }

            $cityId = $request->input('city_id') ?? $request->input('location_id');
            if ($cityId && $this->dynamicPostsHasColumn('city_id')) {
                $query->where('city_id', $cityId);
            }

            if ($request->filled('search')) {
                $search = trim((string) $request->search);
                $query->where('area_locality', 'LIKE', "%{$search}%");
            }

            $areas = $query
                ->whereNotNull('area_locality')
                ->where('area_locality', '!=', '')
                ->distinct()
                ->orderBy('area_locality')
                ->pluck('area_locality')
                ->values();

            return response()->json([
                'status' => true,
                'message' => 'Area localities fetched successfully.',
                'post_type' => $this->postTypePayload($postType),
                'data' => $areas,
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);

        } catch (Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    public function searchAllLocations(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'type' => ['required', 'string', 'in:countries,states,cities'],
                'search' => ['nullable', 'string', 'max:255'],

                'country_id' => ['nullable', 'integer', 'exists:countries,id'],
                'state_id' => ['nullable', 'integer', 'exists:states,id'],

                'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
                'post_type_slug' => ['nullable', 'string'],
                'post_type' => ['nullable', 'string'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $postType = $this->validateAndResolvePostType($request);
            $type = $request->input('type');
            $search = $request->input('search');

            if ($type === 'countries') {
                $data = $this->countriesQuery(
                    postType: $postType,
                    countryId: $request->filled('country_id') ? (int) $request->country_id : null,
                    search: $search
                )->get();

                $message = 'Countries fetched successfully';
            } elseif ($type === 'states') {
                $data = $this->statesQuery(
                    postType: $postType,
                    countryId: $request->filled('country_id') ? (int) $request->country_id : null,
                    search: $search
                )->get();

                $message = 'States fetched successfully';
            } else {
                $data = $this->citiesQuery(
                    postType: $postType,
                    stateId: $request->filled('state_id') ? (int) $request->state_id : null,
                    cityId: null,
                    search: $search
                )->get();

                $message = 'Cities fetched successfully';
            }

            return response()->json([
                'status' => $data->isNotEmpty(),
                'message' => $data->isNotEmpty() ? $message : 'No records found',
                'type' => $type,
                'post_type' => $this->postTypePayload($postType),
                'data' => $data,
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);

        } catch (Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    public function searchCountries(Request $request)
    {
        try {
            $request->validate([
                'search' => ['nullable', 'string', 'max:255'],
                'country_id' => ['nullable', 'integer', 'exists:countries,id'],
                'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
                'post_type_slug' => ['nullable', 'string'],
                'post_type' => ['nullable', 'string'],
            ]);

            $postType = $this->validateAndResolvePostType($request);

            $countries = $this->countriesQuery(
                postType: $postType,
                countryId: $request->filled('country_id') ? (int) $request->country_id : null,
                search: $request->input('search')
            )->get();

            return response()->json([
                'status' => true,
                'message' => 'Countries fetched successfully',
                'post_type' => $this->postTypePayload($postType),
                'data' => $countries,
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);

        } catch (Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    public function searchStates(Request $request)
    {
        try {
            $request->validate([
                'search' => ['nullable', 'string', 'max:255'],
                'country_id' => ['nullable', 'integer', 'exists:countries,id'],
                'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
                'post_type_slug' => ['nullable', 'string'],
                'post_type' => ['nullable', 'string'],
            ]);

            $postType = $this->validateAndResolvePostType($request);

            $states = $this->statesQuery(
                postType: $postType,
                countryId: $request->filled('country_id') ? (int) $request->country_id : null,
                search: $request->input('search')
            )->get();

            return response()->json([
                'status' => true,
                'message' => 'States fetched successfully',
                'post_type' => $this->postTypePayload($postType),
                'data' => $states,
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);

        } catch (Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    public function searchCities(Request $request)
    {
        try {
            $request->validate([
                'search' => ['nullable', 'string', 'max:255'],
                'state_id' => ['nullable', 'integer', 'exists:states,id'],
                'city_id' => ['nullable', 'integer', 'exists:cities,id'],
                'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
                'post_type_slug' => ['nullable', 'string'],
                'post_type' => ['nullable', 'string'],
            ]);

            $postType = $this->validateAndResolvePostType($request);

            $cities = $this->citiesQuery(
                postType: $postType,
                stateId: $request->filled('state_id') ? (int) $request->state_id : null,
                cityId: $request->filled('city_id') ? (int) $request->city_id : null,
                search: $request->input('search')
            )->get();

            return response()->json([
                'status' => true,
                'message' => 'Cities fetched successfully',
                'post_type' => $this->postTypePayload($postType),
                'data' => $cities,
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);

        } catch (Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    private function countriesQuery(?PostType $postType = null, ?int $countryId = null, ?string $search = null)
    {
        $hasDynamicPostCountry = $this->dynamicPostsHasColumn('country_id');

        $query = DB::table('countries')
            ->leftJoin('states', 'states.country_id', '=', 'countries.id');

        if ($hasDynamicPostCountry) {
            $query->leftJoin('dynamic_posts', function ($join) use ($postType) {
                $join->on('dynamic_posts.country_id', '=', 'countries.id');

                if ($postType) {
                    $join->where('dynamic_posts.post_type_id', '=', $postType->id);
                }
            });
        }

        $query->select(
            'countries.id',
            'countries.name',
            DB::raw('COUNT(DISTINCT states.id) as state_count'),
            $hasDynamicPostCountry
                ? DB::raw('COUNT(DISTINCT dynamic_posts.id) as post_count')
                : DB::raw('0 as post_count')
        )
            ->when($countryId, fn ($q) => $q->where('countries.id', $countryId))
            ->when($search, fn ($q) => $q->where('countries.name', 'LIKE', "%{$search}%"))
            ->groupBy('countries.id', 'countries.name')
            ->orderBy('countries.name');

        return $query;
    }

    private function statesQuery(?PostType $postType = null, ?int $countryId = null, ?string $search = null)
    {
        $hasDynamicPostState = $this->dynamicPostsHasColumn('state_id');

        $query = DB::table('states')
            ->leftJoin('cities', 'cities.state_id', '=', 'states.id');

        if ($hasDynamicPostState) {
            $query->leftJoin('dynamic_posts', function ($join) use ($postType) {
                $join->on('dynamic_posts.state_id', '=', 'states.id');

                if ($postType) {
                    $join->where('dynamic_posts.post_type_id', '=', $postType->id);
                }
            });
        }

        $query->select(
            'states.id',
            'states.name',
            'states.country_id',
            DB::raw('COUNT(DISTINCT cities.id) as city_count'),
            $hasDynamicPostState
                ? DB::raw('COUNT(DISTINCT dynamic_posts.id) as post_count')
                : DB::raw('0 as post_count')
        )
            ->when($countryId, fn ($q) => $q->where('states.country_id', $countryId))
            ->when($search, fn ($q) => $q->where('states.name', 'LIKE', "%{$search}%"))
            ->groupBy('states.id', 'states.name', 'states.country_id')
            ->orderBy('states.name');

        return $query;
    }

    private function citiesQuery(?PostType $postType = null, ?int $stateId = null, ?int $cityId = null, ?string $search = null)
    {
        $hasDynamicPostCity = $this->dynamicPostsHasColumn('city_id');

        $visitSubQuery = DB::table('city_visits')
            ->select('city_id', DB::raw('SUM(count) as visitor_count'))
            ->groupBy('city_id');

        $query = DB::table('cities')
            ->leftJoinSub($visitSubQuery, 'city_visit_summary', function ($join) {
                $join->on('city_visit_summary.city_id', '=', 'cities.id');
            });

        if ($hasDynamicPostCity) {
            $query->leftJoin('dynamic_posts', function ($join) use ($postType) {
                $join->on('dynamic_posts.city_id', '=', 'cities.id');

                if ($postType) {
                    $join->where('dynamic_posts.post_type_id', '=', $postType->id);
                }
            });
        }

        $query->select(
            'cities.id',
            'cities.name',
            'cities.state_id',
            'cities.is_nearby',
            'cities.is_popular',
            DB::raw('COALESCE(city_visit_summary.visitor_count, 0) as visitor_count'),
            $hasDynamicPostCity
                ? DB::raw('COUNT(DISTINCT dynamic_posts.id) as post_count')
                : DB::raw('0 as post_count')
        )
            ->when($stateId, fn ($q) => $q->where('cities.state_id', $stateId))
            ->when($cityId, fn ($q) => $q->where('cities.id', $cityId))
            ->when($search, fn ($q) => $q->where('cities.name', 'LIKE', "%{$search}%"))
            ->groupBy(
                'cities.id',
                'cities.name',
                'cities.state_id',
                'cities.is_nearby',
                'cities.is_popular',
                'city_visit_summary.visitor_count'
            )
            ->orderBy('cities.name');

        return $query;
    }

    private function validateAndResolvePostType(Request $request): ?PostType
    {
        $postType = $this->resolvePostTypeFromRequest($request);

        if ($this->hasPostTypeFilter($request) && !$postType) {
            throw ValidationException::withMessages([
                'post_type' => ['Invalid post type.'],
            ]);
        }

        return $postType;
    }

    private function resolvePostTypeFromRequest(Request $request): ?PostType
    {
        if ($request->filled('post_type_id')) {
            return PostType::query()
                ->where('id', $request->post_type_id)
                ->first();
        }

        if ($request->filled('post_type_slug')) {
            return PostType::query()
                ->where('slug', $request->post_type_slug)
                ->first();
        }

        if ($request->filled('post_type')) {
            $value = trim((string) $request->post_type);

            return PostType::query()
                ->where(function ($query) use ($value) {
                    $query->where('slug', $value)
                        ->orWhere('name', $value);
                })
                ->first();
        }

        return null;
    }

    private function hasPostTypeFilter(Request $request): bool
    {
        return $request->filled('post_type_id')
            || $request->filled('post_type_slug')
            || $request->filled('post_type');
    }

    private function postTypePayload(?PostType $postType): ?array
    {
        if (!$postType) {
            return null;
        }

        return [
            'id' => $postType->id,
            'name' => $postType->name,
            'slug' => $postType->slug,
        ];
    }

    private function dynamicPostsHasColumn(string $column): bool
    {
        return Schema::hasTable('dynamic_posts')
            && Schema::hasColumn('dynamic_posts', $column);
    }

    private function cleanName(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        return ucwords(strtolower($value));
    }

    private function toBooleanFlag(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        $value = strtolower(trim((string) $value));

        return in_array($value, ['1', 'true', 'yes', 'y'], true) ? 1 : 0;
    }

    private function isEmptyCsvRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function sanitizeRequestNumericParams(Request $request, array $keys): void
    {
        $input = $request->all();
        $modified = false;

        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                $val = $input[$key];
                if (is_string($val)) {
                    $trimmed = trim($val);
                    if ($trimmed === '' || in_array(strtolower($trimmed), ['undefined', 'null', 'nan', 'none', 'false', 'true'], true) || !is_numeric($trimmed)) {
                        $input[$key] = null;
                        $modified = true;
                    } else {
                        $input[$key] = (int) $trimmed;
                        $modified = true;
                    }
                } elseif (!is_int($val) && !is_null($val)) {
                    $input[$key] = is_numeric($val) ? (int) $val : null;
                    $modified = true;
                }
            }
        }

        if ($modified) {
            $request->merge($input);
        }
    }
}