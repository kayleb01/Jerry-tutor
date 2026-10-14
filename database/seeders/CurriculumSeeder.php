<?php

namespace Database\Seeders;

use App\Enums\KeyStage;
use App\Models\Strand;
use App\Models\Topic;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * National Curriculum strands and topics for KS2, KS3 and GCSE. Safe to run repeatedly:
 * rows are matched on slug, so names and order can be corrected by editing this list.
 */
class CurriculumSeeder extends Seeder
{
    /** @var array<string, string> slug => name */
    public const STRANDS = [
        'number' => 'Number',
        'algebra' => 'Algebra',
        'statistics' => 'Statistics',
        'geometry' => 'Geometry',
    ];

    /** @var array<string, array<string, array<int, string>>> key stage => strand slug => topic names */
    public const TOPICS = [
        'ks2' => [
            'number' => ['Place value', 'Addition & subtraction', 'Multiplication & division', 'Fractions', 'Decimals & percentages', 'Ratio & proportion'],
            'algebra' => ['Patterns & sequences', 'Formulae & equations'],
            'statistics' => ['Tables & charts', 'Averages'],
            'geometry' => ['Properties of shapes', 'Angles', 'Position & direction', 'Measurement', 'Perimeter, area & volume', 'Time & money'],
        ],
        'ks3' => [
            'number' => ['Place value & ordering', 'Four operations', 'Factors, multiples & primes', 'Fractions, decimals & percentages', 'Powers & roots', 'Standard form', 'Ratio & proportion'],
            'algebra' => ['Algebraic notation', 'Simplifying & expanding', 'Equations', 'Inequalities', 'Sequences', 'Formulae', 'Graphs'],
            'statistics' => ['Data & charts', 'Averages & spread', 'Probability'],
            'geometry' => ['Angles & polygons', 'Perimeter & area', 'Volume & surface area', 'Circles', 'Transformations', 'Constructions', "Pythagoras' theorem"],
        ],
        'ks4' => [
            'number' => ['Number', 'Fractions, decimals & percentages', 'Indices & surds', 'Standard form', 'Bounds', 'Ratio, proportion & rates of change'],
            'algebra' => ['Expressions', 'Equations', 'Quadratics', 'Simultaneous equations', 'Inequalities', 'Sequences', 'Graphs', 'Functions'],
            'statistics' => ['Probability', 'Statistics & charts'],
            'geometry' => ['Angles & polygons', 'Area & volume', 'Transformations', 'Congruence & similarity', 'Pythagoras & trigonometry', 'Circle theorems', 'Vectors'],
        ],
    ];

    public function run(): void
    {
        $strands = [];

        foreach (array_keys(self::STRANDS) as $order => $slug) {
            $strands[$slug] = Strand::updateOrCreate(['slug' => $slug], ['name' => self::STRANDS[$slug], 'sort_order' => $order]);
        }

        foreach (self::TOPICS as $keyStage => $byStrand) {
            $order = 0;

            foreach ($byStrand as $strandSlug => $names) {
                foreach ($names as $name) {
                    Topic::updateOrCreate(
                        ['key_stage' => KeyStage::from($keyStage), 'slug' => Str::slug($name)],
                        ['strand_id' => $strands[$strandSlug]->id, 'name' => $name, 'sort_order' => $order++],
                    );
                }
            }
        }
    }
}
