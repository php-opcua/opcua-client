<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Repository;

/**
 * Contract for generated type registrars produced by the NodeSet2.xml code generator.
 *
 * Implementations register ExtensionObject codecs and provide enum mappings
 * for automatic value casting when reading nodes.
 *
 * @see \Gianfriaur\OpcuaPhpClient\Client\ManagesReadWriteTrait::loadGeneratedTypes()
 */
interface GeneratedTypeRegistrar
{
    /**
     * Register all generated ExtensionObject codecs with the repository.
     *
     * @param ExtensionObjectRepository $repository
     * @return void
     */
    public function registerCodecs(ExtensionObjectRepository $repository): void;

    /**
     * Return a mapping of NodeId strings to BackedEnum class names.
     *
     * @return array<string, class-string<\BackedEnum>>
     */
    public function getEnumMappings(): array;

    /**
     * Return registrars for required NodeSet dependencies.
     *
     * @return GeneratedTypeRegistrar[]
     */
    public function dependencyRegistrars(): array;
}
