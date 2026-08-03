<?php

declare(strict_types=1);

namespace Moselwal\FA4T3\Tests\Unit\Controller\Backend;

use Moselwal\FA4T3\Controller\Backend\PageDataAjaxController;
use Moselwal\FA4T3\Service\AnalyticsService;
use Moselwal\FA4T3\Service\ConfigurationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Die Abbruchpfade des Ajax-Endpunkts.
 *
 * Sie sind der Teil, der im Betrieb am haeufigsten laeuft — ein Backend-Modul
 * fragt fuer jede Seite, und die meisten Seiten gehoeren zu keiner
 * konfigurierten Site. Geprueft wird hier nicht nur DASS abgebrochen wird,
 * sondern dass die Antwort das sagt: `success: false` mit einem Grund. Ein
 * Endpunkt, der im Fehlerfall eine leere Erfolgsantwort liefert, laesst das
 * Modul eine Seite ohne Zugriffe anzeigen statt einer ohne Konfiguration.
 */
class PageDataAjaxControllerTest extends TestCase
{
    #[Test]
    public function aRequestWithoutAPageUidIsRefusedWithAReason(): void
    {
        $response = $this->subject()->handleRequest($this->request([]));

        self::assertSame(
            ['success' => false, 'error' => 'No page UID provided'],
            $this->decode($response),
        );
    }

    #[Test]
    public function aPageUidOfZeroCountsAsMissing(): void
    {
        $response = $this->subject()->handleRequest($this->request(['pageUid' => '0']));

        self::assertFalse($this->decode($response)['success']);
    }

    /**
     * Ohne Site am Request wird sie ueber den SiteFinder gesucht. Findet der
     * nichts, ist das kein Ausfall, sondern der Normalfall fuer Seiten
     * ausserhalb einer Site — und muss als solcher beantwortet werden.
     */
    #[Test]
    public function aPageOutsideAnySiteIsAnsweredNotThrown(): void
    {
        $finder = $this->createStub(SiteFinder::class);
        $finder->method('getSiteByPageId')->willThrowException(new SiteNotFoundException('nope'));

        $response = $this->subject(finder: $finder)->handleRequest($this->request(['pageUid' => '42']));

        self::assertSame(
            ['success' => false, 'error' => 'Could not resolve site'],
            $this->decode($response),
        );
    }

    /**
     * Eine NullSite ist TYPO3s Platzhalter fuer "keine Site" und darf nicht als
     * Site durchgehen — sonst faellt der Controller in die Konfigurationsabfrage
     * und stellt Fragen an ein Objekt, das keine Antworten hat.
     */
    #[Test]
    public function aNullSiteTriggersTheLookupJustLikeAMissingOne(): void
    {
        $finder = $this->createStub(SiteFinder::class);
        $finder->method('getSiteByPageId')->willThrowException(new SiteNotFoundException('nope'));

        $request = $this->request(['pageUid' => '42'], new NullSite());

        $response = $this->subject(finder: $finder)->handleRequest($request);

        self::assertSame('Could not resolve site', $this->decode($response)['error']);
    }

    #[Test]
    public function anUnconfiguredSiteIsRefusedWithItsOwnReason(): void
    {
        // Eine echte Site ohne fathom-Einstellungen. isConfigured() prueft
        // API-Schluessel und Site-ID; beide fehlen, also ist die Antwort echt
        // und nicht behauptet.
        $site = new Site('example', 1, ['base' => 'https://example.com/']);

        $response = $this->subject()
            ->handleRequest($this->request(['pageUid' => '42'], $site));

        self::assertSame(
            ['success' => false, 'error' => 'Extension not configured'],
            $this->decode($response),
        );
    }

    /**
     * ConfigurationService und AnalyticsService sind `final` und lassen sich
     * nicht doubeln — PHPUnit lehnt das mit ClassIsFinalException ab.
     *
     * Fuer den ConfigurationService ist das kein Verlust: er braucht nur eine
     * ExtensionConfiguration, und `isConfigured()` liefert fuer eine Site ohne
     * Einstellungen von selbst false. Eine echte Instanz sagt hier also mehr
     * aus als ein Doppel, das dasselbe behaupten wuerde.
     *
     * Der AnalyticsService wird in KEINEM dieser Tests aufgerufen — sie enden
     * alle vor der ersten Abfrage. Deshalb eine Instanz ohne Konstruktor: sie
     * erfuellt den Typ und wird nie benutzt. Das ist ehrlicher als ein Doppel
     * mit erfundenen Rueckgabewerten, das den Eindruck erweckte, hier wuerde
     * etwas abgefragt.
     */
    private function subject(
        ?ConfigurationService $config = null,
        ?SiteFinder $finder = null,
    ): PageDataAjaxController {
        return new PageDataAjaxController(
            $config ?? new ConfigurationService($this->createStub(ExtensionConfiguration::class)),
            (new \ReflectionClass(AnalyticsService::class))->newInstanceWithoutConstructor(),
            $finder ?? $this->createStub(SiteFinder::class),
            $this->createStub(ConnectionPool::class),
        );
    }

    /**
     * @param array<string, string> $query
     */
    private function request(array $query, ?object $site = null): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn($query);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name): mixed => 'site' === $name ? $site : null,
        );

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(object $response): array
    {
        $body = (string) $response->getBody();
        $decoded = \json_decode($body, true);

        self::assertIsArray($decoded, 'the endpoint must always answer with JSON');

        return $decoded;
    }
}
