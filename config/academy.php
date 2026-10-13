<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Academy Timezone
    |--------------------------------------------------------------------------
    |
    | The timezone class schedules are written in and times are shown in.
    | Everything is stored in UTC.
    |
    */

    'timezone' => env('ACADEMY_TIMEZONE', 'Africa/Lagos'),

    /*
    |--------------------------------------------------------------------------
    | Lateness Grace Period
    |--------------------------------------------------------------------------
    |
    | Minutes after a lesson's start before an arrival counts as late (D5).
    |
    */

    'lateness_grace_minutes' => (int) env('ACADEMY_LATENESS_GRACE_MINUTES', 0),

    /*
    |--------------------------------------------------------------------------
    | Lesson Horizon
    |--------------------------------------------------------------------------
    |
    | How many days ahead lessons are generated from each class's schedule.
    |
    */

    'lesson_horizon_days' => 28,

];
