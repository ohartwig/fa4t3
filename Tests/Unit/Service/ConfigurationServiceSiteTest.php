<?php

declare(strict_types=1);

namespace Moselwal\FA4T3\Tests\Unit\Service;

use Moselwal\FA4T3\Service\ConfigurationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Die site-bezogenen Teile der Konfiguration.
 *
 * ConfigurationServiceTest deckt die globalen Einstellungen und den
 * API-Schluessel ab. Hier geht es um das, was pro Site entschieden wird — und
 * um die Freigabe-URL, die als einzige Methode dieser Klasse wirklich rechnet.
 */
class ConfigurationServiceSiteTest extends TestCase
{
    /**
     * Die Freigabe-URL bekommt das Passwort als SHA-256 angehaengt, nicht im
     * Klartext.
     *
     * Diese URL landet im Backend-Modul und damit im Browserverlauf, in
     * Proxy-Logs und potenziell in einem Screenshot. Faellt das Hashing weg,
     * steht das Fathom-Freigabepasswort dort im Klartext — und dass die Seite
     * trotzdem laedt, macht den Fehler unsichtbar.
     */
    #[Test]
    public function theSharePasswordIsHashedNeverAppendedInClear(): void
    {
        $site = $this->site([
            'fa4t3ShareUrl' => 'https://app.usefathom.com/share/abc',
            'fa4t3SharePassword' => 'geheim',
        ]);

        $url = $this->subject()->getShareUrl($site);

        self::assertStringNotContainsString('geheim', $url);
        self::assertStringContainsString('password=' . hash('sha256', 'geheim'), $url);
    }

    /**
     * Traegt die URL schon einen Parameter, wird mit & angehaengt, sonst mit ?.
     *
     * Ein festes "?" wuerde die zweite Frage im String erzeugen; der
     * Fathom-Server nimmt die URL dann entgegen und ignoriert das Passwort —
     * die Freigabe schlaegt fehl, ohne dass irgendwo ein Fehler steht.
     */
    #[Test]
    public function theSeparatorFollowsTheUrlNotAConvention(): void
    {
        $ohneQuery = $this->subject()->getShareUrl($this->site([
            'fa4t3ShareUrl' => 'https://example.com/share',
            'fa4t3SharePassword' => 'x',
        ]));
        $mitQuery = $this->subject()->getShareUrl($this->site([
            'fa4t3ShareUrl' => 'https://example.com/share?theme=dark',
            'fa4t3SharePassword' => 'x',
        ]));

        self::assertStringContainsString('/share?password=', $ohneQuery);
        self::assertStringContainsString('&password=', $mitQuery);
        self::assertStringNotContainsString('??', $mitQuery);
    }

    #[Test]
    public function noShareUrlMeansNoPasswordEither(): void
    {
        $site = $this->site(['fa4t3SharePassword' => 'geheim']);

        self::assertSame('', $this->subject()->getShareUrl($site));
    }

    #[Test]
    public function aShareUrlWithoutPasswordStaysUntouched(): void
    {
        $site = $this->site(['fa4t3ShareUrl' => 'https://example.com/share']);

        self::assertSame('https://example.com/share', $this->subject()->getShareUrl($site));
    }

    /**
     * Eine NullSite ist TYPO3s Platzhalter fuer "keine Site". Jede
     * site-bezogene Frage muss darauf leer antworten statt in eine
     * Konfiguration zu greifen, die es nicht gibt.
     */
    #[Test]
    public function aNullSiteAnswersEmptyEverywhere(): void
    {
        $subject = $this->subject();
        $null = new NullSite();

        self::assertSame('', $subject->getSiteId($null));
        self::assertSame('', $subject->getShareUrl($null));
        self::assertFalse($subject->isConfigured($null));
    }

    /**
     * Bei einer NullSite faellt der API-Schluessel auf den globalen zurueck —
     * anders als die uebrigen Werte.
     *
     * Das ist Absicht: der Schluessel gehoert zum Zugang, nicht zur Site.
     */
    #[Test]
    public function aNullSiteStillGetsTheGlobalApiKey(): void
    {
        $subject = $this->subject(['apiKey' => 'global-key']);

        self::assertSame('global-key', $subject->getApiKeyForSite(new NullSite()));
    }

    #[Test]
    public function theSiteIdComesFromTheSiteConfiguration(): void
    {
        $site = $this->site(['fa4t3SiteId' => 'ABCDEF']);

        self::assertSame('ABCDEF', $this->subject()->getSiteId($site));
    }

    #[Test]
    public function aSiteWithoutIdAnswersEmptyRatherThanNull(): void
    {
        self::assertSame('', $this->subject()->getSiteId($this->site()));
    }

    #[Test]
    public function theDefaultDateRangeFallsBackTo30d(): void
    {
        self::assertSame('30d', $this->subject()->getDefaultDateRange());
        self::assertSame('7d', $this->subject(['defaultDateRange' => '7d'])->getDefaultDateRange());
    }

    #[Test]
    public function hasGlobalApiKeyReflectsWhetherOneIsSet(): void
    {
        self::assertFalse($this->subject()->hasGlobalApiKey());
        self::assertTrue($this->subject(['apiKey' => 'k'])->hasGlobalApiKey());
    }

    /**
     * Ohne Site liefert die Tracking-Konfiguration abgeschaltete Vorgaben.
     *
     * Wichtig ist hier nicht die Vollstaendigkeit der Schluessel, sondern dass
     * `enabled` false ist: der Middleware entscheidet daran, ob sie ein
     * Tracking-Skript ausliefert. Ein true als Vorgabe hiesse, auf Seiten ohne
     * Site zu tracken.
     */
    #[Test]
    public function trackingIsOffWhenThereIsNoSite(): void
    {
        $config = $this->subject()->getTrackingConfig(new NullSite());

        self::assertFalse($config['enabled']);
        self::assertFalse($config['honorDnt']);
        self::assertSame('', $config['customDomain']);
    }

    #[Test]
    public function trackingFlagsComeFromTheSiteConfiguration(): void
    {
        $site = $this->site([
            'fa4t3TrackingEnabled' => true,
            'fa4t3CustomDomain' => 'stats.example.com',
            'fa4t3HonorDnt' => true,
            'fa4t3SpaMode' => 'auto',
        ]);

        $config = $this->subject()->getTrackingConfig($site);

        self::assertTrue($config['enabled']);
        self::assertSame('stats.example.com', $config['customDomain']);
        self::assertTrue($config['honorDnt']);
        self::assertSame('auto', $config['spaMode']);
    }

    /**
     * Wirft die ExtensionConfiguration — etwa weil die Extension noch nie
     * konfiguriert wurde —, faellt der Wert auf null und damit auf die
     * Vorgaben zurueck, statt den Aufruf abzubrechen.
     */
    #[Test]
    public function anUnreadableExtensionConfigurationFallsBackToDefaults(): void
    {
        $extConfig = $this->createStub(ExtensionConfiguration::class);
        $extConfig->method('get')->willThrowException(new \RuntimeException('not configured'));

        $subject = new ConfigurationService($extConfig);

        self::assertSame('', $subject->getGlobalApiKey());
        self::assertSame(300, $subject->getCacheDuration());
        self::assertSame('30d', $subject->getDefaultDateRange());
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function subject(array $settings = []): ConfigurationService
    {
        $extConfig = $this->createStub(ExtensionConfiguration::class);
        $extConfig->method('get')->willReturnCallback(
            static fn(string $ext, string $key): mixed => $settings[$key] ?? null,
        );

        return new ConfigurationService($extConfig);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function site(array $config = []): Site
    {
        return new Site('example', 1, ['base' => 'https://example.com/'] + $config);
    }
}
