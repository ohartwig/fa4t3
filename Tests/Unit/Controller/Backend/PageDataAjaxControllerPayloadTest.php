<?php

declare(strict_types=1);

namespace Moselwal\FA4T3\Tests\Unit\Controller\Backend;

use Doctrine\DBAL\Result;
use Moselwal\FA4T3\Controller\Backend\PageDataAjaxController;
use Moselwal\FA4T3\Domain\Model\AggregationResult;
use Moselwal\FA4T3\Service\AnalyticsService;
use Moselwal\FA4T3\Service\ConfigurationService;
use Moselwal\FA4T3\Service\Fa4t3ApiClientInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\PageRouter;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Die Antwort, die das Seitenmodul tatsaechlich anzeigt.
 *
 * PageDataAjaxControllerTest deckt die Abbruchpfade ab — hier geht es um den
 * Fall, in dem der Endpunkt Zahlen liefert. Er ist der aufwendigste des
 * Endpunkts: pro Sprache eine Existenzpruefung in der Datenbank, eine
 * Adressaufloesung ueber den Router und eine Abfrage an Fathom.
 *
 * Zwei Dinge muessen dabei stimmen, und beide fallen im Betrieb nicht auf:
 *
 * Eine Sprache, in der es die Seite nicht gibt, darf nicht als Zeile mit
 * Nullwerten erscheinen — das laese sich nicht von einer uebersetzten Seite
 * ohne Zugriffe unterscheiden.
 *
 * Und ein Fehler der API darf nicht als Null durchgehen. Eine Zeile mit
 * `pageviews: 0` und `error: null` behauptet, die Seite sei gemessen worden
 * und niemand war da.
 */
final class PageDataAjaxControllerPayloadTest extends TestCase
{
    /**
     * Sprach-IDs, in denen die Seite laut Datenbank existiert.
     *
     * @var list<int>
     */
    private array $languagesWithThePage = [0];

    protected function setUp(): void
    {
        parent::setUp();

        // Der Controller baut je Sprache eine `new DeletedRestriction()`, und
        // die zieht ueber GeneralUtility::makeInstance() die TCA-Schema-Fabrik
        // nach. Ein echtes Schema gibt es im Unit-Lauf nicht, also je Abfrage
        // ein Doppel aus der Warteschlange.
        for ($i = 0; $i < 20; ++$i) {
            GeneralUtility::addInstance(
                TcaSchemaFactory::class,
                $this->createStub(TcaSchemaFactory::class),
            );
        }
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();

        parent::tearDown();
    }

    #[Test]
    public function eachExistingTranslationIsReportedWithItsMetrics(): void
    {
        $this->languagesWithThePage = [0, 1];
        $this->queueRouters(2);

        $payload = $this->decode(
            $this->subject($this->apiClientReturning($this->aggregation(pageviews: 42, uniques: 30)))
                ->handleRequest($this->request(['pageUid' => '17'])),
        );

        self::assertTrue($payload['success']);
        $translations = $payload['data']['translations'];
        self::assertCount(2, $translations);
        self::assertSame([0, 1], array_column($translations, 'languageId'));
        self::assertSame(42, $translations[0]['metrics']['pageviews']);
        self::assertSame(30, $translations[0]['metrics']['uniques']);
        self::assertNull($translations[0]['error']);
    }

    /**
     * Eine Sprache ohne Uebersetzung faellt heraus, statt als leere Zeile
     * aufzutauchen.
     */
    #[Test]
    public function aLanguageWithoutATranslationIsOmittedNotZeroed(): void
    {
        $this->languagesWithThePage = [0];
        $this->queueRouters(1);

        $payload = $this->decode(
            $this->subject($this->apiClientReturning($this->aggregation(pageviews: 42)))
                ->handleRequest($this->request(['pageUid' => '17'])),
        );

        $translations = $payload['data']['translations'];
        self::assertCount(1, $translations);
        self::assertSame(0, $translations[0]['languageId']);
    }

    /**
     * Die Adresse wird mit Schema und Host gemeldet, nicht nur als Pfad.
     *
     * Fathom speichert den Hostnamen MIT Schema, und der Filter greift sonst
     * ins Leere: die Seite haette dann immer null Zugriffe.
     */
    #[Test]
    public function theHostnameIsReportedWithItsScheme(): void
    {
        $this->languagesWithThePage = [0];
        $this->queueRouters(1, 'https://example.org/leistungen');

        $payload = $this->decode(
            $this->subject($this->apiClientReturning($this->aggregation()))
                ->handleRequest($this->request(['pageUid' => '17'])),
        );

        $translation = $payload['data']['translations'][0];
        self::assertSame('/leistungen', $translation['slug']);
        self::assertSame('https://example.org', $translation['hostname']);
    }

    /**
     * Und ein Fehler der API wird als Fehler gemeldet, nicht als Null.
     */
    #[Test]
    public function anApiErrorIsReportedRatherThanCountedAsZero(): void
    {
        $this->languagesWithThePage = [0];
        $this->queueRouters(1);

        $payload = $this->decode(
            $this->subject($this->apiClientReturning(AggregationResult::createError('rate limit')))
                ->handleRequest($this->request(['pageUid' => '17'])),
        );

        $translation = $payload['data']['translations'][0];
        self::assertSame('rate limit', $translation['error']);
        self::assertSame(0, $translation['metrics']['pageviews']);
    }

    /**
     * Laesst sich die Adresse nicht aufloesen, wird das gesagt — und Fathom
     * gar nicht erst gefragt. Ein Pfad, den der Router nicht kennt, wuerde
     * ohnehin nichts finden.
     */
    #[Test]
    public function anUnresolvableSlugIsReportedAndNoQueryIsSent(): void
    {
        $this->languagesWithThePage = [0];

        $router = $this->createStub(PageRouter::class);
        $router->method('generateUri')->willThrowException(new \RuntimeException('no route'));
        GeneralUtility::addInstance(PageRouter::class, $router);

        $apiClient = $this->createMock(Fa4t3ApiClientInterface::class);
        $apiClient->expects(self::never())->method('getAggregation');

        $payload = $this->decode($this->subject($apiClient)->handleRequest($this->request(['pageUid' => '17'])));

        $translation = $payload['data']['translations'][0];
        self::assertNull($translation['slug']);
        self::assertStringContainsString('no route', (string) $translation['error']);
    }

    private function aggregation(int $pageviews = 0, int $uniques = 0): AggregationResult
    {
        return new AggregationResult(
            visits: $uniques,
            uniques: $uniques,
            pageviews: $pageviews,
            avgDuration: 12.34,
            bounceRate: 56.78,
            dateFrom: new \DateTimeImmutable('2026-01-01'),
            dateTo: new \DateTimeImmutable('2026-01-31'),
        );
    }

    private function apiClientReturning(AggregationResult $result): Fa4t3ApiClientInterface
    {
        $apiClient = $this->createStub(Fa4t3ApiClientInterface::class);
        $apiClient->method('getAggregation')->willReturn($result);

        return $apiClient;
    }

    /**
     * Der Router kommt ueber GeneralUtility::makeInstance() aus Site::getRouter()
     * — je Sprache einmal, deshalb die Warteschlange.
     */
    private function queueRouters(int $count, string $uri = 'https://example.org/leistungen'): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $router = $this->createStub(PageRouter::class);
            $router->method('generateUri')->willReturn(new Uri($uri));
            GeneralUtility::addInstance(PageRouter::class, $router);
        }
    }

    private function subject(Fa4t3ApiClientInterface $apiClient): PageDataAjaxController
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturnCallback(
            static fn(string $extension, string $key): mixed => 'cacheDuration' === $key ? 300 : null,
        );
        $configurationService = new ConfigurationService($extensionConfiguration);

        $cache = $this->createStub(FrontendInterface::class);
        $cache->method('get')->willReturn(false);

        return new PageDataAjaxController(
            $configurationService,
            new AnalyticsService($apiClient, $cache, $configurationService),
            $this->createStub(SiteFinder::class),
            $this->connectionPool(),
        );
    }

    /**
     * Ein ConnectionPool, dessen Existenzabfrage die in
     * $languagesWithThePage genannten Sprachen bejaht.
     *
     * Die Sprach-ID ist der erste benannte Parameter der Abfrage — der
     * Controller setzt sie immer zuerst, vor uid beziehungsweise l10n_parent.
     * Der Doppel liest sie dort ab, statt die Reihenfolge der Aufrufe zu
     * zaehlen: so bleibt er auch dann richtig, wenn eine Sprache uebersprungen
     * wird.
     */
    private function connectionPool(): ConnectionPool
    {
        $pool = $this->createStub(ConnectionPool::class);
        $pool->method('getQueryBuilderForTable')->willReturnCallback(
            fn (): QueryBuilder => $this->queryBuilder(),
        );

        return $pool;
    }

    private function queryBuilder(): QueryBuilder
    {
        $languageId = null;

        $queryBuilder = $this->createStub(QueryBuilder::class);
        foreach (['select', 'from', 'where', 'andWhere', 'setMaxResults'] as $chained) {
            $queryBuilder->method($chained)->willReturnSelf();
        }
        $queryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));
        $queryBuilder->method('createNamedParameter')->willReturnCallback(
            static function (mixed $value) use (&$languageId): string {
                $languageId ??= (int) $value;

                return ':p';
            },
        );

        $restrictions = $this->createStub(QueryRestrictionContainerInterface::class);
        $restrictions->method('removeAll')->willReturnSelf();
        $restrictions->method('add')->willReturnSelf();
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);

        $result = $this->createStub(Result::class);
        // Eine echte Closure mit `use (&$languageId)`, kein Pfeilausdruck: der
        // wuerde den Wert beim Erzeugen einfangen, und zu dem Zeitpunkt hat die
        // Abfrage ihren Parameter noch nicht gesetzt.
        $result->method('fetchOne')->willReturnCallback(
            function () use (&$languageId): int|false {
                return in_array($languageId, $this->languagesWithThePage, true) ? 17 : false;
            },
        );
        $queryBuilder->method('executeQuery')->willReturn($result);

        return $queryBuilder;
    }

    /**
     * @param array<string, string> $query
     */
    private function request(array $query): ServerRequestInterface
    {
        $site = new Site('example', 1, [
            'base' => 'https://example.org/',
            'fa4t3SiteId' => 'ABCDEF',
            'fa4t3ApiKeyOverride' => 'site-key',
            'languages' => [
                [
                    'languageId' => 0,
                    'title' => 'Deutsch',
                    'locale' => 'de_DE.UTF-8',
                    'base' => '/',
                    'flag' => 'de',
                ],
                [
                    'languageId' => 1,
                    'title' => 'English',
                    'locale' => 'en_US.UTF-8',
                    'base' => '/en/',
                    'flag' => 'gb',
                ],
            ],
        ]);

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
        $decoded = \json_decode((string) $response->getBody(), true);

        self::assertIsArray($decoded, 'the endpoint must always answer with JSON');

        return $decoded;
    }
}
