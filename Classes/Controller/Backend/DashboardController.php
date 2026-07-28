<?php

declare(strict_types=1);

namespace Moselwal\FA4T3\Controller\Backend;

use Moselwal\FA4T3\Service\ConfigurationService;
use Moselwal\FA4T3\Service\Fa4t3ApiClientInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

final class DashboardController extends ActionController
{
    public function __construct(
        private readonly ConfigurationService $configurationService,
        private readonly Fa4t3ApiClientInterface $apiClient,
        private readonly SiteFinder $siteFinder,
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
    ) {}

    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());

        $site = $this->resolveCurrentSite();

        if ($site === null) {
            $moduleTemplate->assignMultiple(['showSetup' => true, 'setupReason' => 'noSite']);
            return $moduleTemplate->renderResponse('Backend/Dashboard/Index');
        }

        // getShareUrl() already appends the SHA-256 hashed password if configured
        $shareUrl = $this->configurationService->getShareUrl($site);

        if ($shareUrl !== '') {
            $moduleTemplate->assignMultiple([
                'showSetup' => false,
                'shareUrl' => $shareUrl,
            ]);
            return $moduleTemplate->renderResponse('Backend/Dashboard/Index');
        }

        // No share URL — show setup hints
        $hasApiKey = $this->configurationService->hasGlobalApiKey()
            || $this->configurationService->getApiKeyForSite($site) !== '';
        $hasSiteId = $this->configurationService->getSiteId($site) !== '';

        $moduleTemplate->assignMultiple([
            'showSetup' => true,
            'hasApiKey' => $hasApiKey,
            'hasSiteId' => $hasSiteId,
            'noShareUrl' => true,
        ]);

        return $moduleTemplate->renderResponse('Backend/Dashboard/Index');
    }

    public function testConnectionAction(): ResponseInterface
    {
        $site = $this->resolveCurrentSite();
        $apiKey = '';

        if ($site !== null) {
            $apiKey = $this->configurationService->getApiKeyForSite($site);
        }

        if ($apiKey === '') {
            $apiKey = $this->configurationService->getGlobalApiKey();
        }

        if ($apiKey === '') {
            $this->addFlashMessage(
                'No API key configured.',
                'Connection Test',
                ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('index');
        }

        $result = $this->apiClient->testConnection($apiKey);

        $this->addFlashMessage(
            $result->getMessage(),
            'Connection Test',
            $result->isSuccess() ? ContextualFeedbackSeverity::OK : ContextualFeedbackSeverity::ERROR
        );

        return $this->redirect('index');
    }

    private function resolveCurrentSite(): ?Site
    {
        $site = $this->request->getAttribute('site');
        if ($site instanceof Site) {
            return $site;
        }

        // getAllSites() is declared as Site[], so the first one is the first one.
        foreach ($this->siteFinder->getAllSites() as $s) {
            return $s;
        }

        return null;
    }
}
