<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Support;

use GES\Botlock\Http\Request;

final class Requests
{
    /**
     * @param array<string,string> $headers
     * @param array<string,string> $query
     * @param array<string,string> $server
     */
    public static function make(
        string $method = 'GET',
        string $url = 'https://example.test/',
        array  $headers = [],
        array  $query = [],
        array  $server = [],
        array  $cookies = [],
        string $body = '',
        array  $post = [],
    ): Request {
        return new Request(
            method: $method,
            requestUrl: $url,
            secure: \str_starts_with($url, 'https://'),
            server: $server + ['REMOTE_ADDR' => '203.0.113.10'],
            rawHeaders: $headers + ['User-Agent' => 'TestAgent/1.0'],
            queryParams: $query,
            cookies: $cookies,
            body: $body,
            postParams: $post,
        );
    }
}
