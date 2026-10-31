<?php

namespace Tests\Concerns;

use Illuminate\Http\Request;

/**
 * 위조 Host 변종으로 요청을 만드는 테스트 도구.
 *
 * 변종은 넷이다 — 외부 도메인, 사이트 하위 도메인, 포트만 바꾼 사이트 Host, 신뢰 프록시가 전달한
 * `X-Forwarded-Host`. 사이트 주소는 `https://shop.example.test` 로 고정한다(`pinForgedHostSite()`).
 * 신뢰 프록시 상태는 정적이라 `tearDown()` 에서 `resetForgedHostProxies()` 로 되돌린다.
 */
trait ForgesRequestHost
{
    /**
     * 테스트 사이트 주소.
     *
     * @return string 사이트 주소
     */
    protected static function forgedHostSite(): string
    {
        return 'https://shop.example.test';
    }

    /**
     * 위조 Host 변종(사이트 주소 행 포함).
     *
     * @return array<string, array{0: string, 1: array<string, string>}> 변종별 요청 루트와 추가 서버 변수
     */
    public static function forgedHosts(): array
    {
        return [
            'canonical' => ['https://shop.example.test', []],
            'external' => ['https://attacker.example', []],
            'subdomain' => ['https://attacker.shop.example.test', []],
            'port' => ['https://shop.example.test:6666', []],
            'forwarded' => ['http://10.0.0.5', [
                'REMOTE_ADDR' => '10.0.0.5',
                'HTTP_X_FORWARDED_HOST' => 'attacker.example',
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ]],
        ];
    }

    /**
     * 사이트 주소를 테스트 사이트로 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function pinForgedHostSite(): void
    {
        config([
            'app.url' => self::forgedHostSite(),
            'g7_settings.core.general.site_url' => self::forgedHostSite(),
        ]);
    }

    /**
     * 변종의 서버 변수에 전달 헤더가 있으면 직전 호출자를 신뢰 프록시로 둡니다.
     *
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    protected function trustProxyForForgedHost(array $server): void
    {
        if (isset($server['HTTP_X_FORWARDED_HOST'])) {
            Request::setTrustedProxies(['10.0.0.5'], Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO);
            config(['trustedproxy.proxies' => '10.0.0.5']);
        }
    }

    /**
     * URL 생성기·컨테이너에 위조 요청을 심습니다(HTTP 요청 없이 메서드를 직접 부를 때).
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @param  string  $path  요청 경로
     * @return Request 심은 요청
     */
    protected function bindForgedRequest(string $root, array $server, string $path = '/'): Request
    {
        $this->trustProxyForForgedHost($server);
        $request = Request::create($root.$path, 'GET', [], [], [], $server);
        app()->instance('request', $request);
        app('url')->setRequest($request);

        return $request;
    }

    /**
     * 주소가 사이트 주소로 시작하는지 단언합니다.
     *
     * @param  string|null  $url  검사할 주소
     * @param  string  $path  사이트 주소 뒤에 와야 하는 경로 접두
     * @return void 반환값 없음
     */
    protected function assertOnForgedHostSite(?string $url, string $path = '/'): void
    {
        $this->assertStringStartsWith(self::forgedHostSite().$path, (string) $url);
    }

    /**
     * 신뢰 프록시 정적 상태를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function resetForgedHostProxies(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }
}
