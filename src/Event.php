<?php

declare(strict_types=1);

namespace CreativeFactoryRV\OpenAIConversions;

final class Event
{
    public const CUSTOM = 'custom';

    /** Event type => required `data` shape. */
    private const DATA_TYPES = [
        'app_installed' => 'customer_action',
        'app_opened' => 'customer_action',
        'appointment_scheduled' => 'customer_action',
        'lead_created' => 'customer_action',
        'registration_completed' => 'customer_action',
        'checkout_started' => 'contents',
        'contents_viewed' => 'contents',
        'items_added' => 'contents',
        'order_created' => 'contents',
        'page_viewed' => 'contents',
        'subscription_created' => 'plan_enrollment',
        'trial_started' => 'plan_enrollment',
        self::CUSTOM => 'custom',
    ];

    private const ACTION_SOURCES = ['web', 'mobile_app', 'offline', 'physical_store', 'phone_call', 'email', 'other'];

    private ?string $customEventName = null;
    private ?string $oppref = null;
    private ?string $sourceUrl = null;
    private ?UserData $user = null;
    private ?bool $optOut = null;
    private ?int $amount = null;
    private ?string $currency = null;
    private ?string $planId = null;
    /** @var list<array<string, mixed>> */
    private array $contents = [];
    private string $actionSource = 'web';

    /**
     * Creates an event instance after its type and ID have been validated.
     *
     * @param string $id Unique event ID
     * @param string $type Standard or custom event type
     * @param int $timestampMs Event timestamp in Unix milliseconds
     */
    private function __construct(
        private readonly string $id,
        private readonly string $type,
        private readonly int $timestampMs,
    ) {
    }

    /**
     * Creates a standard event and validates its type, ID, and action source.
     *
     * @param string $type Supported standard event type
     * @param string $id Unique event ID; reuse it for retries and Pixel deduplication (`event_id`)
     * @param int|null $timestampMs Unix timestamp in milliseconds; uses the current time if null
     * @param string $actionSource Source of the event action
     * @return self A new standard event
     * @throws \InvalidArgumentException If the type, ID, or action source is invalid
     */
    public static function create(string $type, string $id, ?int $timestampMs = null, string $actionSource = 'web'): self
    {
        if (!isset(self::DATA_TYPES[$type])) {
            throw new \InvalidArgumentException("Unsupported event type: $type");
        }
        if (trim($id) === '') {
            throw new \InvalidArgumentException('Event ID cannot be empty.');
        }
        $self = new self($id, $type, $timestampMs ?? (int) round(microtime(true) * 1000));
        return $self->actionSource($actionSource);
    }

    /**
     * Creates a custom event after validating its name and shared event fields.
     *
     * @param string $name Custom event name
     * @param string $id Unique event ID
     * @param int|null $timestampMs Unix timestamp in milliseconds; uses the current time if null
     * @param string $actionSource Source of the event action
     * @return self A new custom event
     * @throws \InvalidArgumentException If the name, ID, or action source is invalid
     */
    public static function custom(string $name, string $id, ?int $timestampMs = null, string $actionSource = 'web'): self
    {
        $name = strtolower($name);
        if (!preg_match('/^[a-z0-9]([a-z0-9_-]{0,62}[a-z0-9])?$/', $name)) {
            throw new \InvalidArgumentException('custom_event_name must be 1-64 characters [a-z0-9_-] and start and end with a letter or digit.');
        }
        if (isset(self::DATA_TYPES[$name])) {
            throw new \InvalidArgumentException('custom_event_name cannot match a standard event name.');
        }
        $self = self::create(self::CUSTOM, $id, $timestampMs, $actionSource);
        $self->customEventName = $name;
        return $self;
    }

    /**
     * Sets the source of the event action.
     *
     * @param string $source Supported action source
     * @return self This event, for fluent configuration
     * @throws \InvalidArgumentException If the action source is unsupported
     */
    public function actionSource(string $source): self
    {
        if (!in_array($source, self::ACTION_SOURCES, true)) {
            throw new \InvalidArgumentException("Invalid action_source: $source");
        }
        $this->actionSource = $source;
        return $this;
    }

    /**
     * Sets the URL where the event occurred.
     *
     * @param string $url Absolute source URL
     * @return self This event, for fluent configuration
     * @throws \InvalidArgumentException If the URL has no scheme or host
     */
    public function sourceUrl(string $url): self
    {
        $parts = parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            throw new \InvalidArgumentException('source_url must include a scheme and host.');
        }
        $this->sourceUrl = $url;
        return $this;
    }

    /**
     * Sets the OpenAI click attribution reference, which is passed through unchanged.
     *
     * @param string $oppref Attribution reference
     * @return self This event, for fluent configuration
     */
    public function oppref(string $oppref): self
    {
        $this->oppref = $oppref;
        return $this;
    }

    /**
     * Attaches user matching data to the event.
     *
     * @param UserData $user User data to include in the event
     * @return self This event, for fluent configuration
     */
    public function user(UserData $user): self
    {
        $this->user = $user;
        return $this;
    }

    /**
     * Sets the user's opt-out status.
     *
     * @param bool $optOut Whether the user opted out
     * @return self This event, for fluent configuration
     */
    public function optOut(bool $optOut = true): self
    {
        $this->optOut = $optOut;
        return $this;
    }

    /**
     * Sets the event amount and currency.
     *
     * @param int $amount Amount in minor units (e.g. 4200 = USD 42.00)
     * @param string $currency Three-letter ISO 4217 currency code
     * @return self This event, for fluent configuration
     * @throws \InvalidArgumentException If the currency code is invalid
     */
    public function amount(int $amount, string $currency): self
    {
        if (!preg_match('/^[A-Za-z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('A three-letter ISO 4217 currency code is required.');
        }
        $this->amount = $amount;
        $this->currency = strtoupper($currency);
        return $this;
    }

    /**
     * Sets the plan ID for a compatible event.
     *
     * @param string $planId Plan identifier
     * @return self This event, for fluent configuration
     * @throws \LogicException If the event type does not support a plan ID
     */
    public function planId(string $planId): self
    {
        $this->assertDataType(['plan_enrollment', 'custom'], 'plan_id');
        $this->planId = $planId;
        return $this;
    }

    /**
     * Adds an item or plan to a compatible event.
     *
     * @param string $id Item or plan identifier
     * @param string|null $name Item name
     * @param int|null $quantity Item quantity
     * @param int|null $unitAmount Unit price in minor units
     * @param string|null $currency Three-letter currency code
     * @param string|null $contentType Type of content
     * @param string|null $groupId Item group identifier
     * @param array<string,string> $variants Item variant attributes
     * @return self This event, for fluent configuration
     * @throws \LogicException If the event type does not support items
     */
    public function addItem(
        string $id,
        ?string $name = null,
        ?int $quantity = null,
        ?int $unitAmount = null,
        ?string $currency = null,
        ?string $contentType = null,
        ?string $groupId = null,
        array $variants = [],
    ): self {
        $this->assertDataType(['contents', 'plan_enrollment', 'custom'], 'contents');
        $this->contents[] = array_filter([
            'id' => $id,
            'group_id' => $groupId,
            'name' => $name,
            'content_type' => $contentType,
            'quantity' => $quantity,
            'amount' => $unitAmount,
            'currency' => $currency !== null ? strtoupper($currency) : null,
            'variant_dict' => $variants ?: null,
        ], static fn ($v) => $v !== null);
        return $this;
    }

    /**
     * Returns the unique event ID.
     *
     * @return string Event ID
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Serializes the event into the API payload format.
     *
     * @return array<string, mixed> Event payload
     * @throws \LogicException If required event data is missing or incompatible
     */
    public function toArray(): array
    {
        if ($this->type === self::CUSTOM && $this->customEventName === null) {
            throw new \LogicException('Custom events must be created with Event::custom().');
        }
        if ($this->actionSource === 'web' && $this->sourceUrl === null) {
            throw new \LogicException("source_url is required for web events (event {$this->id}).");
        }
        if (in_array($this->type, ['app_installed', 'app_opened'], true) && $this->actionSource !== 'mobile_app') {
            throw new \LogicException('app_installed/app_opened require action_source=mobile_app.');
        }

        $data = array_filter([
            'type' => self::DATA_TYPES[$this->type],
            'amount' => $this->amount,
            'currency' => $this->currency,
            'plan_id' => $this->planId,
            'contents' => $this->contents ?: null,
        ], static fn ($v) => $v !== null);

        return array_filter([
            'id' => $this->id,
            'type' => $this->type,
            'timestamp_ms' => $this->timestampMs,
            'custom_event_name' => $this->customEventName,
            'oppref' => $this->oppref,
            'source_url' => $this->sourceUrl,
            'action_source' => $this->actionSource,
            'user' => $this->user?->toArray() ?: null,
            'opt_out' => $this->optOut,
            'data' => $data,
        ], static fn ($v) => $v !== null);
    }

    /**
     * Ensures the event's data shape is allowed for the current field.
     *
     * @param list<string> $allowed Allowed data shapes
     * @param string $field Field being validated
     * @throws \LogicException If the event's data shape is not allowed
     */
    private function assertDataType(array $allowed, string $field): void
    {
        if (!in_array(self::DATA_TYPES[$this->type], $allowed, true)) {
            throw new \LogicException("The $field field is not available for the {$this->type} event.");
        }
    }
}
