<?php

declare(strict_types=1);

use App\Service\KsefService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $file = __DIR__ . $path;

    if ($path !== '/' && is_file($file)) {
        return false;
    }
}

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

$app->get('/test', function (Request $request, Response $response): Response {
    $response->getBody()->write(json_encode(['status' => 'ok'], JSON_UNESCAPED_UNICODE));

    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/send-invoice', function (Request $request, Response $response): Response {
    $contentType = strtolower(trim($request->getHeaderLine('Content-Type')));
    $ksefToken = trim($request->getHeaderLine('X-KSeF-Token'));

    if ($contentType === '' || !str_starts_with($contentType, 'application/xml')) {
        $response->getBody()->write(json_encode([
            'error' => 'Content-Type must be application/xml.'
        ], JSON_UNESCAPED_UNICODE));

        return $response
            ->withStatus(415)
            ->withHeader('Content-Type', 'application/json');
    }

    if ($ksefToken === '') {
        $response->getBody()->write(json_encode([
            'error' => 'Header "X-KSeF-Token" is required.'
        ], JSON_UNESCAPED_UNICODE));

        return $response
            ->withStatus(400)
            ->withHeader('Content-Type', 'application/json');
    }

    $xml = trim((string) $request->getBody());

    if ($xml === '') {
        $response->getBody()->write(json_encode([
            'error' => 'Request body must contain a non-empty XML document.'
        ], JSON_UNESCAPED_UNICODE));

        return $response
            ->withStatus(400)
            ->withHeader('Content-Type', 'application/json');
    }

    $service = new KsefService($ksefToken);
    $result = $service->sendInvoice($xml);

    $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));

    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/invoice-status/{sessionReferenceNumber}/{invoiceReferenceNumber}', function (Request $request, Response $response, array $args): Response {
    $ksefToken = trim($request->getHeaderLine('X-KSeF-Token'));
    $sessionReferenceNumber = trim((string) ($args['sessionReferenceNumber'] ?? ''));
    $invoiceReferenceNumber = trim((string) ($args['invoiceReferenceNumber'] ?? ''));

    // if ($ksefToken === '') {
    //     $response->getBody()->write(json_encode([
    //         'error' => 'Header "X-KSeF-Token" is required.'
    //     ], JSON_UNESCAPED_UNICODE));

    //     return $response
    //         ->withStatus(400)
    //         ->withHeader('Content-Type', 'application/json');
    // }

    if ($sessionReferenceNumber === '') {
        $response->getBody()->write(json_encode([
            'error' => 'Path parameter "sessionReferenceNumber" is required.'
        ], JSON_UNESCAPED_UNICODE));

        return $response
            ->withStatus(400)
            ->withHeader('Content-Type', 'application/json');
    }

    if ($invoiceReferenceNumber === '') {
        $response->getBody()->write(json_encode([
            'error' => 'Path parameter "invoiceReferenceNumber" is required.'
        ], JSON_UNESCAPED_UNICODE));

        return $response
            ->withStatus(400)
            ->withHeader('Content-Type', 'application/json');
    }

    $service = new KsefService($ksefToken);
    $result = $service->invoiceStatus($invoiceReferenceNumber, $sessionReferenceNumber);

    $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));

    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
