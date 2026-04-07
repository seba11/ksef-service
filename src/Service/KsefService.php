<?php

declare(strict_types=1);

namespace App\Service;

use DOMDocument;
use DOMXPath;
use GuzzleHttp\Client;
use N1ebieski\KSEFClient\ClientBuilder;
use N1ebieski\KSEFClient\Factories\EncryptionKeyFactory;
use N1ebieski\KSEFClient\Requests\Sessions\Online\Send\SendXmlRequest;
use N1ebieski\KSEFClient\Support\Utility;
use N1ebieski\KSEFClient\ValueObjects\Mode;
use N1ebieski\KSEFClient\ValueObjects\Requests\ReferenceNumber;
use Throwable;

final class KsefService
{
    public function __construct(private readonly ?string $token)
    {
    }

    public function sendInvoice(string $xml): array
    {
        if ($this->token === null || trim($this->token) === '') {
            $this->log('ERROR: missing KSEF token.');
            return [
                'status' => 'error',
                'message' => 'missing KSEF token.',
            ];
        }

        if (!$this->isXMLvalid($xml)) {
            $this->log('ERROR: Invalid XML invoice payload.');
            return [
                'status' => 'error',
                'message' => 'Invalid XML invoice payload.',
            ];
        }

        try {
            $identifier = $this->extractSellerNip($xml);

            if ($identifier === null) {
                throw new \RuntimeException('Missing seller NIP in invoice XML.');
            }

            $formCode = $this->extractFormCode($xml);

            $client = (new ClientBuilder())
                ->withMode($this->resolveMode())
                ->withHttpClient(new Client())
                ->withIdentifier($identifier)
                ->withKsefToken($this->token)
                ->withEncryptionKey(EncryptionKeyFactory::makeRandom())
                ->withValidateXml(false)
                ->build();

            $sessionReferenceNumber = null;
            $sendResponse = null;

            try {
                $openResponse = $client->sessions()->online()->open([
                    'formCode' => $formCode,
                ])->object();

                $sessionReferenceNumber = $openResponse->referenceNumber ?? null;
                $sendResponse = $client->sessions()->online()->send(
                    new SendXmlRequest(
                        referenceNumber: ReferenceNumber::from((string) $sessionReferenceNumber),
                        faktura: $xml
                    )
                )->object();
            } finally {
                if (is_string($sessionReferenceNumber) && $sessionReferenceNumber !== '') {
                    try {
                        $client->sessions()->online()->close([
                            'referenceNumber' => $sessionReferenceNumber,
                        ]);
                    } catch (Throwable) {
                    }
                }
            }

            if (!is_object($sendResponse)) {
                throw new \RuntimeException('Missing response from KSeF send invoice request.');
            }
            
            $this->log("SUCCESS: sessionReferenceNumber $sessionReferenceNumber, invoiceReferenceNumber $sendResponse->referenceNumber");
            return [
                'status' => 'ok',
                'sessionReferenceNumber' => $sessionReferenceNumber,
                'invoiceReferenceNumber' => $sendResponse->referenceNumber ?? null
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'error',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function invoiceStatus(string $invoiceReferenceNumber, string $sessionReferenceNumber): array
    {
        if ($this->token === null || trim($this->token) === '') {
            $this->log('ERROR: missing KSEF token.');
            return [
                'status' => 'error',
                'message' => 'missing KSEF token',
            ];
        }

        $identifier = $this->resolveIdentifier();

        if ($identifier === null) {
            $this->log('ERROR: missing KSEF identifier.');
            return [
                'status' => 'error',
                'message' => 'missing KSEF identifier',
            ];
        }

        try {
            $client = (new ClientBuilder())
                ->withMode($this->resolveMode())
                ->withHttpClient(new Client())
                ->withIdentifier($identifier)
                ->withKsefToken($this->token)
                ->build();

            $statusResponse = Utility::retry(function () use ($client, $sessionReferenceNumber, $invoiceReferenceNumber) {
                $response = $client->sessions()->invoices()->status([
                    'referenceNumber' => $sessionReferenceNumber,
                    'invoiceReferenceNumber' => $invoiceReferenceNumber,
                ])->object();

                if (($response->status->code ?? null) === 200) {
                    return $response;
                }

                if (($response->status->code ?? 0) >= 400) {
                    throw new \RuntimeException((string) ($response->status->description ?? 'Invoice status request failed.'));
                }

                return null;
            });

            if (!is_object($statusResponse)) {
                $this->log('ERROR: Missing response from KSeF invoice status request.');
                throw new \RuntimeException('Missing response from KSeF invoice status request.');
            }

            $data = $this->normalizeResponseData($statusResponse);
            $this->log('OK: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return [
                'status' => 'ok',
                'data' => $data,
            ];
        } catch (Throwable $exception) {
            $this->log("ERROR: invoiceReferenceNumber $invoiceReferenceNumber, ".$exception->getMessage());
            return [
                'status' => 'error',
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     *  Private methods
     */
    
    private function isXMLvalid(string $xml): bool
    {
        $document = new DOMDocument();
        $previousValue = libxml_use_internal_errors(true);

        try {
            $isLoaded = $document->loadXML($xml);

            return $isLoaded && libxml_get_last_error() === false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousValue);
        }
    }

    private function extractSellerNip(string $xml): ?string
    {
        $document = new DOMDocument();
        $previousValue = libxml_use_internal_errors(true);

        try {
            if (!$document->loadXML($xml)) {
                return null;
            }

            $xpath = new DOMXPath($document);
            $nip = trim((string) $xpath->evaluate('string(//*[local-name()="Podmiot1"]/*[local-name()="DaneIdentyfikacyjne"]/*[local-name()="NIP"][1])'));
            $nip = preg_replace('/\D+/', '', $nip);

            return is_string($nip) && $nip !== '' ? $nip : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousValue);
        }
    }

    private function extractFormCode(string $xml): string
    {
        $document = new DOMDocument();
        $previousValue = libxml_use_internal_errors(true);

        try {
            if (!$document->loadXML($xml)) {
                return 'FA (3)';
            }

            $xpath = new DOMXPath($document);
            $formCode = trim((string) $xpath->evaluate('string(//*[local-name()="KodFormularza"][1]/@kodSystemowy)'));

            return $formCode !== '' ? $formCode : 'FA (3)';
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousValue);
        }
    }

    private function resolveMode(): Mode
    {
        $mode = strtolower((string) ($_ENV['KSEF_MODE'] ?? getenv('KSEF_MODE') ?: 'production'));

        return match ($mode) {
            'test' => Mode::Test,
            'demo' => Mode::Demo,
            default => Mode::Production,
        };
    }

    private function resolveIdentifier(): ?string
    {
        if (is_string($this->token) && preg_match('/\|nip-(\d{10})\|/i', $this->token, $matches) === 1) {
            return $matches[1];
        }

        $identifier = trim((string) ($_ENV['KSEF_IDENTIFIER'] ?? getenv('KSEF_IDENTIFIER') ?: $_ENV['KSEF_NIP'] ?? getenv('KSEF_NIP') ?: ''));

        return $identifier !== '' ? $identifier : null;
    }

    private function normalizeResponseData(object $response): array
    {
        $json = json_encode($response, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }

    private function log(string $message): string
    {
        // $formattedMessage = sprintf('[%s] %s', gmdate('D M d H:i:s \U\T\C Y'), $message);

        error_log($message);

        return $message;
    }
}
