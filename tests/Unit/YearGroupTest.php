<?php

use App\Enums\Difficulty;
use App\Enums\KeyStage;
use App\Enums\YearGroup;

it('maps each year group to its key stage', function (int $year, KeyStage $keyStage) {
    expect(YearGroup::from($year)->keyStage())->toBe($keyStage);
})->with([
    [3, KeyStage::Ks2], [4, KeyStage::Ks2], [5, KeyStage::Ks2], [6, KeyStage::Ks2],
    [7, KeyStage::Ks3], [8, KeyStage::Ks3], [9, KeyStage::Ks3],
    [10, KeyStage::Ks4], [11, KeyStage::Ks4],
]);

it('maps each year group to the brief\'s default difficulty', function (int $year, ?Difficulty $difficulty) {
    expect(YearGroup::from($year)->defaultDifficulty())->toBe($difficulty);
})->with([
    [3, Difficulty::Easy], [4, Difficulty::Easy], [5, Difficulty::Intermediate], [6, Difficulty::Hard],
    [7, Difficulty::Easy], [8, Difficulty::Intermediate], [9, Difficulty::Hard],
    [10, null], [11, null],
]);

it('labels year groups for display', function () {
    expect(YearGroup::Year6->label())->toBe('Year 6');
});

it('rejects years outside 3 to 11', function (int $year) {
    YearGroup::from($year);
})->with([2, 12])->throws(ValueError::class);

it('lists year groups as select options', function () {
    expect(YearGroup::options())
        ->toHaveCount(9)
        ->and(YearGroup::options()[0])->toBe(['value' => 3, 'label' => 'Year 3']);
});
