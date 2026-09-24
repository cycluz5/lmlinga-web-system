<?php

namespace App\Console\Commands;

use App\Support\ClientTestingProvisioner;
use Illuminate\Console\Command;

final class ProvisionClientTestingData extends Command
{
    protected $signature = 'client-testing:provision
        {--fresh-death : Replace pending death record for Ramon Bautista}
        {--validate-only : Validate ERD schema without writing data}';

    protected $description = 'Idempotently seed client-testing staff, households, and health records (ERD-safe).';

    public function handle(ClientTestingProvisioner $provisioner): int
    {
        if (config('app.env') === 'production') {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        $errors = $provisioner->validateSchema();
        if ($errors !== []) {
            $this->error('Schema validation failed:');
            foreach ($errors as $error) {
                $this->line("- {$error}");
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('validate-only')) {
            $this->info('Client-testing schema validation passed. No data was written.');

            return self::SUCCESS;
        }

        $report = $provisioner->provision(
            replaceDeathRecord: (bool) $this->option('fresh-death')
        );

        foreach ($report['messages'] as $line) {
            $this->line($line);
        }

        $this->newLine();
        $this->info('Client-testing provision complete.');
        $this->table(
            ['Table', 'Rows'],
            collect($report['counts'])->map(static fn (int $count, string $table): array => [$table, $count])->values()->all()
        );

        return self::SUCCESS;
    }
}
