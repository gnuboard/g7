<?php

namespace Tests\Unit\Models;

use App\Extension\Helpers\StorageLinkHelper;
use App\Extension\HookManager;
use App\Models\User;
use App\Support\AssetUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 레거시 `avatar` 필드의 URL 해석 계약 테스트 (`User::getAvatarUrl`).
 *
 * 이 분기는 저장소 전체에 테스트가 한 건도 없었다. 그 사이 구현이 `url('storage/…')`
 * 손조립에서 스토리지 드라이버 위임(`CoreStorageDriver('public')->url()`)으로 바뀌었는데,
 * 손조립은 운영자 설정(`disks.public.url`)과 확장 훅(`core.storage.filter_url`)을 둘 다
 * 우회해 그 사이트에서 **레거시 아바타만** 다른 곳을 가리켰다. 위임이 그 네 상황을
 * 실제로 따르는지 여기서 고정한다 (제품 결정 2026-08-26 — 분기는 유지).
 *
 * `avatar` 는 현재 users 테이블 컬럼이 아니다(구 설치본에서 넘어온 값이 메모리에 실릴 때만
 * 이 분기가 산다). 그래서 모델 속성으로 직접 세우고 관계는 비운다 — 저장하지 않은 모델의
 * `avatarAttachment` 는 부모 키가 없어 조회 없이 null 이다.
 *
 * 레거시 아바타 주소는 요청 Host 를 따르지 않는다 — 위조 Host 변종에서도 사이트 주소를 쓴다.
 * 첨부 관계는 미리 비워 두어(setRelation) DB 를 읽지 않는다.
 *
 * @scenario storage_link=usable, disk_url=default, hook=unregistered
 */
class UserAvatarUrlTest extends TestCase
{
    /** 테스트 사이트 주소 */
    private const SITE = 'https://shop.example.test';

    /**
     * 사이트 주소와 public 디스크 주소를 고정합니다.
     *
     * public 디스크의 기본 주소는 부팅 시 `APP_URL` 에서 파생된다(`{APP_URL}/storage`). 실행 중에
     * `app.url` 만 바꾸면 두 값이 갈리므로 같은 사이트 주소로 함께 고정한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => self::SITE,
            'filesystems.disks.public.url' => self::SITE.'/storage',
        ]);
    }

    /**
     * 훅·자산 URL 모드·신뢰 프록시 상태를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::resetAll();
        AssetUrl::forceMode(null);
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        parent::tearDown();
    }

    /**
     * 첨부 없이 레거시 avatar 파일명만 가진 사용자를 만든다.
     *
     * @return User avatar 값만 채운 사용자
     */
    private function legacyAvatarUser(): User
    {
        $user = new User;
        $user->avatar = 'me.png';

        return $user;
    }

    /**
     * ① 링크 정상 — 종전대로 정적 `/storage/...` 주소다.
     *
     * @scenario storage_link=usable, disk_url=default, hook=unregistered
     *
     * @effects legacy_avatar_url_stays_on_static_storage_when_link_usable
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function it_returns_the_static_storage_url_when_the_link_is_usable(): void
    {
        StorageLinkHelper::forcePublicStorageLinkUsable(true);

        // 기대값을 프로덕션이 읽는 **같은 설정**에서 조립하면 값 축은 언제나 참이 되고
        // 경로 접미사만 고정된다. 설정을 알려진 값으로 고정하고 결과를 리터럴로 적는다.
        config(['filesystems.disks.public.url' => 'https://example.test/storage']);

        $this->assertSame(
            'https://example.test/storage/attachments/avatars/me.png',
            $this->legacyAvatarUser()->getAvatarUrl()
        );
    }

    /**
     * ② 링크 불가 — 읽기 전용 서빙 라우트로 전환된다 (B-3).
     *
     * 손조립 시절에는 이 상황에서도 `/storage/...` 를 내보내 아바타만 404 였다.
     *
     * @effects public_storage_url_switches_to_route_when_link_missing
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function it_switches_to_the_serving_route_when_the_link_is_unusable(): void
    {
        StorageLinkHelper::forcePublicStorageLinkUsable(false);
        AssetUrl::forceMode(AssetUrl::MODE_EXTENSION);

        $url = (string) $this->legacyAvatarUser()->getAvatarUrl();

        $this->assertSame(
            '/api/system/public-storage/attachments/avatars/me.png',
            parse_url($url, PHP_URL_PATH),
            '링크가 없으면 대체 서빙 라우트를 가리켜야 한다'
        );
    }

    /**
     * ③ 운영자가 public 디스크를 다른 위치(CDN)로 지정 — 그 주소를 그대로 쓴다.
     *
     * 링크 부재를 이유로 로컬 라우트로 끌어오면 그 사이트의 공개 자산이 통째로 어긋난다.
     *
     * @effects public_disk_url_keeps_operator_configured_location_without_a_link
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function it_honours_an_operator_configured_public_disk_url(): void
    {
        StorageLinkHelper::forcePublicStorageLinkUsable(false);
        Config::set('filesystems.disks.public.url', 'https://cdn.test/media');

        $this->assertSame(
            'https://cdn.test/media/attachments/avatars/me.png',
            $this->legacyAvatarUser()->getAvatarUrl()
        );
    }

    /**
     * ④ 확장이 `core.storage.filter_url` 로 URL 을 갈아끼우면 그 결과가 나간다.
     *
     * 서명 URL·전용 도메인을 붙이는 자리다 — 손조립은 이 훅을 통째로 우회했다.
     *
     * @effects legacy_avatar_url_passes_through_the_storage_url_filter_hook
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function it_passes_through_the_storage_url_filter_hook(): void
    {
        StorageLinkHelper::forcePublicStorageLinkUsable(true);

        HookManager::addFilter(
            'core.storage.filter_url',
            fn ($url, array $context) => 'https://signed.test/'.$context['full_path'].'?sig=abc'
        );

        $this->assertSame(
            'https://signed.test/attachments/avatars/me.png?sig=abc',
            $this->legacyAvatarUser()->getAvatarUrl()
        );
    }

    /**
     * 레거시 값이 없으면 종전대로 null (분기 자체의 대조군).
     *
     * @effects avatar_url_is_null_without_attachment_or_legacy_value
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function it_returns_null_without_an_attachment_or_legacy_value(): void
    {
        $this->assertNull((new User)->getAvatarUrl());
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
