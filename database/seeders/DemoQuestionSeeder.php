<?php

namespace Database\Seeders;

use App\Actions\ChangeQuestionStatus;
use App\Actions\SaveQuestion;
use App\Enums\KeyStage;
use App\Enums\QuestionStatus;
use App\Enums\Role;
use App\Models\Question;
use App\Models\Topic;
use App\Models\User;
use App\Support\QuestionInput;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;

/**
 * A small published question bank for demos: every key stage, strand and answer type.
 * Seeded through the same rules and workflow as the admin screens. Safe to run repeatedly.
 */
class DemoQuestionSeeder extends Seeder
{
    /** @var list<array<string, mixed>> */
    public const QUESTIONS = [
        ['key_stage' => 'ks2', 'strand' => 'number', 'topic' => 'Place value', 'year_group' => 3, 'difficulty' => 'easy', 'type' => 'multiple_choice', 'stem' => 'What is the value of the 4 in 3,472?', 'options' => ['400', '4', '40', '4,000'], 'correct' => [0], 'explanation' => 'The 4 is in the hundreds column, so it is worth 400.'],
        ['key_stage' => 'ks2', 'strand' => 'number', 'topic' => 'Multiplication & division', 'year_group' => 4, 'difficulty' => 'easy', 'type' => 'numeric', 'stem' => 'What is $7 \times 8$?', 'numeric_answer' => 56, 'explanation' => '$7 \times 8 = 56$.'],
        ['key_stage' => 'ks2', 'strand' => 'number', 'topic' => 'Fractions', 'year_group' => 5, 'difficulty' => 'intermediate', 'type' => 'multiple_choice', 'stem' => 'What is $\frac{1}{2} + \frac{1}{4}$?', 'options' => ['$\frac{3}{4}$', '$\frac{2}{6}$', '$\frac{1}{6}$', '$\frac{2}{4}$'], 'correct' => [0], 'explanation' => 'Make the denominators the same: $\frac{2}{4} + \frac{1}{4} = \frac{3}{4}$.', 'exam_tracks' => ['sats']],
        ['key_stage' => 'ks2', 'strand' => 'number', 'topic' => 'Decimals & percentages', 'year_group' => 6, 'difficulty' => 'hard', 'type' => 'numeric', 'stem' => 'What is 15% of 240?', 'numeric_answer' => 36, 'explanation' => '10% of 240 is 24 and 5% is 12, so 15% is 36.', 'exam_tracks' => ['sats', 'eleven_plus']],
        ['key_stage' => 'ks2', 'strand' => 'algebra', 'topic' => 'Patterns & sequences', 'year_group' => 5, 'difficulty' => 'intermediate', 'type' => 'numeric', 'stem' => 'What is the next number in the sequence 3, 7, 11, 15, …?', 'numeric_answer' => 19, 'explanation' => 'The numbers go up by 4 each time, so the next one is 15 + 4 = 19.'],
        ['key_stage' => 'ks2', 'strand' => 'statistics', 'topic' => 'Averages', 'year_group' => 6, 'difficulty' => 'hard', 'type' => 'numeric', 'stem' => 'What is the mean of 4, 7, 9 and 12?', 'numeric_answer' => 8, 'explanation' => 'Add them up to get 32, then divide by 4 to get 8.', 'exam_tracks' => ['sats']],
        ['key_stage' => 'ks2', 'strand' => 'geometry', 'topic' => 'Properties of shapes', 'year_group' => 4, 'difficulty' => 'easy', 'type' => 'multi_select', 'stem' => 'Which of these shapes have exactly four sides?', 'options' => ['Square', 'Triangle', 'Rectangle', 'Pentagon'], 'correct' => [0, 2], 'explanation' => 'Squares and rectangles have four sides. A triangle has three and a pentagon has five.'],
        ['key_stage' => 'ks2', 'strand' => 'geometry', 'topic' => 'Time & money', 'year_group' => 3, 'difficulty' => 'easy', 'type' => 'short_text', 'stem' => 'How many minutes are there in one hour?', 'accepted_answers' => ['60', 'sixty'], 'explanation' => 'There are 60 minutes in an hour.'],

        ['key_stage' => 'ks3', 'strand' => 'number', 'topic' => 'Powers & roots', 'year_group' => 7, 'difficulty' => 'easy', 'type' => 'numeric', 'stem' => 'What is $2^5$?', 'numeric_answer' => 32, 'explanation' => '$2 \times 2 \times 2 \times 2 \times 2 = 32$.'],
        ['key_stage' => 'ks3', 'strand' => 'number', 'topic' => 'Factors, multiples & primes', 'year_group' => 7, 'difficulty' => 'easy', 'type' => 'multi_select', 'stem' => 'Which of these numbers are prime?', 'options' => ['2', '9', '11', '15', '17'], 'correct' => [0, 2, 4], 'explanation' => '9 = 3 × 3 and 15 = 3 × 5, so they are not prime.'],
        ['key_stage' => 'ks3', 'strand' => 'number', 'topic' => 'Fractions, decimals & percentages', 'year_group' => 8, 'difficulty' => 'intermediate', 'type' => 'multiple_choice', 'stem' => 'Which of these is the largest?', 'options' => ['0.6', '$\frac{5}{8}$', '58%', '$\frac{3}{5}$'], 'correct' => [1], 'explanation' => 'As decimals they are 0.6, 0.625, 0.58 and 0.6, so $\frac{5}{8}$ is the largest.'],
        ['key_stage' => 'ks3', 'strand' => 'algebra', 'topic' => 'Equations', 'year_group' => 8, 'difficulty' => 'intermediate', 'type' => 'short_text', 'stem' => 'Solve $2x + 3 = 11$. Write your answer as x = …', 'accepted_answers' => ['x = 4', 'x=4', '4'], 'explanation' => 'Subtract 3 from both sides to get $2x = 8$, then divide by 2.'],
        ['key_stage' => 'ks3', 'strand' => 'algebra', 'topic' => 'Simplifying & expanding', 'year_group' => 9, 'difficulty' => 'hard', 'type' => 'multiple_choice', 'stem' => 'Expand $3(x + 4)$.', 'options' => ['$3x + 12$', '$3x + 4$', '$x + 12$', '$3x + 7$'], 'correct' => [0], 'explanation' => 'Multiply both terms by 3: $3 \times x + 3 \times 4 = 3x + 12$.'],
        ['key_stage' => 'ks3', 'strand' => 'algebra', 'topic' => 'Sequences', 'year_group' => 9, 'difficulty' => 'hard', 'type' => 'short_text', 'stem' => 'Write the $n$th term of the sequence 5, 8, 11, 14, …', 'accepted_answers' => ['3n + 2', '3n+2', '2 + 3n'], 'explanation' => 'It goes up by 3, so start with $3n$. $3 \times 1 = 3$ and the first term is 5, so add 2.'],
        ['key_stage' => 'ks3', 'strand' => 'statistics', 'topic' => 'Probability', 'year_group' => 7, 'difficulty' => 'easy', 'type' => 'multiple_choice', 'stem' => 'A fair six-sided dice is rolled. What is the probability of rolling a 3?', 'options' => ['$\frac{1}{6}$', '$\frac{1}{3}$', '$\frac{1}{2}$', '$\frac{3}{6}$'], 'correct' => [0], 'explanation' => 'There is one 3 out of six equally likely outcomes.'],
        ['key_stage' => 'ks3', 'strand' => 'geometry', 'topic' => "Pythagoras' theorem", 'year_group' => 9, 'difficulty' => 'hard', 'type' => 'numeric', 'stem' => 'A right-angled triangle has shorter sides of 6 cm and 8 cm. How long is the longest side, in cm?', 'numeric_answer' => 10, 'explanation' => '$6^2 + 8^2 = 36 + 64 = 100$, and $\sqrt{100} = 10$.'],

        ['key_stage' => 'ks4', 'strand' => 'number', 'topic' => 'Indices & surds', 'year_group' => 10, 'difficulty' => 'intermediate', 'type' => 'multiple_choice', 'stem' => 'Simplify $\sqrt{50}$.', 'options' => ['$5\sqrt{2}$', '$25\sqrt{2}$', '$2\sqrt{5}$', '$10\sqrt{5}$'], 'correct' => [0], 'explanation' => '$\sqrt{50} = \sqrt{25 \times 2} = 5\sqrt{2}$.', 'exam_tracks' => ['gcse']],
        ['key_stage' => 'ks4', 'strand' => 'number', 'topic' => 'Standard form', 'year_group' => 10, 'difficulty' => 'intermediate', 'type' => 'multiple_choice', 'stem' => 'Write 0.00045 in standard form.', 'options' => ['$4.5 \times 10^{-4}$', '$4.5 \times 10^{4}$', '$45 \times 10^{-5}$', '$0.45 \times 10^{-3}$'], 'correct' => [0], 'explanation' => 'Move the decimal point 4 places to get 4.5, a number between 1 and 10.', 'exam_tracks' => ['gcse']],
        ['key_stage' => 'ks4', 'strand' => 'number', 'topic' => 'Ratio, proportion & rates of change', 'year_group' => 10, 'difficulty' => 'intermediate', 'type' => 'numeric', 'stem' => 'Share £60 in the ratio 2 : 3. How much is the larger share, in pounds?', 'numeric_answer' => 36, 'explanation' => 'There are 5 parts, so each part is £12. The larger share is 3 × £12 = £36.', 'exam_tracks' => ['gcse']],
        ['key_stage' => 'ks4', 'strand' => 'algebra', 'topic' => 'Quadratics', 'year_group' => 11, 'difficulty' => 'hard', 'type' => 'multi_select', 'stem' => 'Which values of $x$ solve $x^2 - 5x + 6 = 0$?', 'options' => ['2', '3', '-2', '-3', '6'], 'correct' => [0, 1], 'explanation' => 'Factorise: $(x - 2)(x - 3) = 0$, so $x = 2$ or $x = 3$.', 'exam_tracks' => ['gcse']],
        ['key_stage' => 'ks4', 'strand' => 'algebra', 'topic' => 'Simultaneous equations', 'year_group' => 11, 'difficulty' => 'hard', 'type' => 'short_text', 'stem' => 'Solve $x + y = 10$ and $x - y = 4$. Write your answer like x = 1, y = 2', 'accepted_answers' => ['x = 7, y = 3', 'x=7, y=3', 'x=7,y=3'], 'explanation' => 'Adding the equations gives $2x = 14$, so $x = 7$ and $y = 3$.', 'exam_tracks' => ['gcse']],
        ['key_stage' => 'ks4', 'strand' => 'statistics', 'topic' => 'Probability', 'year_group' => 10, 'difficulty' => 'intermediate', 'type' => 'numeric', 'stem' => 'A bag has 3 red and 5 blue counters. One is taken at random. What is the probability it is red? Give your answer as a decimal.', 'numeric_answer' => 0.375, 'explanation' => '$\frac{3}{8} = 0.375$.', 'exam_tracks' => ['gcse']],
        ['key_stage' => 'ks4', 'strand' => 'geometry', 'topic' => 'Pythagoras & trigonometry', 'year_group' => 11, 'difficulty' => 'hard', 'type' => 'numeric', 'stem' => 'A right-angled triangle has a hypotenuse of 10 cm and an angle of $30^\circ$. How long is the side opposite the $30^\circ$ angle, in cm?', 'numeric_answer' => 5, 'explanation' => '$\sin 30^\circ = \frac{1}{2}$, so the opposite side is $10 \times \frac{1}{2} = 5$ cm.', 'exam_tracks' => ['gcse']],
        ['key_stage' => 'ks4', 'strand' => 'geometry', 'topic' => 'Circle theorems', 'year_group' => 11, 'difficulty' => 'hard', 'type' => 'multiple_choice', 'stem' => 'The angle at the centre of a circle is $140^\circ$. What is the angle at the circumference standing on the same arc?', 'options' => ['$70^\circ$', '$140^\circ$', '$280^\circ$', '$40^\circ$'], 'correct' => [0], 'explanation' => 'The angle at the centre is twice the angle at the circumference.', 'exam_tracks' => ['gcse']],
    ];

    public function run(SaveQuestion $saveQuestion, ChangeQuestionStatus $changeStatus): void
    {
        $admin = User::where('role', Role::Admin)->orderBy('id')->first();

        if (! $admin || Question::where('stem', self::QUESTIONS[0]['stem'])->exists()) {
            return;
        }

        $topics = Topic::with('strand')->get();

        foreach (self::QUESTIONS as $question) {
            $topic = $topics->first(fn (Topic $topic): bool => $topic->key_stage === KeyStage::from($question['key_stage'])
                && $topic->strand->slug === $question['strand']
                && $topic->name === $question['topic']);

            $data = QuestionInput::normalise([...$question, 'topic_id' => $topic?->id]);
            Validator::make($data, QuestionInput::rules($data), QuestionInput::messages(), QuestionInput::attributes())
                ->after(QuestionInput::after($data))
                ->validate();

            $saved = $saveQuestion->handle($data, $admin);
            $changeStatus->handle($saved, QuestionStatus::InReview, $admin);
            $changeStatus->handle($saved, QuestionStatus::Published, $admin);
        }
    }
}
