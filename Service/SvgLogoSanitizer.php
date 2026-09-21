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

use enshrined\svgSanitize\data\AllowedAttributes;
use enshrined\svgSanitize\data\AllowedTags;
use enshrined\svgSanitize\data\AttributeInterface;
use enshrined\svgSanitize\data\TagInterface;
use enshrined\svgSanitize\Sanitizer;
use Twig\Markup;

/**
 * Turns an SVG pasted into the back office into markup that is safe to render inline, or
 * into null.
 *
 * This is the single choke point between what a merchant pasted and what a template prints
 * with `|raw`, so it lives on its own rather than inside the settings reader: a stored-XSS
 * surface deserves a class whose whole job is that surface, testable without a
 * configuration store behind it.
 *
 * Two allow-lists are narrowed past the library's defaults, because a logo needs none of
 * what is taken away:
 *
 * - tags: `<style>` is not scoped to the SVG once the markup is inlined in the page, so it
 *   leaks CSS over the whole login page; `<a>` hijacks the click; `<image>` and `<font>`
 *   pull external resources.
 * - attributes: `style` alone is enough to lay a full-page overlay over the login form
 *   (`position:fixed;inset:0;z-index:…`) and turn the page into a clickjacking target;
 *   `class` does the same by borrowing the page's own stylesheet (a theme utility such as
 *   `.fixed` on an oversized `<svg>`); `overflow` lets the drawing paint outside its
 *   button; and `tabindex` steals the keyboard order. `id` is kept, because gradients,
 *   clip paths and masks are referenced through it (`url(#…)`), as are the presentation
 *   attributes, which is all artwork needs.
 *
 * The return type is {@see Markup} rather than a string on purpose: it is what the theme's
 * button component declares, so a future caller handing it an unsanitized string fails at
 * mount time instead of reaching `|raw`.
 */
final readonly class SvgLogoSanitizer
{
    public function sanitize(string $rawSvg): ?Markup
    {
        $sanitizer = new Sanitizer();
        $sanitizer->removeRemoteReferences(true);
        $sanitizer->removeXMLTag(true);
        $sanitizer->setAllowedTags(new class implements TagInterface {
            /**
             * @return array<string>
             */
            public static function getTags(): array
            {
                return array_values(array_diff(AllowedTags::getTags(), ['style', 'a', 'image', 'font']));
            }
        });
        $sanitizer->setAllowedAttrs(new class implements AttributeInterface {
            /**
             * @return array<string>
             */
            public static function getAttributes(): array
            {
                return array_values(array_diff(AllowedAttributes::getAttributes(), ['style', 'class', 'overflow', 'tabindex']));
            }
        });

        try {
            $clean = $sanitizer->sanitize($rawSvg);
        } catch (\Throwable) {
            // The library returns false when the input does not parse and throws when it
            // parses to something that is not an SVG at all. Both, and anything else it may
            // throw, mean "no logo": the caller falls back to the theme's built-in brand
            // mark rather than rendering broken markup or failing the page.
            return null;
        }

        if (!\is_string($clean) || !str_contains(strtolower($clean), '<svg')) {
            return null;
        }

        return new Markup($clean, 'UTF-8');
    }
}
