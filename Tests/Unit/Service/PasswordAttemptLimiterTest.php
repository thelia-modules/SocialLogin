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
use SocialLogin\Exception\TooManyAttemptsException;
use SocialLogin\Service\PasswordAttemptLimiter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Contracts\Cache\ItemInterface;

final class PasswordAttemptLimiterTest extends TestCase
{
    public function testTheSixthAttemptWithinTheWindowIsRefused(): void
    {
        $limiter = new PasswordAttemptLimiter(new ArrayAdapter(), new RequestStack(), self::lockFactory());

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $limiter->consume('attach', 42);
        }

        $this->expectException(TooManyAttemptsException::class);

        $limiter->consume('attach', 42);
    }

    /**
     * Attempts that have aged out of the window no longer count against a caller. There is
     * no clock to move here — WINDOW_SECONDS is a private constant, and the module's own
     * namespace is not one PHPUnit's clock mock rewrites — so a full budget of attempts is
     * seeded with timestamps older than the window, under the exact cache key the limiter
     * itself derives from (flow, account, client address). All five are pruned on the next
     * read, so the fresh guess is accepted rather than being the sixth of a full window.
     */
    public function testAttemptsThatAgedOutOfTheWindowNoLongerCount(): void
    {
        $cache = new ArrayAdapter();
        $limiter = new PasswordAttemptLimiter($cache, new RequestStack(), self::lockFactory());

        $key = 'sociallogin.attempts.'.hash('sha256', 'attach|42|cli');
        $cache->get($key, static function (ItemInterface $item): string {
            $item->expiresAfter(3600);

            $stale = time() - 1000;

            return (string) json_encode([$stale, $stale, $stale, $stale, $stale]);
        });

        $limiter->consume('attach', 42);

        $this->addToAssertionCount(1);
    }

    /**
     * The mirror of the case above: five attempts still inside the window fill the budget,
     * so the next guess is refused. Seeded rather than driven through consume() only to
     * pin the timestamps to a known point within the window; the refusal itself is the
     * observable behaviour under test.
     */
    public function testAFullWindowStillWithinRangeRefusesTheNextAttempt(): void
    {
        $cache = new ArrayAdapter();
        $limiter = new PasswordAttemptLimiter($cache, new RequestStack(), self::lockFactory());

        $key = 'sociallogin.attempts.'.hash('sha256', 'attach|42|cli');
        $cache->get($key, static function (ItemInterface $item): string {
            $item->expiresAfter(3600);

            $recent = time() - 1;

            return (string) json_encode([$recent, $recent, $recent, $recent, $recent]);
        });

        $this->expectException(TooManyAttemptsException::class);

        $limiter->consume('attach', 42);
    }

    /**
     * A cache entry written before the sliding-window format existed — a {startedAt, count}
     * object — must not be mined for numbers that happen to pass for timestamps, which
     * would hand a caller blocked at deploy time a fresh part-budget. The legacy entry is
     * discarded whole, so a full allowance is available: five guesses are accepted and only
     * the sixth is refused, exactly as against an empty window.
     */
    public function testAnEntryInTheLegacyFormatDoesNotCarryOver(): void
    {
        $cache = new ArrayAdapter();
        $limiter = new PasswordAttemptLimiter($cache, new RequestStack(), self::lockFactory());

        $key = 'sociallogin.attempts.'.hash('sha256', 'attach|42|cli');
        $cache->get($key, static function (ItemInterface $item): string {
            $item->expiresAfter(3600);

            return (string) json_encode(['startedAt' => time(), 'count' => 5]);
        });

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $limiter->consume('attach', 42);
        }

        $this->expectException(TooManyAttemptsException::class);

        $limiter->consume('attach', 42);
    }

    /**
     * A caller already over the limit cannot push the window forward by hammering it: a
     * refused attempt is not recorded. Five attempts inside the window are seeded, the next
     * consume is refused, and the stored window still holds five entries rather than six.
     */
    public function testARefusedConsumeDoesNotRecordAnotherAttempt(): void
    {
        $cache = new ArrayAdapter();
        $limiter = new PasswordAttemptLimiter($cache, new RequestStack(), self::lockFactory());

        $key = 'sociallogin.attempts.'.hash('sha256', 'attach|42|cli');
        $recent = time() - 1;
        $cache->get($key, static function (ItemInterface $item) use ($recent): string {
            $item->expiresAfter(3600);

            return (string) json_encode([$recent, $recent, $recent, $recent, $recent]);
        });

        try {
            $limiter->consume('attach', 42);
            self::fail('A full window must refuse the next attempt.');
        } catch (TooManyAttemptsException) {
        }

        $stored = json_decode((string) $cache->getItem($key)->get(), true);

        self::assertIsArray($stored);
        self::assertCount(5, $stored, 'A refused attempt must not be added to the window.');
    }

    /**
     * The counter is now read and written under a lock, so the limiter needs a factory.
     * In one process with no concurrency, the store only has to grant and release — what
     * it is worth against a real burst is a property of the deployed store, which a unit
     * test has no way to exercise.
     */
    private static function lockFactory(): LockFactory
    {
        return new LockFactory(new InMemoryStore());
    }
}
