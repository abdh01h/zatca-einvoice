<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Xml;

/** Resolves the namespace URI that belongs to an element prefix. */
final class Prefixes
{
    /** @var array<string, string> */
    private const MAP = [
        'cac' => Namespaces::CAC,
        'cbc' => Namespaces::CBC,
        'ext' => Namespaces::EXT,
        'sig' => Namespaces::SIG,
        'sac' => Namespaces::SAC,
        'ds' => Namespaces::DS,
        'xades' => Namespaces::XADES,
    ];

    public static function uriFor(string $qualifiedName): ?string
    {
        if (! str_contains($qualifiedName, ':')) {
            return null;
        }

        $prefix = strstr($qualifiedName, ':', true);

        return self::MAP[$prefix] ?? null;
    }
}
