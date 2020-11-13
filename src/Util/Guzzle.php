<?php

namespace Hyperf\Nacos\Util;

use GuzzleHttp\Client;
use Hyperf\Guzzle\RetryMiddleware;
use Hyperf\Guzzle\HandlerStackFactory;
use Hyperf\Logger\LoggerFactory;

class Guzzle
{
    /**
     * @param array $config
     *
     * @return Client
     */
    public static function create(array $config = [])
    {
        $factory = new HandlerStackFactory();
        $handlerStack = $factory->create([], ['retry' => [RetryMiddleware::class, [3, 10]]]);
        return make(Client::class, [
            'config' => array_merge([
                'handler' => $handlerStack,
                'verify' => false,
            ], $config),
        ]);
    }

    public static function get($url, $query = [], $header = [])
    {
        return self::request('get', $url, $query, $header);
    }

    public static function post($url, $params = [], $header = [])
    {
        return self::request('post', $url, $params, $header);
    }

    public static function request($method, $api, $params = [], $headers = [])
    {
        $client = self::create([
            'timeout' => $headers['timeout'] ?? 10.0,
        ]);
        $method = strtoupper($method);
        $options = [
            'http_errors' => false
        ];
        $headers['charset'] = $headers['charset'] ?? 'UTF-8';

        $options['headers'] = $headers;
        if ($method == 'GET' && $params) {
            $options['query'] = $params;
        }
        if ($method == 'POST') {
            $options['headers']['Content-Type'] = $headers['Content-Type'] ?? 'application/json';
            if ($options['headers']['Content-Type'] == 'application/json' && $params) {
                $options['body'] = \GuzzleHttp\json_encode($params ? $params : (object)[]);
            }
            if ($options['headers']['Content-Type'] == 'application/x-www-form-urlencoded' && $params) {
                $options['form_params'] = $params;
            }
        }
        $logger = container(LoggerFactory::class);
        try {
            $request = $client->request($method, $api, $options);
            $code = $request->getStatusCode();
            $content = $request->getBody()->getContents();
            if (is_json_str($content)) {
                $content = json_decode($content, true);
            }
            $logger->get('api_request')->debug($api, [
                'method' => $method,
                'code' => $code,
                'options' => $options,
                'content' => $content,
            ]);

            return $content;
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            $logger->get('api_request')->error($api, [
                'method' => $method,
                'options' => $options,
                'exception' => (string)$e,
            ]);

            return false;
        }
    }
}
