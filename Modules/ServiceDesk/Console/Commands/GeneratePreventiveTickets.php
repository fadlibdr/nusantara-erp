<?php

namespace Modules\ServiceDesk\Console\Commands;

use Illuminate\Console\Command;
use Modules\ServiceDesk\Services\PreventiveService;

class GeneratePreventiveTickets extends Command
{
    protected $signature = 'svc:generate-pm';

    protected $description = 'Generate preventive maintenance tickets for schedules that are due (and roll their next due date)';

    public function handle(PreventiveService $service): int
    {
        $created = $service->generateDueTickets();

        $this->info("Generated {$created} preventive maintenance ticket(s).");

        return self::SUCCESS;
    }
}
