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

namespace SocialLogin\DTO;

/**
 * What a provider is willing to attest about the person who just signed in there.
 *
 * The identifier is the provider's own stable subject: it is the only thing this
 * module ever matches an account on. The email is carried alongside because an
 * account needs one, never because it identifies anybody.
 *
 * `emailVerified` is the provider's claim that it checked the mailbox, not ours. It
 * is the single reason this module is willing to open an account on the strength of
 * an address it was handed, so a provider that cannot make that claim must say so.
 */
final readonly class VerifiedIdentity
{
    /**
     * What `customer.firstname` and `customer.lastname` hold — the single source of
     * truth for every place a name coming from a provider is cut down before it reaches
     * the database, whether that happens here, in {@see \SocialLogin\Provider\AppleProvider}
     * or in {@see \SocialLogin\Service\SocialLoginService}.
     */
    public const int NAME_MAXIMUM_LENGTH = 255;

    public function __construct(
        public string $provider,
        public string $identifier,
        public ?string $email,
        public bool $emailVerified,
        public ?string $firstName = null,
        public ?string $lastName = null,
    ) {
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->emailVerified && null !== $this->email && '' !== trim($this->email);
    }

    /**
     * @return array{provider: string, identifier: string, email: string|null, emailVerified: bool, firstName: string|null, lastName: string|null}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'identifier' => $this->identifier,
            'email' => $this->email,
            'emailVerified' => $this->emailVerified,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['provider', 'identifier'] as $requiredKey) {
            if (!isset($data[$requiredKey]) || !\is_string($data[$requiredKey]) || '' === $data[$requiredKey]) {
                throw new \InvalidArgumentException(\sprintf('A stored identity is missing its "%s".', $requiredKey));
            }
        }

        return new self(
            provider: (string) $data['provider'],
            identifier: (string) $data['identifier'],
            email: \is_string($data['email'] ?? null) ? $data['email'] : null,
            emailVerified: true === ($data['emailVerified'] ?? false),
            firstName: \is_string($data['firstName'] ?? null) ? $data['firstName'] : null,
            lastName: \is_string($data['lastName'] ?? null) ? $data['lastName'] : null,
        );
    }
}
