# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-11-07

### Added
- LICENSE file (OSL-3.0), in the repository and in the module package.
- Requests to kwtSMS carry the integration name and version (no personal data) so kwtSMS can see which integrations are in use.

### Changed
- Admin phone number hint and its translations now show the placeholder 965XXXXXXXX instead of a sample mobile number; tests send only to the number in the KWTSMS_TEST_NUMBER environment variable and skip when it is not set

### Docs
- Help page: test messages are queued but not delivered; their credits are held until you delete them from the Sending Queue (previously described as using no credits)
- ERR028 message is now "Sent to the same number too soon." Duplicate SMS protection is opt-in per account with a configurable window (10 seconds by default), not a fixed 15 seconds

## [1.0.0] - 2026-03-16

- Initial release.
