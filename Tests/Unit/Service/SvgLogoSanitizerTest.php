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
use SocialLogin\Service\SvgLogoSanitizer;
use Twig\Markup;

/**
 * The single choke point between an SVG pasted into the back office and the markup a
 * template prints with `|raw`, so it is exercised on its own: a merchant-supplied logo is
 * stored-XSS territory and nothing but this class stands between the two. It reads no
 * configuration, so it is tested without ModuleConfig's static store behind it;
 * {@see \SocialLogin\Service\SocialLoginConfiguration::getCustomLogo()} only reads a value
 * and delegates here.
 */
final class SvgLogoSanitizerTest extends TestCase
{
    public function testAMaliciousSvgLosesItsScriptAndEventHandlers(): void
    {
        $clean = $this->sanitize('<svg onload="alert(1)"><script>alert(1)</script><path d="M0 0"/></svg>');

        self::assertStringContainsStringIgnoringCase('<svg', $clean);
        self::assertStringNotContainsStringIgnoringCase('<script', $clean);
        self::assertStringNotContainsStringIgnoringCase('onload', $clean);
    }

    public function testACleanSvgIsKept(): void
    {
        $clean = $this->sanitize('<svg viewBox="0 0 24 24"><path d="M12 2 2 22h20z"/></svg>');

        self::assertStringContainsStringIgnoringCase('<svg', $clean);
        self::assertStringContainsStringIgnoringCase('<path', $clean);
        self::assertStringContainsStringIgnoringCase('viewBox', $clean);
    }

    /**
     * The result is handed to a template that prints it with `|raw`, and the theme's button
     * component declares the prop as a Markup precisely so a raw string cannot get there.
     * That contract starts here.
     */
    public function testTheResultIsTwigMarkupRatherThanAString(): void
    {
        self::assertInstanceOf(
            Markup::class,
            (new SvgLogoSanitizer())->sanitize('<svg viewBox="0 0 24 24"><path d="M12 2 2 22h20z"/></svg>'),
        );
    }

    /**
     * `style="position:fixed;inset:0;z-index:9999"` on the root element turns a logo into a
     * full-page invisible overlay on the login form, which is a clickjacking surface. No
     * artwork needs the attribute, so it does not survive.
     */
    public function testAStyleAttributeIsStrippedSoALogoCannotOverlayTheLoginPage(): void
    {
        $clean = $this->sanitize(
            '<svg viewBox="0 0 24 24" style="position:fixed;inset:0;z-index:9999;width:100vw;height:100vh"><path d="M0 0"/></svg>'
        );

        self::assertStringNotContainsStringIgnoringCase('style=', $clean);
        self::assertStringNotContainsStringIgnoringCase('position:fixed', $clean);
    }

    /**
     * Once inlined, the logo is styled by the page's own stylesheet: a theme utility class
     * such as `.fixed` on an oversized root element is the same full-page overlay as a
     * `style` attribute, without writing a single CSS rule.
     */
    public function testAClassAttributeIsStrippedSoALogoCannotBorrowThePageStyles(): void
    {
        $clean = $this->sanitize(
            '<svg viewBox="0 0 24 24" class="fixed" width="4000" height="4000"><path class="inset-0" d="M0 0"/></svg>'
        );

        self::assertStringNotContainsStringIgnoringCase('class=', $clean);
        self::assertStringNotContainsStringIgnoringCase('fixed', $clean);
    }

    /**
     * Gradients, clip paths and masks are referenced by id, so taking ids away would break
     * ordinary brand artwork for no gain.
     */
    public function testAnIdAttributeIsKeptSoGradientsStillResolve(): void
    {
        $clean = $this->sanitize(
            '<svg viewBox="0 0 24 24"><defs><linearGradient id="brand"><stop offset="0" stop-color="#4285f4"/></linearGradient></defs><path fill="url(#brand)" d="M0 0"/></svg>'
        );

        self::assertStringContainsString('id="brand"', $clean);
        self::assertStringContainsString('url(#brand)', $clean);
    }

    /**
     * `overflow: visible` lets the drawing paint outside the button it sits in.
     */
    public function testAnOverflowAttributeIsStripped(): void
    {
        $clean = $this->sanitize('<svg viewBox="0 0 24 24" overflow="visible"><path d="M0 0"/></svg>');

        self::assertStringNotContainsStringIgnoringCase('overflow', $clean);
    }

    /**
     * A logo is decoration inside a link; giving it its own place in the keyboard order is
     * only ever a way to intercept it.
     */
    public function testATabindexAttributeIsStripped(): void
    {
        $clean = $this->sanitize('<svg viewBox="0 0 24 24" tabindex="1"><path d="M0 0"/></svg>');

        self::assertStringNotContainsStringIgnoringCase('tabindex', $clean);
    }

    public function testAStyleElementIsStrippedSoItCannotLeakCssOntoThePage(): void
    {
        $clean = $this->sanitize('<svg viewBox="0 0 24 24"><style>body{display:none}</style><circle r="8"/></svg>');

        self::assertStringNotContainsStringIgnoringCase('<style', $clean);
        self::assertStringNotContainsStringIgnoringCase('display:none', $clean);
    }

    public function testAFontElementIsStripped(): void
    {
        $clean = $this->sanitize(
            '<svg viewBox="0 0 24 24"><font horiz-adv-x="1000"><font-face font-family="x"/></font><path d="M0 0"/></svg>'
        );

        self::assertStringNotContainsStringIgnoringCase('<font', $clean);
    }

    public function testAnAnchorAndAJavascriptHrefAreStripped(): void
    {
        $clean = $this->sanitize('<svg viewBox="0 0 24 24"><a xlink:href="javascript:alert(1)"><path d="M0 0"/></a></svg>');

        self::assertStringNotContainsStringIgnoringCase('<a ', $clean);
        self::assertStringNotContainsStringIgnoringCase('javascript:', $clean);
    }

    public function testAForeignObjectIsStripped(): void
    {
        $clean = $this->sanitize(
            '<svg viewBox="0 0 24 24"><foreignObject><body xmlns="http://www.w3.org/1999/xhtml">x</body></foreignObject><path d="M0 0"/></svg>'
        );

        self::assertStringNotContainsStringIgnoringCase('<foreignObject', $clean);
    }

    public function testARemoteReferenceIsStripped(): void
    {
        $clean = $this->sanitize('<svg viewBox="0 0 24 24"><image href="https://evil.example/x.png"/><path d="M0 0"/></svg>');

        self::assertStringNotContainsStringIgnoringCase('evil.example', $clean);
        self::assertStringNotContainsStringIgnoringCase('<image', $clean);
    }

    /**
     * What is taken away is taken away whatever the case it was pasted in: the library
     * matches tag and attribute names case-insensitively, so `STYLE=` is no more of a way
     * through than `style=`. Worth stating, because the shape of the allow-lists makes it
     * look like an exact-string match.
     */
    public function testWhatIsStrippedIsStrippedWhateverItsCase(): void
    {
        $clean = $this->sanitize(
            '<svg viewBox="0 0 24 24" STYLE="position:fixed;inset:0" ONLOAD="alert(1)"><STYLE>body{display:none}</STYLE><path Style="overflow:visible" d="M0 0"/></svg>'
        );

        self::assertStringNotContainsStringIgnoringCase('style', $clean);
        self::assertStringNotContainsStringIgnoringCase('onload', $clean);
        self::assertStringContainsStringIgnoringCase('<path', $clean);
    }

    /**
     * An element name only counts as an SVG root in lowercase: the library parses as XML,
     * which is case-sensitive, and refuses a document holding no `svg` element at all. The
     * SVG specification agrees, so `<SVG>` is not a logo that got lost, it is not a logo.
     * It reaches the storefront as the built-in brand mark rather than as an error.
     */
    public function testAnUppercaseRootElementIsNotReadAsAnSvg(): void
    {
        self::assertNull((new SvgLogoSanitizer())->sanitize('<SVG viewBox="0 0 24 24"><path d="M0 0"/></SVG>'));
    }

    public function testMarkupWithoutAnSvgElementFallsBackToNull(): void
    {
        self::assertNull((new SvgLogoSanitizer())->sanitize('<p>not a logo</p>'));
    }

    public function testUnparsableInputFallsBackToNull(): void
    {
        self::assertNull((new SvgLogoSanitizer())->sanitize('this is not markup at all'));
    }

    private function sanitize(string $rawSvg): string
    {
        $clean = (new SvgLogoSanitizer())->sanitize($rawSvg);

        self::assertNotNull($clean);

        return (string) $clean;
    }
}
