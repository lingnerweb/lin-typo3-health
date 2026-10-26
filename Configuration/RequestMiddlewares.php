<?php

return [
    'frontend' => [
        'lingner/lin-typo3-health/health' => [
            'target' => \LINGNER\LinTypo3Health\Middleware\HealthMiddleware::class,
            'description' => 'Monitoring endpoint (/_api/health).',
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
            'before' => [
                'typo3/cms-frontend/site',
                'typo3/cms-frontend/authentication',
                'sourcebroker/t3api/prepare-api-request',
                'sourcebroker/t3api/process-api-request',
            ],
        ],
    ],
];
