<?php

declare(strict_types=1);

namespace CreativeFactoryRV\OpenAIConversions\Tests;

use PHPUnit\Framework\TestCase;
use CreativeFactoryRV\OpenAIConversions\Event;
use CreativeFactoryRV\OpenAIConversions\UserData;

final class EventTest extends TestCase
{
    /**
     * Verifies the serialized payload for an order event.
     */
    public function testOrderCreatedPayload(): void
    {
        $a = Event::create('order_created', 'order_1', 1773892800000)
            ->sourceUrl('https://shop.example.com/thanks')
            ->amount(2599, 'usd')
            ->addItem('sku_1', name: 'Bundle', quantity: 1, contentType: 'product')
            ->toArray();

        $this->assertSame('contents', $a['data']['type']);
        $this->assertSame('USD', $a['data']['currency']);
        $this->assertSame(2599, $a['data']['amount']);
        $this->assertSame('sku_1', $a['data']['contents'][0]['id']);
    }

    /**
     * Verifies that web events require a source URL.
     */
    public function testWebEventRequiresSourceUrl(): void
    {
        $this->expectException(\LogicException::class);
        Event::create('lead_created', 'lead_1')->toArray();
    }

    /**
     * Verifies that custom event names cannot shadow standard event types.
     */
    public function testCustomEventCannotShadowStandardName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Event::custom('order_created', 'x');
    }

    /**
     * Verifies that email addresses are normalized before hashing.
     */
    public function testEmailIsNormalizedAndHashed(): void
    {
        $u = UserData::create()->email('  Mario.Rossi@Example.COM ')->toArray();
        $this->assertSame(hash('sha256', 'mario.rossi@example.com'), $u['emails_sha256'][0]);
    }

    /**
     * Verifies that phone formatting is removed during normalization.
     */
    public function testPhoneNormalization(): void
    {
        $this->assertSame('14155552671', UserData::normalizePhone('+1 (415) 555-2671'));
    }

    /**
     * Verifies that name normalization removes punctuation and preserves accents.
     */
    public function testNameNormalizationKeepsAccents(): void
    {
        $this->assertSame('oconnor', UserData::normalizeName("O'Connor"));
        $this->assertSame('josé', UserData::normalizeName('José'));
    }
}
