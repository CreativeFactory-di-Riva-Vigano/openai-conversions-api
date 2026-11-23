# OpenAI Conversions API for PHP

A lightweight PHP client for sending server-to-server conversion events to the
[OpenAI Ads Conversions API](https://developers.openai.com/ads/conversions-api).

## Installation

```bash
composer require creativefactoryrv/openai-conversions-api
# Choose one PSR-18 / PSR-17 combination:
composer require guzzlehttp/guzzle
# Or:
composer require symfony/http-client nyholm/psr7
```

Requires PHP 8.1 or later, plus the `ext-json` and `ext-mbstring` extensions.

## Usage

```php
use CreativeFactoryRV\OpenAIConversions\{Client, Event, UserData};
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;

$factory = new HttpFactory();
$client = new Client(
    pixelId: getenv('OPENAI_PIXEL_ID'),
    apiKey: getenv('OPENAI_CONVERSIONS_API_KEY'), // Server-side Conversions API key
    http: new GuzzleClient(),
    requestFactory: $factory,
    streamFactory: $factory,
);

$user = UserData::create()
    ->email('john@example.com') // Normalized and hashed automatically
    ->ipAddress($_SERVER['REMOTE_ADDR'])
    ->userAgent($_SERVER['HTTP_USER_AGENT'])
    ->country('IT');

if (isset($_COOKIE['__obref'])) {
    $user->obref($_COOKIE['__obref']); // Pass the Pixel __obref cookie unchanged
}

$event = Event::create('order_created', id: 'order_12345')
    ->sourceUrl('https://shop.example.com/checkout/confirmation')
    ->amount(2599, 'EUR') // Minor units: 2599 = EUR 25.99
    ->addItem('sku_123', name: 'Starter bundle', quantity: 1, contentType: 'product')
    ->user($user);

if (isset($_COOKIE['oppref'])) {
    $event->oppref($_COOKIE['oppref']); // Pass the captured value unchanged
}

$client->send($event); // Sends and saves the event.

// For validation only, without saving:
// $client->send($event, validateOnly: true);
```

Create a custom event with `Event::custom('quote_requested', 'quote_1')`, then add its source URL
and any applicable event data.

## Classes

### `Client`

Sends events using PSR-18. You must pass a PSR-18 HTTP client, a PSR-17 request factory, and a
PSR-17 stream factory to the constructor. The library does not discover implementations
automatically.

#### Methods

| Method | Parameters | Behavior and return |
|---|---|---|
| `__construct(...)` | Required: `$pixelId` (Pixel ID sent as `pid`), `$apiKey` (server-side Conversions API key, not an Advertiser API key), `$http` (PSR-18 client), `$requestFactory` and `$streamFactory` (PSR-17 factories). Optional: `$integrationSource` (partner identifier), `$baseUrl` (API base URL; defaults to `https://bzr.openai.com`). | Creates a client. The PSR-18 and PSR-17 implementations must be provided explicitly. |
| `send(Event\|list<Event> $events, bool $validateOnly = false): array` | `$events`: one event or a list of **1–1,000 events**; `$validateOnly`: validate without saving, defaults to `false`. | Returns the decoded JSON response as an associative array, or `[]` if the response has no usable JSON body. Empty or oversized lists throw `InvalidArgumentException`; non-2xx responses throw [`Exception\ApiException`](#exceptionapiexception); network errors come from the PSR-18 client. Invalid events can throw `LogicException`; JSON encoding errors throw `JsonException`. |

### `Event`

Builds and serializes a conversion event. All configuration methods return the same `Event`
instance, so they can be chained.

#### Methods

Standard `$type` values:

- App: `app_installed`, `app_opened`
- Lead and appointment: `appointment_scheduled`, `lead_created`, `registration_completed`
- Content and commerce: `checkout_started`, `contents_viewed`, `items_added`, `order_created`, `page_viewed`
- Subscription: `subscription_created`, `trial_started`

| Method | Parameters | Behavior and return |
|---|---|---|
| `create(string $type, string $id, ?int $timestampMs = null, string $actionSource = 'web'): self` | `$type`: standard event name; `$id`: unique, non-blank event ID; `$timestampMs`: Unix timestamp in milliseconds, defaults to the current time; `$actionSource`: action source, defaults to `web`. | Creates a standard event. The client maps the event name to the API's required data format; you do not set `data.type`. Reuse the ID for retries and Pixel deduplication. Web events need a source URL; `app_installed` and `app_opened` require `mobile_app`. |
| `custom(string $name, string $id, ?int $timestampMs = null, string $actionSource = 'web'): self` | `$name`: custom name; remaining parameters have the same meaning as in `create()`. | Creates a custom event. The name is lowercased and must be 1–64 characters, contain only `a-z`, `0-9`, `_`, or `-`, start and end with a letter or digit, and not match a standard event name. |
| `actionSource(string $source): self` | `$source`: `web`, `mobile_app`, `offline`, `physical_store`, `phone_call`, `email`, or `other`. | Sets the action source. |
| `sourceUrl(string $url): self` | `$url`: URL with a scheme and host. | Sets where the event occurred; required for `web` events. |
| `oppref(string $oppref): self` | `$oppref`: OpenAI click attribution value. | Sets the value unchanged. |
| `user(UserData $user): self` | `$user`: user matching data. | Attaches user data. |
| `optOut(bool $optOut = true): self` | `$optOut`: whether the user opted out. | Sets the opt-out flag. |
| `amount(int $amount, string $currency): self` | `$amount`: amount in minor units; `$currency`: three-letter currency value. | Sets the amount and uppercases the currency (for example, `4200` with `usd` means USD 42.00). |
| `planId(string $planId): self` | `$planId`: plan identifier. | Available for `subscription_created`, `trial_started`, and custom events. |
| `addItem(string $id, ?string $name = null, ?int $quantity = null, ?int $unitAmount = null, ?string $currency = null, ?string $contentType = null, ?string $groupId = null, array $variants = []): self` | `$id` is required; other values are optional. Amounts use minor units; `$variants` must be a string-to-string map. | Adds an item to `checkout_started`, `contents_viewed`, `items_added`, `order_created`, `page_viewed`, `subscription_created`, `trial_started`, or a custom event. |
| `getId(): string` | — | Returns the event ID. |
| `toArray(): array` | — | Returns the API payload. Throws `LogicException` if required data is missing or incompatible; web events need a source URL and app install/open events require `mobile_app`. |

The API accepts event timestamps from the last seven days. Use the same event ID and Pixel ID as
the corresponding Pixel event to enable deduplication.

### `UserData`

Creates the matching data passed to `Event::user()`. Hashing methods normalize values and apply
SHA-256 before adding them to the payload. Each list-valued field allows up to three distinct
values; adding a fourth distinct value throws `LengthException`. Repeating an existing value does
not count toward the limit.

#### Methods

| Method | Parameters | Behavior and return |
|---|---|---|
| `create(): UserData` | — | Creates an empty builder. |
| `email(string $email): self` | `$email`: email address. | Trims and lowercases the value, then hashes it with SHA-256; it must not be empty. |
| `phone(string $phone): self` | `$phone`: phone number including international calling code. | Removes whitespace, parentheses, periods, hyphens, a leading `+`, and leading zeroes, then hashes it. The result must contain 8–15 digits. |
| `externalId(string $id): self` | `$id`: external user identifier. | Trims and hashes the value; it must not be empty. |
| `firstName(string $name): self`, `lastName(string $name): self` | `$name`: first or last name. | Lowercases and removes spaces and ASCII punctuation, then hashes the value; it must not be empty. |
| `country(string $iso2): self` | `$iso2`: ISO 3166-1 alpha-2 code. | Trims and uppercases the value without validating or hashing it. |
| `city(string $city): self`, `region(string $region): self`, `postalCode(string $code): self` | City, region, or postal code. | Trims and adds the value without hashing it. |
| `obref(string $obref): self` | `$obref`: Pixel `__obref` cookie value. | Adds the value unchanged. |
| `ipAddress(string $ip): self` | `$ip`: IPv4 or IPv6 address. | Validates and adds the address. |
| `userAgent(string $ua): self` | `$ua`: user-agent string. | Requires a non-blank value and retains it unchanged. |
| `androidAdvertisingId(string $gaid): self` | `$gaid`: Android advertising ID. | Adds the value unchanged. |
| `toArray(): array` | — | Returns the user data payload. |
| `UserData::normalizeEmail(string $email): string` | `$email`: email address. | Trims and lowercases the value. |
| `UserData::normalizePhone(string $phone): string` | `$phone`: phone number. | Returns the normalized 8–15 digit value; throws `InvalidArgumentException` if invalid. |
| `UserData::normalizeName(string $name): string` | `$name`: first or last name. | Lowercases and removes spaces and ASCII punctuation while preserving non-ASCII characters. |

### `Exception\ApiException`

Represents a non-2xx response from the Conversions API and extends PHP's `RuntimeException`.

#### Methods

| Method | Parameters | Behavior and return |
|---|---|---|
| `__construct(string $message, int $statusCode, string $responseBody = '')` | `$message`: error message; `$statusCode`: HTTP status code; `$responseBody`: raw response body, defaults to an empty string. | Creates the exception; the status code is also passed to `RuntimeException`. |
| `getStatusCode(): int` | — | Returns the HTTP status code. |
| `getResponseBody(): string` | — | Returns the raw response body. |

## Tests

```bash
composer install
composer test
```
