<?php
/**
 * Client::send() Integration Tests.
 *
 * Tests the refactored Client::send() method that handles
 * single numbers, multiple numbers, and auto-chunking for 200+.
 * All tests hit the real kwtSMS API with test=1.
 */
declare(strict_types=1);

namespace KwtSms\SmsIntegration\Test\Integration;

use KwtSms\SmsIntegration\Model\Api\Client;
use KwtSms\SmsIntegration\Model\Config;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Minimal Config stub that returns real credentials for testing.
 * Not a mock, reads from .env just like the real Config reads from Magento DB.
 */
class TestConfig extends Config
{
    private string $username;
    private string $password;

    public function __construct(string $username, string $password)
    {
        // Skip parent constructor (requires Magento DI)
        $this->username = $username;
        $this->password = $password;
    }

    public function getApiUsername(): string
    {
        return $this->username;
    }

    public function getApiPassword(): string
    {
        return $this->password;
    }

    public function isDebugEnabled(): bool
    {
        return false;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function isTestMode(): bool
    {
        return true;
    }

    public function getSenderId(): string
    {
        return 'KWT-SMS';
    }

    public function getDefaultCountryCode(): string
    {
        return '965';
    }
}

/**
 * Minimal CurlFactory that creates real Curl instances without Magento DI.
 */
class TestCurlFactory extends CurlFactory
{
    public function __construct()
    {
        // Skip parent constructor
    }

    public function create(array $data = []): Curl
    {
        return new Curl();
    }
}

// 2026-11-05 11:17 +03: live sends go only to KWTSMS_TEST_NUMBER (TestNumberTrait, skipped when unset);
// hard-coded and generated Kuwait numbers removed because they belong to real people, int-testnumber.
// The multi-number test adds 99912345678 (country code 999 is unassigned) as the extra recipient.
class ClientSendTest extends TestCase
{
    use TestNumberTrait;

    private Client $client;
    private string $username;
    private string $password;

    protected function setUp(): void
    {
        // Load credentials from .env
        $envPaths = [
            dirname(__DIR__, 3) . '/.env',
            dirname(__DIR__, 4) . '/.env',
            '/var/www/html/.env',
        ];

        foreach ($envPaths as $path) {
            if (file_exists($path)) {
                $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    if (str_starts_with($line, '#')) {
                        continue;
                    }
                    $parts = explode('=', $line, 2);
                    if (count($parts) === 2) {
                        $_ENV[trim($parts[0])] = trim($parts[1]);
                    }
                }
                break;
            }
        }

        $this->username = $_ENV['KWTSMS_USERNAME'] ?? '';
        $this->password = $_ENV['KWTSMS_PASSWORD'] ?? '';

        if (!$this->username || !$this->password) {
            $this->markTestSkipped('KWTSMS_USERNAME and KWTSMS_PASSWORD required in .env');
        }

        $config = new TestConfig($this->username, $this->password);
        $curlFactory = new TestCurlFactory();
        $logger = new NullLogger();

        // 2026-11-07 08:15 +03: Client now also takes PackageInfo (User-Agent version).
        $packageInfo = $this->createMock(\Magento\Framework\Module\PackageInfo::class);
        $packageInfo->method('getVersion')->willReturn('test');
        $this->client = new Client($config, $curlFactory, $logger, $packageInfo);
    }

    // ---- Client::send() single number ----

    public function testSendSingleNumberEnglish(): void
    {
        $response = $this->client->send('KWT-SMS', $this->requireTestNumber(),'Client::send() test English', true);

        $this->assertEquals('OK', $response['result'], 'Send failed: ' . json_encode($response));
        $this->assertArrayHasKey('msg-id', $response);
        $this->assertNotEmpty($response['msg-id']);
        $this->assertEquals(1, $response['numbers']);
        $this->assertArrayHasKey('points-charged', $response);
        $this->assertArrayHasKey('balance-after', $response);
        $this->assertGreaterThan(0, $response['balance-after']);
    }

    public function testSendSingleNumberArabic(): void
    {
        $response = $this->client->send('KWT-SMS', $this->requireTestNumber(),'اختبار ارسال عربي من Client::send()', true);

        $this->assertEquals('OK', $response['result'], 'Arabic send failed: ' . json_encode($response));
        $this->assertArrayHasKey('msg-id', $response);
    }

    // ---- Client::send() multiple numbers (under 200) ----

    public function testSendMultipleNumbers(): void
    {
        $response = $this->client->send(
            'KWT-SMS',
            $this->requireTestNumber() . ',99912345678',
            'Client::send() multi-number test',
            true
        );

        // The 999 number cannot be routed; the API counts and charges only accepted numbers
        $this->assertEquals('OK', $response['result'], 'Multi send failed: ' . json_encode($response));
        $this->assertGreaterThanOrEqual(1, $response['numbers']);
        $this->assertLessThanOrEqual(2, $response['numbers']);
        $this->assertArrayHasKey('points-charged', $response);
        $this->assertEquals($response['numbers'], $response['points-charged']);
    }

    // ---- Client::send() empty numbers ----

    public function testSendEmptyNumbers(): void
    {
        $response = $this->client->send('KWT-SMS', '', 'Test empty', true);

        $this->assertEquals('ERROR', $response['result']);
        $this->assertEquals('ERR006', $response['code']);
    }

    // ---- Client::send() error handling ----

    public function testSendEmptyMessageReturnsError(): void
    {
        $response = $this->client->send('KWT-SMS', $this->requireTestNumber(),'', true);

        $this->assertEquals('ERROR', $response['result']);
        $this->assertContains($response['code'], ['ERR002', 'ERR009']);
    }

    // ---- Client::getBalance() ----

    public function testGetBalanceReturnsAvailableAndPurchased(): void
    {
        $response = $this->client->getBalance();

        $this->assertEquals('OK', $response['result']);
        $this->assertArrayHasKey('available', $response);
        $this->assertArrayHasKey('purchased', $response);
        $this->assertIsNumeric($response['available']);
        $this->assertGreaterThan(0, $response['available']);
    }

    // ---- Client::getSenderIds() ----

    public function testGetSenderIdsReturnsList(): void
    {
        $response = $this->client->getSenderIds();

        $this->assertEquals('OK', $response['result']);
        $this->assertArrayHasKey('senderid', $response);
        $this->assertIsArray($response['senderid']);
        $this->assertContains('KWT-SMS', $response['senderid']);
    }

    // ---- Client::getCoverage() ----

    public function testGetCoverageReturnsKuwait(): void
    {
        $response = $this->client->getCoverage();

        $this->assertEquals('OK', $response['result']);
        $this->assertArrayHasKey('prefixes', $response);
        $this->assertContains('965', $response['prefixes']);
    }

    // ---- Client::send() balance-after updates ----

    public function testSendReturnsBalanceAfter(): void
    {
        $balanceBefore = $this->client->getBalance();
        $availableBefore = (int) $balanceBefore['available'];

        $response = $this->client->send('KWT-SMS', $this->requireTestNumber(),'Balance check test', true);

        $this->assertEquals('OK', $response['result']);
        $this->assertArrayHasKey('balance-after', $response);

        // Balance after should be less than or equal to before (credits consumed even in test mode)
        $this->assertLessThanOrEqual($availableBefore, (int) $response['balance-after']);
    }

    // ---- Client::send() with test mode flag ----

    public function testSendTestModeDoesNotDeliverButQueues(): void
    {
        $response = $this->client->send('KWT-SMS', $this->requireTestNumber(),'Test mode verification', true);

        $this->assertEquals('OK', $response['result']);
        $this->assertNotEmpty($response['msg-id'], 'Test mode should still return a msg-id');
        // Test mode: message is queued (gets msg-id) but not delivered to handset
        // Credits are consumed but recoverable by deleting from kwtSMS queue
    }

    // ---- Client::send() long message (multi-page) ----

    public function testSendLongEnglishMessage(): void
    {
        // 320 chars = 2 pages for English (160 chars per page)
        $longMessage = str_repeat('Test message content. ', 16); // ~352 chars
        $response = $this->client->send('KWT-SMS', $this->requireTestNumber(),$longMessage, true);

        $this->assertEquals('OK', $response['result'], 'Long message send failed: ' . json_encode($response));
        // Should charge more than 1 credit for multi-page message
        $this->assertGreaterThanOrEqual(2, $response['points-charged']);
    }

    public function testSendLongArabicMessage(): void
    {
        // 140 chars Arabic = 2 pages (70 chars per page)
        $longArabic = str_repeat('اختبار رسالة طويلة ', 8); // ~152 chars Arabic
        $response = $this->client->send('KWT-SMS', $this->requireTestNumber(),$longArabic, true);

        $this->assertEquals('OK', $response['result'], 'Long Arabic send failed: ' . json_encode($response));
        $this->assertGreaterThanOrEqual(2, $response['points-charged']);
    }
}
