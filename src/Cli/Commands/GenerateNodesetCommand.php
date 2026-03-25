<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Cli\Commands;

use Gianfriaur\OpcuaPhpClient\Cli\CodeGenerator;
use Gianfriaur\OpcuaPhpClient\Cli\NodeSetParser;
use Gianfriaur\OpcuaPhpClient\Cli\Output\OutputInterface;
use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;

/**
 * Generates PHP classes (NodeId constants, Codecs, Registrar) from a NodeSet2.xml file.
 */
class GenerateNodesetCommand implements CommandInterface
{
    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'generate:nodeset';
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return 'Generate PHP classes from a NodeSet2.xml file';
    }

    /**
     * {@inheritDoc}
     */
    public function getUsage(): string
    {
        return 'generate:nodeset <xmlFile> [--output=./generated/] [--namespace=Generated\\OpcUa]';
    }

    /**
     * {@inheritDoc}
     */
    public function requiresConnection(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function execute(OpcUaClientInterface $client, array $arguments, array $options, OutputInterface $output): int
    {
        if (count($arguments) < 1) {
            $output->error('Usage: opcua-cli generate:nodeset <xmlFile> [--output=./generated/] [--namespace=Generated\\OpcUa]');

            return 1;
        }

        $xmlFile = $arguments[0];
        if (! file_exists($xmlFile)) {
            $output->error("File not found: {$xmlFile}");

            return 1;
        }

        $outputDir = rtrim((string) ($options['output'] ?? './generated'), '/');
        $namespace = (string) ($options['namespace'] ?? 'Generated\\OpcUa');

        $parser = new NodeSetParser();
        $parser->parse($xmlFile);

        $generator = new CodeGenerator();
        $nodes = $parser->getNodes();
        $dataTypes = $parser->getDataTypes();
        $enumerations = $parser->getEnumerations();

        if (empty($nodes) && empty($dataTypes) && empty($enumerations)) {
            $output->writeln('No nodes or data types found in the file.');

            return 0;
        }

        $this->ensureDirectory($outputDir);

        $baseName = $this->deriveBaseName($xmlFile);
        $filesWritten = 0;

        if (! empty($nodes)) {
            $nodeIdCode = $generator->generateNodeIdClass($baseName . 'NodeIds', $nodes, $namespace);
            $this->writeFile($outputDir . '/' . $baseName . 'NodeIds.php', $nodeIdCode);
            $output->writeln("Generated: {$outputDir}/{$baseName}NodeIds.php");
            $filesWritten++;
        }

        $codecRegistrations = [];

        if (! empty($dataTypes)) {
            $this->ensureDirectory($outputDir . '/Codecs');

            foreach ($dataTypes as $dt) {
                if (empty($dt['fields']) || $dt['encodingId'] === null) {
                    continue;
                }

                $codecName = $dt['name'] . 'Codec';
                $codecCode = $generator->generateCodecClass($codecName, $dt['fields'], $namespace);
                $this->writeFile($outputDir . '/Codecs/' . $codecName . '.php', $codecCode);
                $output->writeln("Generated: {$outputDir}/Codecs/{$codecName}.php");
                $filesWritten++;

                $codecRegistrations[] = [
                    'encodingId' => $dt['encodingId'],
                    'codecClass' => $codecName,
                ];
            }
        }

        if (! empty($enumerations)) {
            $this->ensureDirectory($outputDir . '/Enums');

            foreach ($enumerations as $enum) {
                $enumCode = $generator->generateEnumClass($enum['name'], $enum['values'], $namespace);
                $this->writeFile($outputDir . '/Enums/' . $enum['name'] . '.php', $enumCode);
                $output->writeln("Generated: {$outputDir}/Enums/{$enum['name']}.php");
                $filesWritten++;
            }
        }

        if (! empty($codecRegistrations)) {
            $registrarCode = $generator->generateRegistrarClass($baseName . 'Registrar', $codecRegistrations, $namespace);
            $this->writeFile($outputDir . '/' . $baseName . 'Registrar.php', $registrarCode);
            $output->writeln("Generated: {$outputDir}/{$baseName}Registrar.php");
            $filesWritten++;
        }

        $output->writeln('');
        $output->writeln("Done. {$filesWritten} file(s) generated in {$outputDir}/");

        return 0;
    }

    /**
     * @param string $xmlFile
     * @return string
     */
    private function deriveBaseName(string $xmlFile): string
    {
        $filename = basename($xmlFile, '.xml');
        $filename = str_replace(['Opc.Ua.', '.NodeSet2', 'NodeSet2'], '', $filename);

        if ($filename === '') {
            return 'OpcUa';
        }

        $filename = str_replace(['.', '-', ' '], '', $filename);

        return $filename;
    }

    /**
     * @param string $dir
     * @return void
     */
    private function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * @param string $path
     * @param string $content
     * @return void
     */
    private function writeFile(string $path, string $content): void
    {
        file_put_contents($path, $content);
    }
}
