<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'TYPO3 Health',
    'description' => 'Bearer-protected health endpoint (/_api/health) with database, PHP-FPM, scheduler and system metrics',
    'category' => 'misc',
    'author' => 'LINGNER Webteam',
    'author_email' => 'webteam@lingner.com',
    'author_company' => 'LINGNER.com',
    'state' => 'beta',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
            'scheduler' => '12.4.0-12.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
