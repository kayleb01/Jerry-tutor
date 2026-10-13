<?php

namespace App\Console\Commands;

use App\Services\LessonScheduler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lessons:generate {--days= : How many days ahead to generate}')]
#[Description("Create upcoming lessons from each class's weekly schedule")]
class GenerateLessons extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(LessonScheduler $scheduler): int
    {
        $days = $this->option('days') === null ? null : (int) $this->option('days');

        $created = $scheduler->generate($days);

        $this->info("Created {$created} lessons.");

        return self::SUCCESS;
    }
}
