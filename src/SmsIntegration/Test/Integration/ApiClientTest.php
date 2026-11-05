<?php
/**
 * kwtSMS API Client Integration Tests.
 *
 * All tests hit the real kwtSMS API with test=1.
 * Requires .env with KWTSMS_USERNAME and KWTSMS_PASSWORD.
 * Credits consumed in test mode are recoverable from kwtSMS queue.
 */
declare(strict_types=1);

namespace KwtSms\SmsIntegration\Test\Integration;

use PHPUnit\Framework\TestCase;

// 2026-11-05 11:16 +03: live sends go only to KWTSMS_TEST_NUMBER (TestNumberTrait, skipped when unset);
// hard-coded and generated Kuwait numbers removed because they belong to real people, int-testnumber.
// Extra recipients in multi-number and validate calls use 99912345678 (country code 999 is unassigned).
class ApiClientTest extends TestCase
{
    use TestNumberTrait;

    private string $username;
    private string $password;
    private string $baseUrl = 'https://www.kwtsms.com/API/';

    protected function setUp(): void
    {
        // Try multiple paths: module root, project root, Magento root
        $envFile = dirname(__DIR__, 3) . '/.env';
        if (!file_exists($envFile)) {
            $envFile = dirname(__DIR__, 4) . '/.env';
        }
        if (!file_exists($envFile)) {
            $envFile = '/var/www/html/.env';
        }
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (str_starts_with($line, '#')) {
                    continue;
                }
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $_ENV[trim($parts[0])] = trim($parts[1]);
                }
            }
        }

        $this->username = $_ENV['KWTSMS_USERNAME'] ?? '';
        $this->password = $_ENV['KWTSMS_PASSWORD'] ?? '';

        if (!$this->username || !$this->password) {
            $this->markTestSkipped('KWTSMS_USERNAME and KWTSMS_PASSWORD required in .env');
        }
    }

    private function post(string $endpoint, array $params): array
    {
        $ch = curl_init($this->baseUrl . $endpoint . '/');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertNotFalse($body, 'API request failed (network error)');
        // kwtSMS returns non-200 for errors (400, 403, 406), still valid JSON
        $decoded = json_decode($body, true);
        $this->assertIsArray($decoded, 'API response is not valid JSON (HTTP ' . $httpCode . '): ' . $body);

        return $decoded;
    }

    // ---- Balance ----

    public function testBalanceWithValidCredentials(): void
    {
        $response = $this->post('balance', [
            'username' => $this->username,
            'password' => $this->password,
        ]);

        $this->assertEquals('OK', $response['result']);
        $this->assertArrayHasKey('available', $response);
        $this->assertArrayHasKey('purchased', $response);
        $this->assertGreaterThan(0, $response['available'], 'Balance should be > 0 for testing');
    }

    public function testBalanceWithInvalidCredentials(): void
    {
        $response = $this->post('balance', [
            'username' => 'wrong_user_xyz',
            'password' => 'wrong_pass_xyz',
        ]);

        $this->assertEquals('ERROR', $response['result']);
        $this->assertEquals('ERR003', $response['code']);
    }

    public function testBalanceWithEmptyCredentials(): void
    {
        $response = $this->post('balance', [
            'username' => '',
            'password' => '',
        ]);

        $this->assertEquals('ERROR', $response['result']);
        $this->assertContains($response['code'], ['ERR002', 'ERR003']);
    }

    // ---- Sender IDs ----

    public function testSenderIdReturnsArray(): void
    {
        $response = $this->post('senderid', [
            'username' => $this->username,
            'password' => $this->password,
        ]);

        $this->assertEquals('OK', $response['result']);
        $this->assertArrayHasKey('senderid', $response);
        $this->assertIsArray($response['senderid']);
        $this->assertNotEmpty($response['senderid'], 'At least one sender ID expected');
        $this->assertContains('KWT-SMS', $response['senderid'], 'KWT-SMS should be available');
    }

    // ---- Coverage ----

    public function testCoverageReturnsPrefixes(): void
    {
        $response = $this->post('coverage', [
            'username' => $this->username,
            'password' => $this->password,
        ]);

        $this->assertEquals('OK', $response['result']);
        $this->assertArrayHasKey('prefixes', $response);
        $this->assertIsArray($response['prefixes']);
        $this->assertContains('965', $response['prefixes'], 'Kuwait (965) should be in coverage');
    }

    // ---- Send (test mode) ----

    public function testSendEnglishTestMode(): void
    {
        $response = $this->post('send', [
            'username' => $this->username,
            'password' => $this->password,
            'sender'   => 'KWT-SMS',
            'mobile'   => $this->requireTestNumber(),
            'message'  => 'Test English message from integration test',
            'test'     => '1',
        ]);

        $this->assertEquals('OK', $response['result'], 'Send failed: ' . json_encode($response));
        $this->assertArrayHasKey('msg-id', $response);
        $this->assertNotEmpty($response['msg-id']);
        $this->assertArrayHasKey('points-charged', $response);
        $this->assertArrayHasKey('balance-after', $response);
        $this->assertEquals(1, $response['numbers']);
    }

    public function testSendArabicTestMode(): void
    {
        $response = $this->post('send', [
            'username' => $this->username,
            'password' => $this->password,
            'sender'   => 'KWT-SMS',
            'mobile'   => $this->requireTestNumber(),
            'message'  => 'رسالة تجريبية عربية',
            'test'     => '1',
        ]);

        $this->assertEquals('OK', $response['result'], 'Arabic send failed: ' . json_encode($response));
        $this->assertArrayHasKey('msg-id', $response);
    }

    public function testSendMultipleNumbersTestMode(): void
    {
        $response = $this->post('send', [
            'username' => $this->username,
            'password' => $this->password,
            'sender'   => 'KWT-SMS',
            'mobile'   => $this->requireTestNumber() . ',99912345678',
            'message'  => 'Test multi-number send',
            'test'     => '1',
        ]);

        // The 999 number cannot be routed; the API counts only accepted numbers
        $this->assertEquals('OK', $response['result'], 'Multi send failed: ' . json_encode($response));
        $this->assertGreaterThanOrEqual(1, $response['numbers']);
        $this->assertLessThanOrEqual(2, $response['numbers']);
    }

    public function testSendEmptyMessage(): void
    {
        $response = $this->post('send', [
            'username' => $this->username,
            'password' => $this->password,
            'sender'   => 'KWT-SMS',
            'mobile'   => $this->requireTestNumber(),
            'message'  => '',
            'test'     => '1',
        ]);

        $this->assertEquals('ERROR', $response['result']);
        $this->assertContains($response['code'], ['ERR002', 'ERR009']);
    }

    public function testSendInvalidNumber(): void
    {
        $response = $this->post('send', [
            'username' => $this->username,
            'password' => $this->password,
            'sender'   => 'KWT-SMS',
            'mobile'   => '12345',
            'message'  => 'Test with bad number',
            'test'     => '1',
        ]);

        $this->assertEquals('ERROR', $response['result']);
        $this->assertContains($response['code'], ['ERR006', 'ERR025']);
    }

    public function testSendWithUnknownSenderId(): void
    {
        $response = $this->post('send', [
            'username' => $this->username,
            'password' => $this->password,
            'sender'   => 'UNKNOWN-XYZ',
            'mobile'   => $this->requireTestNumber(),
            'message'  => 'Test unknown sender',
            'test'     => '1',
        ]);

        // API may accept unknown senders in test mode or reject with ERR008
        $this->assertContains($response['result'], ['OK', 'ERROR']);
        if ($response['result'] === 'ERROR') {
            $this->assertEquals('ERR008', $response['code']);
        }
    }

    // ---- Validate ----

    public function testValidateNumbers(): void
    {
        $response = $this->post('validate', [
            'username' => $this->username,
            'password' => $this->password,
            'mobile'   => $this->requireTestNumber() . ',12345,99912345678',
        ]);

        $this->assertEquals('OK', $response['result']);
        $this->assertArrayHasKey('mobile', $response);
        $this->assertIsArray($response['mobile']);
        $this->assertArrayHasKey('OK', $response['mobile']);
    }
}
