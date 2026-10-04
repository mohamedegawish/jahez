<?php

namespace App\Readiness;

/**
 * The structure every readiness questionnaire version keeps (owner decision, ADR-018
 * addendum 2), as the source document states it: five pillars, two questions per
 * pillar, four choices per question worth 1, 2, 3 and 4 points (أ to د). Every version
 * therefore scores 10 to 40. Administrators change the wording, the labels, the order
 * and the category texts, ranges and recommendations, never the shape.
 */
final class QuestionnaireShape
{
    public const PILLARS = 5;

    public const QUESTIONS_PER_PILLAR = 2;

    public const CHOICES_PER_QUESTION = 4;

    /**
     * The points of a question's choices, each used once.
     *
     * @var list<int>
     */
    public const POINTS = [1, 2, 3, 4];

    /**
     * The most recommendation lines one category's roadmap may hold.
     */
    public const MAX_RECOMMENDATIONS = 30;

    /**
     * Whether a question's choice points are exactly 1, 2, 3 and 4, in any order.
     *
     * @param  list<int>  $points
     */
    public static function hasSourcePoints(array $points): bool
    {
        sort($points);

        return $points === self::POINTS;
    }

    /**
     * Whether every label is filled in and no two are the same.
     *
     * @param  list<string>  $labels
     */
    public static function hasDistinctLabels(array $labels): bool
    {
        $trimmed = array_map(fn (string $label): string => trim($label), $labels);

        return ! in_array('', $trimmed, true) && count($trimmed) === count(array_unique($trimmed));
    }
}
