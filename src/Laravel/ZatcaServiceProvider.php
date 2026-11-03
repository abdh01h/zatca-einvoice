<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Zatca\EInvoice\Crypto\InvoiceSigner;
use Zatca\EInvoice\Crypto\SigningCredentials;
use Zatca\EInvoice\Xml\InvoiceDocumentBuilder;
use Zatca\EInvoice\Xml\RootElement;

/**
 * Optional Laravel wiring.
 *
 * illuminate/support is not a dependency of this package: the provider is only
 * loaded when the application registers it, so plain-PHP users are unaffected.
 */
final class ZatcaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/zatca.php', 'zatca');

        $this->app->singleton(InvoiceDocumentBuilder::class, static fn (Application $app): InvoiceDocumentBuilder => new InvoiceDocumentBuilder(
            RootElement::from((string) $app['config']->get('zatca.root_element', 'Invoice')),
        ));

        $this->app->singleton(SigningCredentials::class, static function (Application $app): SigningCredentials {
            $certificate = $app['config']->get('zatca.certificate_path');
            $privateKey = $app['config']->get('zatca.private_key_path');

            if (! is_string($certificate) || ! is_string($privateKey)) {
                throw new \RuntimeException('Set ZATCA_CERTIFICATE_PATH and ZATCA_PRIVATE_KEY_PATH.');
            }

            return SigningCredentials::fromFiles($certificate, $privateKey);
        });

        $this->app->singleton(InvoiceSigner::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../../config/zatca.php' => $this->app->configPath('zatca.php'),
        ], 'zatca-config');

        $this->publishes([
            __DIR__.'/../../resources/xsd' => $this->app->resourcePath('zatca/xsd'),
        ], 'zatca-schemas');
    }
}
