<?php

namespace Tests\Unit\Services;

use App\Services\CoreUpdateService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * spawn 자식은 부모가 비우지 않은 이전 버전 config 캐시로 부팅할 수 있다. 그 상태에서
 * `config('app.update.*')` 는 캐시에 박힌 옛 목록이라, 신버전이 추가한 쓰기 권한 디렉토리
 * (7.0.10 의 `public/build/ext`) 가 자식의 권한 정상화에서 빠진다 (2026-09-06 서버 실측).
 * `freshDiskUpdateConfig()` 는 디스크의 `config/app.php` 를 직접 읽어 그 목록을 돌려준다.
 */
class CoreUpdateServiceFreshDiskConfigTest extends TestCase
{
    /**
     * @effects execute_upgrade_steps_child_reads_update_config_from_disk_when_config_is_cached
     */
    #[Test]
    public function 메모리_config_가_stale_해도_디스크의_update_목록을_돌려준다(): void
    {
        config(['app.update.restore_ownership_group_writable' => ['stale-only']]);

        $fresh = app(CoreUpdateService::class)->freshDiskUpdateConfig('restore_ownership_group_writable');

        $this->assertNotSame(['stale-only'], $fresh);
        $this->assertContains('public/build/ext', $fresh, '디스크 config/app.php 의 목록을 읽어야 한다');
        $this->assertContains('bootstrap/cache', $fresh);
    }

    /**
     * @effects execute_upgrade_steps_child_reads_update_config_from_disk_when_config_is_cached
     */
    #[Test]
    public function 없는_키는_기본값을_돌려준다(): void
    {
        $this->assertSame(['x'], app(CoreUpdateService::class)->freshDiskUpdateConfig('no_such_key_'.uniqid(), ['x']));
    }
}
