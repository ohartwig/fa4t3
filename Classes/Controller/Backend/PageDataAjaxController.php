<?php

declare(strict_types=1);

namespace Moselwal\FA4T3\Controller\Backend;

use Moselwal\FA4T3\Domain\Model\DateRange;
use Moselwal\FA4T3\Service\AnalyticsService;
use Moselwal\FA4T3\Service\ConfigurationService;
use Moselwal\FA4T3\Service\PageAccessCheckerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

final readonly class PageDataAjaxController
{
    public function __construct(
        private ConfigurationService $configurationService,
        private AnalyticsService $analyticsService,
        private SiteFinder $siteFinder,
        private ConnectionPool $connectionPool,
        private PageAccessCheckerInterface $pageAccessChecker,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $pageUid = (int)($request->getQueryParams()['pageUid'] ?? 0);

        if ($pageUid === 0) {
            return new JsonResponse(['success' => false, 'error' => 'No page UID provided']);
        }

        // Page permission and web mount first, before anything is resolved or
        // asked of Fathom (AUD-078-F02).
        if (!$this->pageAccessChecker->maySeePage($pageUid)) {
            return new JsonResponse(['success' => false, 'error' => 'No access to this page']);
        }

        $site = $request->getAttribute('site');
        if ($site === null || $site instanceof NullSite) {
            try {
                $site = $this->siteFinder->getSiteByPageId($pageUid);
            } catch (\Exception) {
                return new JsonResponse(['success' => false, 'error' => 'Could not resolve site']);
            }
        }

        if (!$site instanceof Site || !$this->configurationService->isConfigured($site)) {
            return new JsonResponse(['success' => false, 'error' => 'Extension not configured']);
        }

        $siteId = $this->configurationService->getSiteId($site);
        $apiKey = $this->configurationService->getApiKeyForSite($site);
        $range = DateRange::fromPreset('30d');

        $translations = [];
        foreach ($site->getLanguages() as $siteLanguage) {
            $entry = $this->buildTranslationEntry($pageUid, $site, $siteLanguage, $siteId, $apiKey, $range);
            if ($entry !== null) {
                $translations[] = $entry;
            }
        }

        return new JsonResponse([
            'success' => true,
            'data' => [
                'translations' => $translations,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>|null Returns null when the page does not exist in the given language.
     */
    private function buildTranslationEntry(
        int $pageUid,
        Site $site,
        SiteLanguage $siteLanguage,
        string $siteId,
        string $apiKey,
        DateRange $range,
    ): ?array {
        $languageId = $siteLanguage->getLanguageId();
        if (!$this->pageExistsInLanguage($pageUid, $languageId)) {
            return null;
        }

        $slug = null;
        $hostname = null;
        $error = null;
        try {
            $uri = $site->getRouter()->generateUri((string)$pageUid, ['_language' => $siteLanguage]);
            $slug = $uri->getPath();
            // Hostname is relevant for Fathom sites that span multiple domains
            // (e.g. moselwal.de + moselwal.com under one Fathom site). Fathom
            // stores the hostname WITH the scheme prefix (e.g. "https://moselwal.de"),
            // so we send the full origin — otherwise the filter matches nothing.
            $scheme = $uri->getScheme();
            $host = $uri->getHost();
            $hostname = ($scheme !== '' && $host !== '') ? $scheme . '://' . $host : null;
        } catch (\Throwable $e) {
            $error = 'Slug konnte nicht aufgeloest werden: ' . $e->getMessage();
        }

        $metrics = [
            'pageviews' => 0,
            'uniques' => 0,
            'avgDuration' => 0.0,
            'bounceRate' => 0.0,
        ];

        if ($slug !== null) {
            $result = $this->analyticsService->getPageAnalytics($siteId, $slug, $range, $apiKey, $hostname);
            if ($result->hasError()) {
                $error = $result->getErrorMessage() ?? 'API unavailable';
            } else {
                $metrics = [
                    'pageviews' => $result->getPageviews(),
                    'uniques' => $result->getUniques(),
                    'avgDuration' => round($result->getAvgDuration(), 1),
                    'bounceRate' => round($result->getBounceRate(), 1),
                ];
            }
        }

        return [
            'languageId' => $languageId,
            'title' => $siteLanguage->getTitle(),
            'twoLetterIsoCode' => $siteLanguage->getLocale()->getLanguageCode(),
            'flagIdentifier' => $siteLanguage->getFlagIdentifier(),
            'slug' => $slug,
            'hostname' => $hostname,
            'metrics' => $metrics,
            'error' => $error,
        ];
    }

    private function pageExistsInLanguage(int $pageUid, int $languageId): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());

        $queryBuilder
            ->select('uid')
            ->from('pages')
            ->setMaxResults(1)
            ->where(
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter($languageId, ParameterType::INTEGER),
                ),
            );

        if ($languageId === 0) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER),
                ),
            );
        } else {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'l10n_parent',
                    $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER),
                ),
            );
        }

        return (bool)$queryBuilder->executeQuery()->fetchOne();
    }
}
