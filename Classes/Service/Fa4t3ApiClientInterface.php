<?php

declare(strict_types=1);

namespace Moselwal\FA4T3\Service;

use Moselwal\FA4T3\Domain\Model\AggregationRequest;
use Moselwal\FA4T3\Domain\Model\AggregationResult;
use Moselwal\FA4T3\Domain\Model\ConnectionResult;
use Moselwal\FA4T3\Domain\Model\CurrentVisitors;
use Moselwal\FA4T3\Domain\Model\EventAggregationResult;
use Moselwal\FA4T3\Domain\Model\Fa4t3Event;

/**
 * Everything AnalyticsService needs from the Fathom API.
 *
 * Fa4t3ApiClient is final and every one of these methods reaches api.usefathom.com
 * over the network. A test for the caching and error handling around it therefore
 * has no way in: it cannot double a final class, and it must not make the call.
 *
 * The six tests covering exactly that — cache hits, cache flushing, and what the
 * service returns when the API fails — had been erroring out on this, unnoticed,
 * because test:phpunit:unit carried allow_failure: true.
 */
interface Fa4t3ApiClientInterface
{
    public function testConnection(string $apiKey): ConnectionResult;

    public function getAggregation(string $siteId, AggregationRequest $request, string $apiKey): AggregationResult;

    public function getEventAggregation(
        string $siteId,
        string $eventName,
        AggregationRequest $request,
        string $apiKey
    ): EventAggregationResult;

    public function getCurrentVisitors(string $siteId, string $apiKey, bool $detailed = false): CurrentVisitors;

    /**
     * @return Fa4t3Event[]
     */
    public function getEvents(string $siteId, string $apiKey): array;
}
