<?php


namespace App\Component;

use App\Exception\WebRequestFailedException;

class WebClient
{
    public $sessionId = '';
    public $referrer = 'https://booking.uz.gov.ua/';

    protected function headers()
    {
        return [
//            "Accept: */*",
//            "Accept-Encoding: gzip, deflate, br",
//            "Accept-Language: uk,ru-RU;q=0.9,ru;q=0.8,en-US;q=0.7,en;q=0.6",
//            "Cache-Control: no-cache",
//            "cache-version: 755",
//            "Connection: close",
//            "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
//            "Cookie: _gv_lang=ru; HTTPSERVERID=server2; _gv_sessid=".$this->sessionId."",
//            "Host: booking.uz.gov.ua",
//            "Origin: https://booking.uz.gov.ua",
//            "Pragma: no-cache",
//            "Referer: ".$this->referrer."",
//            "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/75.0.3770.100 Safari/537.36",
//            "X-Requested-With: XMLHttpRequest",

            'Accept: application/json, text/javascript, */*; q=0.01',
            'Accept-Language: en-US,en;q=0.9,ru;q=0.8',
            'Connection: close',
            'Cookie: __uzma=1b5b235a-43e1-413c-80f3-f7d5dbe63239; __uzmb=1684604731; __uzme=0587; _gv_lang=uk; _gv_sessid=' . $this->sessionId. '; HTTPSERVERID=server3; cookiesession1=678B286E938C6CE221984A8A2CD47F54; _ga=GA1.3.504056239.1684604732; _gid=GA1.3.1064600465.1684604732; __uzmc=924621917906; __uzmd=1684604747',
            'Referer: ' . $this->referrer,
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-origin',
            'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/113.0.0.0 Safari/537.36',
            'X-Requested-With: XMLHttpRequest',
            'cache-version: 761',
            'sec-ch-ua: "Google Chrome";v="113", "Chromium";v="113", "Not-A.Brand";v="24"',
            'sec-ch-ua-mobile: ?0',
            'sec-ch-ua-platform: "Linux"',

        ];
    }

    public function get(string $url, array $headers = null): string
    {
        $opts = [
            "http" => [
                "method" => "GET",
                "header" => implode("\r\n", $headers ?: $this->headers()),
            ],
            "ssl" => [
                "verify_peer" => false,
                "verify_peer_name" => false,
            ],
        ];
        $context = stream_context_create($opts);

        $response = file_get_contents($url, false, $context);

        if (!$this->checkIfRequestSucceed($http_response_header)) {
            throw new WebRequestFailedException('unexpected response status: '.$response);
        }

        foreach ($http_response_header as $headerLine) {
            if (preg_match('~_gv_sessid=([^;]+);~', $headerLine, $match)) {
                $this->sessionId = $match[1];
            }
        }

        $this->referrer = $url;
        return $response;
    }

    public function post(string $url, $data, array $headers = null, bool $raw = false): string
    {
        $opts = [
            "http" => [
                "method" => "POST",
                "header" => implode("\r\n", $headers ?: $this->headers()),
                "content" => $raw ? $data : http_build_query($data)
            ],
            "ssl" => [
                "verify_peer" => false,
                "verify_peer_name" => false,
            ],
        ];
        $context = stream_context_create($opts);

        $response = file_get_contents($url, false, $context);

        if (!$this->checkIfRequestSucceed($http_response_header)) {
            throw new WebRequestFailedException('unexpected response status: '.$response);
        }

        return $response;

    }

    protected function checkIfRequestSucceed(array $responseHeaders): bool
    {
        $statusLine = $responseHeaders[0];

        preg_match('~HTTP[^\s]*\s(\d{3})~', $statusLine, $match);

        $status = $match[1];

        return ($status === "200" || $status === "302");
    }
}
