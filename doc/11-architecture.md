# Architecture

## Project Structure

```
src/
├── Client.php                           # Main entry point
├── OpcUaClientInterface.php             # Public API interface
│
├── Transport/
│   └── TcpTransport.php                 # TCP socket communication
│
├── Encoding/
│   ├── BinaryEncoder.php                # Serialization (write)
│   └── BinaryDecoder.php                # Deserialization (read)
│
├── Protocol/
│   ├── MessageHeader.php                # OPC UA message framing
│   ├── HelloMessage.php                 # HEL message
│   ├── AcknowledgeMessage.php           # ACK message
│   ├── SecureChannelRequest.php         # OPN request
│   ├── SecureChannelResponse.php        # OPN response
│   ├── SessionService.php               # CreateSession / ActivateSession
│   ├── BrowseService.php                # Browse / BrowseNext
│   ├── ReadService.php                  # Read
│   ├── WriteService.php                 # Write
│   ├── CallService.php                  # Call (method invocation)
│   ├── GetEndpointsService.php          # GetEndpoints
│   ├── SubscriptionService.php          # Create/Modify/Delete Subscription
│   ├── MonitoredItemService.php         # Create/Delete MonitoredItems
│   ├── PublishService.php               # Publish (notifications)
│   └── HistoryReadService.php           # HistoryRead (raw/processed/attime)
│
├── Security/
│   ├── SecurityPolicy.php               # Security policy enum + algorithm config
│   ├── SecurityMode.php                 # Security mode enum
│   ├── SecureChannel.php                # Secure channel lifecycle
│   ├── MessageSecurity.php              # Cryptographic operations
│   └── CertificateManager.php          # Certificate loading & utilities
│
├── Types/
│   ├── BuiltinType.php                  # OPC UA type enum
│   ├── NodeClass.php                    # Node class enum
│   ├── NodeId.php                       # Node identifier
│   ├── Variant.php                      # Typed value container
│   ├── DataValue.php                    # Value + status + timestamps
│   ├── QualifiedName.php                # Namespace-qualified name
│   ├── LocalizedText.php                # Locale-aware text
│   ├── ReferenceDescription.php         # Browse result item
│   ├── EndpointDescription.php          # Server endpoint info
│   ├── UserTokenPolicy.php              # Authentication policy
│   ├── StatusCode.php                   # Status code constants & helpers
│   ├── AttributeId.php                  # Attribute ID constants
│   ├── ConnectionState.php              # Connection state enum
│   ├── BrowseDirection.php              # Browse direction enum
│   └── BrowseNode.php                   # Recursive browse tree node
│
└── Exception/
    ├── OpcUaException.php               # Base exception
    ├── ConfigurationException.php       # Config errors
    ├── ConnectionException.php          # TCP errors
    ├── EncodingException.php            # Binary codec errors
    ├── ProtocolException.php            # Protocol violations
    ├── SecurityException.php            # Crypto errors
    └── ServiceException.php             # Server errors (with status code)
```

## Layer Diagram

```
┌─────────────────────────────────┐
│           Client                │  Public API
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

## Service Pattern

All protocol services follow a consistent pattern:

1. Each service wraps a `SessionService` instance
2. Each operation has separate encoding and decoding methods
3. Each supports both secure and non-secure paths
4. Inner body construction is factored out for reuse between secure and non-secure variants

```
encodeFooRequest()
  ├── (no security) → write headers + body → wrapInMessage()
  └── (security)    → write body → secureChannel->buildMessage()

decodeFooResponse()
  └── read headers → readResponseHeader() → parse result fields
```

## Message Flow

### Non-Secure Message

```
┌──────────┬─────────────┬──────────┬───────────┬─────────────┐
│ MSG/F    │ MessageSize │ ChannelId│ TokenId   │ Sequence    │
│ (3+1 B)  │ (4 B)       │ (4 B)    │ (4 B)     │ Num + ReqId │
├──────────┴─────────────┴──────────┴───────────┴─────────────┤
│                     Service Body                             │
│  (TypeId + RequestHeader + Service-specific fields)          │
└──────────────────────────────────────────────────────────────┘
```

### Secure Message

```
┌──────────┬─────────────┬──────────┐
│ MSG/F    │ MessageSize │ ChannelId│
├──────────┴─────────────┴──────────┤
│ TokenId │ SequenceNum │ RequestId │
├─────────┴─────────────┴───────────┤
│          Encrypted Body           │ ← AES-CBC
│   (TypeId + Headers + Fields)     │
│   + Padding + PaddingByte         │
├───────────────────────────────────┤
│          HMAC Signature           │ ← HMAC-SHA1/SHA256
└───────────────────────────────────┘
```

## Binary Encoding

The library implements the OPC UA Binary encoding specification:

- **Little-endian** byte order for all integers
- **Length-prefixed** strings (Int32 length + UTF-8 bytes, -1 for null)
- **NodeId** compact encoding (TwoByte/FourByte/Numeric/String/Guid/Opaque)
- **Variant** encoding with type byte and optional array flag
- **DataValue** encoding with bitmask for optional fields
- **DateTime** as 100-nanosecond intervals since 1601-01-01 UTC
