<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Client;

use Gianfriaur\OpcuaPhpClient\Encoding\BinaryDecoder;
use Gianfriaur\OpcuaPhpClient\Exception\ServiceException;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\QualifiedName;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

trait ManagesTranslateBrowsePathTrait
{
    /**
     * @param array<array{startingNodeId: NodeId, relativePath: array<array{referenceTypeId?: NodeId, isInverse?: bool, includeSubtypes?: bool, targetName: QualifiedName}>}> $browsePaths
     * @return array<array{statusCode: int, targets: array<array{targetId: NodeId, remainingPathIndex: int}>}>
     */
    public function translateBrowsePaths(array $browsePaths): array
    {
        return $this->executeWithRetry(function () use ($browsePaths) {
            $this->ensureConnected();

            $requestId = $this->nextRequestId();
            $request = $this->translateBrowsePathService->encodeTranslateRequest($requestId, $browsePaths, $this->authenticationToken);
            $this->transport->send($request);

            $response = $this->transport->receive();
            $responseBody = $this->unwrapResponse($response);
            $decoder = new BinaryDecoder($responseBody);

            return $this->translateBrowsePathService->decodeTranslateResponse($decoder);
        });
    }

    /**
     * @param string $path
     * @param ?NodeId $startingNodeId
     * @return NodeId
     * @throws ServiceException
     */
    public function resolveNodeId(string $path, ?NodeId $startingNodeId = null): NodeId
    {
        $startingNodeId ??= NodeId::numeric(0, 84); // Root

        $path = trim($path, '/');
        $segments = explode('/', $path);

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
            throw new ServiceException("TranslateBrowsePaths returned no results for path: /{$path}", StatusCode::BadNoData);
        }

        $result = $results[0];

        if (StatusCode::isBad($result['statusCode'])) {
            throw new ServiceException(
                sprintf("Failed to resolve path '/%s': %s", $path, StatusCode::getName($result['statusCode'])),
                $result['statusCode'],
            );
        }

        if (empty($result['targets'])) {
            throw new ServiceException("No targets found for path: /{$path}", StatusCode::BadNoData);
        }

        return $result['targets'][0]['targetId'];
    }

    private static function parseQualifiedName(string $segment): QualifiedName
    {
        if (str_contains($segment, ':')) {
            $parts = explode(':', $segment, 2);
            if (ctype_digit($parts[0])) {
                return new QualifiedName((int) $parts[0], $parts[1]);
            }
        }

        return new QualifiedName(0, $segment);
    }
}
