<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Xml;

/** Namespace URIs used by a ZATCA-conformant UBL 2.1 document. */
final class Namespaces
{
    public const INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    public const CREDIT_NOTE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';
    public const DEBIT_NOTE = 'urn:oasis:names:specification:ubl:schema:xsd:DebitNote-2';

    public const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    public const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    public const EXT = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';
    public const SIG = 'urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2';
    public const SAC = 'urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2';
    public const SBC = 'urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2';

    public const DS = 'http://www.w3.org/2000/09/xmldsig#';
    public const XADES = 'http://uri.etsi.org/01903/v1.3.2#';

    public const C14N11 = 'http://www.w3.org/2006/12/xml-c14n11';
    public const ECDSA_SHA256 = 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256';
    public const SHA256 = 'http://www.w3.org/2001/04/xmlenc#sha256';
    public const XPATH_TRANSFORM = 'http://www.w3.org/TR/1999/REC-xpath-19991116';
    public const SIGNATURE_PROPERTIES_TYPE = 'http://www.w3.org/2000/09/xmldsig#SignatureProperties';

    public const UBL_SIGNATURE_URI_PREFIX = 'urn:oasis:names:specification:ubl:signature:';
}
