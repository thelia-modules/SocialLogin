<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SocialLogin\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Small values this module hands to a browser and expects back unchanged.
 *
 * A payload goes out as JSON with the time it was minted, next to an HMAC of it. Coming
 * back it is believed only if the HMAC still matches and the stated lifetime has not run
 * out. Nothing secret ever goes in one: the signature says the shop wrote it, it does not
 * hide it.
 *
 * Each use gets its own key, derived from the application secret and the purpose string,
 * so that a value minted for one thing cannot be presented as another. Comparison is
 * {@see hash_equals()}, because a byte-by-byte one leaks the right answer through timing.
 */
final readonly class SignedPayloadCodec
{
    private const string ISSUED_AT_KEY = 'issuedAt';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $applicationSecret,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function encode(string $purpose, array $payload): string
    {
        $payload[self::ISSUED_AT_KEY] = time();

        $body = $this->toBase64Url((string) json_encode($payload, \JSON_THROW_ON_ERROR));

        return $body.'.'.$this->toBase64Url($this->sign($purpose, $body));
    }

    /**
     * @return array<string, mixed>|null the payload, or null when it was not written here,
     *                                   was altered, or is older than $lifetimeSeconds
     */
    public function decode(string $purpose, string $token, int $lifetimeSeconds): ?array
    {
        $parts = explode('.', $token);

        if (2 !== \count($parts)) {
            return null;
        }

        [$body, $signature] = $parts;

        if (!hash_equals($this->sign($purpose, $body), (string) $this->fromBase64Url($signature))) {
            return null;
        }

        $payload = json_decode((string) $this->fromBase64Url($body), true);

        if (!\is_array($payload) || !is_numeric($payload[self::ISSUED_AT_KEY] ?? null)) {
            return null;
        }

        if (time() - (int) $payload[self::ISSUED_AT_KEY] > $lifetimeSeconds) {
            return null;
        }

        // Keys are restated as strings: a JSON object key that reads as a number comes
        // back from json_decode() as an int, and every caller here indexes by name.
        $normalized = [];

        foreach ($payload as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }

    private function sign(string $purpose, string $body): string
    {
        $key = hash_hmac('sha256', 'sociallogin:'.$purpose, $this->applicationSecret, true);

        return hash_hmac('sha256', $body, $key, true);
    }

    private function toBase64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function fromBase64Url(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
