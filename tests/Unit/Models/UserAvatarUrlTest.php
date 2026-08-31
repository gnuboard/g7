<?php

namespace Tests\Unit\Models;

use App\Extension\Helpers\StorageLinkHelper;
use App\Extension\HookManager;
use App\Models\User;
use App\Support\AssetUrl;
use Illuminate\Support\Facades\Config;
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
 * @scenario storage_link=usable, disk_url=default, hook=unregistered
 */
class UserAvatarUrlTest extends TestCase
{
    protected function tearDown(): void
    {
        HookManager::resetAll();
        AssetUrl::forceMode(null);

        parent::tearDown();
    }

    private function legacyAvatarUser(): User
    {
        $user = new User;
        $user->avatar = 'me.png';

        return $user;
    }

    /**
     * ① 링크 정상 — 종전대로 정적 `/storage/...` 주소다.
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
     */
    #[Test]
    public function it_returns_null_without_an_attachment_or_legacy_value(): void
    {
        $this->assertNull((new User)->getAvatarUrl());
    }
}
