<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'TYPO3 Health',
    'description' => 'Bearer-protected health endpoint (/_api/health) with system metrics',
    'category' => 'misc',
    'author' => 'Calvin Reibenspieß',
    'author_email' => 'c.reibenspiess@lingner.com',
    'author_company' => 'LINGNER.COM',
    'state' => 'beta',
    'version' => '1.0.1',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-14.3.99',
            'scheduler' => '12.4.0-14.3.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
