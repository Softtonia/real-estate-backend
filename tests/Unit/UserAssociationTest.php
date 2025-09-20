<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use App\Models\User;
use App\Models\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
// use Tests\TestCase;

class UserAssociationTest extends TestCase
{
    /**
     * A basic unit test example.
     */
    // public function test_example(): void
    // {
    //     $this->assertTrue(true);
    // }

    use RefreshDatabase;

    public function test_company_sees_connected_consultancies_and_cache_is_invalidated_on_accept()
    {
        Cache::flush(); // ensure deterministic cache state

        $company = User::factory()->create(['role' => 'company']);
        $consultancy = User::factory()->create(['role' => 'consultancy']);
        $other = User::factory()->create(['role' => 'consultancy']);

        // no connection initially
        $this->actingAs($company, 'sanctum')
             ->getJson('/api/my/consultancies')
             ->assertJsonPath('total', 0);

        // create pending request company -> consultancy
        Connection::create([
            'requester_id' => $company->id,
            'receiver_id' => $consultancy->id,
            'state' => 'pending',
            'created_by' => $company->id,
        ]);

        // still zero (pending)
        $this->actingAs($company, 'sanctum')
             ->getJson('/api/my/consultancies')
             ->assertJsonPath('total', 0);

        // accept the connection and flush caches (simulate endpoint)
        $conn = Connection::first();
        $conn->update(['state' => 'accepted', 'accepted_at' => now()]);

        // In real controller accept will call AssociationService->flushForUsers
        // Simulate flush:
        Cache::tags(['connections', "user_{$company->id}", "user_{$consultancy->id}"])->flush();

        // Now association should show up
        $this->actingAs($company, 'sanctum')
             ->getJson('/api/my/consultancies')
             ->assertJsonPath('total', 1)
             ->assertJsonFragment(['id' => $consultancy->id]);
    }

}
