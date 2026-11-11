<?php
/**
 * SMS Sender: single send() handles everything.
 *
 * 1. Pre-flight: module enabled, gateway configured, sender ID set
 * 2. Clean message (emoji, HTML, hidden chars)
 * 3. Normalize, deduplicate, validate all numbers
 * 4. Coverage filter
 * 5. Balance check from cached DB (refreshes from API if cache > 24 hours)
 * 6. Delegate to Client::send() which auto-chunks 200+ with 500ms delay
 * 7. On success: update local balance from API balance-after response
 * 8. Log every outcome
 *
 * @see \KwtSms\SmsIntegration\Model\Api\Client
 * @see \KwtSms\SmsIntegration\Model\Phone\Normalizer
 * @see \KwtSms\SmsIntegration\Model\MessageCleaner
 */
declare(strict_types=1);

namespace KwtSms\SmsIntegration\Model;

use KwtSms\SmsIntegration\Api\Data\GatewayDataInterface;
use KwtSms\SmsIntegration\Api\Data\SmsLogInterface;
use KwtSms\SmsIntegration\Api\Data\SmsLogInterfaceFactory;
use KwtSms\SmsIntegration\Api\SmsLogRepositoryInterface;
use KwtSms\SmsIntegration\Model\Api\Client;
use KwtSms\SmsIntegration\Model\Api\ErrorCodes;
use KwtSms\SmsIntegration\Model\GatewayList;
use KwtSms\SmsIntegration\Model\Phone\Normalizer;
use KwtSms\SmsIntegration\Model\ResourceModel\GatewayData as GatewayDataResource;
use Psr\Log\LoggerInterface;

class SmsSender
{
    private Config $config;
    private Normalizer $normalizer;
    private MessageCleaner $messageCleaner;
    private Client $apiClient;
    private SmsLogRepositoryInterface $smsLogRepository;
    private SmsLogInterfaceFactory $smsLogFactory;
    private GatewayDataResource $gatewayDataResource;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        Normalizer $normalizer,
        MessageCleaner $messageCleaner,
        Client $apiClient,
        SmsLogRepositoryInterface $smsLogRepository,
        SmsLogInterfaceFactory $smsLogFactory,
        GatewayDataResource $gatewayDataResource,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->normalizer = $normalizer;
        $this->messageCleaner = $messageCleaner;
        $this->apiClient = $apiClient;
        $this->smsLogRepository = $smsLogRepository;
        $this->smsLogFactory = $smsLogFactory;
        $this->gatewayDataResource = $gatewayDataResource;
        $this->logger = $logger;
    }

    /**
     * Send SMS to one or many recipients.
     *
     * @param string|array $recipients  Single phone or array of phones
     * @param string       $message     Raw message (cleaned automatically)
     * @param string       $eventType   Event identifier for logging
     * @param string|null  $relatedEntityId   Order ID, customer ID, etc.
     * @param string|null  $relatedEntityType 'order', 'customer', etc.
     * @return array{success:bool, sent:int, failed:int, skipped:int, invalid:int, error:?string, msg_ids:string[]}
     */
    public function send(
        string|array $recipients,
        string $message,
        string $eventType,
        ?string $relatedEntityId = null,
        ?string $relatedEntityType = null
    ): array {
        $result = [
            'success' => false,
            'sent'    => 0,
            'failed'  => 0,
            'skipped' => 0,
            'invalid' => 0,
            'error'   => null,
            'msg_ids' => [],
        ];

        // ---- 1. Pre-flight ----

        if (!$this->config->isEnabled()) {
            $result['error'] = 'SMS module is disabled. Enable it in Stores > Configuration > kwtSMS.';
            $this->logger->info('kwtSMS: ' . $result['error']);
            return $result;
        }

        if (!$this->config->getApiUsername() || !$this->config->getApiPassword()) {
            $result['error'] = 'Gateway not configured. Enter API credentials and click Login.';
            $this->logger->error('kwtSMS: ' . $result['error']);
            return $result;
        }

        $senderId = $this->config->getSenderId();
        if (!$senderId) {
            $result['error'] = 'No Sender ID selected. Login to the gateway first, then select a Sender ID.';
            $this->logger->error('kwtSMS: ' . $result['error']);
            return $result;
        }

        $testMode = $this->config->isTestMode();

        // ---- 2. Clean message ----

        $cleanMessage = $this->messageCleaner->clean($message);
        if ($cleanMessage === '') {
            $result['error'] = 'Message is empty after cleaning (emoji, HTML, hidden characters removed).';
            $this->logger->error('kwtSMS: ' . $result['error']);
            return $result;
        }

        // ---- 3. Normalize, deduplicate, validate ----

        $inputNumbers = is_array($recipients) ? $recipients : [$recipients];
        if (empty($inputNumbers)) {
            $result['error'] = 'No recipients provided.';
            return $result;
        }

        $defaultCountry = $this->config->getDefaultCountryCode();
        $validNumbers = [];
        $seen = [];

        foreach ($inputNumbers as $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') {
                $result['invalid']++;
                continue;
            }

            $phoneResult = $this->normalizer->normalize($raw, $defaultCountry);
            if (!$phoneResult->isValid()) {
                $result['invalid']++;
                $this->saveLog(
                    $raw, $cleanMessage, $eventType, SmsLogInterface::STATUS_FAILED,
                    null, null, 'Invalid number: ' . $phoneResult->getError(),
                    $relatedEntityId, $relatedEntityType, $testMode
                );
                continue;
            }

            $normalized = $phoneResult->getNormalized();
            if (isset($seen[$normalized])) {
                continue; // deduplicate
            }
            $seen[$normalized] = true;
            $validNumbers[] = $normalized;
        }

        if (empty($validNumbers)) {
            $result['error'] = 'No valid phone numbers after normalization.';
            return $result;
        }

        // ---- 4. Coverage filter ----

        $coveragePrefixes = $this->loadCoveragePrefixes();
        if (!empty($coveragePrefixes)) {
            $covered = [];
            foreach ($validNumbers as $number) {
                if ($this->normalizer->isInCoverage($number, $coveragePrefixes)) {
                    $covered[] = $number;
                } else {
                    $result['skipped']++;
                    $cc = $this->normalizer->findCountryCode($number);
                    $this->saveLog(
                        $number, $cleanMessage, $eventType, SmsLogInterface::STATUS_SKIPPED,
                        null, null,
                        'Country +' . ($cc ?? '?') . ' not in your coverage.',
                        $relatedEntityId, $relatedEntityType, $testMode
                    );
                }
            }
            $validNumbers = $covered;
        }

        if (empty($validNumbers)) {
            $result['error'] = 'All numbers filtered out. Recipient countries not active on your account.';
            return $result;
        }

        // ---- 5. Balance check (once, from cache; refreshes if > 24h stale) ----

        $balance = $this->getAvailableBalance();
        if ($balance !== null && $balance <= 0) {
            $result['error'] = 'Account balance is zero. Recharge at kwtsms.com before sending.';
            $this->logger->warning('kwtSMS: ' . $result['error']);
            foreach ($validNumbers as $number) {
                $result['skipped']++;
                $this->saveLog(
                    $number, $cleanMessage, $eventType, SmsLogInterface::STATUS_SKIPPED,
                    null, null, 'Account balance is zero.',
                    $relatedEntityId, $relatedEntityType, $testMode
                );
            }
            return $result;
        }

        // ---- 6. Send (Client handles chunking for 200+ numbers) ----

        $mobileParam = implode(',', $validNumbers);

        try {
            $response = $this->apiClient->send($senderId, $mobileParam, $cleanMessage, $testMode);

            $isOk = isset($response['result']) && in_array($response['result'], ['OK', 'PARTIAL'], true);

            if ($isOk) {
                $status = $testMode ? SmsLogInterface::STATUS_TEST : SmsLogInterface::STATUS_SENT;
                $sentCount = (int) ($response['numbers'] ?? count($validNumbers));
                $charged = (int) ($response['points-charged'] ?? 0);

                // Collect msg IDs (single or bulk)
                if (isset($response['msg-id'])) {
                    $result['msg_ids'][] = $response['msg-id'];
                } elseif (isset($response['msg-ids'])) {
                    $result['msg_ids'] = $response['msg-ids'];
                }

                $result['sent'] = $sentCount;

                $this->saveLog(
                    $mobileParam, $cleanMessage, $eventType, $status,
                    $response['msg-id'] ?? ($response['msg-ids'][0] ?? null),
                    $charged, null,
                    $relatedEntityId, $relatedEntityType, $testMode,
                    json_encode($response)
                );

                // 7. Update local balance from API response
                $balanceAfter = $response['balance-after'] ?? null;
                if ($balanceAfter !== null) {
                    $this->updateLocalBalance((string) $balanceAfter);
                }

                // Handle partial failures in bulk
                if ($response['result'] === 'PARTIAL' && !empty($response['errors'])) {
                    $result['failed'] = count($response['errors']);
                }
            } else {
                $errorCode = $response['code'] ?? $response['result'] ?? 'UNKNOWN';
                $errorDesc = $response['description'] ?? ErrorCodes::getDescription($errorCode);

                $result['failed'] = count($validNumbers);
                $result['error'] = $errorCode . ': ' . $errorDesc;

                $this->logger->error('kwtSMS: API error', ['code' => $errorCode, 'desc' => $errorDesc]);
                $this->saveLog(
                    $mobileParam, $cleanMessage, $eventType, SmsLogInterface::STATUS_FAILED,
                    null, null, $errorCode . ': ' . $errorDesc,
                    $relatedEntityId, $relatedEntityType, $testMode,
                    json_encode($response)
                );
            }
        } catch (\Exception $e) {
            $result['failed'] = count($validNumbers);
            $result['error'] = 'System error: ' . $e->getMessage();
            $this->logger->error('kwtSMS: Send failed', ['message' => $e->getMessage()]);
            $this->saveLog(
                $mobileParam, $cleanMessage, $eventType, SmsLogInterface::STATUS_FAILED,
                null, null, 'System error: ' . $e->getMessage(),
                $relatedEntityId, $relatedEntityType, $testMode
            );
        }

        $result['success'] = $result['sent'] > 0;
        return $result;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Get available balance from local cache.
     * If cache is null or older than 24 hours, refreshes from /balance/ API.
     */
    private function getAvailableBalance(): ?float
    {
        $raw = $this->getGatewayValue(GatewayDataInterface::TYPE_BALANCE);
        $syncedAt = $this->getGatewaySyncedAt(GatewayDataInterface::TYPE_BALANCE);

        $isStale = ($raw === null)
            || ($syncedAt === null)
            || (strtotime($syncedAt) < strtotime('-24 hours'));

        if ($isStale) {
            $fresh = $this->refreshBalanceFromApi();
            if ($fresh !== null) {
                return $fresh;
            }
        }

        if ($raw === null) {
            return null;
        }
        $data = json_decode($raw, true);
        if (is_array($data) && isset($data['available'])) {
            return (float) $data['available'];
        }
        return (float) $raw;
    }

    /** Call /balance/ API, save result, return available. Null on failure. */
    private function refreshBalanceFromApi(): ?float
    {
        try {
            $response = $this->apiClient->getBalance();
            if (isset($response['result'], $response['available']) && $response['result'] === 'OK') {
                $this->updateLocalBalance(
                    (string) $response['available'],
                    (int) ($response['purchased'] ?? 0)
                );
                return (float) $response['available'];
            }
        } catch (\Exception $e) {
            $this->logger->debug('kwtSMS: Balance refresh failed, using cache', [
                'message' => $e->getMessage(),
            ]);
        }
        return null;
    }

    /** Read coverage prefixes from local DB cache. */
    private function loadCoveragePrefixes(): array
    {
        // 2026-11-11 18:03 +03: read the list from a bare list or a full API response row. Task magento-111.
        return GatewayList::fromJson(
            $this->getGatewayValue(GatewayDataInterface::TYPE_COVERAGE),
            GatewayList::KEY_COVERAGE
        );
    }

    /** Persist an SMS log entry. */
    private function saveLog(
        string $recipient,
        string $message,
        string $eventType,
        string $status,
        ?string $msgId = null,
        ?int $pointsCharged = null,
        ?string $errorCode = null,
        ?string $relatedEntityId = null,
        ?string $relatedEntityType = null,
        bool $testMode = false,
        ?string $apiResponse = null
    ): void {
        try {
            $smsLog = $this->smsLogFactory->create();
            $smsLog->setRecipient($recipient);
            $smsLog->setMessage($message);
            $smsLog->setEventType($eventType);
            $smsLog->setStatus($status);
            $smsLog->setTestMode($testMode ? 1 : 0);
            if ($msgId !== null) {
                $smsLog->setMsgId($msgId);
            }
            if ($pointsCharged !== null) {
                $smsLog->setPointsCharged($pointsCharged);
            }
            if ($errorCode !== null) {
                $smsLog->setErrorCode(mb_substr($errorCode, 0, 255));
            }
            if ($relatedEntityId !== null) {
                $smsLog->setRelatedEntityId($relatedEntityId);
            }
            if ($relatedEntityType !== null) {
                $smsLog->setRelatedEntityType($relatedEntityType);
            }
            if ($apiResponse !== null) {
                $smsLog->setApiResponse($apiResponse);
            }
            $this->smsLogRepository->save($smsLog);
        } catch (\Exception $e) {
            $this->logger->error('kwtSMS: Failed to save log', ['message' => $e->getMessage()]);
        }
    }

    /** Read synced_at from gateway_data. DB only. */
    private function getGatewaySyncedAt(string $type): ?string
    {
        try {
            $connection = $this->gatewayDataResource->getConnection();
            $tableName = $this->gatewayDataResource->getMainTable();
            $select = $connection->select()
                ->from($tableName, [GatewayDataInterface::SYNCED_AT])
                ->where(GatewayDataInterface::DATA_TYPE . ' = ?', $type)
                ->limit(1);
            $value = $connection->fetchOne($select);
            return ($value !== false && $value !== null) ? (string) $value : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /** Read data_value from gateway_data. DB only. */
    private function getGatewayValue(string $type): ?string
    {
        try {
            $connection = $this->gatewayDataResource->getConnection();
            $tableName = $this->gatewayDataResource->getMainTable();
            $select = $connection->select()
                ->from($tableName, [GatewayDataInterface::DATA_VALUE])
                ->where(GatewayDataInterface::DATA_TYPE . ' = ?', $type)
                ->limit(1);
            $value = $connection->fetchOne($select);
            return $value !== false ? (string) $value : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /** Update cached balance. Preserves purchased if not provided. */
    private function updateLocalBalance(string $available, ?int $purchased = null): void
    {
        try {
            $connection = $this->gatewayDataResource->getConnection();
            $tableName = $this->gatewayDataResource->getMainTable();
            $currentRaw = $this->getGatewayValue(GatewayDataInterface::TYPE_BALANCE);
            $data = ($currentRaw !== null) ? json_decode($currentRaw, true) : [];
            if (!is_array($data)) {
                $data = [];
            }
            $data['result'] = 'OK';
            $data['available'] = (int) $available;
            if ($purchased !== null) {
                $data['purchased'] = $purchased;
            }

            $now = date('Y-m-d H:i:s');
            $exists = $connection->fetchOne(
                $connection->select()->from($tableName, ['entity_id'])
                    ->where(GatewayDataInterface::DATA_TYPE . ' = ?', GatewayDataInterface::TYPE_BALANCE)
            );

            if ($exists) {
                $connection->update(
                    $tableName,
                    [GatewayDataInterface::DATA_VALUE => json_encode($data), GatewayDataInterface::SYNCED_AT => $now],
                    [GatewayDataInterface::DATA_TYPE . ' = ?' => GatewayDataInterface::TYPE_BALANCE]
                );
            } else {
                $connection->insert($tableName, [
                    GatewayDataInterface::DATA_TYPE  => GatewayDataInterface::TYPE_BALANCE,
                    GatewayDataInterface::DATA_VALUE => json_encode($data),
                    GatewayDataInterface::SYNCED_AT  => $now,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('kwtSMS: Failed to update balance cache', ['message' => $e->getMessage()]);
        }
    }
}
