<?php

namespace App\Console\Commands;

use App\Domain\Billing\Enforcement\BillingEnforcementEngine;
use App\Domain\Billing\Metering\BillingUsageMeter;
use Illuminate\Console\Command;

final class BillingEnforceCommand extends Command
{
    protected $signature = 'controlpanel:billing-enforce {--window=5m}';

    protected $description = 'Aggregate usage and enforce tenant billing limits.';

    public function handle(BillingUsageMeter $meter, BillingEnforcementEngine $engine): int
    {
        foreach (['1m', '5m', '1h'] as $window) {
            $meter->aggregate($window);
        }
        $this->info(json_encode($engine->run((string) $this->option('window')), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}

