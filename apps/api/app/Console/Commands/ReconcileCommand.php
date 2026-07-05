<?php

namespace App\Console\Commands;

use App\Domain\Consistency\GlobalConsistencyEngine;
use Illuminate\Console\Command;

final class ReconcileCommand extends Command
{
    protected $signature = 'controlpanel:reconcile {--limit=500 : Maximum sites to reconcile in one pass}';

    protected $description = 'Run one global desired/actual reconciliation pass.';

    public function handle(GlobalConsistencyEngine $engine): int
    {
        $result = $engine->run((int) $this->option('limit'));
        $this->info(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}

