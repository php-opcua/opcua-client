# CLI Tool

## Overview

`opcua-cli` is a standalone command-line tool for exploring OPC UA servers without writing code. Useful for debugging on-site, verifying connectivity, and inspecting the address space.

Zero additional dependencies — uses the same pure PHP OPC UA client under the hood.

## Installation

The CLI tool is included with the library. After `composer install`, it's available at:

```bash
php vendor/bin/opcua-cli
```

## Commands

### `browse` — Browse the address space

```bash
# Browse the Objects folder (default)
php vendor/bin/opcua-cli browse opc.tcp://localhost:4840

# Browse a specific path
php vendor/bin/opcua-cli browse opc.tcp://localhost:4840 /Objects/MyPLC

# Browse a specific NodeId
php vendor/bin/opcua-cli browse opc.tcp://localhost:4840 "ns=2;i=1000"

# Recursive browse with depth limit
php vendor/bin/opcua-cli browse opc.tcp://localhost:4840 /Objects --recursive --depth=3

# JSON output
php vendor/bin/opcua-cli browse opc.tcp://localhost:4840 /Objects --json
```

Output:

```
├── Server (i=2253) [Object]
├── MyPLC (ns=2;i=1000) [Object]
│   ├── Temperature (ns=2;i=1001) [Variable]
│   └── Pressure (ns=2;i=1002) [Variable]
└── DeviceSet (ns=3;i=5001) [Object]
```

**Options:**

| Option | Description |
|--------|-------------|
| `--recursive` | Browse recursively (tree view) |
| `--depth=N` | Maximum depth for recursive browse (default: 3) |

### `read` — Read a node value

```bash
# Read the Value attribute (default)
php vendor/bin/opcua-cli read opc.tcp://localhost:4840 "i=2259"

# Read a specific attribute
php vendor/bin/opcua-cli read opc.tcp://localhost:4840 "ns=2;i=1001" --attribute=DisplayName

# JSON output
php vendor/bin/opcua-cli read opc.tcp://localhost:4840 "ns=2;i=1001" --json
```

Output:

```
NodeId:     ns=2;i=1001
Attribute:  Value
Value:      23.5
Type:       Double
Status:     Good (0x00000000)
Source:     2026-03-24T15:30:00+00:00
Server:     2026-03-24T15:30:00+00:00
```

**Options:**

| Option | Description |
|--------|-------------|
| `--attribute=NAME` | Attribute to read: Value (default), DisplayName, BrowseName, DataType, NodeClass, Description, AccessLevel, NodeId |

### `endpoints` — Discover server endpoints

```bash
php vendor/bin/opcua-cli endpoints opc.tcp://localhost:4840
php vendor/bin/opcua-cli endpoints opc.tcp://localhost:4840 --json
```

Output:

```
Endpoint: opc.tcp://localhost:4840
Security: None (mode: None)
Auth:     Anonymous, UserName

Endpoint: opc.tcp://localhost:4840
Security: Basic256Sha256 (mode: SignAndEncrypt)
Auth:     Anonymous, UserName, Certificate
```

### `watch` — Watch a value in real time

Two modes:

- **Without `--interval`** (default): uses OPC UA subscriptions. The server notifies only when the value changes — efficient, no unnecessary polling.
- **With `--interval=N`**: manual polling with `read()` every N milliseconds. Useful for servers that don't support subscriptions or for debugging.

```bash
# Subscription mode (default)
php vendor/bin/opcua-cli watch opc.tcp://localhost:4840 "ns=2;i=1001"

# Polling mode — read every 250ms
php vendor/bin/opcua-cli watch opc.tcp://localhost:4840 "ns=2;i=1001" --interval=250

# JSON output
php vendor/bin/opcua-cli watch opc.tcp://localhost:4840 "ns=2;i=1001" --json
```

Output:

```
[15:30:00.123] 23.5
[15:30:00.625] 23.6
[15:30:01.127] 23.4
^C
```

Stop with Ctrl+C.

## Security Options

All commands support full security configuration:

```bash
# Username/password authentication
php vendor/bin/opcua-cli read opc.tcp://server:4840 "i=2259" -u admin -p secret

# Full security with certificates
php vendor/bin/opcua-cli read opc.tcp://server:4840 "i=2259" \
  --security-policy=Basic256Sha256 \
  --security-mode=SignAndEncrypt \
  --cert=/path/to/client.pem \
  --key=/path/to/client.key \
  --ca=/path/to/ca.pem \
  -u operator -p secret
```

| Option | Short | Description |
|--------|-------|-------------|
| `--security-policy=<policy>` | `-s` | None, Basic256Sha256, Aes256Sha256RsaPss, etc. |
| `--security-mode=<mode>` | `-m` | None, Sign, SignAndEncrypt |
| `--cert=<path>` | | Client certificate path |
| `--key=<path>` | | Client private key path |
| `--ca=<path>` | | CA certificate path |
| `--username=<user>` | `-u` | Username for authentication |
| `--password=<pass>` | `-p` | Password for authentication |
| `--timeout=<seconds>` | `-t` | Connection timeout (default: 5) |

## Output Options

### JSON

Add `--json` (or `-j`) to any command for machine-readable output. Works with `jq` and shell scripts:

```bash
# Browse and extract node names
php vendor/bin/opcua-cli browse opc.tcp://localhost:4840 --json | jq '.[].name'

# Read a value
php vendor/bin/opcua-cli read opc.tcp://localhost:4840 "i=2259" --json | jq '.Value'
```

### Debug Logging

Three debug modes:

```bash
# Log to stdout (incompatible with --json)
php vendor/bin/opcua-cli read opc.tcp://localhost:4840 "i=2259" --debug

# Log to stderr (compatible with --json)
php vendor/bin/opcua-cli read opc.tcp://localhost:4840 "i=2259" --debug-stderr --json

# Log to file (compatible with --json)
php vendor/bin/opcua-cli read opc.tcp://localhost:4840 "i=2259" --debug-file=/tmp/opcua.log --json
```

Debug output shows PSR-3 log messages: handshake, secure channel, session, retries, errors.

## Global Options

| Option | Short | Description |
|--------|-------|-------------|
| `--json` | `-j` | Output in JSON format |
| `--debug` | `-d` | Debug logging on stdout |
| `--debug-stderr` | | Debug logging on stderr |
| `--debug-file=<path>` | | Debug logging to file |
| `--help` | `-h` | Show help |
| `--version` | `-v` | Show version |
