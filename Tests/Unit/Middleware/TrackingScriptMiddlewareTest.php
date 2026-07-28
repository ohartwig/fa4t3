<?php

declare(strict_types=1);

namespace Moselwal\FA4T3\Tests\Unit\Middleware;

use Moselwal\FA4T3\Middleware\TrackingScriptMiddleware;
use Moselwal\FA4T3\Service\ConfigurationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;

class TrackingScriptMiddlewareTest extends TestCase
{
    /**
     * @param array<string, mixed> $trackingConfig internal shape, see trackingConfig()
     */
    private function buildRequest(array $trackingConfig, string $siteId): ServerRequestInterface
    {
        return $this->requestWithSite($this->site($trackingConfig, $siteId));
    }

    private function requestWithSite(?SiteInterface $site): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            static fn (string $name) => $name === 'site' ? $site : null,
        );

        return $request;
    }

    private function htmlResponse(string $body, string $contentType = 'text/html; charset=utf-8'): ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($body);
        $stream->rewind();

        return (new Response())
            ->withHeader('Content-Type', $contentType)
            ->withBody($stream);
    }

    /**
     * The real service over a real Site, not a double.
     *
     * ConfigurationService is final and cannot be doubled, but it also does no
     * I/O — it only reads the site configuration array — so a double would buy
     * nothing except a second copy of the key mapping. Going through the real
     * one means these tests also cover that mapping, which is where the
     * fa4t3-prefixed keys are actually resolved.
     */
    private function configService(): ConfigurationService
    {
        return new ConfigurationService($this->extensionConfiguration());
    }

    /**
     * Builds a Site whose configuration produces the given tracking config.
     *
     * @param array<string, mixed> $trackingConfig
     */
    private function site(array $trackingConfig, string $siteId = ''): Site
    {
        return new Site('test', 1, [
            'base' => 'https://example.org/',
            'fa4t3SiteId' => $siteId,
            'fa4t3TrackingEnabled' => $trackingConfig['enabled'] ?? false,
            'fa4t3CustomDomain' => $trackingConfig['customDomain'] ?? '',
            'fa4t3ExcludedPages' => $trackingConfig['excludedPages'] ?? '',
            'fa4t3ConsentCategory' => $trackingConfig['consentCategory'] ?? '',
            'fa4t3SpaMode' => $trackingConfig['spaMode'] ?? '',
            'fa4t3HonorDnt' => $trackingConfig['honorDnt'] ?? false,
        ]);
    }

    private function extensionConfiguration(): ExtensionConfiguration
    {
        $extConfig = $this->createMock(ExtensionConfiguration::class);
        $extConfig->method('get')->willReturn(null);

        return $extConfig;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function trackingConfig(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'customDomain' => '',
            'excludedPages' => '',
            'consentCategory' => '',
            'spaMode' => '',
            'honorDnt' => false,
        ], $overrides);
    }

    private function handlerReturning(ResponseInterface $response): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        return $handler;
    }

    #[Test]
    public function emptyConsentCategoryInjectsUngatedExecutableScript(): void
    {
        $request = $this->buildRequest($this->trackingConfig(['consentCategory' => '']), 'ABCDEF');
        $handler = $this->handlerReturning($this->htmlResponse('<html><body><p>Hi</p></body></html>'));

        $middleware = new TrackingScriptMiddleware(
            $this->configService(),
        );

        $html = (string)$middleware->process($request, $handler)->getBody();

        self::assertStringContainsString('data-consent="ignore"', $html);
        self::assertStringContainsString('src="https://cdn.usefathom.com/script.js"', $html);
        self::assertStringContainsString('data-site="ABCDEF"', $html);
        self::assertStringNotContainsString('type="text/plain"', $html);
    }

    #[Test]
    public function nonEmptyConsentCategoryInjectsAuthorGatedScript(): void
    {
        $request = $this->buildRequest($this->trackingConfig(['consentCategory' => 'analytics']), 'ABCDEF');
        $handler = $this->handlerReturning($this->htmlResponse('<html><body><p>Hi</p></body></html>'));

        $middleware = new TrackingScriptMiddleware(
            $this->configService(),
        );

        $html = (string)$middleware->process($request, $handler)->getBody();

        self::assertStringContainsString('type="text/plain"', $html);
        self::assertStringContainsString('data-category="analytics"', $html);
        self::assertStringContainsString('data-src="https://cdn.usefathom.com/script.js"', $html);
        self::assertStringContainsString('data-site="ABCDEF"', $html);
        // No executable src attribute (the URL lives in data-src instead).
        self::assertStringNotContainsString(' src="', $html);
    }

    #[Test]
    public function spaAndDntAttributesArePreservedInBothBranches(): void
    {
        foreach (['', 'analytics'] as $category) {
            $request = $this->buildRequest($this->trackingConfig([
                'consentCategory' => $category,
                'spaMode' => 'auto',
                'honorDnt' => true,
            ]), 'ABCDEF');
            $handler = $this->handlerReturning($this->htmlResponse('<html><body></body></html>'));

            $middleware = new TrackingScriptMiddleware($this->configService());

            $html = (string)$middleware->process($request, $handler)->getBody();

            self::assertStringContainsString('data-spa="auto"', $html);
            self::assertStringContainsString('data-honor-dnt="true"', $html);
        }
    }

    #[Test]
    public function noScriptInjectedWhenTrackingDisabled(): void
    {
        $request = $this->buildRequest($this->trackingConfig(['enabled' => false]), 'ABCDEF');
        $handler = $this->handlerReturning($this->htmlResponse('<html><body></body></html>'));

        $middleware = new TrackingScriptMiddleware(
            $this->configService(),
        );

        $html = (string)$middleware->process($request, $handler)->getBody();

        self::assertStringNotContainsString('<script', $html);
    }

    #[Test]
    public function noScriptInjectedWhenSiteIdEmpty(): void
    {
        $request = $this->buildRequest($this->trackingConfig(), '');
        $handler = $this->handlerReturning($this->htmlResponse('<html><body></body></html>'));

        $middleware = new TrackingScriptMiddleware(
            $this->configService(),
        );

        $html = (string)$middleware->process($request, $handler)->getBody();

        self::assertStringNotContainsString('<script', $html);
    }

    #[Test]
    public function noScriptInjectedForNonHtmlContentType(): void
    {
        $request = $this->buildRequest($this->trackingConfig(), 'ABCDEF');
        $handler = $this->handlerReturning(
            $this->htmlResponse('{"foo":"bar"}', 'application/json'),
        );

        $middleware = new TrackingScriptMiddleware(
            $this->configService(),
        );

        $html = (string)$middleware->process($request, $handler)->getBody();

        self::assertStringNotContainsString('<script', $html);
    }
}
