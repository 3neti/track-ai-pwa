<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class StorageTestCommand extends Command
{
    protected $signature = 'trackai:storage-test';

    protected $description = 'Test storage connectivity by writing, reading, and deleting a test file';

    public function handle(): int
    {
        $diskName = config('trackai.uploads_disk');
        $prefix = config('trackai.uploads_prefix');
        $path = $prefix.'/healthcheck/'.Str::ulid().'.txt';
        $testContent = 'ok';

        $this->info("Testing storage disk: {$diskName}");
        $this->info("Test path: {$path}");
        $this->newLine();

        try {
            $disk = Storage::disk($diskName);

            // Write
            $this->info('Writing test file...');
            $disk->put($path, $testContent);
            $this->info('✓ Write successful');

            // Read
            $this->info('Reading test file...');
            $contents = $disk->get($path);

            if ($contents !== $testContent) {
                $this->error('✗ Content mismatch: expected "ok", got "'.$contents.'"');

                return self::FAILURE;
            }
            $this->info('✓ Read successful');

            // Delete
            $this->info('Deleting test file...');
            $disk->delete($path);
            $this->info('✓ Delete successful');

            $this->newLine();
            $this->info('Storage test passed!');

            return self::SUCCESS;

        } catch (Throwable $e) {
            $this->error('Storage test failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
