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

# With coverage report
php -d pcov.enabled=1 ./vendor/bin/pest --coverage
```

All tests must pass before submitting a pull request.

## Project Structure

```
src/
├── Client.php                  # Main entry point
├── OpcUaClientInterface.php    # Public API interface
├── Client/                     # Client traits (connection, read/write, browse, etc.)
├── Transport/                  # TCP socket communication
├── Cli/                        # CLI tool (Application, Commands, Output, ArgvParser)
├── Protocol/                   # OPC UA service encoding/decoding (AbstractProtocolService base, ServiceTypeId constants)
├── Encoding/                   # Binary serialization
├── Security/                   # Secure channel, crypto, certificates
├── Cache/                      # PSR-16 cache drivers (InMemoryCache, FileCache)
├── Event/                      # PSR-14 events (38 event classes + NullEventDispatcher)
├── Builder/                    # Fluent builders for multi-operations
├── Repository/                 # Per-client codec registry
├── Testing/                    # MockClient for consumer testing
├── Types/                      # OPC UA data types, enums, and DTOs
└── Exception/                  # Exception hierarchy

tests/
├── Unit/                       # Unit tests (no server required)
└── Integration/                # Integration tests (require test server)
    └── Helpers/TestHelper.php  # Shared test utilities
```

## Design Principles

### Zero Runtime Dependencies

This library depends only on `ext-openssl` and PSR interface packages (`psr/log`, `psr/simple-cache`, `psr/event-dispatcher`). PSR packages contain interfaces only — no runtime code, no transitive dependencies.

**Do not add Composer dependencies that ship runtime code.** If a feature requires an external library (Redis driver, HTTP client, etc.), it belongs in a separate package or should accept a PSR interface that the consumer provides. This is a deliberate architectural choice — see the [Won't Do](ROADMAP.md#wont-do-by-design) section in the roadmap for examples.

### Cross-Platform Compatibility

The library must work on Linux, macOS, and Windows. Do not use platform-specific APIs (Unix sockets, `pcntl_*` in production code, `/proc`, etc.). The only allowed extension is `ext-openssl`, which is available on all platforms.

### Public Readonly DTOs

All service response types and value objects use `public readonly` properties. Do not add getter methods — access is `$result->subscriptionId`, not `$result->getSubscriptionId()`. Old getters are deprecated but kept for backward compatibility.

### Instance-Level State

The `Client` does not use static state. Each client instance has its own codec registry (`ExtensionObjectRepository`), cache, logger, and configuration. Two clients in the same process must not interfere with each other.

## Guidelines

### Code Style

The project enforces a Laravel-style coding standard (PSR-12 + opinionated rules) via [php-cs-fixer](https://github.com/PHP-CS-Fixer/PHP-CS-Fixer). Configuration lives in `.php-cs-fixer.php`.

```bash
# Format all files
composer format

# Check without modifying (CI mode)
composer format:check
```

**You must run `composer format` before committing.** Pull requests with unformatted code will fail the CI check. Make it a habit: write code, run `composer format`, then commit.

**Key rules:**

- `declare(strict_types=1)` required
- Single quotes for strings
- Trailing commas in multiline arrays, arguments, and parameters
- `not_operator_with_successor_space` (space after `!`)
- Ordered imports (alphabetical)
- No unused imports
- No blank lines after class opening brace
- Type declarations for parameters, return types, and properties
- `public readonly` properties for DTOs — no getters

**IDE integration:**

- **PhpStorm**: Settings > PHP > Quality Tools > PHP CS Fixer — point to `vendor/bin/php-cs-fixer` and `.php-cs-fixer.php`. Enable "On Save" for automatic formatting.
- **VSCode**: Install the `junstyle.php-cs-fixer` extension. It reads `.php-cs-fixer.php` automatically.

### Documentation & Comments

- Every class, trait, interface, and enum must have a PHPDoc description
- Every public method must have a PHPDoc block with `@param`, `@return`, `@throws`, and `@see` where applicable
- `@return` and `@param` must be on their own line, not inline with the description
- **Do not add comments inside function bodies.** No `//`, no `/* */`, no section headers. If the code needs a comment to be understood, the method is too complex — split it into smaller, well-named methods instead. The method name and its PHPDoc should be enough to understand what it does.
- Update relevant files in `doc/` for new features
- Update `CHANGELOG.md` with your changes
- Update `README.md` features list if adding a major feature
- Update `llms.txt` and `llms-full.txt` if the change affects the public API or architecture

### Public API Changes

- Any new public method must be added to `OpcUaClientInterface`
- Configuration methods should return `self` for fluent chaining
- Use traits (`Client/Manages*Trait.php`) to organize client functionality
- All methods accepting a `NodeId` should also accept `string` (OPC UA format: `'i=2259'`, `'ns=2;s=MyNode'`)
- Use `AttributeId` constants instead of magic numbers for attribute IDs

### Testing

- Write unit tests for all new functionality
- Write integration tests for features that interact with an OPC UA server
- Use Pest PHP syntax (not PHPUnit)
- Group integration tests with `->group('integration')`
- Use `TestHelper::safeDisconnect()` in `finally` blocks
- Use `MockClient` for builder and consumer-facing tests — do not create anonymous classes implementing `OpcUaClientInterface`
- **Code coverage must remain at or above 99.5%.** Pull requests that drop coverage below this threshold will not be merged. Run `php -d pcov.enabled=1 ./vendor/bin/pest --coverage` to check locally before submitting.

### Commits

- Use descriptive commit messages
- Prefix with `[ADD]`, `[UPD]`, `[PATCH]`, `[REF]`, `[DOC]`, `[TEST]` as appropriate

## Pull Request Process

1. Fork the repository and create a feature branch
2. Write your code and tests
3. Run `composer format` to format your code
4. Ensure all tests pass and coverage is >= 99.5%
5. Update documentation, changelog, and llms files
6. Submit a pull request using the provided template
6. Wait for review — a maintainer will review your PR, may request changes or ask questions
7. Once approved, your PR will be merged

## Reporting Issues

Use the [issue templates](https://github.com/gianfriaur/opcua-php-client/issues/new/choose) to report bugs, request features, or ask questions.
