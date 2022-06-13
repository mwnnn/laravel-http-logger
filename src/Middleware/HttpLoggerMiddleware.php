<?php

namespace Mamitech\LaravelHttpLogger\Middleware;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class HttpLoggerMiddleware
{
    public function handle($request, \Closure $next)
    {
        $this->request = $request;
        $startTime = microtime(true);

        $this->response = $next($request);

        $this->duration = microtime(true) - $startTime; // this is in second
        $this->duration = (int) ($this->duration * 1000); // to make it in millisecond

        $this->logHttpRequest();

        return $this->response;
    }

    protected function logHttpRequest()
    {
        if (!config('http-logger.enabled')) {
            return;
        }

        try {
            $logData = $this->getLogData();
            $jsoned = json_encode($logData, JSON_PARTIAL_OUTPUT_ON_ERROR);
            if (!$jsoned) {
                return;
            }

            Log::channel(config('http-logger.log_channel'))->info($jsoned);
        } catch (\Throwable $e) { // For PHP 7
            Log::channel('errorlog')->error($e);
        }
    }

    protected function getLogData()
    {
        $request = $this->request;
        $response = $this->response;
        # only record json typed response body
        $responseContent = json_decode($response->getContent(), true) ?? '[FILTERED] non-json response';

        $requestBody = $this->filterLongData($this->getRequestBody());
        $responseBody = json_encode($this->filterResponseBody($responseContent));

        // Check:
        // @link https://www.elastic.co/guide/en/ecs/current/ecs-url.html
        // @link https://www.elastic.co/guide/en/ecs/current/ecs-http.html
        // @link https://www.elastic.co/guide/en/ecs/current/ecs-user.html
        $data = [
            'url' => [
                'domain' => $request->root(),
                'full' => $request->fullUrl(),
                'path' => $request->path(),
            ],
            'http' => [
                'request' => [
                    'method' => $request->method(),
                    'referrer' => $request->headers->get('referrer'),
                    'headers' => $request->header(),
                    'body' => [
                        'content' => $requestBody,
                    ],
                    'params' => $this->request->all(),
                ],

                'response' => [
                    'status_code' => $response->getStatusCode(),
                    'headers' => $response->headers->allPreserveCaseWithoutCookies(),
                    'body' => [
                        'content' => $responseBody,
                    ],
                ],
            ],
            'user' => [
                'id' => Auth::id(),
            ],
            'event' => [
                'duration' => $this->duration,
                'action' => $request->route()->getActionName(),
            ],
        ];

        return $this->filterHttpLogData($data);
    }

    protected function filterHttpLogData($data)
    {
        if (isset($data['http']['request']['headers']['authorization'])) {
            $data['http']['request']['headers']['authorization'] = '[FILTERED]';
        }

        if (isset($data['http']['request']['headers']['cookie'])) {
            $data['http']['request']['headers']['cookie'] = '[FILTERED]';
        }

        return $data;
    }

    protected function getRequestBody()
    {
        $content = $this->request->getContent();
        return $this->filterLongData($content);
    }

    protected function filterLongData($data)
    {
        $truncateStr = '[TRUNCATED] : data is too long';

        if (is_array($data)) {
            foreach ($data as $k => $v) {
                if (mb_strlen($v) > 512) {
                    $data[$k] = $truncateStr;
                }
            }
        }

        if (
            mb_strlen($data) > 512
        ) {
            return $truncateStr;
        }

        return $data;
    }

    protected function filterResponseBody($responseBody)
    {
        $responseBody = $this->filterLongData($responseBody);

        return $responseBody;
    }
}
