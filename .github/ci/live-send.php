<?php
/**
 * FILE:      .github/ci/live-send.php
 * CREATED:   2026-11-07 08:09 +03
 * TASK:      int-rerelease-number / int-ci-matrix
 * AUTHOR:    M Alhassoun
 * WHAT:      Sends one SMS through the installed module's SmsSender with test mode on, to KWTSMS_TEST_NUMBER, then
 *            checks the result and the kwtsms_sms_log row. Prints no phone number and no credentials.
 * WHY:       Proves the module works end to end on a fresh Magento install, not only that it compiles.
 * USAGE:     php .github/ci/live-send.php   (from the Magento root; run by magento-install.yml)
 * DEPENDS:   Installed Magento with KwtSms_SmsIntegration configured (enabled, test_mode 1, sender, credentials).
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;

require getcwd() . '/app/bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

$config = $om->get(\KwtSms\SmsIntegration\Model\Config::class);
if (!$config->isTestMode()) {
    fwrite(STDERR, "ABORT: test mode is off, nothing sent\n");
    exit(2);
}

$number = (string) getenv('KWTSMS_TEST_NUMBER');
$result = $om->get(\KwtSms\SmsIntegration\Model\SmsSender::class)
    ->send($number, 'QA-magento-ci ' . gmdate('YmdHi'), 'gateway_test');

$safe = array_intersect_key($result, array_flip(['success', 'sent', 'failed', 'skipped', 'invalid', 'error']));
echo 'result: ' . json_encode($safe) . "\n";

$db = $om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
$row = $db->fetchRow(
    'SELECT status, test_mode FROM ' . $db->getTableName('kwtsms_sms_log') . ' ORDER BY entity_id DESC LIMIT 1'
);
echo 'log row: ' . json_encode($row) . "\n";

exit(($result['success'] ?? false) && (int) ($row['test_mode'] ?? 0) === 1 ? 0 : 1);
