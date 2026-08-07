<?php

declare(strict_types=1);

namespace Moselwal\FA4T3\Tests\Unit\EventListener;

use Moselwal\FA4T3\EventListener\PageLayoutAnalyticsListener;
use Moselwal\FA4T3\Service\ConfigurationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Der Zusatz im Seitenmodul: das JavaScript, das die Zugriffszahlen je Seite
 * nachlaedt.
 *
 * Interessant ist hier nur eine Frage, und die hat zwei falsche Antworten. Ein
 * Modul, das das Skript NICHT laedt, obwohl die Site eingerichtet ist, zeigt
 * dem Redakteur eine leere Spalte. Ein Modul, das es laedt, obwohl nichts
 * eingerichtet ist, laesst das Skript ins Leere fragen — auf jeder Seite jeder
 * unbeteiligten Site der Installation.
 */
final class PageLayoutAnalyticsListenerTest extends TestCase
{
    #[Test]
    public function aConfiguredSiteGetsTheAnalyticsModule(): void
    {
        $pageRenderer = $this->createMock(PageRenderer::class);
        $pageRenderer->expects(self::once())
            ->method('loadJavaScriptModule')
            ->with('@moselwal/fa4t3/PageAnalytics.js');

        $site = new Site('example', 1, [
            'base' => 'https://example.org/',
            'fa4t3SiteId' => 'ABCDEF',
            'fa4t3ApiKeyOverride' => 'site-key',
        ]);

        $this->listener($pageRenderer)($this->event($site));
    }

    /**
     * Ohne Site im Request gibt es nichts zu messen — und keine Konfiguration,
     * die man fragen koennte.
     */
    #[Test]
    public function aRequestWithoutASiteLoadsNothing(): void
    {
        $pageRenderer = $this->createMock(PageRenderer::class);
        $pageRenderer->expects(self::never())->method('loadJavaScriptModule');

        $this->listener($pageRenderer)($this->event(null));
    }

    /**
     * Und eine Site ohne Fathom-Einstellungen ebenfalls nicht. Das ist der
     * Normalfall in einer Mehr-Site-Installation, in der nur eine Site
     * gemessen wird.
     */
    #[Test]
    public function anUnconfiguredSiteLoadsNothing(): void
    {
        $pageRenderer = $this->createMock(PageRenderer::class);
        $pageRenderer->expects(self::never())->method('loadJavaScriptModule');

        $site = new Site('example', 1, ['base' => 'https://example.org/']);

        $this->listener($pageRenderer)($this->event($site));
    }

    private function listener(PageRenderer $pageRenderer): PageLayoutAnalyticsListener
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(null);

        return new PageLayoutAnalyticsListener(
            $pageRenderer,
            new ConfigurationService($extensionConfiguration),
        );
    }

    /**
     * Das ModuleTemplate wird vom Listener nie angefasst, ist aber `final` und
     * damit nicht doppelbar. Eine Instanz ohne Konstruktor erfuellt den Typ,
     * ohne die halbe Backend-Vorlagenschicht mitzuziehen.
     */
    private function event(?Site $site): ModifyPageLayoutContentEvent
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name): mixed => 'site' === $name ? $site : null,
        );

        return new ModifyPageLayoutContentEvent(
            $request,
            (new \ReflectionClass(ModuleTemplate::class))->newInstanceWithoutConstructor(),
        );
    }
}
