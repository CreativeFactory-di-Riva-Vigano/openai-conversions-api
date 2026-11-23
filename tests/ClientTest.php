<?php

declare(strict_types=1);

namespace CreativeFactoryRV\OpenAIConversions\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use CreativeFactoryRV\OpenAIConversions\Client;
use CreativeFactoryRV\OpenAIConversions\Event;
use CreativeFactoryRV\OpenAIConversions\Exception\ApiException;

final class ClientTest extends TestCase
{
    /**
     * Creates a fake HTTP client with a configurable response.
     *
     * @param int $status HTTP status code to return
     * @param string $body Response body to return
     * @return object Fake client that records the last request
     */
    private function fake(int $status, string $body = '{}'): object
    {
        return new class($status, $body) implements ClientInterface {
            public ?RequestInterface $last = null;

            /**
             * Stores the response used by this fake client.
             *
             * @param int $s HTTP status code to return
             * @param string $b Response body to return
             */
            public function __construct(private int $s, private string $b)
            {
            }

            /**
             * Records the request and returns the configured response.
             *
             * @param RequestInterface $request Request sent by the client under test
             * @return ResponseInterface Configured fake response
             */
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->last = $request;
                return new Response($this->s, [], $this->b);
            }
        };
    }

    /**
     * Creates a client using the provided fake HTTP implementation.
     *
     * @param object $http Fake HTTP client
     * @param string|null $source Optional integration source
     * @return Client Client configured for the test
     */
    private function client(object $http, ?string $source = null): Client
    {
        if (!$http instanceof ClientInterface) {
            throw new \InvalidArgumentException('The test HTTP client must implement ClientInterface.');
        }

        $f = new Psr17Factory();
        return new Client(
            pixelId: 'pix_1',
            apiKey: 'key_1',
            http: $http,
            requestFactory: $f,
            streamFactory: $f,
            integrationSource: $source,
        );
    }

    /**
     * Creates a valid order event for client tests.
     *
     * @return Event Test event with a source URL
     */
    private function event(): Event
    {
        return Event::create('order_created', 'o1')->sourceUrl('https://shop.example.com/ok');
    }

    /**
     * Verifies the request method, URL, headers, and payload.
     */
    public function testSendsExpectedRequest(): void
    {
        $http = $this->fake(200);
        $this->client($http, 'acme_partner')->send($this->event(), validateOnly: true);

        $req = $http->last;
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('https://bzr.openai.com/v1/events?pid=pix_1', (string) $req->getUri());
        $this->assertSame('Bearer key_1', $req->getHeaderLine('Authorization'));

        $body = json_decode((string) $req->getBody(), true);
        $this->assertTrue($body['validate_only']);
        $this->assertSame('acme_partner', $body['integration_source']);
        $this->assertSame('o1', $body['events'][0]['id']);
    }

    /**
     * Verifies that a non-2xx response raises an API exception.
     */
    public function testThrowsOnHttpError(): void
    {
        $this->expectException(ApiException::class);
        $this->client($this->fake(400, '{"error":"bad"}'))->send($this->event());
    }

    /**
     * Verifies that batches larger than the API limit are rejected.
     */
    public function testRejectsOversizedBatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client($this->fake(200))->send(array_fill(0, 1001, $this->event()));
    }
}
