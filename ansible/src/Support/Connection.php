<?php

namespace Agoodley\LibrenmsAnsible\Support;

/**
 * How the plugin reaches the runner: its URL, API token, project, the id of the LibreNMS inventory in it, TLS
 * verification and the timeout. The plugin stores it itself, encrypted (Data\ConnectionRepository), and only
 * LibreNMS admins change it: these settings decide where the token is sent and what a run can reach, so
 * they are kept apart from the settings any holder of LibreNMS' plugin.admin permission can save.
 */
final class Connection
{
    public const TIMEOUT_DEFAULT = 15;

    public function __construct(
        public readonly string $url = '',
        public readonly string $token = '',
        public readonly int $project = 0,
        public readonly int $inventory = 0,
        public readonly bool $verifyTls = true,
        public readonly string $caBundle = '',
        public readonly int $timeout = self::TIMEOUT_DEFAULT,
        public readonly string $savedBy = '',
        public readonly int $savedAt = 0,
    ) {
    }

    /**
     * Can the plugin launch runs (URL, token, project and the LibreNMS inventory set)?
     */
    public function complete(): bool
    {
        return $this->canConnect() && $this->inventory > 0;
    }

    /**
     * Can the plugin ask the runner (URL, token and project set)? Enough to test the connection and list the
     * project's inventories, among which the LibreNMS one is chosen.
     */
    public function canConnect(): bool
    {
        return $this->url !== '' && $this->token !== '' && $this->project > 0;
    }

    /**
     * The connection posted by an admin, or what is wrong with it. An empty token keeps the saved one ($current),
     * unless $removeToken; but not when the token would go somewhere else than where it was saved for (another
     * runner URL, TLS verification turned off, another CA bundle): then the token must be entered again, so an admin
     * who does not know it cannot send it to a host of their choice.
     *
     * @param  array<string, mixed>  $input  url, token, project, inventory, verify_tls, ca_bundle, timeout
     * @return array{connection: self|null, errors: array<string, string>}
     */
    public static function fromInput(#[\SensitiveParameter] array $input, self $current, string $user, int $now, bool $removeToken = false): array
    {
        $errors = [];
        $url = self::url((string) ($input['url'] ?? ''), $errors);
        $verifyTls = ! empty($input['verify_tls']);
        $caBundle = trim((string) ($input['ca_bundle'] ?? ''));
        $token = trim((string) ($input['token'] ?? ''));
        if ($token === '' && ! $removeToken) {
            $token = $current->token;
            $moved = $token !== '' && $url !== '' ? self::moved($current, $url, $verifyTls, $caBundle) : [];
            if ($moved) {
                $errors['token'] = ucfirst(implode(' and ', $moved)) . ': enter the API token again. The saved token is only sent to the runner '
                    . 'it was saved for, checked as it was then.';
            }
        } elseif ($token !== '' && ! preg_match('/^[\x21-\x7e]{8,512}$/D', $token)) {
            $errors['token'] = 'The token is 8 to 512 printable characters without spaces (Semaphore UI shows it once, when it is created).';
        }
        $project = self::positive($input['project'] ?? '', 'project', 'The project id is the number in the project\'s URL in Semaphore UI (/project/<id>/...).', $errors);
        // the inventory may wait: Semaphore UI does not show inventory ids, Test connection lists them
        $inventory = trim((string) ($input['inventory'] ?? '')) === '' ? 0
            : self::positive($input['inventory'], 'inventory', 'The LibreNMS inventory id is a number (Test connection lists the project\'s inventories with their ids), or empty for now.', $errors);
        if ($caBundle !== '' && (! str_starts_with($caBundle, '/') || str_contains($caBundle, "\0") || strlen($caBundle) > 500)) {
            $errors['ca_bundle'] = 'The CA bundle is the absolute path of a PEM file on the LibreNMS server, or empty for the system CAs.';
        }
        $timeout = trim((string) ($input['timeout'] ?? ''));
        if ($timeout === '') {
            $timeout = (string) self::TIMEOUT_DEFAULT;
        }
        if (! preg_match('/^\d{1,3}$/D', $timeout) || (int) $timeout < 1 || (int) $timeout > 120) {
            $errors['timeout'] = 'The timeout is 1 to 120 seconds.';
        }
        if ($errors) {
            return ['connection' => null, 'errors' => $errors];
        }

        return ['connection' => new self($url, $token, $project, $inventory, $verifyTls, $caBundle, (int) $timeout, $user, $now), 'errors' => []];
    }

    /**
     * How the saved token's destination would change: another runner (scheme, host, port or path), TLS verification
     * turned off, or another CA bundle to verify the runner with.
     *
     * @return string[]
     */
    private static function moved(self $current, string $url, bool $verifyTls, string $caBundle): array
    {
        $moved = [];
        if (self::destination($current->url) !== self::destination($url)) {
            $moved[] = 'the runner URL changed';
        }
        if ($current->verifyTls && ! $verifyTls) {
            $moved[] = 'TLS verification was turned off';
        } elseif ($current->verifyTls && $verifyTls && $current->caBundle !== $caBundle) {
            $moved[] = 'the CA bundle changed';
        }

        return $moved;
    }

    /**
     * Where a URL sends the token: scheme, host and port (the default one when none is given) and path, compared
     * without case where case does not matter.
     */
    public static function destination(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $scheme . '://' . strtolower($parts['host']) . ':' . $port . rtrim($parts['path'] ?? '', '/');
    }

    /**
     * What differs from $before, for the eventlog: never the token itself, only that it was set, replaced or removed.
     *
     * @return string[]
     */
    public function changes(self $before): array
    {
        $changes = [];
        if ($before->url !== $this->url) {
            $changes[] = 'runner URL ' . ($before->url ?: 'none') . ' → ' . ($this->url ?: 'none');
        }
        if ($before->token !== $this->token) {
            $changes[] = match (true) {
                $before->token === '' => 'token set',
                $this->token === '' => 'token removed',
                default => 'token replaced',
            };
        }
        foreach (['project' => 'project', 'inventory' => 'LibreNMS inventory'] as $field => $label) {
            if ($before->$field !== $this->$field) {
                $changes[] = "$label " . ($before->$field ?: 'none') . ' → ' . ($this->$field ?: 'none');
            }
        }
        if ($before->verifyTls !== $this->verifyTls) {
            $changes[] = 'TLS verification ' . ($this->verifyTls ? 'on' : 'off');
        }
        if ($before->caBundle !== $this->caBundle) {
            $changes[] = 'CA bundle ' . ($before->caBundle ?: 'system CAs') . ' → ' . ($this->caBundle ?: 'system CAs');
        }
        if ($before->timeout !== $this->timeout) {
            $changes[] = "timeout {$before->timeout} → {$this->timeout} s";
        }

        return $changes;
    }

    /**
     * The runner's base URL: http(s), no user, query or fragment, without a trailing "/" or "/api".
     *
     * @param  array<string, string>  $errors
     */
    private static function url(string $url, array &$errors): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($url === '' || $parts === false || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || preg_match('/\s/', $url)) {
            $errors['url'] = 'The runner URL is the address of Semaphore UI, such as https://semaphore.example.com (http or https, without a user, ? or #).';

            return '';
        }

        return (string) preg_replace('#/api$#', '', rtrim($url, '/'));
    }

    /**
     * A positive whole number, or an error.
     *
     * @param  array<string, string>  $errors
     */
    private static function positive(mixed $value, string $key, string $error, array &$errors): int
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d{1,9}$/D', $value) || (int) $value < 1) {
            $errors[$key] = $error;

            return 0;
        }

        return (int) $value;
    }

    /**
     * What is stored (the repository encrypts it).
     *
     * @return array<string, string|int|bool>
     */
    public function toArray(): array
    {
        return ['url' => $this->url, 'token' => $this->token, 'project' => $this->project, 'inventory' => $this->inventory,
            'verify_tls' => $this->verifyTls, 'ca_bundle' => $this->caBundle, 'timeout' => $this->timeout,
            'saved_by' => $this->savedBy, 'saved_at' => $this->savedAt];
    }

    /**
     * The stored connection; missing fields get their defaults.
     */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['url'] ?? ''), (string) ($data['token'] ?? ''), (int) ($data['project'] ?? 0), (int) ($data['inventory'] ?? 0),
            (bool) ($data['verify_tls'] ?? true), (string) ($data['ca_bundle'] ?? ''), (int) ($data['timeout'] ?? self::TIMEOUT_DEFAULT) ?: self::TIMEOUT_DEFAULT,
            (string) ($data['saved_by'] ?? ''), (int) ($data['saved_at'] ?? 0));
    }

    /**
     * What a page may show: everything but the token, which is never sent to a browser.
     *
     * @return array<string, string|int|bool>
     */
    public function forPage(): array
    {
        $data = $this->toArray();
        unset($data['token']);

        return $data + ['has_token' => $this->token !== ''];
    }
}
