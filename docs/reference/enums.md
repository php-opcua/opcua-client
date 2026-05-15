---
eyebrow: 'Docs · Reference'
lede:    'Every enum the public API exposes. PHP 8.1 backed enums throughout — use case() to construct from a wire value, ->value to recover the underlying scalar.'

see_also:
  - { href: './client-api.md',           meta: '8 min' }
  - { href: '../types/built-in-types.md', meta: '5 min' }
  - { href: '../security/policies.md',    meta: '7 min' }

prev: { label: 'Exceptions',    href: './exceptions.md' }
next: { label: 'Upgrading to v4.3', href: '../recipes/upgrading-to-v4.3.md' }
---

# Enums

The library exposes seven enums on its public surface. All are PHP
8.1 backed enums; the `value` corresponds to the OPC UA-spec integer
or URI.

<!-- @divider eyebrow="BuiltinType" -->
The 25 primitive types of OPC UA. The full mapping table — including
PHP types, ranges, and pitfalls — is in [Types · Built-in
types](../types/built-in-types.md).
<!-- @enddivider -->

| Case              | Value | Case            | Value | Case             | Value |
| ----------------- | ----- | --------------- | ----- | ---------------- | ----- |
| `Boolean`         | 1     | `String`        | 12    | `LocalizedText`  | 21    |
| `SByte`           | 2     | `DateTime`      | 13    | `ExtensionObject`| 22    |
| `Byte`            | 3     | `Guid`          | 14    | `DataValue`      | 23    |
| `Int16`           | 4     | `ByteString`    | 15    | `Variant`        | 24    |
| `UInt16`          | 5     | `XmlElement`    | 16    | `DiagnosticInfo` | 25    |
| `Int32`           | 6     | `NodeId`        | 17    |                  |       |
| `UInt32`          | 7     | `ExpandedNodeId`| 18    |                  |       |
| `Int64`           | 8     | `StatusCode`    | 19    |                  |       |
| `UInt64`          | 9     | `QualifiedName` | 20    |                  |       |
| `Float`           | 10    |                 |       |                  |       |
| `Double`          | 11    |                 |       |                  |       |

`BuiltinType::from(11)` returns `BuiltinType::Double`.

<!-- @divider eyebrow="NodeClass" -->
The eight node classes from OPC UA Part 3. Bitmask-friendly — combine
with `|` when filtering Browse results.
<!-- @enddivider -->

| Case            | Value | Meaning                                    |
| --------------- | ----- | ------------------------------------------ |
| `Unspecified`   | 0     | No filter / unknown                        |
| `Object`        | 1     | An object node (folder, device, instance)  |
| `Variable`      | 2     | A variable node (holds a Value attribute)  |
| `Method`        | 4     | A method node (callable)                   |
| `ObjectType`    | 8     | A type definition for objects              |
| `VariableType`  | 16    | A type definition for variables            |
| `ReferenceType` | 32    | A reference-type definition                |
| `DataType`      | 64    | A DataType definition                      |
| `View`          | 128   | A view (subset of the address space)       |

<!-- @code-block language="php" label="bitmask filter" -->
```php
$client->browse(
    'i=85',
    nodeClassMask: NodeClass::Variable->value | NodeClass::Method->value,
);
```
<!-- @endcode-block -->

<!-- @divider eyebrow="BrowseDirection" -->
Which references a browse should follow.
<!-- @enddivider -->

| Case      | Meaning                                  |
| --------- | ---------------------------------------- |
| `Forward` | Follow references pointing **away from** the node (the default — children, attributes, methods) |
| `Inverse` | Follow references pointing **at** the node (parent, type definition, …) |
| `Both`    | Follow both directions; expensive on large address spaces |

<!-- @divider eyebrow="ConnectionState" -->
The client state machine. See [Connection · Opening and
closing](../connection/opening-and-closing.md).
<!-- @enddivider -->

| Case          | When                                                  |
| ------------- | ----------------------------------------------------- |
| `Disconnected`| Initial state, and after a successful `disconnect()`  |
| `Connected`   | After `connect()` returns and during normal operation |
| `Broken`      | An I/O error invalidated the session; needs `reconnect()` or `disconnect()` |

<!-- @divider eyebrow="SecurityPolicy" -->
The full algorithm-suite enum — 6 RSA + 4 ECC. See [Security ·
Policies](../security/policies.md) for picking criteria.
<!-- @enddivider -->

| Case                       | Value (URI prefix `…SecurityPolicy#`)  |
| -------------------------- | -------------------------------------- |
| `None`                     | `None`                                 |
| `Basic128Rsa15`            | `Basic128Rsa15` *(deprecated)*         |
| `Basic256`                 | `Basic256` *(deprecated)*              |
| `Basic256Sha256`           | `Basic256Sha256`                       |
| `Aes128Sha256RsaOaep`      | `Aes128_Sha256_RsaOaep`                |
| `Aes256Sha256RsaPss`       | `Aes256_Sha256_RsaPss`                 |
| `EccNistP256`              | `ECC_nistP256`                         |
| `EccNistP384`              | `ECC_nistP384`                         |
| `EccBrainpoolP256r1`       | `ECC_brainpoolP256r1`                  |
| `EccBrainpoolP384r1`       | `ECC_brainpoolP384r1`                  |

Helpers:

- `isEcc(): bool` — `true` for the four ECC cases
- `getEcdhCurveName(): string` — OpenSSL curve name (P-256 cases →
  `'prime256v1'`, etc.)
- `getEphemeralKeyLength(): int` — uncompressed-point byte length (64
  for P-256, 96 for P-384)
- `SecurityPolicy::from($uri)` — parse the URI back into the case

<!-- @divider eyebrow="SecurityMode" -->
The three modes — `None`, `Sign`, `SignAndEncrypt`. Used with
`SecurityPolicy`. The numeric values match the OPC UA
`MessageSecurityMode` enum.
<!-- @enddivider -->

| Case              | Value | Meaning                                  |
| ----------------- | ----- | ---------------------------------------- |
| `None`            | 1     | No signing, no encryption                |
| `Sign`            | 2     | Every message is signed; nothing encrypted |
| `SignAndEncrypt`  | 3     | Every message is signed and encrypted    |

<!-- @divider eyebrow="TrustPolicy" -->
What the trust store checks. See [Security · Trust
store](../security/trust-store.md).
<!-- @enddivider -->

| Case                   | Value                | Behaviour                                            |
| ---------------------- | -------------------- | ---------------------------------------------------- |
| `Fingerprint`          | `fingerprint`        | SHA-256 fingerprint must be in the store. Validity window ignored. |
| `FingerprintAndExpiry` | `fingerprint+expiry` | Fingerprint must be in the store **and** the cert must be within `notBefore`/`notAfter`. |
| `Full`                 | `full`               | X.509 chain validation against a CA bundle in the store, including expiry. |

## StatusCode

`StatusCode` is a class, not an enum — OPC UA status codes are too
many for a PHP enum to be ergonomic. Use it as a constant catalogue
and as a helper:

<!-- @code-block language="php" label="StatusCode helpers" -->
```php
use PhpOpcua\Client\Types\StatusCode;

StatusCode::isGood(0);                  // true
StatusCode::isBad(0x80340000);          // true (BadNodeIdUnknown)
StatusCode::isUncertain(0x40000000);    // true

StatusCode::getName(0x80340000);        // "BadNodeIdUnknown"

// Common bad codes:
StatusCode::BadNodeIdUnknown;           // 0x80340000
StatusCode::BadUserAccessDenied;        // 0x801F0000
StatusCode::BadServiceUnsupported;      // 0x800B0000
StatusCode::BadTimeout;                 // 0x800A0000
StatusCode::BadTypeMismatch;            // 0x80740000
StatusCode::BadOutOfRange;              // 0x803E0000
StatusCode::BadWriteNotSupported;       // 0x80730000
```
<!-- @endcode-block -->

The bit layout (Part 4 §7.34):

- **Bits 31–30** — severity: `00` = Good, `01` = Uncertain, `10` = Bad
- **Bits 29–16** — sub-code (the meaning)
- **Bits 15–0** — info bits (semantic flags rarely used directly)

The full catalogue of named codes is in `src/Types/StatusCode.php` —
~250 constants covering everything Part 4 §7.34 defines. Browse it
in the IDE for autocomplete.

## AttributeId

Like `StatusCode`, `AttributeId` is a class of integer constants —
the OPC UA attribute IDs from Part 3 §5.9:

| Constant                    | ID  | Applies to                |
| --------------------------- | --- | ------------------------- |
| `AttributeId::NodeId`       | 1   | Every node                |
| `AttributeId::NodeClass`    | 2   | Every node                |
| `AttributeId::BrowseName`   | 3   | Every node                |
| `AttributeId::DisplayName`  | 4   | Every node                |
| `AttributeId::Description`  | 5   | Every node (optional)     |
| `AttributeId::WriteMask`    | 6   | Every node                |
| `AttributeId::UserWriteMask`| 7   | Every node                |
| `AttributeId::IsAbstract`   | 8   | Type nodes                |
| `AttributeId::Symmetric`    | 9   | ReferenceType             |
| `AttributeId::InverseName`  | 10  | ReferenceType             |
| `AttributeId::ContainsNoLoops` | 11 | View                    |
| `AttributeId::EventNotifier`| 12  | Object, View              |
| `AttributeId::Value`        | 13  | Variable                  |
| `AttributeId::DataType`     | 14  | Variable, VariableType    |
| `AttributeId::ValueRank`    | 15  | Variable, VariableType    |
| `AttributeId::ArrayDimensions` | 16 | Variable, VariableType  |
| `AttributeId::AccessLevel`  | 17  | Variable                  |
| `AttributeId::UserAccessLevel` | 18 | Variable                |
| `AttributeId::MinimumSamplingInterval` | 19 | Variable        |
| `AttributeId::Historizing`  | 20  | Variable                  |
| `AttributeId::Executable`   | 21  | Method                    |
| `AttributeId::UserExecutable` | 22 | Method                   |

`AttributeId::Value` is the default for `$client->read()`; pass any
of the others to read the corresponding attribute.
