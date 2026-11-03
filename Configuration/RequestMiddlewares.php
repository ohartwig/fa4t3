<?php

return [
    'frontend' => [
        'moselwal/fa4t3/tracking-script' => [
            'target' => \Moselwal\FA4T3\Middleware\TrackingScriptMiddleware::class,
            // The page exclusion needs the page id, which only exists as
            // PageArguments once page-resolver has run (AUD-078-F01).
            'after' => [
                'typo3/cms-frontend/page-resolver',
            ],
            'before' => [
                'typo3/cms-frontend/prepare-tsfe-rendering',
                'typo3/cms-frontend/tsfe',
            ],
        ],
    ],
];
