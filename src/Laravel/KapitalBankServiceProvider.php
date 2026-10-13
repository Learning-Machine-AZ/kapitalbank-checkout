<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout\Laravel;

use Illuminate\Support\ServiceProvider;
use LearningMachine\KapitalBank\Checkout\CheckoutClient;
use LearningMachine\KapitalBank\Checkout\Webhook\WebhookParser;

/**
 * Optional Laravel glue (auto-discovered). Binds CheckoutClient and WebhookParser as
 * singletons built from config/kapitalbank.php; requests go through Laravel's HTTP client
 * (LaravelHttpClient) and the token is cached in a Laravel cache store (PSR-16). Nothing is
 * resolved until first use, so missing credentials only fail then.
 */
final class KapitalBankServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/kapitalbank.php', 'kapitalbank');

        $this->app->singleton(CheckoutClient::class, function ($app) {
            $config = (array) $app['config']->get('kapitalbank', []);
            $store = $config['token_cache_store'] ?? null;
            $cache = $store === false || $store === 'false'
                ? null
                : $app['cache']->store($store ?: null);

            // Through Laravel's HTTP client, so Http::fake() / preventStrayRequests() cover it.
            $http = new LaravelHttpClient(
                (float) ($config['timeout'] ?? 30),
                (float) ($config['connect_timeout'] ?? 10),
            );

            return CheckoutClient::fromConfig($config, $http, cache: $cache);
        });

        $this->app->singleton(WebhookParser::class, function ($app) {
            return new WebhookParser((string) $app['config']->get('kapitalbank.webhook_secret'));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/kapitalbank.php' => $this->app->configPath('kapitalbank.php'),
            ], 'kapitalbank-config');
        }
    }
}
