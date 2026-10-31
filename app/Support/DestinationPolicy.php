<?php

namespace App\Support;

use App\Models\TenantSourcePolicy;

/**
 * Destination limits for a source database. Metadata and link-local
 * addresses are always rejected. Private ranges stay available unless the
 * organization policy turns them off. The Connector repeats the DNS check
 * because this API cannot see the customer's resolver.
 */
class DestinationPolicy
{
    public const FINGERPRINT_VECTOR = '8e3aa288660156a943946168367b1fa7be8bace2a20fa607aec54801d54ebe18';

    /**
     * @param  list<string>  $allowedHosts
     * @param  list<int>  $allowedPorts
     */
    public function __construct(
        public readonly bool $allowLoopback,
        public readonly bool $allowPrivate,
        public readonly array $allowedHosts,
        public readonly array $allowedPorts,
    ) {}

    public static function fromModel(TenantSourcePolicy $policy): self
    {
        return new self(
            (bool) $policy->allow_loopback,
            (bool) $policy->allow_private,
            array_values(array_filter(array_map(
                fn ($host) => is_string($host) ? strtolower(trim($host)) : '',
                $policy->allowed_hosts ?? [],
            ))),
            array_values(array_map('intval', $policy->allowed_ports ?? [])),
        );
    }

    public function assert(string $host, int $port): void
    {
        $normalized = self::normalizeHost($host);

        if (self::isMetadataName($normalized)) {
            throw new \InvalidArgumentException('Ese destino no está permitido por la política de la organización.');
        }

        if ($this->allowedPorts !== [] && ! in_array($port, $this->allowedPorts, true)) {
            throw new \InvalidArgumentException('Ese puerto no está permitido por la política de la organización.');
        }

        if ($this->allowedHosts !== [] && ! $this->hostAllowed($normalized)) {
            throw new \InvalidArgumentException('Ese host no está en la lista de la organización.');
        }

        if (filter_var($normalized, FILTER_VALIDATE_IP)) {
            $this->assertIp($normalized);

            return;
        }

        if (self::isLocalName($normalized) && ! $this->allowLoopback) {
            throw new \InvalidArgumentException('Ese destino no está permitido por la política de la organización.');
        }
    }

    public static function fingerprint(string $engine, string $host, int $port, string $database, string $schema): string
    {
        $normalized = self::normalizeHost($host);

        return hash('sha256', "v1\n{$engine}\n{$normalized}\n{$port}\n{$database}\n{$schema}");
    }

    public static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $host;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($host);

            return $packed === false ? $host : strtolower(inet_ntop($packed));
        }

        return rtrim($host, '.');
    }

    private function assertIp(string $ip): void
    {
        if (self::isMetadataIp($ip) || self::isLinkLocal($ip) || self::isUnspecified($ip) || self::isMulticast($ip)) {
            throw new \InvalidArgumentException('Ese destino no está permitido por la política de la organización.');
        }

        if (self::isLoopback($ip)) {
            if ($this->allowLoopback) {
                return;
            }

            throw new \InvalidArgumentException('Ese destino no está permitido por la política de la organización.');
        }

        if (! $this->allowPrivate && self::isPrivate($ip)) {
            throw new \InvalidArgumentException('Ese destino no está permitido por la política de la organización.');
        }
    }

    private function hostAllowed(string $host): bool
    {
        foreach ($this->allowedHosts as $entry) {
            if (str_starts_with($entry, '.')) {
                $bare = substr($entry, 1);
                if ($host === $bare || str_ends_with($host, $entry)) {
                    return true;
                }

                continue;
            }

            if ($entry === $host) {
                return true;
            }
        }

        return false;
    }

    private static function isMetadataName(string $host): bool
    {
        return in_array($host, ['metadata.google.internal', 'metadata.google.com', 'instance-data'], true);
    }

    private static function isLocalName(string $host): bool
    {
        return in_array($host, ['localhost', 'localhost.localdomain'], true);
    }

    private static function isMetadataIp(string $ip): bool
    {
        return in_array(self::normalizeHost($ip), ['169.254.169.254', '169.254.170.2', 'fd00:ec2::254'], true);
    }

    private static function isLoopback(string $ip): bool
    {
        return str_starts_with($ip, '127.') || $ip === '::1';
    }

    private static function isPrivate(string $ip): bool
    {
        if (self::isLoopback($ip) || self::isLinkLocal($ip)) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    private static function isLinkLocal(string $ip): bool
    {
        if (str_starts_with($ip, '169.254.')) {
            return true;
        }

        $packed = inet_pton($ip);

        return $packed !== false && strlen($packed) === 16 && ord($packed[0]) === 0xfe && (ord($packed[1]) & 0xc0) === 0x80;
    }

    private static function isUnspecified(string $ip): bool
    {
        return $ip === '0.0.0.0' || $ip === '::';
    }

    private static function isMulticast(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $first = (int) explode('.', $ip)[0];

            return $first >= 224 && $first <= 239;
        }

        $packed = inet_pton($ip);

        return $packed !== false && strlen($packed) === 16 && (ord($packed[0]) & 0xff) === 0xff;
    }
}
