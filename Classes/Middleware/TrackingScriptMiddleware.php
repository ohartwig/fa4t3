<?php

declare(strict_types=1);

namespace Moselwal\FA4T3\Middleware;

use Moselwal\FA4T3\Service\ConfigurationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Site\Entity\NullSite;

/**
 * Injects the Fathom tracking script directly into the HTML response.
 *
 * Does NOT use AssetCollector/AssetRenderer because TYPO3 14's
 * AssetRenderer crashes (truncates HTML) when processing scripts
 * registered via addJavaScript/addInlineJavaScript in FrankenPHP
 * worker configurations.
 */
final readonly class TrackingScriptMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ConfigurationService $configurationService,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $site = $request->getAttribute('site');
        if ($site === null || $site instanceof NullSite) {
            return $handler->handle($request);
        }

        $trackingConfig = $this->configurationService->getTrackingConfig($site);

        if (!$trackingConfig['enabled']) {
            return $handler->handle($request);
        }

        $siteId = $this->configurationService->getSiteId($site);
        if ($siteId === '') {
            return $handler->handle($request);
        }

        if ($this->isPageExcluded($request, $trackingConfig['excludedPages'])) {
            return $handler->handle($request);
        }

        // Let TYPO3 render the full response first
        $response = $handler->handle($request);

        // Only inject into HTML responses
        $contentType = $response->getHeaderLine('Content-Type');
        if (!str_contains($contentType, 'text/html')) {
            return $response;
        }

        // Build the Fathom script tag
        $scriptDomain = $trackingConfig['customDomain'] !== ''
            ? rtrim($trackingConfig['customDomain'], '/')
            : 'https://cdn.usefathom.com';

        $scriptUrl = htmlspecialchars($scriptDomain . '/script.js');
        $siteAttr = ' data-site="' . htmlspecialchars($siteId) . '"';
        $consentCategory = $trackingConfig['consentCategory'];

        // The Fathom extension declares its OWN consent category and drives the
        // script markup off it, so @koh/consent-core's runtime auto-blocker never
        // has to neutralise the tag cosmetically:
        //   - Non-empty category => author-gated. Emit a parked
        //     <script type="text/plain" data-category="X" data-src="URL"> that the
        //     consent manager activates (swaps data-src->src) once the category is
        //     granted. There is NO executable src, so Fathom stays off until consent.
        //   - Empty category => ungated but declared. Emit an executable script
        //     carrying data-consent="ignore" (one of consent-core's MANAGED_ATTRS),
        //     so the auto-blocker skips the node instead of neutralising it and
        //     Fathom loads normally.
        if ($consentCategory !== '') {
            $attrs = 'type="text/plain"'
                . ' data-category="' . htmlspecialchars($consentCategory) . '"'
                . ' data-src="' . $scriptUrl . '"'
                . $siteAttr
                . ' defer';
        } else {
            $attrs = 'src="' . $scriptUrl . '"'
                . $siteAttr
                . ' defer'
                . ' data-consent="ignore"';
        }

        if ($trackingConfig['spaMode'] !== '') {
            $attrs .= ' data-spa="' . htmlspecialchars($trackingConfig['spaMode']) . '"';
        }

        if ($trackingConfig['honorDnt']) {
            $attrs .= ' data-honor-dnt="true"';
        }

        $scriptTag = '<script ' . $attrs . '></script>';

        // Inject before </body>
        $body = (string)$response->getBody();
        $injected = str_ireplace('</body>', $scriptTag . '</body>', $body);

        $stream = new Stream('php://temp', 'rw');
        $stream->write($injected);

        return $response
            ->withBody($stream)
            ->withoutHeader('Content-Length');
    }

    private function isPageExcluded(ServerRequestInterface $request, string $excludedPages): bool
    {
        if ($excludedPages === '') {
            return false;
        }

        $routing = $request->getAttribute('routing');
        if ($routing === null) {
            return false;
        }

        $pageId = $routing->getPageId();

        if ($pageId === 0) {
            return false;
        }

        $excludedUids = array_map('intval', array_filter(
            array_map('trim', explode(',', $excludedPages)),
            static fn (string $uid): bool => '' !== $uid,
        ));

        if (in_array($pageId, $excludedUids, true)) {
            return true;
        }

        try {
            $rootlineUtility = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
                \TYPO3\CMS\Core\Utility\RootlineUtility::class,
                $pageId,
            );
            $rootline = $rootlineUtility->get();

            foreach ($rootline as $page) {
                if (in_array((int)$page['uid'], $excludedUids, true)) {
                    return true;
                }
            }
        } catch (\Exception) {
        }

        return false;
    }
}
