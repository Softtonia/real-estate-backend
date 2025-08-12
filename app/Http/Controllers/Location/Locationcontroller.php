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
use Auth;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Storage;
use Str;
use Illuminate\Validation\Rule;

use Illuminate\Database\Eloquent\ModelNotFoundException;


class LocationController extends Controller
{


    public function getCountries()
    {
        $countries = Country::all();
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
        $states = State::where('country_id', $countryId)->get();

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
        $cities = City::where('state_id', $stateId)->get();

        // Return the cities as a JSON response
        return response()->json($cities, 200);
    }


    // Bulk upload country , state and city


    public function bulkUploadCSC(Request $request)
    {
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

            foreach ($rows as $row) {
                $data = array_combine($header, $row);

                // Country check
                $country = Country::where('name', $data['country'])->first();
                if (!$country) {
                    $country = Country::create(['name' => $data['country']]);
                }

                // State check
                $state = State::where('name', $data['state'])->where('country_id', $country->id)->first();
                if (!$state) {
                    $state = State::create([
                        'name' => $data['state'],
                        'country_id' => $country->id,
                    ]);
                }

                // City check
                $city = City::where('name', $data['city'])->where('state_id', $state->id)->first();
                if (!$city) {
                    City::create([
                        'name' => $data['city'],
                        'state_id' => $state->id,
                    ]);
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



}
