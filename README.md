# OpenAI Conversions API for PHP

A lightweight PHP client (PSR-18 / PSR-17) for sending server-to-server conversion events to the
[OpenAI Ads Conversions API](https://developers.openai.com/ads/conversions-api).

## Installation

```bash
composer require creativefactoryrv/openai-conversions-api
# Choose one PSR-18 / PSR-17 combination:
composer require guzzlehttp/guzzle
# Or:
composer require symfony/http-client nyholm/psr7
```

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

$event = Event::create('order_created', id: 'order_12345')
    ->sourceUrl('https://shop.example.com/checkout/confirmation')
    ->amount(2599, 'EUR') // Minor units: 2599 = EUR 25.99
    ->addItem('sku_123', name: 'Starter bundle', quantity: 1, contentType: 'product')
    ->user(
        UserData::create()
            ->email('mario.rossi@example.com') // Normalized and hashed automatically
            ->ipAddress($_SERVER['REMOTE_ADDR'])
            ->userAgent($_SERVER['HTTP_USER_AGENT'])
            ->country('IT')
    );

if (isset($_COOKIE['oppref'])) {
    $event->oppref($_COOKIE['oppref']); // Pass the captured value unchanged
}

$client->send($event, validateOnly: true); // Validate without saving
$client->send($event);
```

Create a custom event with `Event::custom('quote_requested', 'quote_1')`, then add its source URL
and any applicable event data.

## Classes

### `Client`

Sends events using PSR-18. You must pass a PSR-18 HTTP client, a PSR-17 request factory, and a
PSR-17 stream factory to the constructor. The library does not discover implementations
automatically.

#### `Client::__construct()`

| Parameter | Required | Meaning |
|---|---:|---|
| `$pixelId` | Yes | Pixel ID sent as the `pid` query parameter. |
| `$apiKey` | Yes | Server-side Conversions API key, not an Advertiser API key. |
| `$integrationSource` | No | Partner integration identifier when sending on behalf of advertisers. |
| `$http` | Yes | PSR-18 HTTP client implementation. |
| `$requestFactory` | Yes | PSR-17 request factory implementation. |
| `$streamFactory` | Yes | PSR-17 stream factory implementation. |
| `$baseUrl` | No | API base URL; defaults to `https://bzr.openai.com`. |

#### `send(Event|list<Event> $events, bool $validateOnly = false): array`

Sends one event or a list containing **1–1,000 events**. An empty list or a larger batch throws
`InvalidArgumentException`. With `$validateOnly = true`, the API validates the events without
saving them; the default is `false`. Returns the decoded JSON response as an associative array, or
an empty array if the response has no usable JSON body.

An HTTP response outside the 2xx range throws
[`Exception\ApiException`](#exceptionapiexception); network failures are reported by the PSR-18
client. Invalid event payloads can throw `LogicException`.

### `Event`

Builds and serializes a conversion event. All configuration methods return the same `Event`
instance, so they can be chained.

Standard event types and their required `data.type`:

| `Event::create()` type | `data.type` |
|---|---|
| `app_installed`, `app_opened`, `appointment_scheduled`, `lead_created`, `registration_completed` | `customer_action` |
| `checkout_started`, `contents_viewed`, `items_added`, `order_created`, `page_viewed` | `contents` |
| `subscription_created`, `trial_started` | `plan_enrollment` |

#### Creation methods

- `Event::create(string $type, string $id, ?int $timestampMs = null, string $actionSource = 'web')`
  creates a standard event. `$type` must be in the table above. `$id` must not be blank; reuse it
  for retries and Pixel deduplication. `$timestampMs` is Unix time in milliseconds and defaults to
  the current time. `$actionSource` must be one of `web`, `mobile_app`, `offline`,
  `physical_store`, `phone_call`, `email`, or `other`.
- `Event::custom(string $name, string $id, ?int $timestampMs = null, string $actionSource = 'web')`
  creates a custom event. `$name` is lowercased and must be 1–64 characters, contain only
  `a-z`, `0-9`, `_`, or `-`, and start and end with a letter or digit. It cannot match a standard
  event name. The other arguments follow the same rules as `create()`.

#### Configuration methods

| Method | Requirements and behavior |
|---|---|
| `actionSource(string $source)` | Must be one of the seven action sources listed above. Defaults to `web`. |
| `sourceUrl(string $url)` | Requires a URL with a scheme and host. Required when the action source is `web`. |
| `oppref(string $oppref)` | Sets the OpenAI click attribution value; passed through unchanged. |
| `user(UserData $user)` | Attaches user matching data. |
| `optOut(bool $optOut = true)` | Sets the opt-out flag. |
| `amount(int $amount, string $currency)` | Amount is in minor units (for example, `4200` = USD 42.00). Currency must contain three letters and is uppercased. |
| `planId(string $planId)` | Available only for `subscription_created`, `trial_started`, and custom events. |
| `addItem(string $id, ?string $name = null, ?int $quantity = null, ?int $unitAmount = null, ?string $currency = null, ?string $contentType = null, ?string $groupId = null, array $variants = [])` | `$id` is required. Available for events whose data type is `contents`, `plan_enrollment`, or `custom`. Optional amounts use minor units; variants must be a string-to-string map. |
| `getId(): string` | Returns the event ID. |
| `toArray(): array` | Returns the API payload. A web event must have a source URL; `app_installed` and `app_opened` require `mobile_app`. |

The API accepts event timestamps from the last seven days. Use the same event ID and Pixel ID as
the corresponding Pixel event to enable deduplication.

### `UserData`

Creates the matching data passed to `Event::user()`. Hashing methods normalize values and apply
SHA-256 before adding them to the payload. Each list-valued field allows up to three distinct
values; adding a fourth throws `LengthException`.

#### Creation and data methods

| Method | Requirements and behavior |
|---|---|
| `UserData::create(): UserData` | Creates an empty builder. |
| `email(string $email)` | Trims and lowercases the value, then hashes it. The normalized value must not be empty. |
| `phone(string $phone)` | Removes spaces, parentheses, periods, hyphens, a leading `+`, and leading zeroes; the result must contain 8–15 digits. Then hashes it. |
| `externalId(string $id)` | Trims and hashes the value; it must not be empty. |
| `firstName(string $name)`, `lastName(string $name)` | Lowercases and removes spaces and ASCII punctuation, then hashes the value; it must not be empty. |
| `country(string $iso2)` | Pass an ISO 3166-1 alpha-2 code. It is trimmed and uppercased; it is not hashed. |
| `city(string $city)`, `region(string $region)`, `postalCode(string $code)` | Values are trimmed and added without hashing. |
| `obref(string $obref)` | Sets the Pixel `__obref` cookie value unchanged. |
| `ipAddress(string $ip)` | Requires a valid IPv4 or IPv6 address. |
| `userAgent(string $ua)` | Requires a non-blank value; the original value is retained. |
| `androidAdvertisingId(string $gaid)` | Adds the Android advertising ID unchanged. |
| `toArray(): array` | Returns the user data payload. |

#### Normalization helpers

- `UserData::normalizeEmail(string $email): string` trims and lowercases an email address.
- `UserData::normalizePhone(string $phone): string` returns the validated digits described above;
  it throws `InvalidArgumentException` if the result is not 8–15 digits.
- `UserData::normalizeName(string $name): string` lowercases the name, removes spaces and ASCII
  punctuation, and preserves non-ASCII characters.

### `Exception\ApiException`

Represents a non-2xx response from the Conversions API. Its constructor accepts the error message,
HTTP status code, and optional raw response body.

- `getStatusCode(): int` returns the HTTP status code.
- `getResponseBody(): string` returns the raw response body.

## Tests

```bash
composer install
composer test
```
