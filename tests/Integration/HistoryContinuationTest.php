<?php

declare(strict_types=1);

use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Module\History\HistoryReadService;
use PhpOpcua\Client\Protocol\ServiceTypeId;
use PhpOpcua\Client\Tests\Integration\Helpers\TestHelper;
use PhpOpcua\Client\Transport\TcpTransport;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;
use PhpOpcua\Client\Types\Variant;

if (! class_exists('HistoryRecordingTransport')) {
    class HistoryRecordingTransport extends TcpTransport
    {
        /** @var list<string> */
        public array $historyReadRequests = [];

        public function send(string $data): void
        {
            $historyReadTypeId = "\x01\x00" . pack('v', ServiceTypeId::HISTORY_READ_REQUEST);
            if (str_starts_with($data, 'MSG') && str_contains(substr($data, 0, 40), $historyReadTypeId)) {
                $this->historyReadRequests[] = $data;
            }
            parent::send($data);
        }
    }
}

/**
 * The open62541-historizing server returns at most 1024 values per HistoryRead
 * response (maxHistoryDataResponseSize) and a continuation point for the rest.
 */
describe('HistoryRead continuation points (integration vs open62541-historizing)', function () {

    it('pages through the server continuation points and honours numValuesPerNode', function () {
        $client = null;
        try {
            $transport = new HistoryRecordingTransport();
            $client = (new ClientBuilder())->setTransport($transport)->connect(TestHelper::ENDPOINT_HISTORIZING);
            $node = NodeId::parse('ns=2;s=Historizing.Counter');

            $start = (new DateTimeImmutable('2025-01-01T00:00:00Z'))->modify('+' . random_int(0, 500000) . ' minutes');
            $values = [];
            for ($i = 0; $i < 1500; $i++) {
                $values[] = new DataValue(new Variant(BuiltinType::Double, (float) $i), StatusCode::Good, $start->modify("+{$i} seconds"));
            }
            foreach (array_chunk($values, 500) as $chunk) {
                foreach ($client->historyInsertData($node, $chunk) as $status) {
                    expect(StatusCode::isGood($status))->toBeTrue();
                }
            }

            $from = $start->modify('-1 second');
            $to = $start->modify('+1500 seconds');

            $session = (fn () => $this->session)->call($client);
            $service = new HistoryReadService($session);
            $client->send($service->encodeHistoryReadRawRequest($client->nextRequestId(), $client->getAuthToken(), $node, $from, $to));
            $firstPage = $service->decodeHistoryReadResponseWithContinuation($client->createDecoder($client->unwrapResponse($client->receive())));
            expect($firstPage['values'])->toHaveCount(1024);
            expect($firstPage['continuationPoint'])->not->toBeNull();
            $client->send($service->encodeHistoryReadRawRequest($client->nextRequestId(), $client->getAuthToken(), $node, $from, $to, 0, false, $firstPage['continuationPoint'], true));
            $service->decodeHistoryReadResponseWithContinuation($client->createDecoder($client->unwrapResponse($client->receive())));

            $read = function (int $numValuesPerNode) use ($client, $transport, $node, $from, $to): array {
                $transport->historyReadRequests = [];

                return array_map(fn (DataValue $dataValue) => $dataValue->getValue(), $client->historyReadRaw($node, $from, $to, $numValuesPerNode));
            };

            expect($read(0))->toBe(array_map('floatval', range(0, 1499)));
            expect($transport->historyReadRequests)->toHaveCount(2);
            expect(str_contains($transport->historyReadRequests[1], pack('V', strlen($firstPage['continuationPoint']))))->toBeTrue();

            expect($read(1100))->toBe(array_map('floatval', range(0, 1099)));
            expect($transport->historyReadRequests)->toHaveCount(2);

            expect($read(5))->toBe([0.0, 1.0, 2.0, 3.0, 4.0]);
            expect($transport->historyReadRequests)->toHaveCount(2);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');
});
