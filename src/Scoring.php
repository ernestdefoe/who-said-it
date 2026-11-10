<?php

namespace ErnestDefoe\WhoSaidIt;

/**
 * 100 points for the right member, plus up to 50 for speed: the full bonus
 * within 3 seconds of the quote appearing, nothing after 20. Timed on the
 * server from when the quote was first sent, so a reload doesn't help.
 */
final class Scoring
{
    public const BASE = 100;
    public const MAX_BONUS = 50;
    public const FULL_BONUS_MS = 3000;
    public const NO_BONUS_MS = 20000;

    /** Grid letters: right and quick, right, wrong, quote withdrawn, not answered yet. */
    public const FAST = 'G';
    public const RIGHT = 'Y';
    public const WRONG = 'R';
    public const VOID = 'V';
    public const OPEN = '-';

    public static function bonus(int $ms): int
    {
        if ($ms <= self::FULL_BONUS_MS) {
            return self::MAX_BONUS;
        }
        if ($ms >= self::NO_BONUS_MS) {
            return 0;
        }

        return (int) round(self::MAX_BONUS * (self::NO_BONUS_MS - $ms) / (self::NO_BONUS_MS - self::FULL_BONUS_MS));
    }

    public static function points(bool $correct, int $ms): int
    {
        return $correct ? self::BASE + self::bonus($ms) : 0;
    }

    public static function letter(bool $correct, int $ms): string
    {
        if (! $correct) {
            return self::WRONG;
        }

        return self::bonus($ms) * 2 >= self::MAX_BONUS ? self::FAST : self::RIGHT;
    }
}
