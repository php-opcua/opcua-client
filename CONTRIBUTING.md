# Contributing to OPC UA PHP Client

## Welcome!

Thank you for considering contributing to this project! Every contribution matters, whether it's a bug report, a feature suggestion, a documentation fix, or a code change. This project is open to everyone, you're welcome here.

If you have any questions or need help getting started, don't hesitate to open an issue. We're happy to help.

## Development Setup

### Requirements

- PHP >= 8.2
- `ext-openssl`
- Composer
- [opcua-test-server-suite](https://github.com/GianfriAur/opcua-test-server-suite) (for integration tests)

### Installation

```bash
git clone https://github.com/gianfriaur/opcua-php-client.git
cd opcua-php-client
composer install
```

### Test Server

Integration tests require the OPC UA test server suite running locally:

```bash
git clone https://github.com/GianfriAur/opcua-test-server-suite.git
cd opcua-test-server-suite
docker compose up -d
```

## Running Tests

```bash
# All tests
./vendor/bin/pest

# Unit tests only
./vendor/bin/pest tests/Unit/

# Integration tests only
./vendor/bin/pest tests/Integration/ --group=integration

# A specific test file
./vendor/bin/pest tests/Unit/ClientBatchingTest.php
```

All tests must pass before submitting a pull request.

## Project Structure

```
src/
├── Client.php                  # Main entry point
├── OpcUaClientInterface.php    # Public API interface
├── Client/                     # Client traits (connection, read/write, browse, etc.)
├── Transport/                  # TCP socket communication
├── Protocol/                   # OPC UA service encoding/decoding
├── Encoding/                   # Binary serialization
├── Security/                   # Secure channel, crypto, certificates
├── Types/                      # OPC UA data types and enums
└── Exception/                  # Exception hierarchy

tests/
├── Unit/                       # Unit tests (no server required)
└── Integration/                # Integration tests (require test server)
    └── Helpers/TestHelper.php  # Shared test utilities
```

## Guidelines

### Code Style

- Follow the existing code style and conventions
- Use strict types (`declare(strict_types=1)`)
- Use type declarations for parameters, return types, and properties
- Keep methods focused and concise

### Public API Changes

- Any new public method must be added to `OpcUaClientInterface`
- Configuration methods should return `self` for fluent chaining
- Use traits (`Client/Manages*Trait.php`) to organize client functionality

### Testing

- Write unit tests for all new functionality
- Write integration tests for features that interact with an OPC UA server
- Use Pest PHP syntax (not PHPUnit)
- Group integration tests with `->group('integration')`
- Use `TestHelper::safeDisconnect()` in `finally` blocks

### Commits

- Use descriptive commit messages
- Prefix with `[ADD]`, `[UPD]`, `[FIX]`, `[REF]`, `[DOC]`, `[TEST]` as appropriate

### Documentation

- Update relevant files in `doc/` for new features
- Update `CHANGELOG.md` with your changes
- Update `README.md` features list if adding a major feature

## Pull Request Process

1. Fork the repository and create a feature branch
2. Write your code and tests
3. Ensure all tests pass
4. Update documentation and changelog
5. Submit a pull request using the provided template
6. Wait for review — a maintainer will review your PR, may request changes or ask questions
7. Once approved, your PR will be merged

## Reporting Issues

Use the [issue templates](https://github.com/gianfriaur/opcua-php-client/issues/new/choose) to report bugs, request features, or ask questions.
