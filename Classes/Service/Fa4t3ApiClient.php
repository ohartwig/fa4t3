<?php

declare(strict_types=1);

namespace Moselwal\FA4T3\Service;

use Moselwal\FA4T3\Domain\Model\AggregationRequest;
use Moselwal\FA4T3\Domain\Model\AggregationResult;
use Moselwal\FA4T3\Domain\Model\ConnectionResult;
use Moselwal\FA4T3\Domain\Model\CurrentVisitors;
use Moselwal\FA4T3\Domain\Model\EventAggregationResult;
use Moselwal\FA4T3\Domain\Model\Fa4t3Event;
use Moselwal\FA4T3\Exception\Fa4t3ApiException;
use Moselwal\FA4T3\Exception\Fa4t3AuthenticationException;
use Moselwal\FA4T3\Exception\Fa4t3RateLimitException;
use TYPO3\CMS\Core\Http\RequestFactory;

final readonly class Fa4t3ApiClient implements Fa4t3ApiClientInterface
{
    private const BASE_URL = 'https://api.usefathom.com';
    private const TIMEOUT = 10;

    public function __construct(
        private RequestFactory $requestFactory,
    ) {}

    public function testConnection(string $apiKey): ConnectionResult
    {
        try {
            $this->request('GET', '/v1/account', $apiKey);
            return new ConnectionResult(true, 'Connection successful');
        } catch (Fa4t3AuthenticationException) {
            return new ConnectionResult(false, 'Invalid API key');
        } catch (Fa4t3ApiException $e) {
            return new ConnectionResult(false, $e->getMessage());
        }
    }

    public function getAggregation(string $siteId, AggregationRequest $request, string $apiKey): AggregationResult
    {
        $params = [
            'entity' => 'pageview',
            'entity_id' => $siteId,
            'aggregates' => 'visits,uniques,pageviews,avg_duration,bounce_rate',
            'date_from' => $request->getDateFrom()->format('Y-m-d H:i:s'),
            'date_to' => $request->getDateTo()->format('Y-m-d H:i:s'),
            'timezone' => $request->getTimezone(),
        ];

        if ($request->getDateGrouping() !== null) {
            $params['date_grouping'] = $request->getDateGrouping();
        }
        if ($request->getFieldGrouping() !== null) {
            $params['field_grouping'] = $request->getFieldGrouping();
        }
        if ($request->getFilters() !== null) {
            $params['filters'] = json_encode($request->getFilters());
        }
        if ($request->getSortBy() !== null) {
            $params['sort_by'] = $request->getSortBy();
        }
        if ($request->getLimit() !== null) {
            $params['limit'] = $request->getLimit();
        }

        $data = $this->request('GET', '/v1/aggregations', $apiKey, $params);

        if ($request->getDateGrouping() !== null || $request->getFieldGrouping() !== null) {
            return new AggregationResult(
                visits: $this->sumField($data, 'visits'),
                uniques: $this->sumField($data, 'uniques'),
                pageviews: $this->sumField($data, 'pageviews'),
                avgDuration: $this->avgField($data, 'avg_duration'),
                bounceRate: $this->avgField($data, 'bounce_rate'),
                dateFrom: $request->getDateFrom(),
                dateTo: $request->getDateTo(),
                groupedData: $data,
            );
        }

        $row = is_array($data) && isset($data[0]) ? $data[0] : ($data ?? []);

        return new AggregationResult(
            (int)($row['visits'] ?? 0),
            (int)($row['uniques'] ?? 0),
            (int)($row['pageviews'] ?? 0),
            (float)($row['avg_duration'] ?? 0),
            (float)($row['bounce_rate'] ?? 0),
            $request->getDateFrom(),
            $request->getDateTo()
        );
    }

    public function getEventAggregation(
        string $siteId,
        string $eventName,
        AggregationRequest $request,
        string $apiKey
    ): EventAggregationResult {
        $params = [
            'entity' => 'event',
            'entity_id' => $siteId,
            'aggregates' => 'conversions,unique_conversions,value',
            'date_from' => $request->getDateFrom()->format('Y-m-d H:i:s'),
            'date_to' => $request->getDateTo()->format('Y-m-d H:i:s'),
            'timezone' => $request->getTimezone(),
        ];

        // For events, the API uses site_id + filters with event name
        $params['filters'] = json_encode([
            ['property' => 'name', 'operator' => 'is', 'value' => $eventName],
        ]);

        $data = $this->request('GET', '/v1/aggregations', $apiKey, $params);
        $row = is_array($data) && isset($data[0]) ? $data[0] : ($data ?? []);

        return new EventAggregationResult(
            $eventName,
            (int)($row['conversions'] ?? 0),
            (int)($row['unique_conversions'] ?? 0),
            (int)($row['value'] ?? 0)
        );
    }

    public function getCurrentVisitors(string $siteId, string $apiKey, bool $detailed = false): CurrentVisitors
    {
        $params = ['site_id' => $siteId];
        if ($detailed) {
            $params['detailed'] = 'true';
        }

        $data = $this->request('GET', '/v1/current_visitors', $apiKey, $params);

        return new CurrentVisitors(
            (int)($data['total'] ?? 0),
            $data['content'] ?? null,
            $data['referrers'] ?? null
        );
    }

    /**
     * @return Fa4t3Event[]
     */
    public function getEvents(string $siteId, string $apiKey): array
    {
        $data = $this->request('GET', '/v1/sites/' . $siteId . '/events', $apiKey, ['limit' => 100]);
        $events = [];

        foreach (($data['data'] ?? []) as $item) {
            $events[] = new Fa4t3Event(
                (string)$item['id'],
                (string)$item['name'],
                $siteId,
                new \DateTimeImmutable($item['created_at'] ?? 'now')
            );
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $queryParams
     * @throws Fa4t3ApiException
     * @throws Fa4t3AuthenticationException
     * @throws Fa4t3RateLimitException
     */
    private function request(string $method, string $path, string $apiKey, array $queryParams = []): mixed
    {
        $url = self::BASE_URL . $path;
        if ($queryParams !== []) {
            $url .= '?' . http_build_query($queryParams);
        }

        try {
            $response = $this->requestFactory->request($url, $method, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Accept' => 'application/json',
                ],
                'timeout' => self::TIMEOUT,
            ]);
        } catch (\Exception $e) {
            throw new Fa4t3ApiException('Fathom API request failed: ' . $e->getMessage(), 0, $e);
        }

        $statusCode = $response->getStatusCode();
        $body = (string)$response->getBody();

        if ($statusCode === 401) {
            throw new Fa4t3AuthenticationException('Invalid or expired API key');
        }

        if ($statusCode === 429) {
            throw new Fa4t3RateLimitException('Rate limit exceeded');
        }

        if ($statusCode >= 400) {
            throw new Fa4t3ApiException('Fathom API error (HTTP ' . $statusCode . ')', $statusCode);
        }

        $decoded = json_decode($body, true);
        if ($decoded === null && $body !== '' && $body !== 'null') {
            throw new Fa4t3ApiException('Invalid JSON response from Fathom API');
        }

        return $decoded;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function sumField(array $rows, string $field): int
    {
        $sum = 0;
        foreach ($rows as $row) {
            $sum += (int)($row[$field] ?? 0);
        }
        return $sum;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function avgField(array $rows, string $field): float
    {
        if ($rows === []) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($rows as $row) {
            $sum += (float)($row[$field] ?? 0);
        }
        return $sum / count($rows);
    }
}
