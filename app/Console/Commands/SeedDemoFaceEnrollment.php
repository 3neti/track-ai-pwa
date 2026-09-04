<?php

namespace App\Console\Commands;

use App\Models\FaceEnrollment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SeedDemoFaceEnrollment extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'face:seed-demo-enrollment
        {username : Existing Track AI username to enroll}
        {--source= : Reference image path. Defaults to the bundled ACOP demo fixture.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed a demo HyperVerge face enrollment for an existing user.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $username = (string) $this->argument('username');
        $source = $this->option('source') ?: resource_path('tests/acop-selfie.jpeg');

        $user = User::where('username', $username)->first();

        if ($user === null) {
            $this->error("No user found for username [{$username}].");

            return self::FAILURE;
        }

        if (! is_string($source) || ! is_file($source)) {
            $this->error("Reference image not found at [{$source}].");

            return self::FAILURE;
        }

        $path = "face-enrollments/{$user->id}/demo-reference.jpeg";

        Storage::disk('local')->put($path, file_get_contents($source));

        FaceEnrollment::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'provider' => 'hyperverge',
            ],
            [
                'disk' => 'local',
                'path' => $path,
                'status' => 'active',
                'metadata' => [
                    'source' => 'demo_fixture',
                    'file_name' => basename($source),
                ],
                'enrolled_at' => now(),
            ],
        );

        $this->info("Seeded demo face enrollment for [{$username}].");

        return self::SUCCESS;
    }
}
