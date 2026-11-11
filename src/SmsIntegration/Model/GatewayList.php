<?php
/**
 * FILE:      Model/GatewayList.php
 * CREATED:   2026-11-11 18:03 +03
 * TASK:      magento-111
 * AUTHOR:    M Alhassoun
 * WHAT:      Reads a cached gateway list (sender IDs or coverage prefixes) from its stored JSON.
 * WHY:       The list is stored as a bare JSON array. Rows written in the full API response shape
 *            ({"result":"OK","senderid":[...]} or {"result":"OK","prefixes":[...]}) are read as their list,
 *            so stores holding such a row keep working without a manual sync.
 * USAGE:     GatewayList::fromJson($storedJson, GatewayList::KEY_COVERAGE)
 * DEPENDS:   PHP only.
 */
declare(strict_types=1);

namespace KwtSms\SmsIntegration\Model;

class GatewayList
{
    /**
     * API response key of the sender ID list.
     */
    public const KEY_SENDERID = 'senderid';

    /**
     * API response key of the coverage prefix list.
     */
    public const KEY_COVERAGE = 'prefixes';

    /**
     * Decode a stored list.
     *
     * @param string|null $json
     * @param string $key API response key holding the list
     * @return array List of strings
     */
    public static function fromJson(?string $json, string $key): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? self::fromArray($decoded, $key) : [];
    }

    /**
     * Return the list from a bare list or from a full API response array.
     *
     * @param array $data
     * @param string $key API response key holding the list
     * @return array List of strings
     */
    public static function fromArray(array $data, string $key): array
    {
        if (array_key_exists($key, $data) || array_key_exists('result', $data)) {
            $data = isset($data[$key]) && is_array($data[$key]) ? $data[$key] : [];
        }
        $list = [];
        foreach ($data as $entry) {
            if (is_string($entry) || is_int($entry)) {
                $list[] = (string) $entry;
            }
        }
        return $list;
    }
}
