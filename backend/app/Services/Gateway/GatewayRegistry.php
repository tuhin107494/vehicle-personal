<?php

namespace App\Services\Gateway;

use App\Exceptions\BusinessRuleException;
use App\Models\Gateway\GatewayConfig;
use App\Services\Gateway\Gateways\BkashGateway;
use App\Services\Gateway\Gateways\ManualGateway;

/**
 * Finds the gateway class for a code. Adding a gateway is one class in Gateways/ plus one line in BUILT_IN (and a
 * seeded gateway_configs row); nothing else in the app changes. Tests register doubles with register().
 */
class GatewayRegistry
{
    /** @var array<string, class-string<PaymentGatewayInterface>> code => class */
    private const BUILT_IN = [
        ManualGateway::CODE => ManualGateway::class,
        BkashGateway::CODE => BkashGateway::class,
    ];

    /** @var array<string, class-string<PaymentGatewayInterface>>|null */
    private static ?array $gateways = null;

    /** @return array<string, class-string<PaymentGatewayInterface>> */
    public static function all(): array
    {
        return self::$gateways ??= self::BUILT_IN;
    }

    /** @param  class-string<PaymentGatewayInterface>  $class */
    public static function register(string $code, string $class): void
    {
        self::all();
        self::$gateways[$code] = $class;
    }

    /** Back to the built-in list (tests). */
    public static function reset(): void
    {
        self::$gateways = null;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    public static function knows(string $code): bool
    {
        return isset(self::all()[$code]);
    }

    /** @return class-string<PaymentGatewayInterface> */
    public static function classFor(string $code): string
    {
        if (! self::knows($code)) {
            throw new BusinessRuleException('Unknown payment gateway.');
        }

        return self::all()[$code];
    }

    /** The gateway, set up with the saved configuration of its active mode. */
    public function make(GatewayConfig $config): PaymentGatewayInterface
    {
        $class = self::classFor($config->code);

        return new $class($config);
    }

    public function makeByCode(string $code): PaymentGatewayInterface
    {
        $config = GatewayConfig::where('code', $code)->first();

        if (! $config) {
            throw new BusinessRuleException('Unknown payment gateway.');
        }

        return $this->make($config);
    }
}
