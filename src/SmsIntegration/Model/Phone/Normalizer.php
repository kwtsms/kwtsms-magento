<?php
/**
 * Phone Number Normalizer.
 *
 * Converts raw phone input into a fully qualified international format.
 * Handles Arabic digit conversion, country code detection, trunk prefix stripping,
 * and format validation against known country rules.
 *
 * @see \KwtSms\SmsIntegration\Model\Phone\Rules
 * @see \KwtSms\SmsIntegration\Model\Phone\Result
 */
declare(strict_types=1);

namespace KwtSms\SmsIntegration\Model\Phone;

use KwtSms\SmsIntegration\Model\Config;
use KwtSms\SmsIntegration\Model\GatewayList;

class Normalizer
{
    /**
     * @var Config|null
     */
    private ?Config $config;

    /**
     * @param Config|null $config
     */
    public function __construct(?Config $config = null)
    {
        $this->config = $config;
    }

    /**
     * Normalize a phone number to international format.
     *
     * @param string $phone
     * @param string|null $defaultCountryCode
     * @return Result
     */
    public function normalize(string $phone, ?string $defaultCountryCode = null): Result
    {
        $phone = trim($phone);
        if ($phone === '') {
            return new Result(false, '', 'Phone number is empty');
        }

        // Convert Arabic-Indic and Extended Arabic-Indic digits to ASCII
        $phone = $this->convertArabicDigits($phone);

        // Strip non-digit characters
        $phone = preg_replace('/\D/', '', $phone);

        // Strip leading zeros
        $phone = ltrim($phone, '0');

        if ($phone === '') {
            return new Result(false, '', 'Phone number contains no valid digits');
        }

        $countryCode = $defaultCountryCode ?? ($this->config !== null ? $this->config->getDefaultCountryCode() : '965');

        // If number is short (local), prepend default country code
        if (strlen($phone) <= 9 && !$this->startsWith($phone, $countryCode)) {
            $phone = $countryCode . $phone;
        }

        // Strip domestic trunk prefix (e.g., 9660559... becomes 966559...)
        $detectedCode = $this->findCountryCode($phone);
        if ($detectedCode !== null) {
            $localPart = substr($phone, strlen($detectedCode));
            if (strlen($localPart) > 0 && $localPart[0] === '0') {
                $localPart = ltrim($localPart, '0');
                $phone = $detectedCode . $localPart;
            }
        }

        // Length checks
        if (strlen($phone) < 7) {
            return new Result(false, $phone, 'Phone number is too short');
        }

        if (strlen($phone) > 15) {
            return new Result(false, $phone, 'Phone number is too long');
        }

        // Validate against country rules
        return $this->validatePhoneFormat($phone);
    }

    /**
     * Find the country code from the beginning of a normalized number.
     *
     * Tries 3-digit, 2-digit, then 1-digit prefixes.
     *
     * @param string $normalized
     * @return string|null
     */
    public function findCountryCode(string $normalized): ?string
    {
        // Try 3-digit code
        if (strlen($normalized) >= 3) {
            $prefix3 = substr($normalized, 0, 3);
            if (isset(Rules::PHONE_RULES[$prefix3])) {
                return $prefix3;
            }
        }

        // Try 2-digit code
        if (strlen($normalized) >= 2) {
            $prefix2 = substr($normalized, 0, 2);
            if (isset(Rules::PHONE_RULES[$prefix2])) {
                return $prefix2;
            }
        }

        // Try 1-digit code
        if (strlen($normalized) >= 1) {
            $prefix1 = substr($normalized, 0, 1);
            if (isset(Rules::PHONE_RULES[$prefix1])) {
                return $prefix1;
            }
        }

        return null;
    }

    /**
     * Validate the phone number format against known country rules.
     *
     * @param string $normalized
     * @return Result
     */
    public function validatePhoneFormat(string $normalized): Result
    {
        $countryCode = $this->findCountryCode($normalized);

        if ($countryCode === null) {
            return new Result(false, $normalized, 'Unknown country code');
        }

        $rule = Rules::PHONE_RULES[$countryCode];
        $localPart = substr($normalized, strlen($countryCode));
        $localLength = strlen($localPart);
        $countryName = Rules::COUNTRY_NAMES[$countryCode] ?? $countryCode;

        // Check local length
        if (!in_array($localLength, $rule['localLengths'], true)) {
            $expectedLengths = implode(' or ', array_map('strval', $rule['localLengths']));
            return new Result(
                false,
                $normalized,
                sprintf(
                    'Invalid phone length for %s. Expected %s digits after country code, got %d',
                    $countryName,
                    $expectedLengths,
                    $localLength
                )
            );
        }

        // Check mobile start digits if defined
        if ($rule['mobileStartDigits'] !== null && $localPart !== '') {
            $firstDigit = $localPart[0];
            if (!in_array($firstDigit, $rule['mobileStartDigits'], true)) {
                $validStarts = implode(', ', $rule['mobileStartDigits']);
                return new Result(
                    false,
                    $normalized,
                    sprintf(
                        'Invalid mobile number for %s. Number after country code must start with %s',
                        $countryName,
                        $validStarts
                    )
                );
            }
        }

        return new Result(true, $normalized);
    }

    /**
     * Mask a phone number for display, showing only the first 4 and last 3 digits.
     *
     * @param string $phone
     * @return string
     */
    public function maskPhone(string $phone): string
    {
        $length = strlen($phone);
        if ($length <= 7) {
            return $phone;
        }

        $start = substr($phone, 0, 4);
        $end = substr($phone, -3);
        $middle = str_repeat('*', $length - 7);

        return $start . $middle . $end;
    }

    /**
     * Remove duplicate phone numbers from an array.
     *
     * @param array $numbers
     * @return array
     */
    public function deduplicate(array $numbers): array
    {
        return array_values(array_unique($numbers));
    }

    /**
     * Check if a normalized number is within the given coverage prefixes.
     *
     * @param string $normalized
     * @param array $coveragePrefixes
     * @return bool
     */
    public function isInCoverage(string $normalized, array $coveragePrefixes): bool
    {
        // 2026-11-11 18:03 +03: accept a bare prefix list or a full API response array. Task magento-111.
        $coveragePrefixes = GatewayList::fromArray($coveragePrefixes, GatewayList::KEY_COVERAGE);
        if (empty($coveragePrefixes)) {
            return true;
        }

        foreach ($coveragePrefixes as $prefix) {
            if ($this->startsWith($normalized, (string) $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert Arabic-Indic and Extended Arabic-Indic digits to ASCII digits.
     *
     * @param string $input
     * @return string
     */
    private function convertArabicDigits(string $input): string
    {
        // Arabic-Indic digits: U+0660 to U+0669
        $arabicIndic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        // Extended Arabic-Indic digits: U+06F0 to U+06F9
        $extendedArabicIndic = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $ascii = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        $input = str_replace($arabicIndic, $ascii, $input);
        $input = str_replace($extendedArabicIndic, $ascii, $input);

        return $input;
    }

    /**
     * Check if a string starts with a given prefix.
     *
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    private function startsWith(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
