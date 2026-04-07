# KSeF Service Plugin

Mikroserwis HTTP (Slim 4 + PHP 8.2) do komunikacji z KSeF.

## Spis treści

- [Wymagania](#wymagania)
- [Szybki start (Docker)](#szybki-start-docker)
- [Szybki start (Docker Compose)](#szybki-start-docker-compose)
- [Konfiguracja](#konfiguracja)
- [Dostępne endpointy](#dostępne-endpointy)
- [Przykłady użycia](#przykłady-użycia)

## Wymagania

- Docker 24+ (zalecane)
- Token KSeF

## Szybki start (Docker)

Uruchomienie kontenera docker:

```bash
docker run --rm -p 8080:8080 \
  -e KSEF_MODE=production \
  --name ksef-service \
  ghcr.io/seba11/ksef-service:latest
```

Po starcie usługa będzie dostępna pod adresem:

```text
http://localhost:8080
```

## Szybki start (Docker Compose)

Przykładowy `compose.yml`:

```yaml
services:
  ksef-service:
    image: ghcr.io/seba11/ksef-service:latest
    container_name: ksef-service
    ports:
      - "8080:8080"
    environment:
      KSEF_MODE: production
    healthcheck:
      test: ["CMD-SHELL", "curl -fsS http://localhost:8080/test || exit 1"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 15s
    restart: unless-stopped
```

Uruchomienie:

```bash
docker compose up -d
```

## Konfiguracja

Dostępne zmienne środowiskowe:

- `KSEF_MODE`:
  - `production` (domyślnie)
  - `test`
  - `demo`
- `KSEF_IDENTIFIER` (alternatywnie `KSEF_NIP`):
  - Opcjonalnie NIP wystawcy (10 cyfr), używany przy sprawdzaniu statusu faktury, gdy nie da się go odczytać z tokena.

## Dostępne endpointy

### 1. `GET /test`

Endpoint health-check.

Przykładowa odpowiedź:

```json
{"status":"ok"}
```

### 2. `POST /send-invoice`

Wysyła fakturę XML do KSeF.

Wymagania:

- Nagłówek `Content-Type: application/xml`
- Nagłówek `X-KSeF-Token: <token>`
- Body: niepusty dokument XML faktury

Przykładowa odpowiedź sukcesu:

```json
{
  "status": "ok",
  "sessionReferenceNumber": "20260330-...",
  "invoiceReferenceNumber": "KSeF:..."
}
```

Przykładowa odpowiedź błędu:

```json
{
  "status": "error",
  "message": "Invalid XML invoice payload."
}
```

### 3. `GET /invoice-status/{sessionReferenceNumber}/{invoiceReferenceNumber}`

Sprawdza status faktury przesłanej w sesji online.

Parametry ścieżki:

- `sessionReferenceNumber`
- `invoiceReferenceNumber`

Nagłówki:

- `X-KSeF-Token: <token>` (wymagany)

Przykładowa odpowiedź sukcesu:

```json
{
  "status": "ok",
  "data": {
    "status": {
      "code": 200
    }
  }
}
```

Przykładowa odpowiedź błędu:

```json
{
  "status": "error",
  "message": "missing KSEF token"
}
```

## Przykłady użycia

### 1. Test działania usługi

```bash
curl -s http://localhost:8080/test
```

### 2. Wysłanie faktury XML

Użycie przykładowego pliku `test/invoice.xml`:

```bash
curl -s -X POST "http://localhost:8080/send-invoice" \
  -H "Content-Type: application/xml" \
  -H "X-KSeF-Token: TWOJ_TOKEN_KSEF" \
  --data-binary @test/invoice.xml
```

### 3. Sprawdzenie statusu faktury

Uzupełnij wartości zwrócone z endpointu `POST /send-invoice`:

```bash
curl -s \
  -H "X-KSeF-Token: TWOJ_TOKEN_KSEF" \
  "http://localhost:8080/invoice-status/SESSION_REFERENCE_NUMBER/INVOICE_REFERENCE_NUMBER"
```
