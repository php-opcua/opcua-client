<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Protocol;

/**
 * OPC UA service and well-known NodeId numeric identifiers (namespace 0).
 *
 * Replaces hard-coded magic numbers throughout the Protocol layer with named constants.
 */
final class ServiceTypeId
{
    // ── Null ──
    public const NULL = 0;

    // ── Well-Known Nodes ──
    public const BASE_DATA_TYPE = 22;

    public const HIERARCHICAL_REFERENCES = 33;

    public const HAS_ENCODING = 38;

    public const HAS_SUBTYPE = 45;

    public const ROOT = 84;

    public const OBJECTS_FOLDER = 85;

    // ── Secure Channel ──
    public const OPEN_SECURE_CHANNEL_REQUEST = 446;

    public const CLOSE_SECURE_CHANNEL_REQUEST = 452;

    // ── Session ──
    public const CREATE_SESSION_REQUEST = 461;

    public const ACTIVATE_SESSION_REQUEST = 467;

    public const CLOSE_SESSION_REQUEST = 473;

    // ── Discovery ──
    public const GET_ENDPOINTS_REQUEST = 428;

    // ── Browse ──
    public const BROWSE_REQUEST = 527;

    public const BROWSE_NEXT_REQUEST = 533;

    public const TRANSLATE_BROWSE_PATHS_REQUEST = 554;

    // ── Read / Write ──
    public const READ_REQUEST = 631;

    public const WRITE_REQUEST = 673;

    public const HISTORY_READ_REQUEST = 664;

    // ── Method Call ──
    public const CALL_REQUEST = 712;

    // ── Monitored Items ──
    public const CREATE_MONITORED_ITEMS_REQUEST = 751;

    public const MODIFY_MONITORED_ITEMS_REQUEST = 763;

    public const SET_TRIGGERING_REQUEST = 769;

    public const DELETE_MONITORED_ITEMS_REQUEST = 781;

    // ── Subscriptions ──
    public const CREATE_SUBSCRIPTION_REQUEST = 787;

    public const MODIFY_SUBSCRIPTION_REQUEST = 793;

    public const SET_PUBLISHING_MODE_REQUEST = 799;

    public const PUBLISH_REQUEST = 826;

    public const REPUBLISH_REQUEST = 832;

    public const TRANSFER_SUBSCRIPTIONS_REQUEST = 841;

    public const DELETE_SUBSCRIPTIONS_REQUEST = 847;

    // ── Identity Tokens ──
    public const ANONYMOUS_IDENTITY_TOKEN = 321;

    public const USERNAME_IDENTITY_TOKEN = 324;

    public const X509_IDENTITY_TOKEN = 327;

    // ── Event Filter Encoding ──
    public const EVENT_FILTER_ENCODING = 727;

    public const SIMPLE_ATTRIBUTE_OPERAND_ENCODING = 2041;

    // ── Server Limits ──
    public const MAX_NODES_PER_READ = 11705;

    public const MAX_NODES_PER_WRITE = 11707;
}
