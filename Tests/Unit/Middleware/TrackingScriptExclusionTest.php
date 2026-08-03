<?php

declare(strict_types=1);

namespace Moselwal\FA4T3\Tests\Unit\Middleware;

use Moselwal\FA4T3\Middleware\TrackingScriptMiddleware;
use Moselwal\FA4T3\Service\ConfigurationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

/**
 * Der Ausschluss einzelner Seiten vom Tracking.
 *
 * TrackingScriptMiddlewareTest prueft, WIE das Skript aussieht, wenn es
 * eingebaut wird. Hier geht es um die Faelle, in denen es NICHT eingebaut
 * werden darf — und die sind die heiklen: ein Ausschluss, der nicht greift,
 * faellt niemandem auf. Die Seite laedt weiterhin, die Zahlen laufen weiter
 * ein, und die Redaktion glaubt, eine interne Seite werde nicht gemessen.
 *
 * Besonders die Vererbung an Unterseiten: `fa4t3ExcludedPages` nennt eine uid,
 * gemeint ist aber der ganze Teilbaum darunter. Wer nur die genannte uid
 * prueft, misst jede Unterseite eines ausgeschlossenen Bereichs mit.
 */
final class TrackingScriptExclusionTest extends TestCase
{
    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();

        parent::tearDown();
    }

    /**
     * Ohne Site gibt es keine Konfiguration, die das Tracking erlauben koennte.
     * Der Aufruf muss unveraendert weiterlaufen — nicht scheitern.
     */
    #[Test]
    public function aRequestWithoutASiteIsPassedThroughUntouched(): void
    {
        foreach ([null, new NullSite()] as $site) {
            $html = $this->injectedHtml($this->requestWithAttributes(['site' => $site]));

            self::assertStringNotContainsString('<script', $html);
        }
    }

    /**
     * Die genannte Seite selbst bekommt kein Skript.
     */
    #[Test]
    public function aPageOnTheExclusionListIsNotTracked(): void
    {
        $html = $this->injectedHtml($this->request('12, 34', 34));

        self::assertStringNotContainsString('<script', $html);
    }

    /**
     * Und eine Seite, die nicht genannt ist, sehr wohl. Sonst waere der
     * vorherige Test auch mit einer Ausschlussliste erfuellt, die alles
     * ausschliesst.
     */
    #[Test]
    public function aPageBesideTheExclusionListIsStillTracked(): void
    {
        $this->queueRootline([['uid' => 99], ['uid' => 1]]);

        $html = $this->injectedHtml($this->request('12, 34', 99));

        self::assertStringContainsString('data-site="ABCDEF"', $html);
    }

    /**
     * Der eigentliche Punkt: `fa4t3ExcludedPages` meint den Teilbaum.
     *
     * Seite 77 steht nicht auf der Liste, liegt aber unter 34, das darauf
     * steht. Sie darf nicht gemessen werden — sonst schuetzt der Ausschluss
     * einer internen Rubrik nur deren Einstiegsseite.
     */
    #[Test]
    public function aPageBeneathAnExcludedAncestorIsNotTrackedEither(): void
    {
        $this->queueRootline([['uid' => 77], ['uid' => 34], ['uid' => 1]]);

        $html = $this->injectedHtml($this->request('12, 34', 77));

        self::assertStringNotContainsString('<script', $html);
    }

    /**
     * Scheitert die Rootline-Abfrage, wird gemessen statt abgebrochen.
     *
     * Das ist eine bewusste Richtung: eine unbeantwortbare Frage nach der
     * Herkunft einer Seite darf keine weisse Seite erzeugen. Sie fuehrt hier
     * dazu, dass das Skript eingebaut wird — festgehalten, damit die
     * Entscheidung sichtbar bleibt und nicht spaeter fuer einen Fehler
     * gehalten wird.
     */
    #[Test]
    public function anUnresolvableRootlineDoesNotBlockTheResponse(): void
    {
        $rootline = $this->createStub(RootlineUtility::class);
        $rootline->method('get')->willThrowException(new \RuntimeException('page gone'));
        GeneralUtility::addInstance(RootlineUtility::class, $rootline);

        $html = $this->injectedHtml($this->request('12, 34', 99));

        self::assertStringContainsString('data-site="ABCDEF"', $html);
    }

    /**
     * Ohne aufgeloestes Routing ist die Seiten-uid unbekannt. Die Liste kann
     * dann nichts ausschliessen, und die Antwort bleibt getrackt.
     */
    #[Test]
    public function anExclusionListWithoutRoutingInformationExcludesNothing(): void
    {
        $html = $this->injectedHtml($this->request('12, 34', null));

        self::assertStringContainsString('data-site="ABCDEF"', $html);
    }

    /**
     * Eine eigene Domain ersetzt cdn.usefathom.com — der Grund, warum es die
     * Einstellung gibt: das Skript soll von der eigenen Herkunft geladen
     * werden, damit Blocker es nicht an der Adresse erkennen.
     */
    #[Test]
    public function aCustomDomainReplacesTheFathomCdn(): void
    {
        $html = $this->injectedHtml($this->request('', null, 'https://stats.example.org/'));

        self::assertStringContainsString('src="https://stats.example.org/script.js"', $html);
        self::assertStringNotContainsString('cdn.usefathom.com', $html);
    }

    /**
     * @param list<array<string, mixed>> $rootline
     */
    private function queueRootline(array $rootline): void
    {
        $utility = $this->createStub(RootlineUtility::class);
        $utility->method('get')->willReturn($rootline);
        GeneralUtility::addInstance(RootlineUtility::class, $utility);
    }

    private function injectedHtml(ServerRequestInterface $request): string
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write('<html><body><p>Hi</p></body></html>');
        $stream->rewind();

        $response = (new Response())
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($stream);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $middleware = new TrackingScriptMiddleware(new ConfigurationService($extensionConfiguration));

        return (string) $middleware->process($request, $handler)->getBody();
    }

    private function request(
        string $excludedPages,
        ?int $pageId = null,
        string $customDomain = '',
    ): ServerRequestInterface {
        return $this->requestWithAttributes([
            'site' => new Site('test', 1, [
                'base' => 'https://example.org/',
                'fa4t3SiteId' => 'ABCDEF',
                'fa4t3TrackingEnabled' => true,
                'fa4t3ExcludedPages' => $excludedPages,
                'fa4t3CustomDomain' => $customDomain,
            ]),
            'routing' => null === $pageId ? null : new PageArguments($pageId, '0', []),
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function requestWithAttributes(array $attributes): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name): mixed => $attributes[$name] ?? null,
        );

        return $request;
    }
}
