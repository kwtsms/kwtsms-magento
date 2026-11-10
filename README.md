<picture>
  <source media="(prefers-color-scheme: dark)" srcset=".github/assets/readme-header-dark.png">
  <img alt="kwtSMS: SMS gateway for Kuwait" src=".github/assets/readme-header-light.png">
</picture>

# kwtSMS for Magento 2

[![Magento](https://img.shields.io/badge/Magento-2.4.7%2B-orange.svg)](https://github.com/magento/magento2)
[![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3-777BB4?logo=php&logoColor=white)](https://php.net)
[![Release](https://img.shields.io/github/v/release/kwtsms/kwtsms-magento)](https://github.com/kwtsms/kwtsms-magento/releases)
[![License: OSL-3.0](https://img.shields.io/badge/License-OSL--3.0-green.svg)](https://opensource.org/license/osl-3-0)
[![CodeQL](https://github.com/kwtsms/kwtsms-magento/actions/workflows/codeql.yml/badge.svg)](https://github.com/kwtsms/kwtsms-magento/actions/workflows/codeql.yml)

Send order updates to your customers and alerts to your admins from Adobe Commerce (Magento 2), through kwtSMS, the
Kuwaiti SMS gateway. Free, and listed on the Adobe Commerce Marketplace.

kwtSMS delivers to Zain, Ooredoo, STC and Virgin in Kuwait and to more than 200 countries, with your own Sender ID,
free API testing and credits that do not expire.

## Features

**Customer SMS**
- New order, status change, shipment, invoice, refund and cancellation.
- Welcome message on registration.

**Admin alerts**
- New order, new customer and low stock.

**Admin**
- English and Arabic templates with placeholders.
- SMS log as a searchable admin grid with export.
- Dashboard: SMS statistics, balance and connection status.
- Test mode: messages are queued on kwtSMS and not delivered.
- Phone validation with rules for more than 90 countries and Arabic digit conversion.
- Daily cron sync of balance, Sender IDs and coverage.

## Requirements

- Magento Open Source or Adobe Commerce 2.4.7 or later, PHP 8.2 or 8.3.
- A kwtSMS account (free test account, no papers): https://www.kwtsms.com/cportal/signup/

## Installation

### With Composer

```bash
composer require kwtsms/module-sms-integration
bin/magento module:enable KwtSms_SmsIntegration
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### Manual

1. Copy `src/SmsIntegration/` to `app/code/KwtSms/SmsIntegration/`.
2. Run the same `bin/magento` commands as above, from `module:enable` on.

## Setup

1. Go to **Stores > Configuration > kwtSMS > Gateway Settings**.
2. Enter the API username and password of your kwtSMS account (not your mobile number) and click **Test Connection**.
3. Choose your Sender ID, keep test mode on for a first try, and enable the module.
4. Place a test order, check the SMS log, then turn test mode off.

Test mode messages are not delivered, but their credits are held until you delete them from the Sending Queue in your
kwtSMS account.

## Arabic

Every template comes in English and Arabic and the admin screens are translated (`i18n/ar_SA.csv`). Arabic SMS are
sent as Unicode: one SMS page holds 70 Arabic characters, against 160 English ones.

## FAQ

**Which username do I use?** The API username and password of your kwtSMS account, not your mobile number.

**Why do test messages not arrive?** Test mode queues messages without delivering them. Turn it off in the settings.

**What is a Sender ID?** The name shown as the sender on the phone. See the
[Sender ID help](https://www.kwtsms.com/sender-id-help.html).

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Support

Help: https://www.kwtsms.com/support.html | FAQ: https://www.kwtsms.com/faq/ |
API: [developers](https://www.kwtsms.com/developers.html), [API reference](https://www.kwtsms.com/api-documentation.html) |
[Issues](https://github.com/kwtsms/kwtsms-magento/issues)

## Security

See [SECURITY.md](SECURITY.md).

## License

OSL-3.0, (c) kwtSMS (BoxLink Center). See https://opensource.org/license/osl-3-0.
