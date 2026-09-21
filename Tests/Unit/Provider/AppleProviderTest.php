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

namespace SocialLogin\Tests\Unit\Provider;

use PHPUnit\Framework\TestCase;
use SocialLogin\DTO\VerifiedIdentity;
use SocialLogin\Provider\AppleIdentityTokenVerifier;
use SocialLogin\Provider\AppleProvider;
use SocialLogin\Service\SocialLoginConfiguration;
use SocialLogin\Service\SvgLogoSanitizer;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Apple hands the visitor's name over exactly once — in the `user` field of the very
 * first authorization's callback body — and never again, not even inside the identity
 * token (see the class docblock). What is exercised here is that reading, in isolation:
 * `nameFromFirstAuthorization()` is a pure function of the callback parameters, so it is
 * called directly rather than through a full callback exchange, which would need a
 * signed identity token and a mocked provider round trip to reach the same code.
 */
final class AppleProviderTest extends TestCase
{
    public function testTheNameIsReadFromTheUserFieldOnTheFirstAuthorization(): void
    {
        [$firstName, $lastName] = $this->nameFromFirstAuthorization([
            'user' => (string) json_encode(['name' => ['firstName' => 'Ada', 'lastName' => 'Lovelace']], \JSON_THROW_ON_ERROR),
        ]);

        self::assertSame('Ada', $firstName);
        self::assertSame('Lovelace', $lastName);
    }

    /**
     * Every later sign-in: Apple sends no `user` field at all once the visitor already
     * authorized the app once.
     */
    public function testNoUserFieldMeansNoName(): void
    {
        [$firstName, $lastName] = $this->nameFromFirstAuthorization([]);

        self::assertNull($firstName);
        self::assertNull($lastName);
    }

    public function testAnEmptyUserFieldMeansNoName(): void
    {
        [$firstName, $lastName] = $this->nameFromFirstAuthorization(['user' => '']);

        self::assertNull($firstName);
        self::assertNull($lastName);
    }

    /**
     * The `user` field is the one part of this callback Apple does not sign; a browser
     * is where a value gets edited, so malformed JSON is treated the same as none.
     */
    public function testAMalformedUserFieldMeansNoName(): void
    {
        [$firstName, $lastName] = $this->nameFromFirstAuthorization(['user' => 'not-json']);

        self::assertNull($firstName);
        self::assertNull($lastName);
    }

    public function testAUserFieldWithNoNameMeansNoName(): void
    {
        [$firstName, $lastName] = $this->nameFromFirstAuthorization([
            'user' => (string) json_encode(['email' => 'ada@example.com'], \JSON_THROW_ON_ERROR),
        ]);

        self::assertNull($firstName);
        self::assertNull($lastName);
    }

    /**
     * A name reaching the database too long either fails the insert or is silently
     * truncated — neither belongs at the end of a sign-in, so it is cut here, to the
     * same {@see VerifiedIdentity::NAME_MAXIMUM_LENGTH} the column itself is bound by.
     */
    public function testANameLongerThanTheColumnIsCutDown(): void
    {
        $tooLong = str_repeat('a', VerifiedIdentity::NAME_MAXIMUM_LENGTH + 50);

        [$firstName] = $this->nameFromFirstAuthorization([
            'user' => (string) json_encode(['name' => ['firstName' => $tooLong]], \JSON_THROW_ON_ERROR),
        ]);

        self::assertNotNull($firstName);
        self::assertSame(VerifiedIdentity::NAME_MAXIMUM_LENGTH, mb_strlen($firstName));
    }

    /**
     * A name that is only whitespace once trimmed is the same as no name at all: nothing
     * worth keeping was actually said.
     */
    public function testABlankNameIsTreatedAsNoName(): void
    {
        [$firstName] = $this->nameFromFirstAuthorization([
            'user' => (string) json_encode(['name' => ['firstName' => '   ']], \JSON_THROW_ON_ERROR),
        ]);

        self::assertNull($firstName);
    }

    /**
     * @param array<string, mixed> $callbackParameters
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function nameFromFirstAuthorization(array $callbackParameters): array
    {
        $method = new \ReflectionMethod(AppleProvider::class, 'nameFromFirstAuthorization');

        /** @var array{0: string|null, 1: string|null} $result */
        $result = $method->invoke($this->provider(), $callbackParameters);

        return $result;
    }

    private function provider(): AppleProvider
    {
        return new AppleProvider(
            new SocialLoginConfiguration(new SvgLogoSanitizer()),
            new AppleIdentityTokenVerifier(new MockHttpClient(), new ArrayAdapter()),
        );
    }
}
