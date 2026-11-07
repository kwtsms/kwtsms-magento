<?php
/**
 * kwtSMS API Client.
 *
 * All HTTP communication with the kwtSMS gateway.
 * Every request: HTTPS POST, Content-Type: application/json.
 * Base URL: https://www.kwtsms.com/API/
 *
 * send() auto-chunks at 200 recipients with 500ms delay between chunks.
 * Max message length: 7 pages (1120 chars English, 490 chars Arabic).
 *
 * @see \KwtSms\SmsIntegration\Model\Config
 */
declare(strict_types=1);

namespace KwtSms\SmsIntegration\Model\Api;

use KwtSms\SmsIntegration\Model\Config;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Module\PackageInfo;
use Psr\Log\LoggerInterface;

class Client
{
    private const BASE_URL = 'https://www.kwtsms.com/API';

    /** kwtSMS API limit: max recipients per request. */
    private const BATCH_SIZE = 200;

    /** Delay between batch API calls in microseconds (0.5 seconds). */
    private const BATCH_DELAY_US = 500000;

    /** Max SMS pages: 7 parts, 1071 chars English, 469 chars Arabic (ERR012 at 8+). */
    public const MAX_PAGES = 7;

    private Config $config;
    private CurlFactory $curlFactory;
    private LoggerInterface $logger;
    private PackageInfo $packageInfo;

    /**
     * 2026-11-07 08:15 +03: PackageInfo added to read the module version for the User-Agent (replaces a silenced
     * file_get_contents that fails the Marketplace coding standard at severity 10). Task int-rerelease-number.
     */
    public function __construct(
        Config $config,
        CurlFactory $curlFactory,
        LoggerInterface $logger,
        PackageInfo $packageInfo
    ) {
        $this->config = $config;
        $this->curlFactory = $curlFactory;
        $this->logger = $logger;
        $this->packageInfo = $packageInfo;
    }

    /**
     * Test connection / login with given credentials.
     * Calls /balance/ to verify credentials are valid.
     */
    public function testConnection(string $username, string $password): array
    {
        return $this->doRequest('balance', [
            'username' => $username,
            'password' => $password,
        ]);
    }

    /**
     * Get current account balance.
     * Returns: {result, available, purchased}
     */
    public function getBalance(): array
    {
        return $this->doRequest('balance', [
            'username' => $this->config->getApiUsername(),
            'password' => $this->config->getApiPassword(),
        ]);
    }

    /**
     * Get approved sender IDs.
     * Returns: {result, senderid: [...]}
     */
    public function getSenderIds(): array
    {
        return $this->doRequest('senderid', [
            'username' => $this->config->getApiUsername(),
            'password' => $this->config->getApiPassword(),
        ]);
    }

    /**
     * Get active coverage country prefixes.
     * Returns: {result, prefixes: [...]}
     */
    public function getCoverage(): array
    {
        return $this->doRequest('coverage', [
            'username' => $this->config->getApiUsername(),
            'password' => $this->config->getApiPassword(),
        ]);
    }

    /**
     * Send SMS. Handles any number of recipients.
     *
     * - 1-200 numbers: single API call
     * - 200+ numbers: auto-chunks into batches of 200 with 500ms delay
     *
     * @param string $sender   Sender ID (case sensitive)
     * @param string $mobile   Comma-separated phone numbers (already normalized)
     * @param string $message  Clean message text
     * @param bool   $testMode If true, messages queued but not delivered
     * @return array Single-batch: direct API response. Multi-batch: aggregated result.
     */
    public function send(string $sender, string $mobile, string $message, bool $testMode = false): array
    {
        // Split mobile string into individual numbers
        $numbers = array_filter(array_map('trim', explode(',', $mobile)));
        $count = count($numbers);

        if ($count === 0) {
            return ['result' => 'ERROR', 'code' => 'ERR006', 'description' => 'No valid numbers submitted'];
        }

        // Single batch: 1-200 numbers, one API call
        if ($count <= self::BATCH_SIZE) {
            return $this->sendBatch($sender, implode(',', $numbers), $message, $testMode);
        }

        // Multi-batch: 200+ numbers, chunk and send with delay
        $chunks = array_chunk($numbers, self::BATCH_SIZE);
        $aggregated = [
            'result'         => 'OK',
            'bulk'           => true,
            'batches'        => count($chunks),
            'numbers'        => 0,
            'points-charged' => 0,
            'balance-after'  => null,
            'msg-ids'        => [],
            'errors'         => [],
        ];

        foreach ($chunks as $index => $chunk) {
            if ($index > 0) {
                usleep(self::BATCH_DELAY_US);
            }

            try {
                $response = $this->sendBatch($sender, implode(',', $chunk), $message, $testMode);

                if (isset($response['result']) && $response['result'] === 'OK') {
                    $aggregated['numbers'] += (int) ($response['numbers'] ?? count($chunk));
                    $aggregated['points-charged'] += (int) ($response['points-charged'] ?? 0);
                    $aggregated['balance-after'] = $response['balance-after'] ?? $aggregated['balance-after'];
                    if (isset($response['msg-id'])) {
                        $aggregated['msg-ids'][] = $response['msg-id'];
                    }
                } else {
                    $aggregated['errors'][] = [
                        'batch' => $index + 1,
                        'code'  => $response['code'] ?? $response['result'] ?? 'UNKNOWN',
                        'description' => $response['description'] ?? 'Unknown error',
                    ];
                }
            } catch (\Exception $e) {
                $aggregated['errors'][] = [
                    'batch' => $index + 1,
                    'code'  => 'EXCEPTION',
                    'description' => $e->getMessage(),
                ];
            }
        }

        // If all batches failed, mark as ERROR
        if (empty($aggregated['msg-ids'])) {
            $aggregated['result'] = 'ERROR';
        } elseif (!empty($aggregated['errors'])) {
            $aggregated['result'] = 'PARTIAL';
        }

        return $aggregated;
    }

    /**
     * Validate phone numbers via the API.
     */
    public function validateNumbers(array $numbers): array
    {
        return $this->doRequest('validate', [
            'username' => $this->config->getApiUsername(),
            'password' => $this->config->getApiPassword(),
            'mobile'   => implode(',', $numbers),
        ]);
    }

    /**
     * Send one batch (up to 200 numbers) to the /send/ endpoint.
     */
    private function sendBatch(string $sender, string $mobile, string $message, bool $testMode): array
    {
        $params = [
            'username' => $this->config->getApiUsername(),
            'password' => $this->config->getApiPassword(),
            'sender'   => $sender,
            'mobile'   => $mobile,
            'message'  => $message,
        ];

        if ($testMode) {
            $params['test'] = 1;
        }

        return $this->doRequest('send', $params);
    }

    /**
     * User-Agent token sent to kwtSMS: "kwtsms-magento/<version>", the version from this module's composer.json.
     * 2026-11-05 14:58 +03: added so kwtSMS can count accounts per integration (name and version only,
     * no personal data), #939.
     */
    public function getUserAgent(): string
    {
        static $userAgent = null;

        if ($userAgent === null) {
            // 2026-11-07 08:15 +03: version from Magento's PackageInfo (composer.json "version"), no file read here.
            $version = (string) ($this->packageInfo->getVersion('KwtSms_SmsIntegration') ?: '0');
            $userAgent = 'kwtsms-magento/' . preg_replace('/[^0-9A-Za-z.+-]/', '', $version);
        }

        return $userAgent;
    }

    /**
     * Execute an API request. HTTPS POST with JSON body.
     * Never logs credentials.
     */
    private function doRequest(string $endpoint, array $params): array
    {
        $url = self::BASE_URL . '/' . $endpoint . '/';

        try {
            $curl = $this->curlFactory->create();
            $curl->addHeader('Content-Type', 'application/json');
            $curl->addHeader('Accept', 'application/json');
            // 2026-11-05 14:58 +03: identify the extension and its version to kwtSMS, #939.
            $curl->addHeader('User-Agent', $this->getUserAgent());
            $curl->post($url, json_encode($params));

            $responseBody = $curl->getBody();
            $httpStatus = $curl->getStatus();

            if ($this->config->isDebugEnabled()) {
                $safeParams = $params;
                unset($safeParams['password']);
                $this->logger->debug('kwtSMS API request', [
                    'endpoint' => $endpoint,
                    'params'   => $safeParams,
                    'status'   => $httpStatus,
                ]);
            }

            $decoded = json_decode($responseBody, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException(
                    sprintf('kwtSMS API returned invalid JSON for %s (HTTP %d)', $endpoint, $httpStatus)
                );
            }

            if ($this->config->isDebugEnabled()) {
                $this->logger->debug('kwtSMS API response', [
                    'endpoint' => $endpoint,
                    'response' => $decoded,
                ]);
            }

            return $decoded;
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('kwtSMS API request failed', [
                'endpoint'  => $endpoint,
                'exception' => $e->getMessage(),
            ]);
            throw new \RuntimeException(
                sprintf('kwtSMS API request to %s failed: %s', $endpoint, $e->getMessage()),
                0,
                $e
            );
        }
    }
}
