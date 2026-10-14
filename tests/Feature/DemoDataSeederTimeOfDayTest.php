<?php

use App\Models\Lesson;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoDataSeeder;

it('seeds cleanly whatever the time of day it runs', function (string $utc) {
    config(['academy.timezone' => 'Africa/Lagos']);
    $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));

    $this->seed(DemoDataSeeder::class);

    expect(Lesson::count())->toBeGreaterThan(20);
})->with(['2025-10-15 01:00:00', '2025-10-15 22:45:00', '2025-10-19 06:00:00']);
