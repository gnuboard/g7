<?php

namespace Tests\Unit\Extension;

use App\Extension\Traits\ClearsTemplateCaches;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `ext.cache_version` 캐시 스토어 일관성 회귀.
 *
 * 배경: `ext.cache_version` 은 확장 업데이트/설치/레이아웃 변경 시 bump 되어
 * 프론트엔드 fetch URL(`?v=`)을 무효화하는 코어 소유 좌표 키다. 값은
 * `ClearsTemplateCaches::incrementExtensionCacheVersion()`(write)과
 * `getExtensionCacheVersion()`(read)가 **반드시 같은 캐시 스토어**를 써야 한다.
 *
 * 결함(재현): 종전 구현은 스토어를 `config('cache.default')` 로 매번 해소했다.
 * 그런데 `SettingsServiceProvider::applyCacheConfig` 가 부팅 중 admin 설정값
 * (`g7_core_settings('cache.driver')`)으로 `cache.default` 를 **런타임 오버라이드**한다.
 * 따라서 settings 로드 타이밍/컨텍스트(웹 vs CLI vs 큐, 설정 미시드 환경)에 따라
 * write 와 read 가 **서로 다른 스토어**(예: write=database(.env), read=file(settings))를
 * 가리키면, bump 한 버전이 read 경로에서 보이지 않아 프론트엔드가 영구 stale 캐시를
 * 받는다(편집기 데이터소스 명칭/레이아웃 변경 미반영).
 *
 * 본 테스트는 write 직후 read 사이에 `config('cache.default')` 가 바뀌어도(설정
 * 오버라이드 시뮬레이션) 값이 일관되게 보존됨을 잠근다.
 */
class ExtensionCacheVersionStoreConsistencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 스토어 메모 초기화 — 각 테스트가 자기 cache.default 기준으로 1회 해소하도록.
        ClearsTemplateCaches::resetExtensionCacheStoreMemo();
    }

    protected function tearDown(): void
    {
        ClearsTemplateCaches::resetExtensionCacheStoreMemo();
        parent::tearDown();
    }

    /** trait protected 메서드 노출용 더블 */
    private function makeSubject(): object
    {
        return new class
        {
            use ClearsTemplateCaches;

            public function bump(): void
            {
                $this->incrementExtensionCacheVersion();
            }

            public function read(): int
            {
                return self::getExtensionCacheVersion();
            }
        };
    }

    #[Test]
    public function version_bump_is_readable_after_cache_default_changes(): void
    {
        $subject = $this->makeSubject();

        // write 시점 — 한 스토어(예: array)
        Config::set('cache.default', 'array');
        $subject->bump();
        $written = $subject->read();
        $this->assertGreaterThan(0, $written, 'bump 직후 같은 스토어에서 읽혀야 함');

        // read 시점 — settings 오버라이드로 cache.default 가 바뀐 상황 시뮬레이션
        Config::set('cache.default', 'file');
        $afterChange = $subject->read();

        // 핵심 불변식: write 와 read 가 같은 고정 스토어를 쓰므로 cache.default 변경에
        // 영향받지 않고 동일 값이 보여야 한다(스토어 분기 = stale 캐시 회귀).
        $this->assertSame(
            $written,
            $afterChange,
            'ext.cache_version 은 cache.default 변경과 무관하게 동일 스토어로 일관 read/write 되어야 함'
        );
    }

    #[Test]
    public function version_written_under_one_default_is_visible_under_another(): void
    {
        $subject = $this->makeSubject();

        // database(.env 기본) 스토어 컨텍스트에서 write
        Config::set('cache.default', 'database');
        $subject->bump();
        $v1 = $subject->read();

        // file(settings 오버라이드) 스토어 컨텍스트에서 read — 같은 값이어야 함
        Config::set('cache.default', 'file');
        $v2 = $subject->read();

        $this->assertSame($v1, $v2, 'write/read 컨텍스트의 cache.default 가 달라도 같은 버전이 보여야 함');
        $this->assertGreaterThan(0, $v2, 'read 컨텍스트에서 0(미설정)으로 떨어지면 stale 캐시 회귀');
    }
}
