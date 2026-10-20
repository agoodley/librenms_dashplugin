<?php

namespace Agoodley\LibrenmsTopology\Routing;

/**
 * BGP NOTIFICATION error codes and subcodes as text, with their usual causes (RFC 4271, with the
 * subcodes of RFC 4486 / 8203, 5492, 6608, 7313, 8538, 9234 and 9384 from the IANA registry).
 * LibreNMS stores the last error a session sent or received in bgpPeerLastErrorCode / SubCode.
 */
class BgpErrors
{
    /** code => [name, [subcode => name]] */
    private const CODES = [
        1 => ['Message Header Error', [1 => 'Connection Not Synchronized', 2 => 'Bad Message Length', 3 => 'Bad Message Type']],
        2 => ['OPEN Message Error', [
            1 => 'Unsupported Version Number', 2 => 'Bad Peer AS', 3 => 'Bad BGP Identifier', 4 => 'Unsupported Optional Parameter',
            5 => 'Authentication Failure', 6 => 'Unacceptable Hold Time', 7 => 'Unsupported Capability', 11 => 'Role Mismatch',
        ]],
        3 => ['UPDATE Message Error', [
            1 => 'Malformed Attribute List', 2 => 'Unrecognized Well-known Attribute', 3 => 'Missing Well-known Attribute',
            4 => 'Attribute Flags Error', 5 => 'Attribute Length Error', 6 => 'Invalid ORIGIN Attribute', 7 => 'AS Routing Loop',
            8 => 'Invalid NEXT_HOP Attribute', 9 => 'Optional Attribute Error', 10 => 'Invalid Network Field', 11 => 'Malformed AS_PATH',
        ]],
        4 => ['Hold Timer Expired', []],
        5 => ['Finite State Machine Error', [
            1 => 'Unexpected Message in OpenSent State', 2 => 'Unexpected Message in OpenConfirm State', 3 => 'Unexpected Message in Established State',
        ]],
        6 => ['Cease', [
            1 => 'Maximum Number of Prefixes Reached', 2 => 'Administrative Shutdown', 3 => 'Peer De-configured', 4 => 'Administrative Reset',
            5 => 'Connection Rejected', 6 => 'Other Configuration Change', 7 => 'Connection Collision Resolution', 8 => 'Out of Resources',
            9 => 'Hard Reset', 10 => 'BFD Down',
        ]],
        7 => ['ROUTE-REFRESH Message Error', [1 => 'Invalid Message Length']],
    ];

    /** What usually causes an error, by "code.subcode" or, for every subcode, by "code". */
    private const CAUSES = [
        '1' => 'the routers do not understand each other\'s messages: a device in the path changes the TCP stream, or a software bug',
        '2.1' => 'the routers support no common BGP version',
        '2.2' => 'the remote AS configured on one router does not match the local AS of the other',
        '2.3' => 'the BGP identifier (router ID) is invalid or the same on both routers',
        '2.4' => 'an optional parameter or capability (address family, 4-byte AS, add-path...) is enabled on one side only',
        '2.5' => 'the authentication (MD5 / TCP-AO password) differs',
        '2.6' => 'the peer does not accept the hold time: configure timers both sides accept',
        '2.7' => 'an optional parameter or capability (address family, 4-byte AS, add-path...) is enabled on one side only',
        '2.11' => 'the BGP roles (RFC 9234) of the two routers do not fit together',
        '3' => 'an UPDATE with a malformed or unexpected attribute was received: check the peer\'s software and attribute policies',
        '4' => 'no keepalive arrived for the whole hold time: packet loss on the path, a busy route processor, an MTU problem that stalls large updates, or a firewall dropping the session',
        '5' => 'a message arrived in the wrong session state, usually a connection collision or a software problem',
        '6.1' => 'the peer sent more prefixes than its configured maximum',
        '6.2' => 'an administrator shut the session down',
        '6.3' => 'the neighbor was removed from the configuration of one router',
        '6.4' => 'an administrator reset the session',
        '6.5' => 'the peer refused the connection: it has no neighbor for this address, or it is at its session limit',
        '6.6' => 'a configuration change reset the session',
        '6.7' => 'both routers opened a connection at the same time and one of them was closed (harmless)',
        '6.8' => 'a router ran out of memory or another resource',
        '6.9' => 'the session was hard reset (graceful restart could not be used)',
        '6.10' => 'BFD declared the path down',
        '6' => 'the session was closed on purpose by one of the routers',
        '7' => 'a malformed ROUTE-REFRESH message was received',
    ];

    /**
     * Text of an error, null when there is none (code 0, or not reported).
     *
     * @return array{code: int, subcode: int, text: string, cause: string}|null text like "Cease / Administrative Reset (6/4)"
     */
    public static function describe(?int $code, ?int $subcode): ?array
    {
        $code = (int) $code;
        $subcode = (int) $subcode;
        if ($code <= 0) {
            return null;
        }

        [$name, $subcodes] = self::CODES[$code] ?? ['Unknown error ' . $code, []];
        $text = $name;
        if ($subcode > 0) {
            $text .= ' / ' . ($subcodes[$subcode] ?? 'subcode ' . $subcode);
        }

        return [
            'code' => $code,
            'subcode' => $subcode,
            'text' => $text . ' (' . $code . '/' . $subcode . ')',
            'cause' => self::CAUSES[$code . '.' . $subcode] ?? self::CAUSES[(string) $code] ?? '',
        ];
    }

    /**
     * Did an administrator end the session on purpose (shutdown, reset, de-configuration)?
     */
    public static function administrative(?int $code, ?int $subcode): bool
    {
        return (int) $code === 6 && in_array((int) $subcode, [2, 3, 4, 6], true);
    }
}
