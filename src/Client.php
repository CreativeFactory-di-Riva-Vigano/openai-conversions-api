<?php

declare(strict_types=1);

namespace CreativeFactoryRV\OpenAIConversions;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use CreativeFactoryRV\OpenAIConversions\Exception\ApiException;

final class Client
{
    public const DEFAULT_BASE_URL = 'https://bzr.openai.com';
    public const MAX_BATCH_SIZE = 1000;

    /**
     * Creates an API client using explicitly provided PSR-18 and PSR-17 implementations.
     *
     * @param string $pixelId Pixel ID (`pid` parameter)
     * @param string $apiKey Conversions API key (not the Advertiser API key)
     * @param ClientInterface $http PSR-18 HTTP client
     * @param RequestFactoryInterface $requestFactory PSR-17 request factory
     * @param StreamFactoryInterface $streamFactory PSR-17 stream factory
     * @param string|null $integrationSource For partners sending events on behalf of advertisers only
     * @param string $baseUrl API base URL
     */
    public function __construct(
        private readonly string $pixelId,
        private readonly string $apiKey,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ?string $integrationSource = null,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
    ) {
    }

    /**
     * Sends one or more events (up to 1,000; if one fails, the entire batch fails).
     *
     * @param Event|list<Event> $events A single event or a list of events to send
     * @param bool $validateOnly Whether to validate without saving the events
     * @return array<string, mixed> The JSON response body (may be empty)
     * @throws ApiException For non-2xx HTTP responses
     * @throws ClientExceptionInterface For network errors
     * @throws \InvalidArgumentException If the event list is empty or exceeds the batch limit
     * @throws \JsonException If the request payload cannot be JSON-encoded
     * @throws \LogicException If an event is incomplete or invalid
     */
    public function send(Event|array $events, bool $validateOnly = false): array
    {
        $events = $events instanceof Event ? [$events] : array_values($events);

        if ($events === []) {
            throw new \InvalidArgumentException('No events to send.');
        }
        if (count($events) > self::MAX_BATCH_SIZE) {
            throw new \InvalidArgumentException('A maximum of ' . self::MAX_BATCH_SIZE . ' events is allowed per request.');
        }

        $payload = ['validate_only' => $validateOnly];
        if ($this->integrationSource !== null) {
            $payload['integration_source'] = $this->integrationSource;
        }
        $payload['events'] = array_map(static fn (Event $e) => $e->toArray(), $events);

        $uri = rtrim($this->baseUrl, '/') . '/v1/events?' . http_build_query(['pid' => $this->pixelId]);
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $request = $this->requestFactory->createRequest('POST', $uri)
            ->withHeader('Authorization', 'Bearer ' . $this->apiKey)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streamFactory->createStream($json));

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            throw new ApiException("OpenAI Conversions API responded with HTTP $status: $body", $status, $body);
        }

        $decoded = $body === '' ? null : json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }
}
