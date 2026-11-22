<?php

/**
 * The Phalcon benchmark app (B4). The same code runs for all Phalcon variants.
 */

declare(strict_types=1);

use Phalcon\Di\FactoryDefault;
use Phalcon\Mvc\Micro;

require __DIR__ . '/../vendor/autoload.php';

$app = new Micro(new FactoryDefault());

$app->get('/json', function () use ($app) {
    return $app->response->setJsonContent(['message' => 'Hello, World!']);
});

$app->get('/plaintext', function () use ($app) {
    return $app->response
        ->setContentType('text/plain', 'UTF-8')
        ->setContent('Hello, World!');
});

$app->handle($_SERVER['REQUEST_URI']);
