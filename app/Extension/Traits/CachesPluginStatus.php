<?php

namespace App\Extension\Traits;

use App\Contracts\Extension\CacheInterface;
use App\Enums\ExtensionStatus;
use App\Extension\Cache\CoreCacheDriver;
use App\Models\Plugin;
use App\Support\InstallerContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 플러그인 상태 캐시를 관리하는 Trait
 *
 * 활성화된 플러그인, 설치된 플러그인 목록을 캐시하여 DB 조회 오버헤드를 줄입니다.
 * PluginManager, PluginServiceProvider 등에서 사용됩니다.
 */
trait CachesPluginStatus
{
    /**
     * 활성화된 플러그인 identifier 목록을 조회합니다.
     *
     * @return array<string> 활성화된 플러그인 identifier 배열
     */
    public static function getActivePluginIdentifiers(): array
    {
        if (! self::isPluginTableReady()) {
            return [];
        }

        return self::rememberNonEmptyPluginList(
            'ext.plugins.active_identifiers',
            fn () => Plugin::where('status', ExtensionStatus::Active->value)
                ->pluck('identifier')
                ->toArray()
        );
    }

    /**
     * 목록 캐시를 조회하되, 빈 결과는 캐시에 남기지 않습니다.
     *
     * 확장 상태는 install/activate/update 도중 잠시 active 가 아닌 값으로 바뀐다. 그 창에서
     * 누가 이 목록을 읽으면 빈 배열이 TTL(기본 하루) 동안 굳어, 작업이 끝난 뒤에도 모든
     * 플러그인이 꺼진 것처럼 동작하며 스스로 회복되지 않는다. 빈 결과는 재계산 비용이 사실상
     * 없는 단순 조회이므로 캐시하지 않는 편이 안전하다.
     *
     * @param  string  $key  캐시 키
     * @param  \Closure  $resolver  목록 계산 클로저
     * @return array<string> 조회된 identifier 배열
     */
    private static function rememberNonEmptyPluginList(string $key, \Closure $resolver): array
    {
        $cache = self::resolvePluginStatusCache();
        $cached = $cache->get($key);

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $fresh = $resolver();

        if ($fresh !== []) {
            $cache->put(
                $key,
                $fresh,
                (int) g7_core_settings('cache.extension_status_ttl', 86400),
                ['ext.status', 'ext.plugins']
            );
        }

        return $fresh;
    }

    /**
     * 설치된 플러그인 (active + inactive) identifier 목록을 조회합니다.
     *
     * @return array<string> 설치된 플러그인 identifier 배열
     */
    public static function getInstalledPluginIdentifiers(): array
    {
        if (! self::isPluginTableReady()) {
            return [];
        }

        return self::rememberNonEmptyPluginList(
            'ext.plugins.installed_identifiers',
            fn () => Plugin::whereIn('status', [
                ExtensionStatus::Active->value,
                ExtensionStatus::Inactive->value,
            ])->pluck('identifier')->toArray()
        );
    }

    /**
     * 상태와 무관하게 행이 있는 식별자 목록을 조회합니다 — 목록 화면의 「미설치」 판정용.
     *
     * 「설치됨」 캐시 목록은 active·inactive 만 센다. 진행 중 상태(installing·updating·uninstalling)에 멈춘 확장까지
     * 미설치로 보면 관리자 목록에 같은 확장이 「업데이트 중」 카드와 「미설치 · 설치」 카드로 겹쳐 나온다(7.1.1 출시 전).
     * 목록을 그릴 때만 부르므로 캐시하지 않는다.
     *
     * @return array<int, string> 식별자 목록
     */
    public static function getRecordedPluginIdentifiers(): array
    {
        if (! self::isPluginTableReady()) {
            return [];
        }

        return Plugin::pluck('identifier')->toArray();
    }

    /**
     * 플러그인 상태 캐시를 무효화합니다.
     * 플러그인 상태 변경 시 (install, activate, deactivate, uninstall) 호출해야 합니다.
     *
     * @return void
     */
    public static function invalidatePluginStatusCache(): void
    {
        $cache = self::resolvePluginStatusCache();
        $cache->forget('ext.plugins.active_identifiers');
        $cache->forget('ext.plugins.installed_identifiers');
    }

    /**
     * 설치 완료 상태에서는 `Schema::hasTable()` 호출을 건너뜁니다.
     * 인스톨러 이전 환경이나 테스트에서는 기존 체크 경로로 폴백합니다.
     *
     * 단, 마이그레이션 계열 명령 실행 중에는 `INSTALLER_COMPLETED=true` 라도 테이블이
     * 아직 없을 수 있으므로(빈 DB 새 서버에 .env 복사 후 migrate 전) fast-path 를
     * 신뢰하지 않고 실제 `Schema::hasTable()` 폴백으로 진입합니다.
     *
     * @return bool plugins 테이블을 조회할 수 있으면 true
     */
    private static function isPluginTableReady(): bool
    {
        if (! InstallerContext::isSchemaMutatingCommand() && config('app.installer_completed')) {
            return true;
        }

        try {
            DB::connection()->getPdo();

            return Schema::hasTable('plugins');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * CacheInterface 인스턴스를 lazy 조회합니다 (register 시점 안전).
     *
     * @return CacheInterface 컨테이너의 캐시, 조회 실패 시 기본 드라이버
     */
    private static function resolvePluginStatusCache(): CacheInterface
    {
        try {
            return app(CacheInterface::class);
        } catch (\Throwable $e) {
            return new CoreCacheDriver(config('cache.default', 'array'));
        }
    }
}
