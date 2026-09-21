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

namespace SocialLogin\Tests\Integration\Controller;

use SocialLogin\Controller\Admin\ConfigurationController;
use SocialLogin\Form\SocialLoginConfigurationForm;
use SocialLogin\Provider\ProviderRegistry;
use SocialLogin\Provider\SocialLoginProviderInterface;
use SocialLogin\Service\SocialLoginConfiguration;
use SocialLogin\SocialLogin;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Template\ParserContext;
use Thelia\Core\Translation\Translator;
use Thelia\Model\Map\ModuleConfigI18nTableMap;
use Thelia\Model\Map\ModuleConfigTableMap;
use Thelia\Model\ModuleConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The save of the back office configuration screen, driven through the screen itself: the
 * form is read from the rendered page and submitted as a browser would.
 *
 * A refused save used to come back to the screen with nothing said and nothing saved, the
 * error being set in a request the redirect had already left behind.
 */
final class ConfigurationControllerTest extends WebIntegrationTestCase
{
    private const string SCREEN_URL = '/admin/module/SocialLogin';

    private const string FORM_NAME = 'sociallogin_configuration_form';

    private const string VALID_LOGO = '<svg viewBox="0 0 24 24" onload="alert(1)"><path d="M12 2 2 22h20z"/></svg>';

    private const string TEST_WITHOUT_OUTER_TRANSACTION = 'testAFailureOnALaterProviderRollsBackAnEarlierProvidersWrite';

    private AdminSessionInjector $injector;

    /**
     * A redirect leaves the request that wrote to the session behind: BrowserKit's
     * request/session objects are gone by the time control returns to the test, and the
     * redisplay that follows the redirect reads and clears the very data a test on the
     * session itself needs to see untouched. Captured on kernel.response, while the
     * session is still attached to the request that is about to be redirected.
     *
     * @var array<string, mixed>|null
     */
    private ?array $capturedFormErrorInformation = null;

    protected function setUp(): void
    {
        // The one test below asserts a real commit/rollback: WebIntegrationTestCase's own
        // transaction, opened before the test runs, would otherwise nest inside it and
        // defer the actual rollback to tearDown(), past the point the test reads it back.
        $this->useTransaction = self::TEST_WITHOUT_OUTER_TRANSACTION !== $this->name();

        parent::setUp();

        // The transaction-less test below drives the method under test directly (no HTTP,
        // no admin firewall), and runs with real commits: an admin fixture here would be
        // a real, permanent row on every run (FixtureFactory's login counter is not undone
        // by a rollback that never happens), for a login this test never needs.
        if ($this->useTransaction) {
            $this->injector = new AdminSessionInjector();
            $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

            $this->getService(EventDispatcherInterface::class)->addListener(
                KernelEvents::RESPONSE,
                function (ResponseEvent $event): void {
                    if (!$event->isMainRequest() || 'POST' !== $event->getRequest()->getMethod()) {
                        return;
                    }

                    $session = $event->getRequest()->getSession();
                    self::assertInstanceOf(Session::class, $session, 'The Thelia session must be the one bound to the request.');

                    $this->capturedFormErrorInformation = $session->getFormErrorInformation();
                },
            );

            // FixtureFactory built directly: createFixtureFactory() pushes a synthetic request
            // the security context would then read the session from on every client request.
            $admin = (new FixtureFactory($this->getPropelConnection()))->admin();
            $admin->eraseCredentials();
            $this->injector->setAdmin($admin);
        }

        SocialLogin::setConfigValue('google_client_id', 'stored-client-id');
        SocialLogin::setConfigValue('google_client_secret', 'stored-client-secret');
        SocialLogin::setConfigValue('google_logo_svg', '<svg viewBox="0 0 1 1"><path d="M0 0h1v1z"/></svg>');
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->injector->clear();
        }

        // The one test run without the outer transaction commits for real: whatever it
        // wrote (or would have left behind, had the rollback under test failed) is undone
        // here rather than by the transaction the other tests rely on.
        if (self::TEST_WITHOUT_OUTER_TRANSACTION === $this->name()) {
            $moduleId = SocialLogin::getModuleId();
            ModuleConfigQuery::create()->filterByModuleId($moduleId)->filterByName('google_enabled')->delete();
            ModuleConfigQuery::create()->filterByModuleId($moduleId)->filterByName('poison_enabled')->delete();
        }

        parent::tearDown();
        ModuleConfigQuery::resetConfigCache();
    }

    public function testASecretSubmittedEmptyKeepsTheStoredOne(): void
    {
        $this->submitConfiguration([
            'google_client_id' => 'new-client-id',
            'google_client_secret' => '',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertSame('new-client-id', $this->storedValue('google_client_id'));
        self::assertSame('stored-client-secret', $this->storedValue('google_client_secret'));
    }

    public function testAValidLogoIsStoredSanitized(): void
    {
        $this->submitConfiguration(['google_logo_svg' => self::VALID_LOGO]);

        $storedLogo = (string) $this->storedValue('google_logo_svg');

        self::assertStringContainsStringIgnoringCase('<path', $storedLogo);
        self::assertStringNotContainsStringIgnoringCase('onload', $storedLogo);
    }

    public function testAnEmptyLogoClearsTheStoredOne(): void
    {
        $this->submitConfiguration(['google_logo_svg' => '']);

        self::assertSame('', (string) $this->storedValue('google_logo_svg'));
    }

    /**
     * The scenario of the report: a logo that cannot be kept, sent along with a changed
     * credential. Nothing of the submission is written, and the screen says why.
     */
    public function testARefusedLogoWritesNothingAndSaysWhy(): void
    {
        $this->submitConfiguration([
            'google_client_id' => 'new-client-id',
            'google_client_secret' => 'new-client-secret',
            'google_logo_svg' => '<p>not a logo</p>',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertSame('stored-client-id', $this->storedValue('google_client_id'));
        self::assertSame('stored-client-secret', $this->storedValue('google_client_secret'));
        self::assertStringContainsString('M0 0h1v1z', (string) $this->storedValue('google_logo_svg'));

        // The kernel is not rebooted between requests here, so the parser context still
        // holds the form of the POST in memory. A real redirect lands in a new request that
        // only has the session: forget the in-memory copy so that is the path exercised.
        $this->getService(ParserContext::class)->remove(SocialLoginConfigurationForm::class.':'.FormType::class);

        $page = $this->client->followRedirect();
        $refusal = Translator::getInstance()->trans(SocialLoginConfigurationForm::INVALID_LOGO_SVG_MESSAGE, [], SocialLogin::DOMAIN_NAME);
        $logoLabel = Translator::getInstance()->trans('%provider% logo (SVG)', ['%provider%' => 'Google'], SocialLogin::DOMAIN_NAME);

        $flash = $page->filter('[data-testid="bo-flash-danger"]');
        self::assertCount(1, $flash, 'The refusal must be shown above the page.');
        self::assertStringContainsString($refusal, $flash->text());
        self::assertStringContainsString($logoLabel, $flash->text(), 'The message must name the field by its label.');
        self::assertStringNotContainsString('google_logo_svg', $flash->text());

        $logoError = $page->filter(\sprintf('#%s_google_logo_svg ~ .invalid-feedback', self::FORM_NAME));
        self::assertCount(1, $logoError, 'The refusal must be shown under the logo field.');
        self::assertStringContainsString($refusal, $logoError->text());

        self::assertSame(
            'new-client-id',
            $page->filter(\sprintf('#%s_google_client_id', self::FORM_NAME))->attr('value'),
            'The other fields must keep what was typed.',
        );
        self::assertSame(
            '',
            (string) $page->filter(\sprintf('#%s_google_client_secret', self::FORM_NAME))->attr('value'),
            'A secret is never carried back to the browser, not even the one just typed.',
        );
    }

    /**
     * The submitted form, when it is still in memory (a runtime that keeps the service
     * between requests), carries the secret that was typed: the screen blanks it anyway.
     */
    public function testATypedSecretIsNotPrintedBackFromAFormStillInMemory(): void
    {
        $this->submitConfiguration([
            'google_client_secret' => 'new-client-secret',
            'google_logo_svg' => '<p>not a logo</p>',
        ]);

        $page = $this->client->followRedirect();

        self::assertSame('', (string) $page->filter(\sprintf('#%s_google_client_secret', self::FORM_NAME))->attr('value'));
        self::assertStringNotContainsString('new-client-secret', (string) $this->client->getResponse()->getContent());
    }

    /**
     * A typed secret used to survive a refused save in clear text wherever the session is
     * persisted (file, database, Redis), even though the screen itself never echoes it
     * back: ParserContext::addForm() writes the whole submitted form data to session, as
     * a redisplay is not a request that has this happen.
     */
    public function testASecretSubmittedOnARefusedSaveIsNotKeptInSession(): void
    {
        $this->submitConfiguration([
            'google_client_secret' => 'new-client-secret',
            'google_logo_svg' => '<p>not a logo</p>',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        self::assertIsArray($this->capturedFormErrorInformation, 'The response listener must have captured the session state.');
        $formKey = SocialLoginConfigurationForm::class.':'.FormType::class;

        self::assertArrayHasKey($formKey, $this->capturedFormErrorInformation, 'The refused form must still be tracked for redisplay.');
        self::assertSame(
            '',
            $this->capturedFormErrorInformation[$formKey]['data']['google_client_secret'] ?? null,
            'A typed secret must not be kept in the session data.',
        );
        self::assertStringNotContainsString(
            'new-client-secret',
            serialize($this->capturedFormErrorInformation),
            'No trace of the typed secret anywhere in what is written to the session.',
        );
    }

    /**
     * A provider toggle unticked in the same submission as a refused logo must come back
     * unticked, not silently re-checked because the stored, still-enabled value leaked
     * back in through the redisplay.
     */
    public function testDecheckingAnEnabledProviderInARefusedSubmissionIsNotCheckedOnRedisplay(): void
    {
        SocialLogin::setConfigValue('google_enabled', '1');

        $this->submitConfiguration(
            ['google_logo_svg' => '<p>not a logo</p>'],
            uncheck: ['google_enabled'],
        );

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->getService(ParserContext::class)->remove(SocialLoginConfigurationForm::class.':'.FormType::class);

        $page = $this->client->followRedirect();

        $checkbox = $page->filter(\sprintf('#%s_google_enabled', self::FORM_NAME));
        self::assertCount(1, $checkbox, 'The provider toggle must be rendered.');
        self::assertNull($checkbox->attr('checked'), 'A toggle unticked in the refused submission must not come back checked.');
    }

    /**
     * The blocks follow the order the merchant saves, the one the storefront buttons follow
     * too: both are read from the registry.
     */
    public function testSavedPositionsReorderTheProviderBlocks(): void
    {
        $this->submitConfiguration([
            'google_position' => '1',
            'facebook_position' => '3',
            'apple_position' => '2',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertSame('1', $this->storedValue('google_position'));

        $page = $this->client->followRedirect();

        self::assertSame(['google', 'apple', 'facebook'], $this->providerBlockOrder($page));
    }

    public function testEmptyingAPositionPutsTheProviderBackInTheDefaultOrder(): void
    {
        $defaultOrder = $this->providerBlockOrder($this->client->request('GET', self::SCREEN_URL));

        SocialLogin::setConfigValue('google_position', '0');
        $movedOrder = $this->providerBlockOrder($this->client->request('GET', self::SCREEN_URL));
        self::assertSame('google', $movedOrder[0], 'A position must move the provider first before the test can undo it.');

        $this->submitConfiguration(['google_position' => '']);

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertSame('', (string) $this->storedValue('google_position'));

        $page = $this->client->followRedirect();

        self::assertSame($defaultOrder, $this->providerBlockOrder($page));
    }

    /**
     * A refused display order goes through the same redisplay as a refused logo: nothing
     * written, the message under the field, and what was typed kept.
     */
    public function testARefusedPositionWritesNothingAndIsShownUnderItsField(): void
    {
        $this->submitConfiguration([
            'google_client_id' => 'new-client-id',
            'google_position' => '-1',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertSame('stored-client-id', $this->storedValue('google_client_id'));
        self::assertNull($this->storedValue('google_position'));

        $this->getService(ParserContext::class)->remove(SocialLoginConfigurationForm::class.':'.FormType::class);

        $page = $this->client->followRedirect();
        $refusal = Translator::getInstance()->trans(SocialLoginConfigurationForm::INVALID_POSITION_MESSAGE, [], SocialLogin::DOMAIN_NAME);

        $positionError = $page->filter(\sprintf('#%s_google_position ~ .invalid-feedback', self::FORM_NAME));
        self::assertCount(1, $positionError, 'The refusal must be shown under the display order field.');
        self::assertStringContainsString($refusal, $positionError->text());
        self::assertSame('-1', $page->filter(\sprintf('#%s_google_position', self::FORM_NAME))->attr('value'));
        self::assertSame('new-client-id', $page->filter(\sprintf('#%s_google_client_id', self::FORM_NAME))->attr('value'));
    }

    /**
     * @return list<string> provider codes, in the order their blocks appear on the screen
     */
    private function providerBlockOrder(Crawler $page): array
    {
        return $page
            ->filter(\sprintf('form[name="%s"] input[name$="_position]"]', self::FORM_NAME))
            ->each(static fn (Crawler $input): string => (string) preg_replace('/^.*\[(.+)_position\]$/', '$1', (string) $input->attr('name')));
    }

    /**
     * The provider loop used to run outside any transaction: a failure on one provider's
     * fields still left every provider processed before it written. A real provider is
     * paired with a double that fails on its own field list, on a private call to the
     * method under test — the same one the HTTP flow calls — bypassing the controller's
     * own error handling to see the raw failure the transaction must survive.
     */
    public function testAFailureOnALaterProviderRollsBackAnEarlierProvidersWrite(): void
    {
        $googleProvider = $this->getService(ProviderRegistry::class)->getAllProviders()[SocialLogin::PROVIDER_GOOGLE];

        $registry = new ProviderRegistry([
            $googleProvider,
            $this->providerThatFailsToListItsFields(),
        ], $this->getService(SocialLoginConfiguration::class));

        $controller = new ConfigurationController();
        $saveProviderConfiguration = new \ReflectionMethod($controller, 'saveProviderConfiguration');

        try {
            $saveProviderConfiguration->invoke($controller, [
                'google_client_id' => 'rolled-back-client-id',
                'google_enabled' => true,
            ], $registry);

            self::fail('The double must have raised its exception.');
        } catch (\RuntimeException $exception) {
            self::assertSame('poisoned provider', $exception->getMessage());
        }

        // A rollback is a database-level fact: it never reaches back into a PHP object
        // already sitting in Propel's instance pool from the write this test just made,
        // which resetConfigCache() alone does not clear. Left in place, the pooled object
        // (the row and, since the value lives in module_config_i18n, its translation) would
        // answer with the value this test wrote and never committed, passing whether or
        // not the transaction actually rolled anything back.
        ModuleConfigTableMap::clearInstancePool();
        ModuleConfigI18nTableMap::clearInstancePool();

        self::assertSame(
            'stored-client-id',
            $this->storedValue('google_client_id'),
            "Google's write, made before the failing provider, must be rolled back with it.",
        );
    }

    private function providerThatFailsToListItsFields(): SocialLoginProviderInterface
    {
        return new class implements SocialLoginProviderInterface {
            public function getCode(): string
            {
                return 'poison';
            }

            public function getLabel(): string
            {
                return 'Poison';
            }

            public function getEnabledFieldName(): string
            {
                return 'poison_enabled';
            }

            public function getLogoFieldName(): string
            {
                return 'poison_logo_svg';
            }

            public function getPositionFieldName(): string
            {
                return 'poison_position';
            }

            public function getCredentialFieldNames(): array
            {
                throw new \RuntimeException('poisoned provider');
            }

            public function getSecretFieldNames(): array
            {
                return [];
            }

            public function isConfigured(): bool
            {
                return false;
            }

            public function getAuthorizationUrl(string $state, string $callbackUrl): string
            {
                throw new \RuntimeException('poisoned provider');
            }

            public function fetchVerifiedIdentity(array $callbackParameters, string $callbackUrl): \SocialLogin\DTO\VerifiedIdentity
            {
                throw new \RuntimeException('poisoned provider');
            }
        };
    }

    /**
     * @param array<string, string> $values  field name => submitted value
     * @param list<string>          $uncheck checkbox fields to untick before submitting
     */
    private function submitConfiguration(array $values, array $uncheck = []): void
    {
        $this->client->catchExceptions(false);
        $crawler = $this->client->request('GET', self::SCREEN_URL);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $form = $this->configurationForm($crawler)->form();

        foreach ($values as $fieldName => $value) {
            $form[\sprintf('%s[%s]', self::FORM_NAME, $fieldName)] = $value;
        }

        foreach ($uncheck as $fieldName) {
            $field = $form[\sprintf('%s[%s]', self::FORM_NAME, $fieldName)];
            self::assertInstanceOf(ChoiceFormField::class, $field, \sprintf('"%s" must be a checkbox to be unticked.', $fieldName));

            $field->untick();
        }

        $this->client->submit($form);
    }

    private function configurationForm(Crawler $crawler): Crawler
    {
        $form = $crawler->filter(\sprintf('form[name="%s"]', self::FORM_NAME));
        self::assertCount(1, $form, 'The configuration screen must render the configuration form.');

        return $form;
    }

    private function storedValue(string $configKey): ?string
    {
        ModuleConfigQuery::resetConfigCache();

        return SocialLogin::getConfigValue($configKey);
    }
}
