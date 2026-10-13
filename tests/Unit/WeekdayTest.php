<?php

use App\Enums\Weekday;

it('uses ISO weekday numbers with readable labels', function () {
    expect(Weekday::Monday->value)->toBe(1)
        ->and(Weekday::Sunday->value)->toBe(7)
        ->and(Weekday::Saturday->label())->toBe('Saturday')
        ->and(Weekday::Saturday->plural())->toBe('Saturdays')
        ->and(Weekday::options())->toHaveCount(7)
        ->and(Weekday::options()[0])->toBe(['value' => 1, 'label' => 'Monday']);
});
