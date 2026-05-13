<?php

namespace Tests\Unit\Services;

use App\Services\CoreUpdateService;
use Tests\TestCase;

/**
 * CoreUpdateService::reloadCoreConfigAndResync() 회귀 테스트.
 *
 * 부모 프로세스가 부팅 시점에 캐시한 stale config 를 우회해 디스크의 fresh
 * config/core.php 를 Config Repository 에 재주입하는 핵심 동작을 검증.
 *
 * 회귀 시나리오 (beta.3 → beta.4 트랜지션 박제 결함):
 *   - CoreUpdateCommand Step 7 (applyUpdate) 가 디스크 config/core.php 를 신버전으로 교체
 *   - 부모 프로세스 메모리의 Config Repository 는 부팅 당시 구버전 값 유지
 *   - Step 9 가 syncCoreRolesAndPermissions / syncCoreMenus 를 직접 호출하면 stale 값으로 sync
 *   - 신규 권한/메뉴가 누락 → 사용자 401/메뉴 부재 회귀
 *
 * 본 테스트는 reloadCoreConfigAndResync() 가 메모리 stale 값을 디스크 값으로 강제 갱신하는지 검증.
 * 즉 회귀 시 reloadCoreConfigAndResync() 호출 후 config('core.*') 가 디스크 값과 일치해야 함.
 */
class CoreUpdateServiceReloadResyncTest extends TestCase
{
    /**
     * stale 메모리 config 가 reloadCoreConfigAndResync() 호출 후 디스크 값으로 복원되는지 검증.
     *
     * 시나리오:
     *   1. 디스크 config/core.php 의 실제 permissions 카탈로그를 baseline 으로 캡처
     *   2. Config Repository 에 stale 값 강제 주입 (예: permissions=[])
     *   3. reloadCoreConfigAndResync() 호출 (sync/seeder 단계는 try/catch DB 의존이라 실패해도 본 검증과 무관)
     *   4. config('core.permissions') 가 다시 baseline 값으로 복원되었는지 확인
     */
    public function test_reload_restores_stale_memory_config_from_disk(): void
    {
        // 디스크 baseline 캡처 — 테스트 실행 환경의 실제 config/core.php 값
        $diskFresh = require config_path('core.php');
        $this->assertIsArray($diskFresh, '디스크 config/core.php 는 array 반환 필수');
        $this->assertArrayHasKey('permissions', $diskFresh, 'config/core.php 에 permissions 키 존재 필수');

        // stale 메모리 주입 — 부모 프로세스가 부팅 후 아무 이유로든 변경된 상태 시뮬레이션
        config(['core.permissions' => ['stale' => 'value']]);
        config(['core.menus' => []]);
        $this->assertSame(['stale' => 'value'], config('core.permissions'), 'stale 주입 확인');

        // 서비스 호출 — sync/seeder 단계는 DB 미연결 환경에서 try/catch warning 으로 graceful 처리됨
        // 본 테스트는 config 재주입 동작만 검증하고, sync/seeder 가 호출되었는지는 다른 테스트가 검증
        $service = new class extends CoreUpdateService
        {
            // sync 단계 격리 — config 재주입 검증에 무관한 부수 효과 회피
            public function syncCoreRolesAndPermissions(): void {}

            public function syncCoreMenus(): void {}
        };

        $service->reloadCoreConfigAndResync();

        // 디스크 값으로 복원 확인
        $this->assertSame(
            $diskFresh['permissions'],
            config('core.permissions'),
            'reloadCoreConfigAndResync() 후 config 가 디스크 값으로 복원되어야 함 (stale 메모리 값이 우선되면 회귀)'
        );
    }

    /**
     * reloadCoreConfigAndResync() 가 코어 도메인 5종 (권한/메뉴/알림/IDV정책/IDV메시지) 을
     * 모두 호출 시도하는지 정적 검증.
     *
     * 회귀 시나리오: 향후 누군가 reloadCoreConfigAndResync 본문에서 시더 호출 일부를 제거하면
     * upgrade 흐름에서 해당 도메인이 누락되는 결함이 잠복함. 본 테스트는 본문에 5종이 모두
     * 등장하는지 확인하여 자동 차단.
     */
    public function test_reload_invokes_all_core_domain_seeders(): void
    {
        $servicePath = base_path('app/Services/CoreUpdateService.php');
        $this->assertFileExists($servicePath);

        $content = file_get_contents($servicePath);
        $this->assertNotFalse($content);

        // reloadCoreConfigAndResync 본문 추출
        $start = strpos($content, 'public function reloadCoreConfigAndResync(): void');
        $this->assertNotFalse($start, 'reloadCoreConfigAndResync 메서드 정의 존재 필수');
        $end = strpos($content, "\n    }\n", $start);
        $this->assertNotFalse($end, '메서드 본문 종결 위치 식별 실패');
        $body = substr($content, $start, $end - $start);

        $this->assertStringContainsString('syncCoreRolesAndPermissions', $body, '권한/역할 sync 호출 누락');
        $this->assertStringContainsString('syncCoreMenus', $body, '메뉴 sync 호출 누락');
        $this->assertStringContainsString('NotificationDefinitionSeeder', $body, '알림 정의 시더 호출 누락');
        $this->assertStringContainsString('IdentityPolicySeeder', $body, 'IDV 정책 시더 호출 누락');
        $this->assertStringContainsString('IdentityMessageDefinitionSeeder', $body, 'IDV 메시지 정의 시더 호출 누락');
    }

    /**
     * CoreUpdateCommand step 9 가 reloadCoreConfigAndResync 사용 (syncCore* 직접 호출 금지) 정합성을
     * audit 룰 `core-update-command-direct-sync` 가 보장하므로, 본 테스트에서는 정적 정합성을
     * 룰 재실행으로 1회 더 확인 (1차 방어).
     */
    public function test_audit_rule_guards_against_direct_sync_call_regression(): void
    {
        $cmdPath = base_path('app/Console/Commands/Core/CoreUpdateCommand.php');
        $this->assertFileExists($cmdPath);

        $content = file_get_contents($cmdPath);
        $this->assertNotFalse($content);

        // 부모 프로세스에서 syncCore* 직접 호출 금지
        $this->assertDoesNotMatchRegularExpression(
            '/->\s*syncCoreRolesAndPermissions\s*\(/',
            $content,
            'CoreUpdateCommand 가 syncCoreRolesAndPermissions() 를 직접 호출하면 부모 stale config 회귀 발생 — reloadCoreConfigAndResync() 사용'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/->\s*syncCoreMenus\s*\(/',
            $content,
            'CoreUpdateCommand 가 syncCoreMenus() 를 직접 호출하면 부모 stale config 회귀 발생 — reloadCoreConfigAndResync() 사용'
        );

        // reloadCoreConfigAndResync 호출 1회 이상 존재
        $this->assertMatchesRegularExpression(
            '/->\s*reloadCoreConfigAndResync\s*\(/',
            $content,
            'CoreUpdateCommand step 9 가 reloadCoreConfigAndResync() 를 호출해야 함'
        );
    }
}
