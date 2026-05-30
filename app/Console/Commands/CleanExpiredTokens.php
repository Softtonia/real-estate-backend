<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanExpiredTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:clean';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete expired personal access tokens';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $query = DB::table('personal_access_tokens')
            ->where('expires_at', '<=', now());

        $count = $query->count();

        $deleted = $query->delete();
        $this->info("Deleted $deleted expired tokens.");
    }
}
