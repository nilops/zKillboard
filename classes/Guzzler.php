<?php

class Guzzler
{
    private $curl;
    private $handler;
    private $client;
    private $concurrent = 0;
    private $maxConcurrent;
    private $usleep;

    public function __construct($maxConcurrent = 10, $usleep = 100000)
    {
        $this->curl = new \GuzzleHttp\Handler\CurlMultiHandler();
        $this->handler = \GuzzleHttp\HandlerStack::create($this->curl);
        $this->client = new \GuzzleHttp\Client(['curl' => [CURLOPT_FRESH_CONNECT => false], 'connect_timeout' => 10, 'timeout' => 60, 'handler' => $this->handler, 'headers' => ['User-Agent' => 'zkillboard.com']]);
        $this->maxConcurrent = max($maxConcurrent, 1);
        $this->usleep = max(0, min(1000000, (int) $usleep));
    }

    public function tick()
    {
        $ms = microtime();
        do {
            $this->curl->tick();
        } while ($this->concurrent >= $this->maxConcurrent);
        return max(0, microtime() - $ms);
    }

    public function finish()
    {
        $ms = microtime();
        $this->curl->execute();
        return max(0, microtime() - $ms);
    }

    public function count()
    {
        return $this->concurrent;
    }

    public function inc()
    {
        $this->concurrent++;
    }

    public function dec()
    {
        $this->concurrent--;
    }

    public function call($uri, $fulfilled, $rejected, $params = [], $setup = [], $callType = 'GET', $body = null)
    {
        $this->verifyCallable($fulfilled);
        $this->verifyCallable($rejected);
        $params['uri'] = $uri;
        $params['callType'] = $callType;

        $statusType = self::getType($uri);

        $guzzler = $this;
        $request = new \GuzzleHttp\Psr7\Request($callType, $uri, $setup, $body);
        $this->client->sendAsync($request)->then(
            function($response) use (&$guzzler, $fulfilled, $rejected, &$params, $statusType) {
                Status::addStatus($statusType, true);
                $guzzler->dec();
                $content = (string) $response->getBody();
                $fulfilled($guzzler, $params, $content);
            },
            function($connectionException) use (&$guzzler, &$rejected, &$params, $statusType) {
                Status::addStatus($statusType, false);
                $guzzler->dec();
                $rejected($guzzler, $params, $connectionException);
            });
        $this->inc();
        $ms = $this->tick();
        $sleep = min(1000000, max(0, $this->usleep - $ms));
        usleep($sleep);
    }

    public function verifyCallable($callable)
    {
        if (!is_callable($callable)) {
            throw new InvalidArgumentException(print_r($callable, true) . " is not a callable function");
        }
    }

    protected function getType($uri)
    {
        if (strpos($uri, 'esi.tech') !== false) return 'esi';
        if (strpos($uri, 'crest-tq') !== false) return 'crest';
        if (strpos($uri, 'login') !== false) return 'sso';
        if (strpos($uri, 'api.eve') !== false) return 'xml';
        Log::log("Unknown type for $uri");
        return 'unknown';
    }
}
