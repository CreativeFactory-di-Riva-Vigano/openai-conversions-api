<?php

declare(strict_types=1);

namespace CreativeFactoryRV\OpenAIConversions;

/**
 * User data for matching. Identifiers are normalized and hashed (SHA-256) as
 * required by the API; plaintext identifier values are never sent.
 * Each list accepts up to three values.
 */
final class UserData
{
    /** @var array<string, mixed> */
    private array $fields = [];

    /**
     * Creates an empty user data builder.
     *
     * @return self A new user data instance
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Adds a normalized and SHA-256 hashed email address.
     *
     * @param string $email Email address to add
     * @return self This user data instance, for fluent configuration
     * @throws \InvalidArgumentException If the normalized value is empty
     * @throws \LengthException If more than three email addresses are added
     */
    public function email(string $email): self
    {
        return $this->addHash('emails_sha256', self::normalizeEmail($email));
    }

    /**
     * Adds a normalized and SHA-256 hashed phone number.
     *
     * @param string $phone Phone number with international calling code
     * @return self This user data instance, for fluent configuration
     * @throws \InvalidArgumentException If the phone number is invalid
     * @throws \LengthException If more than three phone numbers are added
     */
    public function phone(string $phone): self
    {
        return $this->addHash('phone_numbers_sha256', self::normalizePhone($phone));
    }

    /**
     * Adds a trimmed and SHA-256 hashed external identifier.
     *
     * @param string $id External user identifier
     * @return self This user data instance, for fluent configuration
     * @throws \InvalidArgumentException If the identifier is empty
     * @throws \LengthException If more than three external IDs are added
     */
    public function externalId(string $id): self
    {
        return $this->addHash('external_ids_sha256', trim($id));
    }

    /**
     * Adds a normalized and SHA-256 hashed first name.
     *
     * @param string $name First name to add
     * @return self This user data instance, for fluent configuration
     * @throws \InvalidArgumentException If the normalized value is empty
     * @throws \LengthException If more than three first names are added
     */
    public function firstName(string $name): self
    {
        return $this->addHash('first_names_sha256', self::normalizeName($name));
    }

    /**
     * Adds a normalized and SHA-256 hashed last name.
     *
     * @param string $name Last name to add
     * @return self This user data instance, for fluent configuration
     * @throws \InvalidArgumentException If the normalized value is empty
     * @throws \LengthException If more than three last names are added
     */
    public function lastName(string $name): self
    {
        return $this->addHash('last_names_sha256', self::normalizeName($name));
    }

    /**
     * Adds a two-letter country code in uppercase.
     *
     * @param string $iso2 ISO 3166-1 alpha-2 country code
     * @return self This user data instance, for fluent configuration
     * @throws \LengthException If more than three country values are added
     */
    public function country(string $iso2): self
    {
        return $this->addRaw('countries', strtoupper(trim($iso2)));
    }

    /**
     * Adds a trimmed city value.
     *
     * @param string $city City name
     * @return self This user data instance, for fluent configuration
     * @throws \LengthException If more than three city values are added
     */
    public function city(string $city): self
    {
        return $this->addRaw('cities', trim($city));
    }

    /**
     * Adds a trimmed region value.
     *
     * @param string $region Region name or code
     * @return self This user data instance, for fluent configuration
     * @throws \LengthException If more than three region values are added
     */
    public function region(string $region): self
    {
        return $this->addRaw('regions', trim($region));
    }

    /**
     * Adds a trimmed postal code.
     *
     * @param string $code Postal code
     * @return self This user data instance, for fluent configuration
     * @throws \LengthException If more than three postal codes are added
     */
    public function postalCode(string $code): self
    {
        return $this->addRaw('postal_codes', trim($code));
    }

    /**
     * Sets the Pixel `__obref` cookie value without modification.
     *
     * @param string $obref Pixel attribution cookie value
     * @return self This user data instance, for fluent configuration
     */
    public function obref(string $obref): self
    {
        $this->fields['obref'] = $obref;
        return $this;
    }

    /**
     * Adds a validated IP address.
     *
     * @param string $ip IPv4 or IPv6 address
     * @return self This user data instance, for fluent configuration
     * @throws \InvalidArgumentException If the IP address is invalid
     */
    public function ipAddress(string $ip): self
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException("Invalid IP address: $ip");
        }
        $this->fields['ip_address'] = $ip;
        return $this;
    }

    /**
     * Adds a non-empty user agent string.
     *
     * @param string $ua User agent value
     * @return self This user data instance, for fluent configuration
     * @throws \InvalidArgumentException If the user agent is empty
     */
    public function userAgent(string $ua): self
    {
        if (trim($ua) === '') {
            throw new \InvalidArgumentException('User agent cannot be empty.');
        }
        $this->fields['user_agent'] = $ua;
        return $this;
    }

    /**
     * Adds an Android advertising ID.
     *
     * @param string $gaid Google Advertising ID
     * @return self This user data instance, for fluent configuration
     */
    public function androidAdvertisingId(string $gaid): self
    {
        $this->fields['android_advertising_id'] = $gaid;
        return $this;
    }

    /**
     * Returns the user data in the API payload format.
     *
     * @return array<string, mixed> User data payload
     */
    public function toArray(): array
    {
        return $this->fields;
    }

    /**
     * Trims and lowercases an email address.
     *
     * @param string $email Email address to normalize
     * @return string Normalized email address
     */
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Removes phone formatting and validates the international digit sequence.
     *
     * @param string $phone Phone number to normalize
     * @return string Normalized phone digits
     * @throws \InvalidArgumentException If the number does not contain 8-15 digits
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/[\s().\-]/', '', $phone) ?? '';
        $digits = ltrim($digits, '+');
        $digits = ltrim($digits, '0');
        if (!preg_match('/^\d{8,15}$/', $digits)) {
            throw new \InvalidArgumentException('Invalid phone number (expected 8-15 digits, including the international calling code).');
        }
        return $digits;
    }

    /**
     * Lowercases a name and removes spaces and ASCII punctuation.
     *
     * @param string $name Name to normalize
     * @return string Normalized name
     */
    public static function normalizeName(string $name): string
    {
        $name = mb_strtolower($name, 'UTF-8');
        // Remove spaces and ASCII punctuation while preserving non-ASCII characters (e.g. "josé").
        return preg_replace('/[\s!-\/:-@\[-`{-~]/', '', $name) ?? '';
    }

    /**
     * Hashes a normalized identifier and adds it to the matching field.
     *
     * @param string $key API field name
     * @param string $normalized Normalized identifier value
     * @return self This user data instance
     * @throws \InvalidArgumentException If the normalized value is empty
     * @throws \LengthException If the field already contains three values
     */
    private function addHash(string $key, string $normalized): self
    {
        if ($normalized === '') {
            throw new \InvalidArgumentException("Empty value for $key.");
        }
        return $this->addRaw($key, hash('sha256', $normalized));
    }

    /**
     * Adds a unique raw value to a list field, up to three values.
     *
     * @param string $key API field name
     * @param string $value Value to add
     * @return self This user data instance
     * @throws \LengthException If adding the value would exceed three values
     */
    private function addRaw(string $key, string $value): self
    {
        $list = $this->fields[$key] ?? [];
        if (!in_array($value, $list, true)) {
            $list[] = $value;
        }
        if (count($list) > 3) {
            throw new \LengthException("A maximum of 3 values is allowed for $key.");
        }
        $this->fields[$key] = $list;
        return $this;
    }
}
