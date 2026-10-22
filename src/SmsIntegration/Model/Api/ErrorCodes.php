<?php
/**
 * kwtSMS API Error Codes.
 *
 * Maps gateway error codes to human-readable descriptions.
 * Used for logging, admin display, and troubleshooting failed SMS attempts.
 */
declare(strict_types=1);

namespace KwtSms\SmsIntegration\Model\Api;

class ErrorCodes
{
    /**
     * Error code to description mapping.
     *
     * @var array<string, string>
     */
    public const CODES = [
        'ERR001' => 'API is off',
        'ERR002' => 'Username or password or other required parameters are missing',
        'ERR003' => 'Wrong username or password',
        'ERR004' => 'Your account does not have API access',
        'ERR005' => 'Your account has been blocked',
        'ERR006' => 'No valid numbers submitted',
        'ERR007' => 'Cannot send more than 200 numbers at a time',
        'ERR008' => 'The sender ID chosen is banned',
        'ERR009' => 'Message parameter is empty',
        'ERR010' => 'Your account balance is zero',
        'ERR011' => 'Not enough balance to send this message',
        'ERR012' => 'Cannot send more than 6 page messages, message is too long',
        'ERR013' => 'Your queued messages reached 1000, wait and try again',
        'ERR019' => 'No reports found',
        'ERR020' => 'Message does not exist',
        'ERR021' => 'Message does not have delivery report',
        'ERR022' => 'Delivery reports are not ready. Check back after 24 hours',
        'ERR023' => 'Unknown error, could not get delivery reports',
        'ERR024' => 'API Lockdown is ON and IP address not in the allowed list',
        'ERR025' => 'No valid numbers found, must be digits only with country code',
        'ERR026' => 'No route found for this number, country not activated',
        'ERR027' => 'HTML tags not allowed',
        // 2026-10-22 15:37 +03: ERR028 text corrected. Duplicate SMS protection is opt-in per account with its own window, never a fixed 15 s.
        'ERR028' => 'Sent to the same number too soon.',
        'ERR029' => 'Message does not exist or the msgid is wrong',
        'ERR030' => 'Message stuck in queue with error (delete to recover credits)',
        'ERR031' => 'Bad language detected, send rejected',
        'ERR032' => 'Spam message detected, send rejected',
        'ERR033' => 'No active coverage on your account, contact us to add one',
    ];

    /**
     * Get a human-readable description for an error code.
     *
     * @param string $code
     * @return string
     */
    public static function getDescription(string $code): string
    {
        return self::CODES[$code] ?? 'Unknown error code';
    }
}
