<?php

namespace App\Kit\Catalog;

use RuntimeException;

/** The catalog cannot be read or saved, with the status and the words to answer (App\Kit\Catalog\Catalog, VoicePreview). */
final class CatalogRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
