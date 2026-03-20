# Architecture

## Project Structure

```
src/
├── Client.php                           # Main entry point
├── OpcUaClientInterface.php             # Public API interface
│
├── Client/
│   ├── ManagesAutoRetryTrait.php        # Auto-retry on connection loss
│   ├── ManagesBatchingTrait.php         # Batch read/write operations
│   ├── ManagesBrowseDepthTrait.php      # Recursive browse depth control
│   ├── ManagesBrowseTrait.php           # Browse operations
│   ├── ManagesConnectionTrait.php       # Connect / disconnect / reconnect
│   ├── ManagesHandshakeTrait.php        # HEL/ACK handshake
│   ├── ManagesHistoryTrait.php          # History read operations
│   ├── ManagesReadWriteTrait.php        # Read / write operations
│   ├── ManagesSecureChannelTrait.php    # Secure channel lifecycle
│   ├── ManagesSessionTrait.php          # Session create / activate
│   ├── ManagesSubscriptionsTrait.php    # Subscriptions and monitored items
│   ├── ManagesTimeoutTrait.php          # Timeout configuration
│   ├── ManagesTranslateBrowsePathTrait.php # Browse path translation
│   └── ManagesTypeDiscoveryTrait.php     # Automatic DataType discovery
│
├── Transport/
│   └── TcpTransport.php                # TCP socket I/O
│
├── Encoding/
│   ├── BinaryEncoder.php               # Serialization (write)
│   ├── BinaryDecoder.php               # Deserialization (read)
│   ├── ExtensionObjectCodec.php        # Interface for custom type codecs
│   ├── DynamicCodec.php               # Auto-generated codec from StructureDefinition
│   ├── DataTypeMapping.php            # Maps DataType NodeIds to BuiltinTypes
│   └── StructureDefinitionParser.php  # Parses DataTypeDefinition attributes
│
├── Protocol/
│   ├── MessageHeader.php               # OPC UA message framing
│   ├── HelloMessage.php                # HEL message
│   ├── AcknowledgeMessage.php          # ACK message
│   ├── SecureChannelRequest.php        # OPN request
│   ├── SecureChannelResponse.php       # OPN response
│   ├── SessionService.php             # CreateSession / ActivateSession
│   ├── BrowseService.php              # Browse / BrowseNext
│   ├── ReadService.php                # Read
│   ├── WriteService.php               # Write
│   ├── CallService.php                # Call (method invocation)
│   ├── GetEndpointsService.php        # GetEndpoints
│   ├── SubscriptionService.php        # Create / Modify / Delete Subscription
│   ├── MonitoredItemService.php       # Create / Delete MonitoredItems
│   ├── PublishService.php             # Publish (notifications)
│   ├── HistoryReadService.php         # HistoryRead (raw / processed / attime)
│   └── TranslateBrowsePathService.php # TranslateBrowsePathsToNodeIds
│
├── Security/
│   ├── SecurityPolicy.php             # Security policy enum + algorithm config
│   ├── SecurityMode.php               # Security mode enum
│   ├── SecureChannel.php              # Secure channel lifecycle
│   ├── MessageSecurity.php            # Cryptographic operations
│   └── CertificateManager.php        # Certificate loading & utilities
│
├── Types/
│   ├── BuiltinType.php                # OPC UA type enum
│   ├── NodeClass.php                  # Node class enum
│   ├── NodeId.php                     # Node identifier
│   ├── Variant.php                    # Typed value container
│   ├── DataValue.php                  # Value + status + timestamps
│   ├── QualifiedName.php              # Namespace-qualified name
│   ├── LocalizedText.php             # Locale-aware text
│   ├── ReferenceDescription.php      # Browse result item
│   ├── EndpointDescription.php       # Server endpoint info
│   ├── UserTokenPolicy.php           # Authentication policy
│   ├── StatusCode.php                # Status code constants & helpers
│   ├── AttributeId.php               # Attribute ID constants
│   ├── ConnectionState.php           # Connection state enum
│   ├── BrowseDirection.php           # Browse direction enum
│   ├── BrowseNode.php                # Recursive browse tree node DTO
│   ├── BrowseResultSet.php           # Browse with continuation result DTO
│   ├── BrowsePathResult.php          # Translate browse path result DTO
│   ├── BrowsePathTarget.php          # Single resolved browse path target DTO
│   ├── CallResult.php                # Method call result DTO
│   ├── SubscriptionResult.php        # Create subscription result DTO
│   ├── MonitoredItemResult.php       # Create monitored item result DTO
│   ├── PublishResult.php             # Publish response result DTO
│   ├── StructureField.php            # Field descriptor for structure definitions
│   └── StructureDefinition.php       # Structure layout for dynamic codecs
│
├── Repository/
│   └── ExtensionObjectRepository.php  # Per-client codec registry
│
└── Exception/
    ├── OpcUaException.php             # Base exception
    ├── ConfigurationException.php     # Config errors
    ├── ConnectionException.php        # TCP errors
    ├── EncodingException.php          # Binary codec errors
    ├── InvalidNodeIdException.php     # Malformed NodeId errors
    ├── ProtocolException.php          # Protocol violations
    ├── SecurityException.php          # Crypto errors
    └── ServiceException.php           # Server errors (with status code)
```

## Layers

```
┌─────────────────────────────────┐
│           Client                │  Public API
│     (+ Client/*Trait.php)       │  Feature-specific traits
├─────────────────────────────────┤
│     Protocol/*Service           │  OPC UA service encoding/decoding
├──────────────┬──────────────────┤
│ BinaryEncoder│  BinaryDecoder   │  Binary serialization
├──────────────┴──────────────────┤
│       SecureChannel             │  Message-level security
├─────────────────────────────────┤
│    MessageSecurity              │  Cryptographic operations
│    CertificateManager           │  Certificate handling
├─────────────────────────────────┤
│       TcpTransport              │  TCP socket I/O
└─────────────────────────────────┘
```

Each layer only talks to the one directly below it. The `Client` is the sole public entry point -- everything else is internal.

## Service Pattern

All protocol services follow the same structure:

1. Each wraps a `SessionService` instance
2. Separate `encode` and `decode` methods per operation
3. Both secure and non-secure code paths
4. Inner body construction is factored out for reuse

```
encodeFooRequest()
  ├── (no security) → write headers + body → wrapInMessage()
  └── (security)    → write body → secureChannel->buildMessage()

decodeFooResponse()
  └── read headers → readResponseHeader() → parse result fields
```

Adding a new OPC UA service means writing one class with `encode*Request()` and `decode*Response()` methods. The security, framing, and transport layers handle everything else.

## Message Format

### Non-Secure

```
┌──────────┬─────────────┬──────────┬───────────┬─────────────┐
│ MSG/F    │ MessageSize │ ChannelId│ TokenId   │ Sequence    │
│ (3+1 B)  │ (4 B)       │ (4 B)    │ (4 B)     │ Num + ReqId │
├──────────┴─────────────┴──────────┴───────────┴─────────────┤
│                     Service Body                             │
│  (TypeId + RequestHeader + Service-specific fields)          │
└──────────────────────────────────────────────────────────────┘
```

### Secure

```
┌──────────┬─────────────┬──────────┐
│ MSG/F    │ MessageSize │ ChannelId│
├──────────┴─────────────┴──────────┤
│ TokenId │ SequenceNum │ RequestId │
├─────────┴─────────────┴───────────┤
│          Encrypted Body           │  ← AES-CBC
│   (TypeId + Headers + Fields)     │
│   + Padding + PaddingByte         │
├───────────────────────────────────┤
│          HMAC Signature           │  ← HMAC-SHA1/SHA256
└───────────────────────────────────┘
```

## Binary Encoding Notes

The library implements OPC UA Binary encoding (Part 6 of the spec):

- **Little-endian** byte order for all integers
- **Length-prefixed strings** -- `Int32` length + UTF-8 bytes, `-1` for null
- **NodeId compact encoding** -- TwoByte / FourByte / Numeric / String / Guid / Opaque, chosen automatically based on namespace and identifier
- **Variant** -- type byte with optional array dimension flag
- **DataValue** -- bitmask header indicating which optional fields are present (value, status, timestamps)
- **DateTime** -- 100-nanosecond intervals since 1601-01-01 UTC
