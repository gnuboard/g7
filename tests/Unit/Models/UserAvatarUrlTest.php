<?php

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 레거시 아바타 주소가 요청 Host 를 따르지 않는지 검증합니다.
 *
 * 첨부 관계는 미리 비워 두어(setRelation) DB 를 읽지 않는다.
 */
class UserAvatarUrlTest extends TestCase
{
    /** 테스트 사이트 주소 */
    private const SITE = 'https://shop.example.test';

    /**
     * 사이트 주소를 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => self::SITE]);
    }

    /**
     * 신뢰 프록시 상태를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        parent::tearDown();
    }

    /**
     * 위조 Host 변종.
     *
     * 사이트 주소 Host(canonical) 행이 함께 들어 있다 — 정상 Host 에서도 같은 값을 내는지 본다.
     *
     * @scenario host_header=canonical, sink=avatar_url
     *
     * @effects legacy_avatar_url_uses_site_origin
     *
     * @return array<string, array{0: string, 1: array<string, string>}> 요청 주소와 추가 서버 변수
     */
    public static function forgedHosts(): array
    {
        return [
            'canonical' => ['https://shop.example.test/', []],
            'external' => ['https://attacker.example/', []],
            'subdomain' => ['https://attacker.shop.example.test/', []],
            'port' => ['https://shop.example.test:6666/', []],
            'forwarded' => ['http://10.0.0.5/', ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_HOST' => 'attacker.example', 'HTTP_X_FORWARDED_PROTO' => 'https']],
        ];
    }

    /**
     * 레거시 avatar 필드 주소는 사이트 주소를 쓴다.
     *
     * @scenario host_header=forged, sink=avatar_url
     *
     * @effects legacy_avatar_url_uses_site_origin
     *
     * @param  string  $url  요청 주소
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_legacy_avatar_url_ignores_forged_host(string $url, array $server): void
    {
        if ($server !== []) {
            Request::setTrustedProxies(['10.0.0.5'], Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO);
        }
        $request = Request::create($url, 'GET', [], [], [], $server);
        app()->instance('request', $request);
        app('url')->setRequest($request);

        $user = new User;
        $user->avatar = 'a1.png';
        $user->setRelation('avatarAttachment', null);

        $this->assertSame(self::SITE.'/storage/attachments/avatars/a1.png', $user->getAvatarUrl());
    }
}
