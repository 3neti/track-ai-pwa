<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\User;
use App\Services\TrackAI\ContractService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

class SyncContractsCommand extends Command
{
    protected $signature = 'trackai:contracts:sync
                            {--user= : User ID, email, or username whose Saras token should be used}
                            {--purge : Delete cached contracts without contacting Saras}
                            {--keep-missing : Keep local contracts that are missing from the Saras response}
                            {--force : Required when purging in production}';

    protected $description = 'Sync or purge cached Track AI contracts from Saras';

    public function handle(ContractService $contractService): int
    {
        $beforeCount = Contract::count();

        if ($this->option('purge')) {
            if (app()->isProduction() && ! $this->option('force')) {
                $this->components->error('Refusing to purge contracts in production without --force.');

                return self::FAILURE;
            }

            $deleted = Contract::query()->delete();

            $this->components->info("Purged {$deleted} cached contract(s).");

            return self::SUCCESS;
        }

        $user = $this->resolveUser();

        if (! $user) {
            $this->components->error('No user with a Saras token was found. Pass --user=<id|email|username>, or run with --purge --force.');

            return self::FAILURE;
        }

        Auth::login($user);

        $this->components->twoColumnDetail('Using user', "{$user->id} / {$user->email}");
        $this->components->twoColumnDetail('Cached before', (string) $beforeCount);

        $contracts = $contractService->syncContractsFromSaras(
            pruneMissing: ! $this->option('keep-missing'),
        );

        $afterCount = Contract::count();

        $this->components->twoColumnDetail('Synced from Saras', (string) $contracts->count());
        $this->components->twoColumnDetail('Cached after', (string) $afterCount);

        $this->table(
            ['ID', 'Saras Process ID', 'Name', 'Certificate'],
            $contracts->map(fn (Contract $contract): array => [
                $contract->id,
                $contract->saras_process_id,
                $contract->name,
                $contract->certificate_status,
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        $identifier = $this->option('user');

        if ($identifier) {
            return User::query()
                ->where('id', $identifier)
                ->orWhere('email', $identifier)
                ->orWhere('username', $identifier)
                ->first();
        }

        return User::query()
            ->whereNotNull('saras_access_token')
            ->orderBy('id')
            ->first();
    }
}
