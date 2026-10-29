<?php

namespace Agoodley\LibrenmsCompliance\Config;

/**
 * Lines of a configuration as the plugin shows them (in a finding's evidence, on its pages, in the eventlog): with
 * their secrets hidden. A configuration holds SNMP communities, passwords and their hashes, the keys of TACACS+ and
 * RADIUS servers, routing protocols and VPNs, and SNMPv3 credentials, in clear or in a reversible type 7; the plugin
 * never stores a configuration, and hides the value after each keyword that introduces one (the encryption type before
 * it kept), a community where SNMP takes it as an argument, a crypt hash anywhere, and certificate data.
 */
final class Secrets
{
    public const HIDDEN = '<hidden>';

    /** A value: quoted (with spaces), or a word. */
    private const VALUE = '("[^"]*"|\S+)';

    /** An encryption type before a value ("7 0822455D0A16", "5 $1$..."), taken whole or not at all. */
    private const TYPE = '(?:(?:\d{1,2}|encrypted|hash|sha512|scrypt)\s+)?+';

    /**
     * Patterns whose last group is a secret, tried in order on the line as the patterns before left it.
     */
    private const PATTERNS = [
        // SNMP: a community, and the one a notification host takes as an argument (a v3 user's name there)
        '~^((?:snmp-server|snmp) community\s+)' . self::VALUE . '~i',
        '~^(snmp-server host \S+(?:\s+(?:informs|traps))?(?:\s+version\s+(?:1|2c|3(?:\s+(?:auth|noauth|priv))?))?\s+)' . self::VALUE . '~i',
        '~(\bcommunity\s+)' . self::VALUE . '~i',
        // SNMPv3 credentials: auth <md5|sha...> <secret>, priv <des|3des|aes [128]|aes-128> <secret>; NX-OS: priv [aes-128] <secret>
        '~(\bauth\s+(?:md5|sha\S*)\s+)' . self::VALUE . '~i',
        '~^(snmp-server user \S+ .*?\bpriv\s+(?:(?:des\S*|3des|aes(?:\s*\d+|-\d+)?)\s+)?)' . self::VALUE . '~i',
        '~(\bpriv\s+(?:des\S*|3des|aes(?:\s*\d+|-\d+)?)\s+)' . self::VALUE . '~i',
        // authentication keys: NTP (authentication-key 1 md5 <secret>), OSPF (authentication-key [7] <secret>)
        '~(\bauthentication-key\s+(?:\d+\s+(?:md5|sha\d*|cmac-aes-128)\s+|\d{1,2}\s+)?)' . self::VALUE . '~i',
        // IPsec: pre-shared-key address <peer> [mask] key <secret>, pre-shared-key [0] <secret>
        '~(\bpre-shared-key\b.*?\bkey\s+' . self::TYPE . ')' . self::VALUE . '~i',
        '~(\bpre-shared-key\s+' . self::TYPE . ')(?!(?:address|hostname)\b)' . self::VALUE . '~i',
        // a keyword, maybe a privilege level and an encryption type, then the secret
        '~(\b(?:password|secret|key-string|passphrase|ascii-text|encrypted-password|secret-key|auth-key|md5|psk|wpa-psk\s+ascii)\s+(?:level\s+\d+\s+)?'
            . self::TYPE . ')' . self::VALUE . '~i',
        // keys of TACACS+ and RADIUS servers (also in their sections), ISAKMP and message digests
        '~(\b(?:tacacs-server|radius-server)\b.*?\bkey\s+' . self::TYPE . ')' . self::VALUE . '~i',
        '~^(key\s+' . self::TYPE . ')' . self::VALUE . '$~i',
        '~(^crypto isakmp key\s+' . self::TYPE . ')' . self::VALUE . '~i',
        '~(\bmessage-digest-key\s+\d+\s+md5\s+' . self::TYPE . ')' . self::VALUE . '~i',
    ];

    /**
     * The line with its secrets hidden.
     */
    public static function mask(string $line): string
    {
        // certificate data (hex groups)
        if (preg_match('~^(?:[0-9A-F]{8}\s*){2,}$~i', trim($line)) === 1) {
            return self::HIDDEN . ' (certificate data)';
        }
        foreach (self::PATTERNS as $pattern) {
            $line = (string) preg_replace_callback($pattern, fn (array $m) => $m[1] . self::HIDDEN, $line);
        }

        // crypt hashes ($1$..., $8$..., $9$...) wherever they are
        return (string) preg_replace('~\$[0-9a-z]{1,2}\$\S+~i', self::HIDDEN, $line);
    }
}
