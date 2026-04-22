<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Protocol;

/**
 * OPC UA service and well-known NodeId numeric identifiers (namespace 0).
 *
 * Replaces hard-coded magic numbers throughout the Protocol layer with named constants.
 *
 * Groups: Null, Well-Known Nodes, Secure Channel, Session, Discovery,
 * Browse, Read/Write, Method Call, Monitored Items, Subscriptions,
 * Identity Tokens, Event Filter Encoding, Server Limits.
 */
final class ServiceTypeId
{
    public const NULL = 0;

    public const BASE_DATA_TYPE = 22;

    public const HIERARCHICAL_REFERENCES = 33;

    public const HAS_ENCODING = 38;

    public const HAS_SUBTYPE = 45;

    public const ROOT = 84;

    public const OBJECTS_FOLDER = 85;

    public const OPEN_SECURE_CHANNEL_REQUEST = 446;

    public const CLOSE_SECURE_CHANNEL_REQUEST = 452;

    public const CREATE_SESSION_REQUEST = 461;

    public const ACTIVATE_SESSION_REQUEST = 467;

    public const CLOSE_SESSION_REQUEST = 473;

    public const GET_ENDPOINTS_REQUEST = 428;

    public const SERVICE_FAULT = 397; // OPC UA 1.05 Part 4 §7.35.

    public const BROWSE_REQUEST = 527;

    public const BROWSE_NEXT_REQUEST = 533;

    public const ADD_NODES_REQUEST = 488;

    public const ADD_REFERENCES_REQUEST = 494;

    public const DELETE_NODES_REQUEST = 500;

    public const DELETE_REFERENCES_REQUEST = 506;

    public const TRANSLATE_BROWSE_PATHS_REQUEST = 554;

    public const READ_REQUEST = 631;

    public const WRITE_REQUEST = 673;

    public const HISTORY_READ_REQUEST = 664;

    public const CALL_REQUEST = 712;

    public const CREATE_MONITORED_ITEMS_REQUEST = 751;

    public const MODIFY_MONITORED_ITEMS_REQUEST = 763;

    public const SET_TRIGGERING_REQUEST = 775;

    public const DELETE_MONITORED_ITEMS_REQUEST = 781;

    public const CREATE_SUBSCRIPTION_REQUEST = 787;

    public const MODIFY_SUBSCRIPTION_REQUEST = 793;

    public const SET_PUBLISHING_MODE_REQUEST = 799;

    public const PUBLISH_REQUEST = 826;

    public const REPUBLISH_REQUEST = 832;

    public const TRANSFER_SUBSCRIPTIONS_REQUEST = 841;

    public const DELETE_SUBSCRIPTIONS_REQUEST = 847;

    public const ANONYMOUS_IDENTITY_TOKEN = 321;

    public const USERNAME_IDENTITY_TOKEN = 324;

    public const X509_IDENTITY_TOKEN = 327;

    public const EVENT_FILTER_ENCODING = 727;

    public const SIMPLE_ATTRIBUTE_OPERAND_ENCODING = 2041;

    public const MAX_NODES_PER_READ = 11705;

    public const MAX_NODES_PER_WRITE = 11707;
}
