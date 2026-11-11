<?php
/**
 * Order data passed to the scoring engine.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Engine;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;

/**
 * Immutable, framework-agnostic snapshot of the order being scored.
 */
final class OrderContext
{
    public int $orderId;
    public int $customerId;
    public string $phone;
    public string $shippingPhone;
    public string $email;
    public string $firstName;
    public string $lastName;
    public string $ip;
    public string $deviceId;
    public float $total;
    public string $currency;
    public string $paymentMethod;
    public int $totalQuantity;
    public int $maxLineQuantity;
    public int $lineCount;
    public string $address1;
    public string $address2;
    public string $city;
    public string $district;
    public string $postcode;
    public string $country;
    public DateTimeImmutable $createdAt;
    public bool $phoneVerified;

    /**
     * Free-form extra data for third-party rules.
     *
     * @var array<string, mixed>
     */
    public array $meta;

    /**
     * Default values for every field.
     *
     * @return array<string, mixed>
     */
    private static function defaults(): array
    {
        return [
            'order_id'          => 0,
            'customer_id'       => 0,
            'phone'             => '',
            'shipping_phone'    => '',
            'email'             => '',
            'first_name'        => '',
            'last_name'         => '',
            'ip'                => '',
            'device_id'         => '',
            'total'             => 0.0,
            'currency'          => 'BDT',
            'payment_method'    => 'cod',
            'total_quantity'    => 0,
            'max_line_quantity' => 0,
            'line_count'        => 0,
            'address_1'         => '',
            'address_2'         => '',
            'city'              => '',
            'district'          => '',
            'postcode'          => '',
            'country'           => 'BD',
            'created_at'        => null,
            'phone_verified'    => false,
            'meta'              => [],
        ];
    }

    /**
     * @param array<string, mixed> $data Normalised data (see defaults()).
     */
    private function __construct(array $data)
    {
        $this->orderId         = max(0, (int) $data['order_id']);
        $this->customerId      = max(0, (int) $data['customer_id']);
        $this->phone           = trim((string) $data['phone']);
        $this->shippingPhone   = trim((string) $data['shipping_phone']);
        $this->email           = trim((string) $data['email']);
        $this->firstName       = trim((string) $data['first_name']);
        $this->lastName        = trim((string) $data['last_name']);
        $this->ip              = trim((string) $data['ip']);
        $this->deviceId        = trim((string) $data['device_id']);
        $this->total           = max(0.0, (float) $data['total']);
        $this->currency        = strtoupper(trim((string) $data['currency']));
        $this->paymentMethod   = trim((string) $data['payment_method']);
        $this->totalQuantity   = max(0, (int) $data['total_quantity']);
        $this->maxLineQuantity = max(0, (int) $data['max_line_quantity']);
        $this->lineCount       = max(0, (int) $data['line_count']);
        $this->address1        = trim((string) $data['address_1']);
        $this->address2        = trim((string) $data['address_2']);
        $this->city            = trim((string) $data['city']);
        $this->district        = trim((string) $data['district']);
        $this->postcode        = trim((string) $data['postcode']);
        $this->country         = strtoupper(trim((string) $data['country']));
        $this->createdAt       = self::toDate($data['created_at']);
        $this->phoneVerified   = (bool) $data['phone_verified'];
        $this->meta            = is_array($data['meta']) ? $data['meta'] : [];
    }

    /**
     * Build a context from an associative array. Unknown keys are ignored.
     *
     * @param array<string, mixed> $data Order data keyed like toArray().
     */
    public static function fromArray(array $data): self
    {
        $defaults = self::defaults();

        return new self(array_merge($defaults, array_intersect_key($data, $defaults)));
    }

    /**
     * Return a copy with some fields replaced.
     *
     * @param array<string, mixed> $changes Fields to replace.
     */
    public function with(array $changes): self
    {
        return self::fromArray(array_merge($this->toArray(), $changes));
    }

    /**
     * Full customer name.
     */
    public function fullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    /**
     * Export as an array (the inverse of fromArray()).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'order_id'          => $this->orderId,
            'customer_id'       => $this->customerId,
            'phone'             => $this->phone,
            'shipping_phone'    => $this->shippingPhone,
            'email'             => $this->email,
            'first_name'        => $this->firstName,
            'last_name'         => $this->lastName,
            'ip'                => $this->ip,
            'device_id'         => $this->deviceId,
            'total'             => $this->total,
            'currency'          => $this->currency,
            'payment_method'    => $this->paymentMethod,
            'total_quantity'    => $this->totalQuantity,
            'max_line_quantity' => $this->maxLineQuantity,
            'line_count'        => $this->lineCount,
            'address_1'         => $this->address1,
            'address_2'         => $this->address2,
            'city'              => $this->city,
            'district'          => $this->district,
            'postcode'          => $this->postcode,
            'country'           => $this->country,
            'created_at'        => $this->createdAt,
            'phone_verified'    => $this->phoneVerified,
            'meta'              => $this->meta,
        ];
    }

    /**
     * Coerce a mixed value into an immutable date (defaults to "now", UTC).
     *
     * @param mixed $value Date value: DateTimeInterface, unix timestamp or date string.
     */
    private static function toDate($value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        $utc = new DateTimeZone('UTC');

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (new DateTimeImmutable('@' . $value))->setTimezone($utc);
        }

        if (is_string($value) && '' !== trim($value)) {
            try {
                return new DateTimeImmutable($value, $utc);
            } catch (Exception $e) {
                return new DateTimeImmutable('now', $utc);
            }
        }

        return new DateTimeImmutable('now', $utc);
    }
}
