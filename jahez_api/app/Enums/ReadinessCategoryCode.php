<?php

namespace App\Enums;

/**
 * Stable identifiers of the digital readiness categories (ADR-018). Code compares
 * these, never the display names. The score range of each category belongs to the
 * questionnaire version and is stored with it (readiness_categories).
 *
 * The levels are ordered as declared, from B4 Automation (lowest) to Smart (highest):
 * a level makes available the services of every level at or below it, and completing a
 * level's plan services opens the next one (ADR-026).
 */
enum ReadinessCategoryCode: string
{
    case B4Automation = 'b4_automation';
    case Basic = 'basic';
    case Advanced = 'advanced';
    case Smart = 'smart';

    /**
     * The level's position, 1 for the lowest.
     */
    public function rank(): int
    {
        return (int) array_search($this, self::cases(), true) + 1;
    }

    /**
     * The level above this one, or null for the highest.
     */
    public function next(): ?self
    {
        return self::cases()[$this->rank()] ?? null;
    }

    /**
     * This level and every level below it, lowest first.
     *
     * @return list<self>
     */
    public function atOrBelow(): array
    {
        return array_slice(self::cases(), 0, $this->rank());
    }

    /**
     * The highest of the given levels, or null when there is none.
     *
     * @param  iterable<self|null>  $levels
     */
    public static function highest(iterable $levels): ?self
    {
        $highest = null;

        foreach ($levels as $level) {
            if ($level !== null && ($highest === null || $level->rank() > $highest->rank())) {
                $highest = $level;
            }
        }

        return $highest;
    }
}
