---
eyebrow: 'Docs · Operations'
lede:    'Six methods that wrap the OPC UA File Transfer service set (Part 5 §C) into a typed PHP API — open, close, read, write, get/set position. Method NodeIds are resolved and cached per-file automatically.'

see_also:
  - { href: '../extensibility/modules.md',                    meta: '6 min' }
  - { href: '../observability/event-reference.md#file-transfer-4', meta: '4 min' }
  - { href: '../reference/enums.md#openfilemode',             meta: '3 min' }
  - { href: 'https://reference.opcfoundation.org/Core/Part5/v105/docs/C', meta: 'external', label: 'OPC UA Part 5 §C — File Transfer' }

prev: { label: 'Client-side aggregates', href: './client-side-aggregates.md' }
next: { label: 'Managing nodes',         href: './managing-nodes.md' }
---

# File Transfer

The `FileTransferModule` (registered by default)
exposes the six method-call shapes of OPC UA Part 5 §C as typed PHP
methods on the `Client` and `OpcUaClientInterface`. Every method
targets a `FileType` instance — typically a node sitting under a
`FolderType` or under `Server` — and translates the
operation into a `Call` against the right Method child node.

The five files shipped by [`uanetstandard-test-suite`
v1.3.0+](https://github.com/php-opcua/uanetstandard-test-suite/blob/master/docs/runtime-features/file-transfer.md)
(four `FileType` nodes under `TestServer/Files/`) are the canonical
fixtures for testing this surface.

## The six methods

| Method                    | Returns         | Wire detail (Part 5 §C.2)                                    |
| ------------------------- | --------------- | ------------------------------------------------------------ |
| `openFile()`              | `int`           | Open(Byte mode) → UInt32 fileHandle                          |
| `closeFile()`             | `void`          | Close(UInt32 fileHandle)                                     |
| `readFile()`              | `string`        | Read(UInt32 fileHandle, Int32 length) → ByteString data      |
| `writeFile()`             | `void`          | Write(UInt32 fileHandle, ByteString data)                    |
| `getFilePosition()`       | `int`           | GetPosition(UInt32 fileHandle) → UInt64 position             |
| `setFilePosition()`       | `void`          | SetPosition(UInt32 fileHandle, UInt64 position)              |

## Open modes — `OpenFileMode` enum

Part 5 §C.2.1 defines a bit-field Byte. Use the enum cases (or
`OpenFileMode::toByte(...)` to OR multiple values):

| Case             | Value | Meaning                                          |
| ---------------- | ----- | ------------------------------------------------ |
| `Read`           | `1`   | Handle can be passed to `readFile()`             |
| `Write`          | `2`   | Handle can be passed to `writeFile()`            |
| `EraseExisting`  | `4`   | Truncate at open time (requires `Write`)          |
| `Append`         | `8`   | Initial position = end-of-file                   |

Single-case opens are common; combinations need the helper:

<!-- @code-block language="php" label="combining modes" -->
```php
use PhpOpcua\Client\Module\FileTransfer\OpenFileMode;

// Plain read
$h = $client->openFile($fileNode, OpenFileMode::Read);

// Truncate + write (the common "overwrite from scratch" idiom)
$h = $client->openFile($fileNode, OpenFileMode::toByte(
    OpenFileMode::Write,
    OpenFileMode::EraseExisting,
));

// Append
$h = $client->openFile($fileNode, OpenFileMode::toByte(
    OpenFileMode::Write,
    OpenFileMode::Append,
));
```
<!-- @endcode-block -->

`openFile()` also accepts a raw `int` directly — useful when the mode
arrives from configuration or a wire payload.

## Quick example — read a remote file

<!-- @code-block language="php" label="read a file end-to-end" -->
```php
use PhpOpcua\Client\Module\FileTransfer\OpenFileMode;

$fileNode = 'ns=1;s=TestServer/Files/ReadOnlyFile';

$handle = $client->openFile($fileNode, OpenFileMode::Read);

try {
    $bytes = $client->readFile($fileNode, $handle, 1024);
} finally {
    $client->closeFile($fileNode, $handle);
}

echo "Got " . strlen($bytes) . " bytes\n";
```
<!-- @endcode-block -->

Wrapping the work in `try/finally` is the recommended pattern — a
forgotten `closeFile()` leaks the `OpenCount` on the server until the
session drops.

## Quick example — chunked read

`readFile()` returns whatever the server gives back. For files larger
than the negotiated max-message-size, drive a loop and stop at EOF
(short-read, no error):

<!-- @code-block language="php" label="chunked drain" -->
```php
$handle = $client->openFile($fileNode, OpenFileMode::Read);

$collected = '';
$chunkSize = 8192;

while (true) {
    $chunk = $client->readFile($fileNode, $handle, $chunkSize);
    if ($chunk === '') {
        break;
    }
    $collected .= $chunk;
}

$client->closeFile($fileNode, $handle);
```
<!-- @endcode-block -->

`getFilePosition()` after each read returns the cumulative offset;
`setFilePosition()` jumps the handle to an arbitrary byte (useful for
range reads or restarting after a partial download).

## Quick example — overwrite a remote file

<!-- @code-block language="php" label="truncate and write" -->
```php
$handle = $client->openFile($fileNode, OpenFileMode::toByte(
    OpenFileMode::Write,
    OpenFileMode::EraseExisting,
));

try {
    $client->writeFile($fileNode, $handle, $newContent);
} finally {
    $client->closeFile($fileNode, $handle);
}
```
<!-- @endcode-block -->

To round-trip the write, close the write-handle first, then open a
fresh read-handle — two handles on the same file are allowed (Part 5
§C.1.2 says `OpenCount` may exceed 1).

## Method NodeId resolution

Every `FileType` instance gets its **own** `Open` / `Close` / `Read` /
`Write` / `GetPosition` / `SetPosition` Method children at server
configuration time, with NodeIds the server assigns at boot. They
are not interchangeable across files.

The module resolves the right Method NodeId for each `(fileNode,
methodName)` pair by issuing one `translateBrowsePaths` call the
first time the pair is used, and caches the result for the lifetime
of the connection. Subsequent calls on the same file reuse the
cached NodeId — `openFile()` × 100 against the same file = 1
translate + 100 calls.

The cache is cleared by `Client::disconnect()` (the module's
`reset()` is invoked) — re-resolution kicks in on the next operation
after `reconnect()`.

## Failure modes

| Status code                        | Cause                                                          |
| ---------------------------------- | -------------------------------------------------------------- |
| `Bad_NodeIdUnknown`                | The `$fileNodeId` does not exist on the server                  |
| `Bad_NotWritable`                  | `openFile(..., Write)` on a file whose `Writable` property is `false` |
| `Bad_InvalidArgument`              | Open mode is 0 (neither Read nor Write), `EraseExisting` without `Write`, or an unknown `fileHandle` was passed to a subsequent call |
| `Bad_InvalidState`                 | `readFile()` on a handle without the Read bit, or `writeFile()` on a handle without the Write bit |
| `Bad_FileHandleInvalid`            | The handle was never issued by this server (rare — Part 5 servers usually use `Bad_InvalidArgument` for unknown handles) |
| `Bad_ServiceUnsupported`           | The server does not implement the OPC UA `Call` service set     |

The module wraps every non-`Good` `CallResult` into a
`ServiceException` whose message includes the StatusCode mnemonic —
catch it to branch on the specific code:

<!-- @code-block language="php" label="handle write-to-read-only-file" -->
```php
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Types\StatusCode;

try {
    $h = $client->openFile($fileNode, OpenFileMode::Write);
} catch (ServiceException $e) {
    if ($e->getStatusCode() === StatusCode::BadNotWritable) {
        // graceful fallback — open read-only instead
        $h = $client->openFile($fileNode, OpenFileMode::Read);
    } else {
        throw $e;
    }
}
```
<!-- @endcode-block -->

## Events

Four PSR-14 events are dispatched lazily (no allocation when no
listener is wired):

| Event              | When                                  | Key fields                                                          |
| ------------------ | ------------------------------------- | ------------------------------------------------------------------- |
| `FileOpened`       | `openFile()` returned                  | `fileNodeId`, `fileHandle`, `mode` (Byte)                            |
| `FileClosed`       | `closeFile()` returned                 | `fileNodeId`, `fileHandle`                                           |
| `FileBytesRead`    | `readFile()` returned                  | `fileNodeId`, `fileHandle`, `bytesRead`, `requestedLength`           |
| `FileBytesWritten` | `writeFile()` returned                 | `fileNodeId`, `fileHandle`, `bytesWritten`                           |

`getFilePosition()` and `setFilePosition()` do not dispatch events —
they're considered low-noise diagnostics.

See [Observability · Event reference](../observability/event-reference.md#file-transfer-4)
for the full field list.

## FileDirectoryType (Part 5 §C.3)

Four additional methods wrap the standard `FileDirectoryType`
management surface. Method NodeIds are resolved + cached the same
way as the FileType six.

<!-- @method name="$client->createDirectory(NodeId|string \$directoryNodeId, string \$directoryName): NodeId" returns="NodeId" visibility="public" -->
<!-- @method name="$client->createFileInDirectory(NodeId|string \$directoryNodeId, string \$fileName, bool \$requestFileOpen = false): CreateFileResult" returns="CreateFileResult" visibility="public" -->
<!-- @method name="$client->deleteFileSystemObject(NodeId|string \$directoryNodeId, NodeId|string \$targetNodeId): void" returns="void" visibility="public" -->
<!-- @method name="$client->moveOrCopyFileSystemObject(NodeId|string \$directoryNodeId, NodeId|string \$sourceNodeId, NodeId|string \$targetDirectoryNodeId, bool \$createCopy, string \$newName = ''): NodeId" returns="NodeId" visibility="public" -->

### `CreateFileResult` DTO

Returned by `createFileInDirectory()`:

| Field         | Type   | Meaning                                                            |
| ------------- | ------ | ------------------------------------------------------------------ |
| `fileNodeId`  | NodeId | Server-assigned NodeId of the new FileType instance               |
| `fileHandle`  | int    | `0` when `$requestFileOpen` was `false`, else a usable Read+Write handle |

### Quick example — create, write, read, delete

<!-- @code-block language="php" label="round-trip on a runtime-created file" -->
```php
$rootDir = 'ns=1;s=TestServer/Files/RootDir';

// Single call: create + open in Read+Write mode.
$created = $client->createFileInDirectory($rootDir, 'report.bin', requestFileOpen: true);

try {
    $client->writeFile($created->fileNodeId, $created->fileHandle, $payload);
} finally {
    $client->closeFile($created->fileNodeId, $created->fileHandle);
}

// Verify by re-reading with a fresh handle.
$h = $client->openFile($created->fileNodeId, OpenFileMode::Read);
$back = $client->readFile($created->fileNodeId, $h, strlen($payload));
$client->closeFile($created->fileNodeId, $h);
assert($back === $payload);

// Clean up — the source dir owns the delete method.
$client->deleteFileSystemObject($rootDir, $created->fileNodeId);
```
<!-- @endcode-block -->

### Move vs copy

`moveOrCopyFileSystemObject(..., $createCopy, $newName)`:

- `$createCopy = true` — deep-clone the source. After the call, both
  source and destination exist.
- `$createCopy = false` — move. After the call, the source NodeId is
  no longer in the address space; only the destination exists.
- `$newName` empty string → reuse the source's BrowseName at the
  destination. Otherwise the destination uses the explicit name.

The first argument (`$directoryNodeId`) is the directory that
**owns the MoveOrCopy method node** — typically the source directory.
The destination directory comes via `$targetDirectoryNodeId`.

## What's not in this module

- Higher-level helpers like `readFileContents()` / `writeFileContents()`
  that wrap Open + N×Read + Close in one call — these may land in a
  later release once the helper shape stabilises across spec edge
  cases (cursor positioning, partial writes, server-side size caps).
- `FileChange` / `FileAccess` events (Part 5 §8.2) — server-emitted.
  The current upstream UA-.NETStandard test server doesn't ship them
  either (see the test-suite doc for the upstream gap), so the
  client-side typed event would have no fixture to validate against.

## What to read next

- [Modules](../extensibility/modules.md) — `FileTransferModule` is the
  10th default module.
- [Event reference](../observability/event-reference.md) — the four
  file-transfer events listed in context.
- [Enums · OpenFileMode](../reference/enums.md) — the bit-field detail.
