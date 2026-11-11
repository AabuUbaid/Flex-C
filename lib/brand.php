<?php
declare(strict_types=1);
// Product identity. Shown in the API, the installer and the Console. The HTML pages carry the same text.
const BRAND_NAME = 'Flex-C';
const BRAND_TAGLINE = 'Your trade, a smart move';
const BRAND_VENDOR = 'Adnys';
const BRAND_URL = 'https://adnys.com';

/** Installed version, read from version.json (stamped by the release builder). */
function app_version(): string
{
    static $v = null;
    if ($v === null) {
        $j = @json_decode((string)@file_get_contents(__DIR__ . '/../version.json'), true);
        $v = is_array($j) && preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', (string)($j['version'] ?? '')) ? $j['version'] : '0.0.0';
    }
    return $v;
}
