<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/CashuWallet.php';

/**
 * Load a JSON fixture from tests/fixtures/.
 *
 * Fixture files carry official protocol test vectors; each records its
 * provenance (upstream repository and commit) under the "_provenance" key.
 */
function cashu_fixture(string $name): array
{
    static $cache = [];

    if (!isset($cache[$name])) {
        $path = __DIR__ . '/fixtures/' . $name . '.json';
        if (!is_file($path)) {
            throw new RuntimeException("Fixture not found: $path");
        }
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $cache[$name] = $data;
    }

    return $cache[$name];
}
