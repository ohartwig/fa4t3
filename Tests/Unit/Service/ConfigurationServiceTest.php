<?php

declare(strict_types=1);

namespace Moselwal\FA4T3\Tests\Unit\Service;

use Moselwal\FA4T3\Service\ConfigurationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\Entity\Site;

class ConfigurationServiceTest extends TestCase
{
    /**
     * A real Site, not a SiteInterface double.
     *
     * getConfiguration() is declared on the concrete Site, not on the
     * interface, so the double could not be given the method at all. It could
     * not have reached the code under test either: every method here refuses
     * anything that is not a Site before it reads the configuration.
     *
     * The keys are the fa4t3-prefixed ones the production code and the live
     * site configurations both use. These tests still carried the fathom-
     * prefixed names from before the rename, so they described settings
     * nothing reads.
     *
     * @param array<string, mixed> $configuration
     */
    private function site(array $configuration): Site
    {
        return new Site('test', 1, array_merge(['base' => 'https://example.org/'], $configuration));
    }

    #[Test]
    public function getGlobalApiKeyReturnsConfiguredKey(): void
    {
        $extConfig = $this->createMock(ExtensionConfiguration::class);
        $extConfig->method('get')->willReturnMap([
            ['fa4t3', 'apiKey', 'test-api-key'],
        ]);

        $service = new ConfigurationService($extConfig);

        self::assertSame('test-api-key', $service->getGlobalApiKey());
    }

    #[Test]
    public function getCacheDurationReturnsDefaultWhenNotConfigured(): void
    {
        $extConfig = $this->createMock(ExtensionConfiguration::class);
        $extConfig->method('get')->willReturn(null);

        $service = new ConfigurationService($extConfig);

        self::assertSame(300, $service->getCacheDuration());
    }

    #[Test]
    public function getApiKeyForSiteReturnsSiteOverrideWhenSet(): void
    {
        $extConfig = $this->createMock(ExtensionConfiguration::class);
        $extConfig->method('get')->willReturnMap([
            ['fa4t3', 'apiKey', 'global-key'],
        ]);

        $site = $this->site(['fa4t3ApiKeyOverride' => 'site-specific-key']);

        $service = new ConfigurationService($extConfig);

        self::assertSame('site-specific-key', $service->getApiKeyForSite($site));
    }

    #[Test]
    public function getApiKeyForSiteFallsBackToGlobalWhenNoOverride(): void
    {
        $extConfig = $this->createMock(ExtensionConfiguration::class);
        $extConfig->method('get')->willReturnMap([
            ['fa4t3', 'apiKey', 'global-key'],
        ]);

        $site = $this->site(['fa4t3ApiKeyOverride' => '']);

        $service = new ConfigurationService($extConfig);

        self::assertSame('global-key', $service->getApiKeyForSite($site));
    }

    #[Test]
    public function isConfiguredReturnsTrueWhenBothKeyAndSiteIdSet(): void
    {
        $extConfig = $this->createMock(ExtensionConfiguration::class);
        $extConfig->method('get')->willReturnMap([
            ['fa4t3', 'apiKey', 'test-key'],
        ]);

        $site = $this->site(['fa4t3SiteId' => 'ABCDEF', 'fa4t3ApiKeyOverride' => '']);

        $service = new ConfigurationService($extConfig);

        self::assertTrue($service->isConfigured($site));
    }

    #[Test]
    public function isConfiguredReturnsFalseWhenSiteIdMissing(): void
    {
        $extConfig = $this->createMock(ExtensionConfiguration::class);
        $extConfig->method('get')->willReturnMap([
            ['fa4t3', 'apiKey', 'test-key'],
        ]);

        $site = $this->site(['fa4t3SiteId' => '', 'fa4t3ApiKeyOverride' => '']);

        $service = new ConfigurationService($extConfig);

        self::assertFalse($service->isConfigured($site));
    }

    #[Test]
    public function getTrackingConfigReturnsCorrectDefaults(): void
    {
        $extConfig = $this->createMock(ExtensionConfiguration::class);

        $site = $this->site([]);

        $service = new ConfigurationService($extConfig);
        $config = $service->getTrackingConfig($site);

        self::assertFalse($config['enabled']);
        self::assertSame('', $config['customDomain']);
        self::assertSame('', $config['consentCategory']);
        self::assertFalse($config['honorDnt']);
    }
}
