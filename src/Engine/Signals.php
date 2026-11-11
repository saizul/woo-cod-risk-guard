<?php
/**
 * Services available to rules.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Engine;

use DateTimeZone;
use Saizul\CodRiskGuard\History\HistoryProviderInterface;
use Saizul\CodRiskGuard\History\InMemoryHistoryProvider;
use Saizul\CodRiskGuard\History\InMemoryVelocityProvider;
use Saizul\CodRiskGuard\History\VelocityProviderInterface;
use Saizul\CodRiskGuard\Lists\InMemoryListStore;
use Saizul\CodRiskGuard\Lists\ListStoreInterface;
use Saizul\CodRiskGuard\Support\AddressAnalyzer;
use Saizul\CodRiskGuard\Support\DisposableEmailDomains;
use Saizul\CodRiskGuard\Support\GibberishDetector;
use Saizul\CodRiskGuard\Support\IdentifierHasher;
use Saizul\CodRiskGuard\Support\PhoneNormalizer;

/**
 * Bundle of data sources and helpers handed to every rule.
 *
 * Everything has an in-memory default so the engine can run without any
 * database, which is what makes it unit-testable.
 */
final class Signals
{
    public IdentifierHasher $hasher;
    public HistoryProviderInterface $history;
    public VelocityProviderInterface $velocity;
    public ListStoreInterface $lists;
    public PhoneNormalizer $phones;
    public AddressAnalyzer $addresses;
    public DisposableEmailDomains $disposable;
    public GibberishDetector $gibberish;
    public DateTimeZone $timezone;

    public function __construct(
        IdentifierHasher $hasher,
        ?HistoryProviderInterface $history = null,
        ?VelocityProviderInterface $velocity = null,
        ?ListStoreInterface $lists = null,
        ?DateTimeZone $timezone = null,
        ?PhoneNormalizer $phones = null,
        ?AddressAnalyzer $addresses = null,
        ?DisposableEmailDomains $disposable = null,
        ?GibberishDetector $gibberish = null
    ) {
        $this->hasher     = $hasher;
        $this->history    = $history ?? new InMemoryHistoryProvider();
        $this->velocity   = $velocity ?? new InMemoryVelocityProvider();
        $this->lists      = $lists ?? new InMemoryListStore();
        $this->timezone   = $timezone ?? new DateTimeZone('Asia/Dhaka');
        $this->phones     = $phones ?? new PhoneNormalizer();
        $this->gibberish  = $gibberish ?? new GibberishDetector();
        $this->addresses  = $addresses ?? new AddressAnalyzer(10, $this->gibberish);
        $this->disposable = $disposable ?? new DisposableEmailDomains();
    }

    /**
     * Hashes of every identifier on the order, keyed by identifier type.
     * Empty identifiers are omitted.
     *
     * @return array<string, string>
     */
    public function identifierHashes(OrderContext $order): array
    {
        $hashes = [
            IdentifierHasher::TYPE_PHONE  => $this->hasher->hashPhone($order->phone),
            IdentifierHasher::TYPE_EMAIL  => $this->hasher->hashEmail($order->email),
            IdentifierHasher::TYPE_IP     => $this->hasher->hashIp($order->ip),
            IdentifierHasher::TYPE_DEVICE => $this->hasher->hashDevice($order->deviceId),
        ];

        return array_filter(
            $hashes,
            static function (string $hash): bool {
                return '' !== $hash;
            }
        );
    }
}
