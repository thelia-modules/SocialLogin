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

namespace SocialLogin\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use SocialLogin\Service\SignedPayloadCodec;

final class SignedPayloadCodecTest extends TestCase
{
    private const string SECRET = 'signed-payload-codec-test-secret';

    private SignedPayloadCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new SignedPayloadCodec(self::SECRET);
    }

    public function testARoundTripReturnsTheSamePayload(): void
    {
        $token = $this->codec->encode('state', ['provider' => 'google', 'nonce' => 'abc']);

        $decoded = $this->codec->decode('state', $token, 600);

        self::assertSame('google', $decoded['provider'] ?? null);
        self::assertSame('abc', $decoded['nonce'] ?? null);
    }

    /**
     * Each purpose derives its own signing key from the application secret, so a value
     * minted for one purpose must not be believed when presented as another — the whole
     * reason the module has more than one purpose string in the first place
     * ({@see \SocialLogin\Service\StateManager}, {@see \SocialLogin\Service\PendingIdentityStore}).
     */
    public function testAPayloadDecodedUnderAnotherPurposeIsRejected(): void
    {
        $token = $this->codec->encode('state', ['provider' => 'google']);

        self::assertNull($this->codec->decode('pending_identity', $token, 600));
    }

    public function testAMalformedTokenIsRejected(): void
    {
        self::assertNull($this->codec->decode('state', 'not-a-well-formed-token', 600));
    }
}
