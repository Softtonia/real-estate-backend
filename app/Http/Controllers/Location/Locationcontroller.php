<?php

namespace App\Http\Controllers\Location;

use App\Http\Controllers\Controller;
use DB;
use Illuminate\Http\Request;
use App\Models\Location;
use App\Models\User;
use App\Models\Country;
use App\Models\City;
use App\Models\State;
use App\Models\PropertyList;
use App\Models\CityVisit;
use Auth;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Storage;
use Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

use Illuminate\Database\Eloquent\ModelNotFoundException;


class LocationController extends Controller
{


    public function getCountries()
    {
        $countries = Country::select('id','name')->get();
        return response()->json($countries, 200);
    }

    // Get states by country ID
    public function getStatesByCountry($countryId)
    {
        // Check if country exists
        $country = Country::find($countryId);

        if (!$country) {
            return response()->json(['error' => 'Country not found.'], 404);
        }

        // Fetch the states associated with the given country
        $states = State::where('country_id', $countryId)->select('id','name','country_id')->get();

        // Return the states as a JSON response
        return response()->json($states, 200);
    }

    // Get cities by state ID
    public function getCitiesByState($stateId)
    {
        // Find the state by ID
        $state = State::find($stateId);

        if (!$state) {
            return response()->json(['error' => 'State not found.'], 404);
        }

        // Fetch cities associated with the state
        $cities = City::where('state_id', $stateId)->select('id','name','state_id')->get();

        // Return the cities as a JSON response
        return response()->json($cities, 200);
    }


    // Bulk upload country , state and city


    // public function bulkUploadCSC(Request $request)
    // {
    //     try {

    //         $request->validate([
    //             'file' => 'required|file|mimes:csv,txt',
    //         ]);

    //         $file = $request->file('file');
    //         $path = $file->getRealPath();
    //         $rows = array_map('str_getcsv', file($path));
    //         $header = array_map('strtolower', array_map('trim', $rows[0]));
    //         unset($rows[0]);

    //         DB::beginTransaction();

    //         foreach ($rows as $row) {
    //             $data = array_combine($header, $row);

    //             // Country check
    //             $country = Country::where('name', $data['country'])->first();
    //             if (!$country) {
    //                 $country = Country::create(['name' => $data['country']]);
    //             }

    //             // State check
    //             $state = State::where('name', $data['state'])->where('country_id', $country->id)->first();
    //             if (!$state) {
    //                 $state = State::create([
    //                     'name' => $data['state'],
    //                     'country_id' => $country->id,
    //                 ]);
    //             }

    //             // City check
    //             $city = City::where('name', $data['city'])->where('state_id', $state->id)->first();
    //             // if (!$city) {
    //             //     City::create([
    //             //         'name' => $data['city'],
    //             //         'state_id' => $state->id,
    //             //     ]);
    //             // }

    //              if (!$city) {
    //                 City::create([
    //                     'name'       => $data['city'],
    //                     'state_id'   => $state->id,
    //                     'is_popular' => isset($data['is_popular']) ? (bool)$data['is_popular'] : 0,
    //                     'is_nearby'  => isset($data['is_nearby']) ? (bool)$data['is_nearby'] : 0,
    //                 ]);
    //                 } else {
    //                     // agar city already hai to update bhi kar do
    //                     $city->update([
    //                         'is_popular' => isset($data['is_popular']) ? (bool)$data['is_popular'] : $city->is_popular,
    //                         'is_nearby'  => isset($data['is_nearby']) ? (bool)$data['is_nearby'] : $city->is_nearby,
    //                     ]);
    //                 }
    //         }

    //         DB::commit();
    //         return response()->json(['message' => 'Data uploaded successfully.']);

    //     } catch (\Illuminate\Validation\ValidationException $e) {
    //         return response()->json(['error' => $e->errors()], 422);
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         return response()->json(['error' => 'Upload failed: ' . $e->getMessage()], 500);
    //     }
    // }

    public function bulkUploadCSC(Request $request)
    {
        set_time_limit(0); // unlimited time

        try {
            $request->validate([
                'file' => 'required|file|mimes:csv,txt',
            ]);

            $file = $request->file('file');
            $path = $file->getRealPath();
            $rows = array_map('str_getcsv', file($path));
            $header = array_map('strtolower', array_map('trim', $rows[0]));
            unset($rows[0]);

            DB::beginTransaction();

            // Load existing data into memory
            $countries = Country::pluck('id', 'name')->mapWithKeys(fn($id, $name) => [strtolower($name) => $id])->toArray();
            $states    = State::select('id', 'name', 'country_id')
                            ->get()
                            ->mapWithKeys(fn($s) => [strtolower($s->name) . '-' . $s->country_id => $s->id])
                            ->toArray();
            $cities    = City::select('id', 'name', 'state_id')
                            ->get()
                            ->mapWithKeys(fn($c) => [strtolower($c->name) . '-' . $c->state_id => $c->id])
                            ->toArray();

            $newCountries = [];
            $newStates    = [];
            $newCities    = [];
            $updateCities = [];

            foreach ($rows as $row) {
                $data = array_combine($header, $row);

                $countryName = strtolower(trim($data['country']));
                $stateName   = strtolower(trim($data['state']));
                $cityName    = strtolower(trim($data['city']));
                $isPopular   = !empty($data['is_popular']) ? 1 : 0;
                $isNearby    = !empty($data['is_nearby']) ? 1 : 0;

                // ✅ Country check
                if (!isset($countries[$countryName])) {
                    $newCountries[$countryName] = [
                        'name'       => ucfirst($data['country']),
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                }
            }

            // Bulk insert countries
            if (!empty($newCountries)) {
                Country::insert(array_values($newCountries));
                $countries = Country::pluck('id', 'name')->mapWithKeys(fn($id, $name) => [strtolower($name) => $id])->toArray();
            }

            // ✅ Now handle states & cities
            foreach ($rows as $row) {
                $data = array_combine($header, $row);

                $countryId  = $countries[strtolower(trim($data['country']))];
                $stateKey   = strtolower(trim($data['state'])) . '-' . $countryId;
                $cityKey    = strtolower(trim($data['city'])) . '-' . ($states[$stateKey] ?? 'tmp');

                // State check
                if (!isset($states[$stateKey])) {
                    $newStates[$stateKey] = [
                        'name'       => ucfirst($data['state']),
                        'country_id' => $countryId,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                }
            }

            if (!empty($newStates)) {
                State::insert(array_values($newStates));
                $states = State::select('id', 'name', 'country_id')
                            ->get()
                            ->mapWithKeys(fn($s) => [strtolower($s->name) . '-' . $s->country_id => $s->id])
                            ->toArray();
            }

            foreach ($rows as $row) {
                $data = array_combine($header, $row);

                $countryId  = $countries[strtolower(trim($data['country']))];
                $stateKey   = strtolower(trim($data['state'])) . '-' . $countryId;
                $stateId    = $states[$stateKey];
                $cityKey    = strtolower(trim($data['city'])) . '-' . $stateId;

                $isPopular = !empty($data['is_popular']) ? 1 : 0;
                $isNearby  = !empty($data['is_nearby']) ? 1 : 0;

                if (!isset($cities[$cityKey])) {
                    $newCities[] = [
                        'name'       => ucfirst($data['city']),
                        'state_id'   => $stateId,
                        'is_popular' => $isPopular,
                        'is_nearby'  => $isNearby,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                } else {
                    $updateCities[$cities[$cityKey]] = [
                        'is_popular' => $isPopular,
                        'is_nearby'  => $isNearby,
                        'updated_at' => now()
                    ];
                }
            }

            // Bulk insert cities
            if (!empty($newCities)) {
                City::insert($newCities);
            }

            // Bulk update existing cities
            if (!empty($updateCities)) {
                foreach ($updateCities as $id => $values) {
                    City::where('id', $id)->update($values);
                }
            }

            DB::commit();
            return response()->json(['message' => 'Data uploaded successfully.']);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Upload failed: ' . $e->getMessage()], 500);
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
                'cities.name as city_name'
            )
            ->orderBy('countries.name')
            ->orderBy('states.name')
            ->orderBy('cities.name')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'All location data fetched successfully',
            'data' => $data
        ]);
    }




    public function getCityGroups(Request $request)
    {
        try {
            $baseCityId = $request->input('city_id'); // Example: Hyderabad id
            $userId     = auth()->id();

            // --- Track City Visit ---
            if ($baseCityId) {
                $visit = CityVisit::where('city_id', $baseCityId)
                    ->when($userId, function ($q) use ($userId) {
                        $q->where('user_id', $userId);
                    })
                    ->first();

                if ($visit) {
                    $visit->increment('count');
                } else {
                    CityVisit::create([
                        'city_id' => $baseCityId,
                        'user_id' => $userId,
                        'count'   => 1
                    ]);
                }
            }

            // --- Filter City (requested city) ---
            $filterCity = null;
            if ($baseCityId) {
                $filterCity = City::where('id', $baseCityId)
                    ->select('id', 'name', 'state_id')
                    ->first();
            }

            // --- Nearby Cities ---
            $nearbyCities = City::where('is_nearby', 1)
                ->where('id', '!=', $baseCityId)
                ->select('id', 'name', 'state_id')
                ->get();

            // --- Popular Cities ---
            $popularCities = City::where('is_popular', 1)
                ->where('id', '!=', $baseCityId)
                ->select('id', 'name', 'state_id')
                ->get();

            // --- Other Cities (analytics ke base par order) ---
            $otherCities = City::where('is_popular', 0)
                ->where('is_nearby', 0)
                ->where('cities.id', '!=', $baseCityId)
                ->leftJoin('city_visits', 'cities.id', '=', 'city_visits.city_id')
                ->select(
                    'cities.id',
                    'cities.name',
                    'cities.state_id',
                    \DB::raw('COALESCE(SUM(city_visits.count), 0) as total_visits')
                )
                ->groupBy('cities.id', 'cities.name', 'cities.state_id')
                ->orderByDesc('total_visits')
                ->limit(10)
                ->get();

            return response()->json([
                'filter_city'    => $filterCity,
                'nearby_cities'  => $nearbyCities,
                'popular_cities' => $popularCities,
                'other_cities'   => $otherCities,
            ]);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


     public function getLocationCountries(Request $request)
    {
        try {
            $countryId = $request->input('country_id');

            $countries = DB::table('countries')
                ->leftJoin('states', 'states.country_id', '=', 'countries.id')
                ->select(
                    'countries.id',
                    'countries.name',
                    DB::raw('COUNT(states.id) as state_count')
                )
                ->when($countryId, function ($query) use ($countryId) {
                    return $query->where('countries.id', $countryId);
                })
                ->groupBy('countries.id', 'countries.name')
                ->orderBy('countries.name')
                ->get();

            return response()->json([
                'status'  => true,
                'message' => 'Countries fetched successfully',
                'data'    => $countries
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }



    public function getLocationStates(Request $request)
    {
        try {
            $request->validate([
                'country_id' => 'required|exists:countries,id'
            ]);

            $states = DB::table('states')
                ->leftJoin('cities', 'cities.state_id', '=', 'states.id')
                ->where('states.country_id', $request->country_id)
                ->select(
                    'states.id',
                    'states.name',
                    DB::raw('COUNT(cities.id) as city_count')
                )
                ->groupBy('states.id', 'states.name')
                ->orderBy('states.name')
                ->get();

            return response()->json([
                'status'  => true,
                'message' => 'States fetched successfully',
                'data'    => $states
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }


    public function getLocationCities(Request $request)
    {
        try {
            $request->validate([
                'state_id' => 'required_without:city_id|exists:states,id',
                'city_id'  => 'nullable|exists:cities,id'
            ]);

            $query = DB::table('cities')
                ->leftJoin('city_visits', 'cities.id', '=', 'city_visits.city_id')
                ->select(
                    'cities.id',
                    'cities.name',
                    'cities.is_nearby',
                    'cities.is_popular',
                    DB::raw('COALESCE(SUM(city_visits.count), 0) as visitor_count')
                )
                ->groupBy('cities.id', 'cities.name', 'cities.is_nearby', 'cities.is_popular')
                ->orderBy('cities.name');

            // filter by state
            if ($request->filled('state_id')) {
                $query->where('cities.state_id', $request->state_id);
            }

            // filter by single city
            if ($request->filled('city_id')) {
                $query->where('cities.id', $request->city_id);
            }

            $cities = $query->get();

            return response()->json([
                'status'  => true,
                'message' => 'Cities fetched successfully',
                'data'    => $cities
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }


    public function updateCityFlags(Request $request, $id)
    {
        try {
            // Validation
            $request->validate([
                'is_popular' => 'nullable|boolean',
                'is_nearby'  => 'nullable|boolean',
            ]);

            // Find city
            $city = City::findOrFail($id);

            // Update values (agar diya gaya ho to)
            if ($request->has('is_popular')) {
                $city->is_popular = $request->is_popular;
            }
            if ($request->has('is_nearby')) {
                $city->is_nearby = $request->is_nearby;
            }

            $city->save();

            return response()->json([
                'message' => 'City flags updated successfully',

            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'error' => $th->getMessage()
            ], 500);
        }
    }




public function locationExportToCSV()
{
    try {
        $fileName = 'locations_export.csv';

        $response = new StreamedResponse(function () {
            $handle = fopen('php://output', 'w');

            // CSV Header
            fputcsv($handle, ['country', 'state', 'city', 'is_popular', 'is_nearby']);

            // Fetch all data with relationships
            $countries = Country::with('states.cities')->get();

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

    } catch (\Throwable $th) {
        return response()->json(['error' => $th->getMessage()], 500);
    }
}







}
