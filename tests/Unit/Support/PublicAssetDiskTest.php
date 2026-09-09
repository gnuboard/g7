<?php

namespace Tests\Unit\Support;

use App\Support\PublicAssetDisk;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * 공개 자산 디스크 해석기 단위 테스트 (공개 #134)
 *
 * `AbstractModule`/`AbstractPlugin` 에 사본으로 있던 판정식을 코어 공통 장소로
 * 추출한 것이므로, 판정 규칙(미설정/'none'/고아 디스크/override 우선순위)이
 * 한 글자도 바뀌지 않았음을 고정한다.
 */
class PublicAssetDiskTest extends TestCase
{
    /**
     * 미설정('')이면 null 이어야 합니다 (호출측이 기존 디스크로 폴백).
     *
     * @scenario public_asset_disk=unset
     *
     * @effects proxy_url_otherwise
     */
    public function test_unset_resolves_to_null(): void
    {
        Config::set('core.storage.public_asset_disk', '');

        $this->assertNull(PublicAssetDisk::resolve());
    }

    /**
     * 'none' 은 명시적 스트리밍 유지 선언이므로 null 이어야 합니다.
     */
    public function test_none_resolves_to_null(): void
    {
        Config::set('core.storage.public_asset_disk', 'none');

        $this->assertNull(PublicAssetDisk::resolve());
    }

    /**
     * config 에 존재하지 않는 디스크(고아 플러그인 디스크)는 null 이어야 합니다.
     *
     * @scenario public_asset_disk=ghost_disk
     *
     * @effects proxy_url_otherwise
     */
    public function test_orphan_disk_resolves_to_null(): void
    {
        Config::set('core.storage.public_asset_disk', 'vanished_plugin_disk');

        $this->assertNull(PublicAssetDisk::resolve());
    }

    /**
     * config 에 존재하는 디스크는 그대로 돌려주어야 합니다.
     */
    public function test_existing_disk_resolves_to_itself(): void
    {
        Config::set('core.storage.public_asset_disk', 'public');

        $this->assertSame('public', PublicAssetDisk::resolve());
    }

    /**
     * override 가 비어 있지 않으면 코어 전역 설정보다 우선해야 합니다.
     */
    public function test_override_takes_precedence_over_global(): void
    {
        Config::set('core.storage.public_asset_disk', 'public');

        $this->assertSame('local', PublicAssetDisk::resolve('local'));
    }

    /**
     * override 가 ''/null 이면 코어 전역 설정을 사용해야 합니다.
     */
    public function test_blank_override_falls_back_to_global(): void
    {
        Config::set('core.storage.public_asset_disk', 'public');

        $this->assertSame('public', PublicAssetDisk::resolve(''));
        $this->assertSame('public', PublicAssetDisk::resolve(null));
    }

    /**
     * override 로 'none' 을 주면 전역 설정이 있어도 스트리밍을 강제해야 합니다.
     */
    public function test_none_override_forces_streaming_over_global(): void
    {
        Config::set('core.storage.public_asset_disk', 'public');

        $this->assertNull(PublicAssetDisk::resolve('none'));
    }

    /**
     * isCurrent() 는 resolve() 를 경유하므로 ''/'none'/고아 디스크가
     * 등가 비교로 통과하지 않아야 합니다.
     */
    public function test_is_current_never_matches_blank_none_or_orphan(): void
    {
        Config::set('core.storage.public_asset_disk', '');
        $this->assertFalse(PublicAssetDisk::isCurrent(''));

        Config::set('core.storage.public_asset_disk', 'none');
        $this->assertFalse(PublicAssetDisk::isCurrent('none'));

        Config::set('core.storage.public_asset_disk', 'vanished_plugin_disk');
        $this->assertFalse(PublicAssetDisk::isCurrent('vanished_plugin_disk'));

        Config::set('core.storage.public_asset_disk', 'public');
        $this->assertTrue(PublicAssetDisk::isCurrent('public'));
        $this->assertFalse(PublicAssetDisk::isCurrent('attachments'));
        $this->assertFalse(PublicAssetDisk::isCurrent(null));
    }

    /**
     * memoize 금지 — 런타임 Config::set 변경이 즉시 반영되어야 합니다.
     */
    public function test_resolution_is_not_memoized(): void
    {
        Config::set('core.storage.public_asset_disk', 'public');
        $this->assertSame('public', PublicAssetDisk::resolve());

        Config::set('core.storage.public_asset_disk', '');
        $this->assertNull(PublicAssetDisk::resolve());
    }
}
