<?php

namespace App\Console\Commands;

use App\Domain\Billing\Pipeline\FossBillingEventPipeline;
use Illuminate\Console\Command;

final class BillingEventsCommand extends Command
{
    protected $signature = 'controlpanel:billing-events {--limit=100}';

    protected $description = 'Process queued FOSSBilling events in tenant order.';

    public function handle(FossBillingEventPipeline $pipeline): int
    {
        $this->info(json_encode(['processed' => $pipeline->processDue((int) $this->option('limit'))], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}

