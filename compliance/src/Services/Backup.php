<?php

namespace Agoodley\LibrenmsCompliance\Services;

/**
 * A device's latest configuration backup as LibreNMS' backup provider gives it (Oxidized, RANCID or Unimus), or why
 * there is none: no provider backs the device up, or the provider has no backup of it (no_backup), or the provider
 * failed or cannot be reached (error).
 */
final class Backup
{
    public const FOUND = 'found';

    public const NO_BACKUP = 'no_backup';

    public const ERROR = 'error';

    private function __construct(
        public readonly string $status,
        public readonly ?string $provider,
        public readonly ?string $content = null,
        public readonly ?int $date = null,
        public readonly ?string $why = null,
        public readonly bool $unreachable = false,
    ) {
    }

    public static function found(string $provider, string $content, ?int $date): self
    {
        return new self(self::FOUND, $provider, $content, $date);
    }

    public static function missing(?string $provider, string $why): self
    {
        return new self(self::NO_BACKUP, $provider, null, null, $why);
    }

    /**
     * @param  bool  $unreachable  the provider cannot be reached: the run asks it for no other device
     */
    public static function failed(?string $provider, string $why, bool $unreachable = false): self
    {
        return new self(self::ERROR, $provider, null, null, $why, $unreachable);
    }
}
