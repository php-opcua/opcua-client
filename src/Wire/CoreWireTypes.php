<?php

declare(strict_types=1);

namespace PhpOpcua\Client\Wire;

use PhpOpcua\Client\Types\BrowseDirection;
use PhpOpcua\Client\Types\BrowseNode;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\ConnectionState;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\EndpointDescription;
use PhpOpcua\Client\Types\ExtensionObject;
use PhpOpcua\Client\Types\LocalizedText;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\QualifiedName;
use PhpOpcua\Client\Types\ReferenceDescription;
use PhpOpcua\Client\Types\UserTokenPolicy;
use PhpOpcua\Client\Types\Variant;

/**
 * Registers the cross-cutting OPC UA value-objects and enums on a
 * {@see WireTypeRegistry}. Always populated by every client, regardless of
 * which service modules are loaded.
 */
final class CoreWireTypes
{
    /**
     * @param WireTypeRegistry $registry
     * @return void
     */
    public static function register(WireTypeRegistry $registry): void
    {
        $registry->register(NodeId::class);
        $registry->register(QualifiedName::class);
        $registry->register(LocalizedText::class);
        $registry->register(DataValue::class);
        $registry->register(Variant::class);
        $registry->register(ExtensionObject::class);
        $registry->register(BrowseNode::class);
        $registry->register(ReferenceDescription::class);
        $registry->register(EndpointDescription::class);
        $registry->register(UserTokenPolicy::class);

        $registry->registerEnum(BuiltinType::class);
        $registry->registerEnum(NodeClass::class);
        $registry->registerEnum(BrowseDirection::class);
        $registry->registerEnum(ConnectionState::class);
    }
}
