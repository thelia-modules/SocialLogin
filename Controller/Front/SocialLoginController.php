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

namespace SocialLogin\Controller\Front;

use Psr\Log\LoggerInterface;
use SocialLogin\DTO\SocialLoginOutcome;
use SocialLogin\DTO\SocialLoginOutcomeStatus;
use SocialLogin\Exception\PendingIdentityNotFoundException;
use SocialLogin\Exception\SocialLoginException;
use SocialLogin\Exception\SocialLoginNotices;
use SocialLogin\Form\FrontAttachPasswordForm;
use SocialLogin\Provider\ProviderRegistry;
use SocialLogin\Service\CrossSiteCallbackHandoff;
use SocialLogin\Service\IdentityAttachmentService;
use SocialLogin\Service\IdentityDetachmentService;
use SocialLogin\Service\SocialLoginService;
use SocialLogin\Service\StateManager;
use SocialLogin\SocialLogin;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Domain\Customer\Exception\CustomerNotEnabledException;
use Thelia\Domain\Customer\Service\AuthenticationReturnUrl;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Model\Customer;

/**
 * Front side of the social sign-in journey: the departure to a provider, its callback,
 * the password prompt an ambiguous return needs, and detaching a linked identity.
 *
 * Every path here that fails does the same three things: it never leaves a session
 * open, it clears the state cookie, and it logs the refusal by its translation key
 * only — never a provider payload, never a password.
 */
#[Route('/social-login', name: 'sociallogin_')]
final class SocialLoginController extends BaseFrontController
{
    private const string DETACH_CSRF_TOKEN_ID = 'sociallogin_detach';
    private const string ATTACH_CANCEL_CSRF_TOKEN_ID = 'sociallogin_attach_cancel';

    /**
     * The cookie that binds a handoff key to the browser that made the POST. Set on the
     * `303`, read by `finish`, and scoped to this controller's path so it never travels
     * anywhere else. `SameSite=Lax` is enough: `finish` is a top-level same-site GET, so
     * the cookie is sent on it, and SameSite governs sending, not the browser storing what
     * the POST response set.
     */
    private const string HANDOFF_COOKIE_NAME = 'sociallogin_handoff';
    private const string HANDOFF_COOKIE_PATH = '/social-login';
    private const int HANDOFF_COOKIE_LIFETIME_SECONDS = 120;

    #[Route('/start/{provider}', name: 'start', methods: ['GET'])]
    public function start(
        string $provider,
        ProviderRegistry $providerRegistry,
        StateManager $stateManager,
        AuthenticationReturnUrl $authenticationReturnUrl,
        LoggerInterface $logger,
    ): RedirectResponse {
        // Remembered here, the same way the login page does it: a provider refusal or a
        // password prompt both come back through this controller without the parameter.
        $authenticationReturnUrl->capture();

        try {
            $providerInstance = $providerRegistry->getProvider($provider);
            $challenge = $stateManager->start($provider);
            $authorizationUrl = $providerInstance->getAuthorizationUrl($challenge->state, $this->callbackUrl($provider));
        } catch (SocialLoginException $exception) {
            $logger->notice('Social login: cannot start the provider departure.', [
                'provider' => $provider,
                'reason' => $exception->getTranslationKey(),
            ]);

            return $this->redirectToLoginWithMessage($exception->getTranslationKey());
        }

        $response = $this->generateRedirect($authorizationUrl);
        $response->headers->setCookie($challenge->cookie);

        return $response;
    }

    /**
     * The return from a provider.
     *
     * A `GET` return (Google, Facebook) is a same-site navigation carrying the visitor's
     * session, so it finishes here. Apple's `POST` does not: being cross-site it arrives
     * with no session cookie, and completing a sign-in on it would open a brand new
     * session whose cookie replaces the visitor's — cart included, mid-checkout included.
     * So the POST goes as far as the verified identity and hands it to
     * {@see CrossSiteCallbackHandoff}, answering `303` to a GET that does have the
     * session. The challenge is spent by this response either way.
     */
    #[Route('/callback/{provider}', name: 'callback', methods: ['GET', 'POST'])]
    public function callback(
        string $provider,
        Request $request,
        ProviderRegistry $providerRegistry,
        StateManager $stateManager,
        SocialLoginService $socialLoginService,
        CrossSiteCallbackHandoff $callbackHandoff,
        AuthenticationReturnUrl $authenticationReturnUrl,
        LoggerInterface $logger,
    ): RedirectResponse {
        $response = null;

        try {
            $state = $request->query->get('state') ?? $request->request->get('state');
            $cookieValue = $request->cookies->get(StateManager::COOKIE_NAME);

            $stateManager->validate($provider, \is_string($state) ? $state : null, $cookieValue);

            $providerInstance = $providerRegistry->getProvider($provider);
            $callbackParameters = $request->isMethod('POST') ? $request->request->all() : $request->query->all();

            $identity = $providerInstance->fetchVerifiedIdentity($callbackParameters, $this->callbackUrl($provider));

            if ($request->isMethod('POST')) {
                $handoff = $callbackHandoff->put($identity);

                $response = $this->generateRedirect(
                    $this->getRoute('sociallogin_finish', ['key' => $handoff['key']]),
                    Response::HTTP_SEE_OTHER,
                );
                $response->headers->setCookie($this->handoffCookie($handoff['bindingSecret'], time() + self::HANDOFF_COOKIE_LIFETIME_SECONDS));
            } else {
                $response = $this->outcomeResponse($socialLoginService->completeLogin($identity), $authenticationReturnUrl);
            }
        } catch (SocialLoginException $exception) {
            $logger->notice('Social login: callback refused.', $this->refusalContext($exception, ['provider' => $provider]));

            $response = $this->loginErrorResponse($request, $exception->getTranslationKey());
        } catch (CustomerNotEnabledException) {
            $logger->notice('Social login: callback matched a disabled account.', ['provider' => $provider]);

            $response = $this->loginErrorResponse($request, SocialLoginNotices::ACCOUNT_ACTIVATION_REQUIRED);
        } catch (\Throwable $throwable) {
            // Anything this controller did not foresee. It lands here rather than as a 500
            // for one reason above readability: a 500 leaves the state cookie in place, and
            // a challenge that survives its own callback can be presented again.
            $logger->error('Social login: callback failed unexpectedly.', [
                'provider' => $provider,
                'exception' => $throwable,
            ]);

            $response = $this->loginErrorResponse($request, SocialLoginNotices::UNEXPECTED_ERROR);
        } finally {
            // Every callback response, success or refusal, spends the challenge: a replayed
            // callback must find no nonce left to match.
            $response?->headers->setCookie($stateManager->clearCookie());
        }

        return $response;
    }

    /**
     * Where Apple's POST lands the visitor: a plain GET, same-site, so the session this
     * signs into is the one the visitor already had.
     */
    #[Route('/finish/{key}', name: 'finish', requirements: ['key' => '[0-9a-f]{64}'], methods: ['GET'])]
    public function finish(
        string $key,
        Request $request,
        CrossSiteCallbackHandoff $callbackHandoff,
        ProviderRegistry $providerRegistry,
        SocialLoginService $socialLoginService,
        AuthenticationReturnUrl $authenticationReturnUrl,
        LoggerInterface $logger,
    ): RedirectResponse {
        $response = null;

        try {
            $bindingSecret = $request->cookies->get(self::HANDOFF_COOKIE_NAME);

            $identity = $callbackHandoff->consume($key, \is_string($bindingSecret) ? $bindingSecret : null);

            // The provider may have been turned off between the POST that parked the
            // identity and this GET: re-read the registry so a disabled provider cannot be
            // signed in on an entry it was still configured for a moment ago. A refusal
            // here is a SocialLoginException like any other.
            $providerRegistry->getProvider($identity->provider);

            $response = $this->outcomeResponse($socialLoginService->completeLogin($identity), $authenticationReturnUrl);
        } catch (SocialLoginException $exception) {
            $logger->notice('Social login: handoff refused.', $this->refusalContext($exception));

            $response = $this->redirectToLoginWithMessage($exception->getTranslationKey());
        } catch (CustomerNotEnabledException) {
            $logger->notice('Social login: handoff matched a disabled account.');

            $response = $this->redirectToLoginWithMessage(SocialLoginNotices::ACCOUNT_ACTIVATION_REQUIRED);
        } catch (\Throwable $throwable) {
            $logger->error('Social login: handoff failed unexpectedly.', ['exception' => $throwable]);

            $response = $this->redirectToLoginWithMessage(SocialLoginNotices::UNEXPECTED_ERROR);
        } finally {
            // The binding cookie is single-use: cleared on every outcome, success or
            // refusal, so it cannot be presented a second time.
            $response?->headers->setCookie($this->handoffCookie('', 1));
        }

        return $response;
    }

    /**
     * The three ways a sign-in can end, and where each one sends the visitor.
     */
    private function outcomeResponse(SocialLoginOutcome $outcome, AuthenticationReturnUrl $authenticationReturnUrl): RedirectResponse
    {
        return match ($outcome->status) {
            SocialLoginOutcomeStatus::LoggedIn => $this->generateRedirect(
                $authenticationReturnUrl->consume($this->getRoute('account_index')),
            ),
            SocialLoginOutcomeStatus::AttachmentRequired => $this->generateRedirectFromRoute('sociallogin_attach'),
            SocialLoginOutcomeStatus::AccountActivationRequired => $this->redirectToLoginWithMessage(
                SocialLoginNotices::ACCOUNT_ACTIVATION_REQUIRED,
            ),
        };
    }

    #[Route('/attach', name: 'attach', methods: ['GET'])]
    public function attach(IdentityAttachmentService $identityAttachmentService): Response
    {
        try {
            $pendingEmail = $identityAttachmentService->getPendingEmail();
        } catch (PendingIdentityNotFoundException $exception) {
            return $this->redirectToLoginWithMessage($exception->getTranslationKey());
        }

        return $this->render('@SocialLoginModule/front/attach.html.twig', [
            'pendingEmail' => $pendingEmail,
        ]);
    }

    #[Route('/attach', name: 'attach_submit', methods: ['POST'])]
    public function attachSubmit(IdentityAttachmentService $identityAttachmentService, LoggerInterface $logger): RedirectResponse
    {
        $form = $this->createForm(FrontAttachPasswordForm::getName());
        $message = null;

        try {
            $validatedForm = $this->validateForm($form, 'post');

            $identityAttachmentService->attach((string) $validatedForm->get('password')->getData());

            return $this->generateSuccessRedirect($form) ?? $this->generateRedirectFromRoute('account_index');
        } catch (PendingIdentityNotFoundException $exception) {
            return $this->redirectToLoginWithMessage($exception->getTranslationKey());
        } catch (FormValidationException $exception) {
            $message = $this->getTranslator()->trans('Please check your input: %s', ['%s' => $exception->getMessage()]);
        } catch (SocialLoginException $exception) {
            $message = $this->getTranslator()->trans($exception->getTranslationKey(), [], SocialLogin::DOMAIN_NAME);

            $logger->notice('Social login: attachment refused.', ['reason' => $exception->getTranslationKey()]);
        } catch (CustomerNotEnabledException) {
            return $this->redirectToLoginWithMessage(SocialLoginNotices::ACCOUNT_ACTIVATION_REQUIRED);
        }

        $form->setErrorMessage($message);
        $this->getParserContext()->addForm($form);

        if ($form->hasErrorUrl()) {
            return $this->generateErrorRedirect($form) ?? $this->generateRedirectFromRoute('sociallogin_attach');
        }

        return $this->generateRedirectFromRoute('sociallogin_attach');
    }

    #[Route('/attach/cancel', name: 'attach_cancel', methods: ['POST'])]
    public function attachCancel(
        Request $request,
        CsrfTokenManagerInterface $csrfTokenManager,
        IdentityAttachmentService $identityAttachmentService,
    ): RedirectResponse {
        $token = new CsrfToken(self::ATTACH_CANCEL_CSRF_TOKEN_ID, (string) $request->request->get('_token'));

        if ($csrfTokenManager->isTokenValid($token)) {
            $identityAttachmentService->cancel();
        }

        return $this->generateRedirectFromRoute('customer_login');
    }

    #[Route('/detach/{identityId}', name: 'detach', requirements: ['identityId' => '\d+'], methods: ['POST'])]
    public function detach(
        int $identityId,
        Request $request,
        CsrfTokenManagerInterface $csrfTokenManager,
        IdentityDetachmentService $identityDetachmentService,
        LoggerInterface $logger,
    ): RedirectResponse {
        $this->checkAuth();

        $customer = $this->getSecurityContext()->getCustomerUser();

        if (!$customer instanceof Customer) {
            throw new AccessDeniedHttpException();
        }

        $token = new CsrfToken(self::DETACH_CSRF_TOKEN_ID, (string) $request->request->get('_token'));

        if (!$csrfTokenManager->isTokenValid($token)) {
            throw new AccessDeniedHttpException();
        }

        $password = $request->request->get('password');

        // Ownership is the service's to check, on the read it acts on and inside the
        // transaction it acts in: a check made here would be a second, earlier read of the
        // same thing, true at a moment that is not the moment of the delete. What comes
        // back when the identity is not this account's is IdentityNotOwnedException, told
        // to the visitor exactly like any other refusal.
        try {
            $identityDetachmentService->detach(
                $customer,
                $identityId,
                \is_string($password) && '' !== $password ? $password : null,
            );

            return $this->generateRedirectFromRoute('account_index', ['sociallogin_notice' => 'detached']);
        } catch (SocialLoginException $exception) {
            $logger->notice('Social login: detachment refused.', [
                'customer_id' => $customer->getId(),
                'identity_id' => $identityId,
                'reason' => $exception->getTranslationKey(),
            ]);

            return $this->generateRedirectFromRoute('account_index', ['sociallogin_notice' => $exception->getTranslationKey()]);
        }
    }

    private function callbackUrl(string $providerCode): string
    {
        return $this->getRoute('sociallogin_callback', ['provider' => $providerCode]);
    }

    private function handoffCookie(string $value, int $expiresAt): Cookie
    {
        return Cookie::create(
            name: self::HANDOFF_COOKIE_NAME,
            value: $value,
            expire: $expiresAt,
            path: self::HANDOFF_COOKIE_PATH,
            secure: true,
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    /**
     * A refusal is logged by its translation key, and — when it wraps a cause — by that
     * cause's class and message too: a provider exchange refused as `invalid_client`
     * carries the reason in its `previous`, and without it a misconfiguration is
     * undiagnosable in production. The provider exceptions already wrap their cause, whose
     * message is a status or a code, never a token or a secret.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function refusalContext(SocialLoginException $exception, array $extra = []): array
    {
        $context = $extra + ['reason' => $exception->getTranslationKey()];

        $previous = $exception->getPrevious();

        if (null !== $previous) {
            $context['cause'] = $previous::class.': '.$previous->getMessage();
        }

        return $context;
    }

    /**
     * A refusal on the callback must not touch the session: Apple's cross-site POST
     * arrives without the session cookie, so a flash there would open a fresh session
     * whose cookie replaces the visitor's one, cart included. The message travels in
     * the query string instead and the login page renders it from a whitelist.
     */
    private function loginErrorResponse(Request $request, string $translationKey): RedirectResponse
    {
        if (!$request->isMethod('POST')) {
            return $this->redirectToLoginWithMessage($translationKey);
        }

        return $this->generateRedirect(
            $this->getRoute('customer_login', ['sociallogin_notice' => $translationKey]),
        );
    }

    private function redirectToLoginWithMessage(string $translationKey): RedirectResponse
    {
        $this->addFlash('information', $this->getTranslator()->trans($translationKey, [], SocialLogin::DOMAIN_NAME));

        return $this->generateRedirectFromRoute('customer_login');
    }
}
