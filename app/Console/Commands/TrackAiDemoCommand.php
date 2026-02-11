<?php

namespace App\Console\Commands;

use Database\Seeders\TrackAIDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class TrackAiDemoCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'trackai:demo {--fresh : Run migrate:fresh before seeding}';

    /**
     * The console command description.
     */
    protected $description = 'Reset database and seed with Track AI demo data';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->newLine();
        $this->components->info('🚀 Track AI Demo Reset');
        $this->newLine();

        if ($this->option('fresh')) {
            $this->components->task('Running migrations', function () {
                Artisan::call('migrate:fresh', ['--force' => true]);
            });
        }

        $this->components->task('Seeding demo data', function () {
            Artisan::call('db:seed', [
                '--class' => TrackAIDemoSeeder::class,
                '--force' => true,
            ]);
        });

        $this->newLine();
        $this->components->info('✅ Demo environment ready!');
        $this->newLine();

        // Display credentials
        $this->components->twoColumnDetail('<fg=bright-blue>Demo Credentials</>', '');
        $this->table(
            ['Username', 'Password', 'Role'],
            [
                ['admin', 'password', 'Admin'],
                ['engineer01', 'password', 'Engineer'],
                ['engineer02', 'password', 'Engineer'],
                ['engineer03', 'password', 'Engineer'],
                ['engineer04', 'password', 'Engineer'],
                ['engineer05', 'password', 'Engineer'],
                ['inspector01', 'password', 'Inspector'],
                ['inspector02', 'password', 'Inspector'],
            ]
        );

        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue>Key Routes</>', '');
        $this->line('  • <fg=green>/login</> - Login page');
        $this->line('  • <fg=green>/app/projects</> - Projects (home page)');
        $this->line('  • <fg=green>/app/attendance</> - Check-in/Check-out');
        $this->line('  • <fg=green>/app/uploads</> - Document uploads');
        $this->line('  • <fg=green>/app/progress</> - Progress updates with AI');
        $this->line('  • <fg=green>/app/sync</> - Offline sync queue');

        $this->newLine();
        $this->components->info('💡 Try logging in as engineer01 and exploring the app!');
        $this->newLine();

        return self::SUCCESS;
    }
}
