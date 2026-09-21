# SocialLogin: sign in with Google, Facebook, Apple

OAuth 2.0 / OpenID Connect sign-in for the Thelia 3 storefront. Adds "Continue with …" buttons to the login, registration and checkout identification steps, and lets a customer link, list and remove social identities from their account.

## Requirements

- Thelia 3.1+
- PHP 8.3+
- HTTPS on the storefront (see [TLS constraint](#tls-constraint) below; sign-in does not work over plain HTTP)
- One developer account per provider you intend to enable (Google Cloud, Meta for Developers, Apple Developer)

## Install

```bash
composer require thelia/social-login-module
php Thelia module:refresh
php Thelia module:activate SocialLogin
```

In this monorepo, from a dev environment:

```bash
ddev exec php bin/console module:activate SocialLogin
```

If the module's schema changed (`Config/schema.xml`), regenerate the module's own SQL before activating:

```bash
ddev exec php bin/console module:generate:sql SocialLogin
```

## Configuration

**Modules → SocialLogin → Configure** (`/admin/module/SocialLogin`) lists one block per provider: an enable toggle, its credential fields, and the callback URL to register with that provider's console. A provider with the toggle off, or with any required field left empty, shows no button anywhere on the storefront: login, registration and checkout pages render exactly as without the module.

A secret field left empty on save keeps the value already stored; it is never redisplayed once saved.

### Display order

Each provider block carries an optional **Display order** field, a whole number of 0 or more. Providers are listed by increasing number, on the storefront buttons and on the configuration screen alike; a provider left empty comes after those that have one, and providers sharing a number, or having none, keep the default order, the order in which the module registers them. Emptying the field puts the provider back in the default order.

### Provider logo

Each provider block carries an optional **logo (SVG)** field. Brand guidelines, Apple's in particular, require the official artwork downloaded from the provider's own design resources and forbid recreating it, so the artwork is yours to supply: paste the SVG file's content into that field and the storefront buttons use it. Left empty, the theme's built-in brand mark is used instead, which is the sensible default for development and for a provider whose guidelines you have not checked yet.

What is pasted is sanitized before it is stored and again before it is printed: scripts, event handlers, `<style>`, `<a>`, `<image>` and `<font>` elements, remote references and the `style`, `class`, `overflow` and `tabindex` attributes are all removed. Presentation attributes and `id` (which gradients, clip paths and masks are referenced by), all that artwork needs, are kept. A paste that survives that as nothing at all is refused at save time with an error rather than stored, and so is a logo over 64 KB, that being what the configuration column holds; the size is checked on the sanitized markup, which is what gets stored and comes out larger than the paste.

### Callback URLs

Each provider gets its own callback, shown on the configuration screen:

```
https://<your-domain>/social-login/callback/google
https://<your-domain>/social-login/callback/facebook
https://<your-domain>/social-login/callback/apple
```

### Google

1. In the [Google Cloud Console](https://console.cloud.google.com/), under **APIs & Services → Credentials**, create an OAuth client ID of type **Web application**.
2. Add the Google callback URL above to its **Authorized redirect URIs**.
3. Fill in **Client ID** and **Client secret** on the configuration screen.

The module requests the `openid`, `email` and `profile` scopes; no further scope configuration is needed.

### Facebook

1. Create an app on [Meta for Developers](https://developers.facebook.com/apps/).
2. Add the **Facebook Login** product to it.
3. Under Facebook Login's settings, add the Facebook callback URL above to **Valid OAuth Redirect URIs**.
4. Fill in **App ID** and **App secret** (found on the app dashboard) on the configuration screen.

Facebook does not publish an `email_verified` claim; the module treats an address Facebook returns as verified, since Facebook only returns the field for a confirmed, still-valid address.

### Apple

Sign in with Apple requires a paid Apple Developer account, and is the most involved of the three:

1. Create a **Services ID** (Certificates, Identifiers & Profiles → Identifiers → **+** → Services IDs). This is *not* the App ID; the Services ID is what goes in the **Service ID** field on the configuration screen.
2. Enable **Sign in with Apple** on that Services ID.
3. In its Sign in with Apple configuration, declare your **domain** and the **Return URL** (the Apple callback URL above), then verify the domain: Apple hands you a verification file to host at `https://<your-domain>/.well-known/apple-developer-domain-association.txt`, served over HTTPS with a valid certificate, `Content-Type: text/plain`, answering `200` with no redirect. The file is only valid for 7 days, so verify the domain promptly after downloading it.
4. Create a **Sign in with Apple private key** (Keys → **+**, enable Sign in with Apple). The **.p8** file it generates can only be downloaded once, so save it. Note the **Key ID** shown at creation.
5. Find your **Team ID** in the top-right corner of your Apple Developer account, under Membership.
6. On the configuration screen, fill in:
   - **Service ID**: the Services ID identifier from step 1
   - **Team ID**: from step 5
   - **Key ID**: from step 4
   - **Private key (.p8 content)**: the full text content of the .p8 file from step 4

The client secret Apple's OAuth exchange requires is not a value you generate or store: the module builds a fresh signed JWT (ES256) from the private key on every sign-in. There is nothing to renew or rotate manually, unlike Google and Facebook, where the secret is a fixed value you copy in once.

Two behaviours to expect from Apple specifically:

- the customer's name is only sent by Apple on the **very first** authorization for a given Services ID; every later sign-in, including from a new browser, arrives without one. The module falls back to a name derived from the email address when this happens.
- the email address returned can be a private relay address (`@privaterelay.appleid.com`), when the visitor chose to hide their real address. The module accepts it like any other verified address, but Apple only forwards mail to it from senders you have registered: see [Private email relay](#private-email-relay) below, or order confirmations and password resets sent to those customers never arrive.

## Brand guidelines and eligibility

Each provider publishes rules for how its sign-in button may look and what an app must do before it can be used by the public. Some are checked when the provider reviews your app; all of them are yours to follow, not the module's. This section lists what applies to a storefront using this module, and where the module stops.

### Google

- **Button**: the allowed titles are "Sign in with Google", "Sign up with Google" and "Continue with Google" (the module uses the last one, translated). The "G" must be the standard colour version, unmodified, on a light background. The module keeps the button light in every state for that reason.
- **Font**: Google asks for Google Sans Medium on a custom button. The module uses the theme's font, because Google Sans is not freely redistributable. If that matters for your review, supply Google's pre-approved button artwork, or render Google's own button with the Google Identity Services SDK instead.
- **Going live**: an OAuth client whose consent screen is in *Testing* status only accepts the test users you list (100 at most). Publish the consent screen to production before opening sign-in to customers. The module requests only `openid`, `email` and `profile`, which are not sensitive scopes, so no security assessment is required; showing your shop's name and logo on the consent screen does require Google's brand verification.

### Facebook

- **Button**: use the official Facebook logo, unmodified, in Facebook blue (`#1877F2`) or in white. The built-in mark follows the blue version; supply the official asset from Meta's brand resources in the logo field for production.
- **Going live**: an app in *Development* mode only lets its own developers and testers sign in. Switching it to *Live* requires a privacy policy URL and a user data deletion URL or callback on the app's settings. The `email` and `public_profile` permissions the module uses are available without App Review; Meta may still ask for Business Verification depending on your app type.

### Apple

Apple is the strictest of the three, and the one most likely to reject a sign-in screen at review.

- **Logo: use Apple's artwork, never a copy.** Apple's Human Interface Guidelines require the logo artwork downloaded from Apple Design Resources and forbid creating a custom Apple logo. The Apple mark built into the theme is a **stand-in for development**: it is drawn by hand, so it does not meet that rule. Before going live, download the official Sign in with Apple artwork (Apple Design Resources, which needs an Apple Developer login) and paste its SVG in the Apple **logo (SVG)** field.
- **Button**: the allowed titles are "Sign in with Apple", "Sign up with Apple" and "Continue with Apple". A button that shows the logo next to text must be rectangular (the module's social buttons are), black, white or white with an outline, and no smaller than the other sign-in buttons on the page. It must be visible without scrolling.
- **SDK: this module uses Apple's REST flow, not Sign in with Apple JS.** Apple offers two ways to add the button to a website:
  - *Sign in with Apple JS*, a script Apple hosts, which renders Apple's own button (compliant by construction) and opens the authorization in a popup or a redirect;
  - the *REST API*, a plain OAuth 2.0 / OpenID Connect exchange done by your server, with a button you draw yourself.

  The module uses the REST API: the whole exchange stays on the server, the button uses the same component as Google and Facebook, and no third-party script is loaded on the login page (nothing to add to your Content Security Policy). The trade-off is that the button's compliance is on you: official artwork in the logo field, and the button rules above. If you prefer Apple's own rendering, Sign in with Apple JS can replace the button; the callback it posts to is the same `/social-login/callback/apple` route, but wiring it is not part of this module.
- **Account**: a paid membership of the Apple Developer Program is required to create the Services ID and the key.
- **Domain**: the Return URL's domain must be verified with the `apple-developer-domain-association.txt` file (see step 3 of the Apple setup above). A local or preview domain such as `*.ddev.site` cannot be verified, so Apple sign-in can only be tested end to end on a real, public domain.

#### Private email relay

When a customer chooses **Hide My Email**, Apple gives your shop a relay address (`…@privaterelay.appleid.com`) instead of their real one. Apple only forwards mail sent to that address from **email sources you have registered**: in Certificates, Identifiers & Profiles, open **Services → Sign in with Apple for Email Communication** and register every domain or address your shop sends mail from (order confirmations, password resets, newsletters). Each domain needs SPF, and DKIM is strongly recommended. Mail from an unregistered source is rejected by Apple, so a customer who hid their address would silently stop receiving anything from the shop.

#### Native app on the App Store

None of this applies to a website alone. If you also publish an iOS app that offers sign-in with Google or Facebook, App Store Review Guideline 4.8 requires that app to offer an equivalent privacy-focused login option as well, such as Sign in with Apple. And if that app lets customers delete their account, Guideline 5.1.1(v) expects the Sign in with Apple tokens to be revoked through Apple's REST API. This module keeps no Apple refresh token, so it cannot revoke one; an app with account deletion would need to store and revoke it.

## Reference documentation

Google

- [Sign in with Google branding guidelines](https://developers.google.com/identity/branding-guidelines)
- [OAuth 2.0 for web server applications](https://developers.google.com/identity/protocols/oauth2/web-server)
- [Google Identity Services for the web](https://developers.google.com/identity/gsi/web/guides/overview) (the SDK alternative)
- [OAuth app verification](https://support.google.com/cloud/answer/13463073)

Facebook

- [Facebook Login for the web](https://developers.facebook.com/docs/facebook-login/web)
- [App Review](https://developers.facebook.com/docs/app-review)
- [Facebook brand resources: logo](https://about.meta.com/brand/resources/facebook/logo/)

Apple

- [Human Interface Guidelines: Sign in with Apple](https://developer.apple.com/design/human-interface-guidelines/sign-in-with-apple) (button and logo rules)
- [Apple Design Resources](https://developer.apple.com/design/resources/) (official artwork)
- [Usage guidelines for websites and other platforms](https://developer.apple.com/sign-in-with-apple/usage-guidelines-for-websites-and-other-platforms/)
- [Configure Sign in with Apple for the web](https://developer.apple.com/help/account/configure-app-capabilities/configure-sign-in-with-apple-for-the-web) (Services ID, domain, Return URL)
- [Configure the private email relay service](https://developer.apple.com/help/account/configure-app-capabilities/configure-private-email-relay-service)
- [Sign in with Apple REST API](https://developer.apple.com/documentation/signinwithapplerestapi): [generate and validate tokens](https://developer.apple.com/documentation/signinwithapplerestapi/generate-and-validate-tokens), [revoke tokens](https://developer.apple.com/documentation/signinwithapplerestapi/revoke-tokens)
- [Sign in with Apple JS](https://developer.apple.com/documentation/signinwithapplejs) (the SDK alternative)
- [App Store Review Guidelines](https://developer.apple.com/app-store/review/guidelines/) (4.8 login services, 5.1.1(v) account deletion)
- [Apple Developer Program](https://developer.apple.com/programs/)

## Behaviour

- **New address, no existing account**: the module opens a Thelia customer account, enabled immediately (the provider already confirmed the address, so no activation email is needed), and signs the visitor in. The account is given a random password nobody knows; signing in stays the provider's job until the customer sets one of their own from their account page.
- **Address already has an account**: nobody is signed in automatically. The visitor is asked for that account's password once, to prove ownership, before the provider identity is linked to it.
- **Address belongs to a guest order only**: the module defers to the shop's normal guest-activation flow (mailed activation code) rather than opening or attaching anything: a guest record does not say who the orders on it belong to.
- **Returning visitor, already linked**: signed in directly.
- **Unlinking**: a customer can remove a linked provider from their account page. Removing the last remaining sign-in method requires the account password, so nobody locks themselves out of an account with no password set.
- **Personal data (RGPD)**: linked identities (provider, last reported email, link/login dates) are included in the customer's data export, and are deleted when the account is anonymized. The provider's internal subject identifier never leaves the module; only what the customer can already see on their own identity list is exported.

## TLS constraint

The state cookie that protects the sign-in flow against CSRF is set `SameSite=None; Secure`, which browsers only store over HTTPS. Apple's callback also arrives as a cross-site `POST`, which needs the same cookie to be forwarded. In practice: sign-in does not work over plain HTTP, in local development or otherwise: use HTTPS, including in DDEV (`https://<project>.ddev.site`).

## Deployment / multi-server

Three pieces of state are shared across a sign-in but kept node-local by default: the single-use state nonce, the Apple callback relay (backed by `symfony/lock`, `LOCK_DSN` defaulting to `flock`), and the `thelia.cache.security` cache (filesystem by default), which also holds the login attempt limiter's window and the cached Apple signing keys (JWKS).

Behind a load balancer with no session affinity, a request can land on a different node at each step, and that node does not see state written by another. In practice: a state already consumed on one node can be replayed on another, Apple sign-in fails whenever the initial `POST` and the return land on different nodes, and the login attempt limiter's budget is effectively multiplied by the number of nodes.

For a multi-server production deployment, configure a shared `LOCK_DSN` (Redis or a database) and a shared `cache.app` (Redis) instead of the local defaults. Sticky sessions at the load balancer are an acceptable alternative, but shared storage is preferred.

## Troubleshooting

**No "Continue with …" button shows up.** The provider is disabled, or at least one of its required fields is empty. Check **Modules → SocialLogin → Configure**; a partially filled provider behaves as if it were off.

**Callback fails with a generic error after returning from the provider.** Usual causes:
- the shop was reached over plain HTTP (the state cookie was never set or never sent, see [TLS constraint](#tls-constraint));
- the sign-in attempt took longer than 10 minutes, or the callback was replayed (the state is single-use and short-lived);
- the callback URL registered with the provider does not exactly match the one shown on the configuration screen.

**Apple sign-in fails immediately, or the button does not appear.** Double check the **Service ID** field holds the Services ID identifier, not the App ID; the two are easy to swap and only the Services ID is a valid OAuth client for this flow. Also confirm the domain was verified and the Return URL matches exactly (scheme and host, including the absence of a trailing slash).

**Customers who signed in with Apple receive no email from the shop.** They chose Hide My Email and the shop's sending domain is not registered with Apple's private email relay. Register it (with SPF, ideally DKIM) as described in [Private email relay](#private-email-relay).

**Google shows "Access blocked" or only lets some accounts in.** The OAuth consent screen is still in *Testing* status, which only admits the listed test users. Publish it to production.

**Facebook says the app is not available or not active.** The Meta app is still in *Development* mode. Add the privacy policy and data deletion URLs, then switch it to *Live*.

**A returning Apple visitor is created as a new account instead of being recognized.** Expected only on the very first sign-in per browser/account combination. If it recurs, the (provider, provider identifier) link may have been removed; check the customer's linked identities.

**"Please enter your password to remove your last sign-in method."** Expected: an account with no password set and only one linked provider would otherwise become impossible to sign into. Set a password from the account page, or keep at least one provider linked.

**The login attempt limiter blocks visitors after only a few tries, or blocks unrelated visitors together.** The limiter is keyed on the client IP address. Behind a reverse proxy, configure Symfony's trusted proxies (`trusted_proxies`) so the real client IP is used instead of the proxy's; otherwise every client behind the proxy shares a single limiter budget.
