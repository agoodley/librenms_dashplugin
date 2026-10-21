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
     * Can the plugin talk to the runner (URL, token, project and inventory set)?
     */
    public function complete(): bool
    {
        return $this->url !== '' && $this->token !== '' && $this->project > 0 && $this->inventory > 0;
    }

    /**
     * The connection posted by an admin, or what is wrong with it. An empty token keeps the saved one ($current),
     * unless $removeToken.
     *
     * @param  array<string, mixed>  $input  url, token, project, inventory, verify_tls, ca_bundle, timeout
     * @return array{connection: self|null, errors: array<string, string>}
     */
    public static function fromInput(array $input, self $current, string $user, int $now, bool $removeToken = false): array
    {
        $errors = [];
        $url = self::url((string) ($input['url'] ?? ''), $errors);
        $token = trim((string) ($input['token'] ?? ''));
        if ($token === '' && ! $removeToken) {
            $token = $current->token;
        } elseif ($token !== '' && ! preg_match('/^[\x21-\x7e]{8,512}$/D', $token)) {
            $errors['token'] = 'The token is 8 to 512 printable characters without spaces (Semaphore UI shows it once, when it is created).';
        }
        $project = self::positive($input['project'] ?? '', 'project', 'The project id is the number in the project\'s URL in Semaphore UI (/project/<id>/...).', $errors);
        $inventory = self::positive($input['inventory'] ?? '', 'inventory', 'The inventory id is the number of the LibreNMS inventory in Semaphore UI (Inventory, then its URL or the API).', $errors);
        $caBundle = trim((string) ($input['ca_bundle'] ?? ''));
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

        return ['connection' => new self($url, $token, $project, $inventory, ! empty($input['verify_tls']), $caBundle, (int) $timeout, $user, $now), 'errors' => []];
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
