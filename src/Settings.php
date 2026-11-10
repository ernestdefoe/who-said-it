<?php

namespace ErnestDefoe\WhoSaidIt;

use Flarum\Settings\SettingsRepositoryInterface;

final class Settings
{
    public const PREFIX = 'ernestdefoe-who-said-it.';

    public const NAV = self::PREFIX.'nav';
    public const MIN_AGE = self::PREFIX.'min_age_days';
    public const MIN_LENGTH = self::PREFIX.'min_length';
    public const REPEAT_DAYS = self::PREFIX.'repeat_days';
    public const ACTIVE_DAYS = self::PREFIX.'active_days';
    public const TAGS = self::PREFIX.'tags';
    public const GROUPS = self::PREFIX.'never_quote_groups';
    public const TIMEZONE = self::PREFIX.'timezone';

    public const VIEW = 'whoSaidIt.view';
    public const PLAY = 'whoSaidIt.play';

    public const DEFAULTS = [
        self::NAV => true,
        self::MIN_AGE => 7,
        self::MIN_LENGTH => 60,
        self::REPEAT_DAYS => 365,
        self::ACTIVE_DAYS => 90,
        self::TAGS => '[]',
        self::GROUPS => '[]',
        self::TIMEZONE => 'UTC',
    ];

    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    public function int(string $key, int $min, int $max): int
    {
        return max($min, min($max, (int) ($this->settings->get($key) ?? self::DEFAULTS[$key])));
    }

    public function timezone(): string
    {
        $tz = (string) $this->settings->get(self::TIMEZONE);

        return in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : 'UTC';
    }

    /** @return list<int> */
    public function tags(): array
    {
        return $this->ids(self::TAGS);
    }

    /**
     * Groups whose members are never quoted nor shown as a choice (bots, feeds, staff…).
     *
     * @return list<int>
     */
    public function groups(): array
    {
        return $this->ids(self::GROUPS);
    }

    /** @return list<int> */
    private function ids(string $key): array
    {
        $ids = json_decode((string) $this->settings->get($key), true);

        return is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids)))) : [];
    }
}
