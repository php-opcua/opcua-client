---
eyebrow: 'Docs · Testing'
lede:    'Integration tests run against real OPC UA servers in Docker. Two sibling repos cover the spectrum — UA-.NETStandard for almost everything, open62541 for NodeManagement.'

see_also:
  - { href: './mock-client.md',                   meta: '6 min' }
  - { href: '../recipes/service-unsupported.md',  meta: '4 min' }
  - { href: 'https://github.com/php-opcua/uanetstandard-test-suite', meta: 'external', label: 'uanetstandard-test-suite' }

prev: { label: 'Handlers',        href: './handlers.md' }
next: { label: 'Client API',      href: '../reference/client-api.md' }
---

# Integration tests

The library's own integration suite — `tests/Integration/` in this
repo — runs against two Docker-based reference servers. The same
servers are useful when you're testing your own integration code; the
images, the compose files, and the port assignments are all public.

## The reference servers

| Stack                 | Source                                                          | Coverage                                                                                  |
| --------------------- | --------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| **UA-.NETStandard**   | [`php-opcua/uanetstandard-test-suite`](https://github.com/php-opcua/uanetstandard-test-suite) | Eight endpoints — every security policy, both modes, anonymous + username + certificate auth, custom DataTypes, ECC variants on `:4848`/`:4849` |
| **open62541**         | [`php-opcua/extra-test-suite`](https://github.com/php-opcua/extra-test-suite)                 | One endpoint with `NodeManagement` enabled on `:24840` — the only counterpart needed for that service set |

Both ship pre-built images to GHCR. Local startup is `docker compose
pull && docker compose up -d`, no build step required.

## Port map

The library's `TestHelper::ENDPOINT_*` constants encode this directly
— same constants you can reuse in your own tests:

| Endpoint constant                        | URL                              | What                                  |
| ---------------------------------------- | -------------------------------- | ------------------------------------- |
| `ENDPOINT_NO_SECURITY`                   | `opc.tcp://localhost:4840`       | `None` policy, anonymous              |
| `ENDPOINT_SIGN`                          | `opc.tcp://localhost:4841`       | `Basic256Sha256` + Sign               |
| `ENDPOINT_SIGN_AND_ENCRYPT`              | `opc.tcp://localhost:4842`       | `Basic256Sha256` + SignAndEncrypt     |
| `ENDPOINT_USERPASS`                      | `opc.tcp://localhost:4843`       | Username/password auth                |
| `ENDPOINT_CERT`                          | `opc.tcp://localhost:4844`       | X.509 certificate auth                |
| `ENDPOINT_CUSTOM_STRUCTURES`             | `opc.tcp://localhost:4845`       | Custom DataTypes for codec tests      |
| `ENDPOINT_ECC_NIST` (`:4848`)            | `opc.tcp://localhost:4848`       | ECC NIST P-256/P-384 endpoints        |
| `ENDPOINT_ECC_BRAINPOOL` (`:4849`)       | `opc.tcp://localhost:4849`       | ECC Brainpool P-256/P-384 endpoints   |
| `ENDPOINT_NODE_MANAGEMENT` (`:24840`)    | `opc.tcp://localhost:24840`      | open62541 with NodeManagement         |

There is no env-var indirection in the test suite — start both
compose stacks once and the constants do the rest.

## Local setup

<!-- @code-block language="bash" label="terminal — start everything" -->
```bash
# uanetstandard-test-suite (8 servers)
git clone https://github.com/php-opcua/uanetstandard-test-suite
(cd uanetstandard-test-suite && docker compose up -d)

# extra-test-suite (open62541 for NodeManagement)
git clone https://github.com/php-opcua/extra-test-suite
(cd extra-test-suite && docker compose up -d)
```
<!-- @endcode-block -->

Both stacks use `restart: unless-stopped` by default — they survive a
dev-machine reboot, so you start them once and forget.

## Writing tests against the suites

Reuse `TestHelper::connectFor*()` to short-circuit the boilerplate:

<!-- @code-block language="php" label="tests/Integration/MyDeviceTest.php" -->
```php
use PhpOpcua\Client\Tests\Integration\Helpers\TestHelper;

it('reads a string node from the test server', function () {
    $client = TestHelper::connectForNoSecurity();   // ENDPOINT_NO_SECURITY

    $dv = $client->read('ns=2;s=Demo.Static.Scalar.String');

    expect($dv->statusCode)->toBe(0);
    expect($dv->getValue())->toBeString();

    $client->disconnect();
});
```
<!-- @endcode-block -->

The `TestHelper` lives in `tests/Integration/Helpers/` — copy it into
your own integration suite or import it as a dev-dependency reference.

## CI integration

The library's own CI does the same:

- The `integration` workflow runs against both stacks on every PHP
  matrix leg.
- Both stacks are consumed via composite GitHub Actions
  (`php-opcua/uanetstandard-test-suite@v1.2.0`,
  `php-opcua/extra-test-suite@v1.0.0`) that `docker compose pull` +
  `up -d` and emit health checks.
- Stack containers run with `restart: "no"` in CI — failures surface
  rather than auto-recover.

The same composite actions are public — drop them into your own
GitHub Actions workflow when you want CI coverage with the same
servers:

<!-- @code-block language="text" label=".github/workflows/integration.yml" -->
```text
- uses: php-opcua/uanetstandard-test-suite@v1.2.0
  with:
    profile: full   # or 'no-security' for the minimum
- uses: php-opcua/extra-test-suite@v1.0.0
  with:
    services: node-management
- run: vendor/bin/pest --group=integration
```
<!-- @endcode-block -->

## Grouping

The library's integration tests are tagged `->group('integration')`.
The Pest convention:

<!-- @code-block language="bash" label="terminal — run only integration" -->
```bash
vendor/bin/pest --group=integration         # only integration
vendor/bin/pest --exclude-group=integration # everything else
```
<!-- @endcode-block -->

Use the exclude form in CI for the fast unit pass; gate the
integration pass on Docker availability.

## Patterns

**Connect once per test class.**

<!-- @code-block language="php" label="connect-once pattern" -->
```php
beforeAll(function () {
    $this->client = TestHelper::connectForSignAndEncrypt();
});

afterAll(function () {
    $this->client->disconnect();
});

it('writes and reads back', function () {
    $this->client->write('ns=2;s=Tag', 42);
    $dv = $this->client->read('ns=2;s=Tag', refresh: true);
    expect($dv->getValue())->toBe(42);
});
```
<!-- @endcode-block -->

Each `connect()` costs ~30-100 ms against the test stacks; sharing
the client across a test class is the difference between a 5-second
and a 50-second suite.

**Reset between scenarios.**

If your tests mutate server state (writes, NodeManagement, trust
store entries), reset deliberately at the end:

<!-- @code-block language="php" label="cleanup pattern" -->
```php
afterEach(function () {
    $this->client->write('ns=2;s=Tag', 0);   // known baseline
    $this->client->flushCache();
});
```
<!-- @endcode-block -->

**Skip when the server is unavailable.**

The library's test helper handles this for you:

<!-- @code-block language="php" label="conditional skip" -->
```php
beforeEach(function () {
    TestHelper::skipIfEndpointUnreachable(TestHelper::ENDPOINT_NODE_MANAGEMENT);
});
```
<!-- @endcode-block -->

## Performance

Integration tests are slow relative to unit tests — each is one or
more real OPC UA round-trips. On a quiet machine:

- A `connect()` + `disconnect()` cycle: ~30-100 ms
- A single `read()`: ~1-5 ms
- A `browseRecursive()` over a small subtree: ~10-50 ms

A full integration suite runs in 10-30 seconds. If yours runs longer,
the suspect is usually a missing `connect-once` pattern.

## When integration is the wrong tool

- **Testing application logic that branches on the client.** Use
  `MockClient` — see [MockClient](./mock-client.md).
- **Testing exception handling for transport failures.** The
  integration servers are reliable; simulating breakage means
  Docker-pausing them mid-test, which is messy. Stub the transport
  instead.
- **Testing encoder/decoder correctness.** The library does this in
  `tests/Unit/Encoding/` with hand-crafted byte fixtures — faster and
  more reproducible than a round-trip.
