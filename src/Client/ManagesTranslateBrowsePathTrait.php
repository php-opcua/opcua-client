<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Client;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Exception\ServiceException;
use Gianfriaur\OpcuaPhpClient\Types\BrowsePathResult;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\QualifiedName;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

/**
 * Provides browse path translation and NodeId resolution from human-readable paths.
 */
trait ManagesTranslateBrowsePathTrait
{
    /**
     * Translate one or more browse paths to their target NodeIds.
     *
     * @param ?array<array{startingNodeId: NodeId|string, relativePath: array<array{referenceTypeId?: NodeId, isInverse?: bool, includeSubtypes?: bool, targetName: QualifiedName}>}> $browsePaths Paths to translate, or null to get a fluent builder.
     * @return ($browsePaths is null ? \Gianfriaur\OpcuaPhpClient\Builder\BrowsePathsBuilder : BrowsePathResult[])
     *
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ConnectionException If the connection is lost during the request.
     * @throws ServiceException If the server returns an error response.
     *
     * @see BrowsePathResult
     */
    public function translateBrowsePaths(?array $browsePaths = null): array|\Gianfriaur\OpcuaPhpClient\Builder\BrowsePathsBuilder
    {
        if ($browsePaths === null) {
            return new \Gianfriaur\OpcuaPhpClient\Builder\BrowsePathsBuilder($this);
        }

        foreach ($browsePaths as &$item) {
            if (isset($item['startingNodeId']) && is_string($item['startingNodeId'])) {
                $item['startingNodeId'] = NodeId::parse($item['startingNodeId']);
            }
        }
        unset($item);

        return $this->executeWithRetry(function () use ($browsePaths) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->translateBrowsePathService->encodeTranslateRequest($requestId, $browsePaths, $this->authenticationToken);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = $this->createDecoder($responseBody);

            return $this->translateBrowsePathService->decodeTranslateResponse($decoder);
        });
    }

    /**
     * Resolve a slash-separated browse path string to a NodeId.
     *
     * @param string $path Slash-separated browse path (e.g. "Objects/MyFolder/MyNode"). Segments may include a namespace prefix like "2:MyNode".
     * @param NodeId|string|null $startingNodeId Starting node, defaults to the Root node (ns=0;i=84).
     * @return NodeId
     *
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\InvalidNodeIdException If a string parameter cannot be parsed as a NodeId.
     * @throws ServiceException If the path cannot be resolved, yields no targets, or the server returns a bad status code.
     * @throws \Gianfriaur\OpcuaPhpClient\Exception\ConnectionException If the connection is lost during the request.
     */
    public function resolveNodeId(string $path, NodeId|string|null $startingNodeId = null, bool $useCache = true): NodeId
    {
        if (is_string($startingNodeId)) {
            $startingNodeId = NodeId::parse($startingNodeId);
        }
        $startingNodeId ??= NodeId::numeric(0, 84); // Root

        $normalizedPath = trim($path, '/');
        $cacheKey = $this->buildCacheKey('resolve', $startingNodeId, md5($normalizedPath));

        return $this->cachedFetch(
            $cacheKey,
            function () use ($normalizedPath, $startingNodeId, $path) {
                $segments = explode('/', $normalizedPath);

                $elements = [];
                foreach ($segments as $segment) {
                    $elements[] = [
                        'targetName' => self::parseQualifiedName($segment),
                    ];
                }

                $results = $this->translateBrowsePaths([
                    [
                        'startingNodeId' => $startingNodeId,
                        'relativePath' => $elements,
                    ],
                ]);

                if (empty($results)) {
                    throw new ServiceException("TranslateBrowsePaths returned no results for path: /{$normalizedPath}", StatusCode::BadNoData);
                }

                $result = $results[0];

                if (StatusCode::isBad($result->statusCode)) {
                    throw new ServiceException(
                        sprintf("Failed to resolve path '/%s': %s", $normalizedPath, StatusCode::getName($result->statusCode)),
                        $result->statusCode,
                    );
                }

                if (empty($result->targets)) {
                    throw new ServiceException("No targets found for path: /{$normalizedPath}", StatusCode::BadNoData);
                }

                return $result->targets[0]->targetId;
            },
            $useCache,
        );
    }

    private static function parseQualifiedName(string $segment): QualifiedName
    {
        if (str_contains($segment, ':')) {
            $parts = explode(':', $segment, 2);
            if (ctype_digit($parts[0])) {
                return new QualifiedName((int)$parts[0], $parts[1]);
            }
        }

        return new QualifiedName(0, $segment);
    }
}
