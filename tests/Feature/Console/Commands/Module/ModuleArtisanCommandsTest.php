<?php

namespace Tests\Feature\Console\Commands\Module;

use App\Enums\ExtensionOwnerType;
use App\Enums\ExtensionStatus;
use App\Models\Module;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ModuleArtisanCommandsTest - 모듈 Artisan 커맨드 테스트
 *
 * ⚠️ 중요: DDL(Data Definition Language) 트랜잭션 격리 문제
 * ============================================================
 *
 * 이 테스트 클래스는 모듈 설치/활성화 시 실행되는 마이그레이션(DDL)으로 인해
 * RefreshDatabase 트랜잭션이 깨지는 문제를 해결하기 위해 특별한 구조로 설계되었습니다.
 *
 * 문제 원인:
 * - 모듈 설치 시 `Artisan::call('migrate')`가 호출됨
 * - MySQL에서 CREATE TABLE 등 DDL 문은 암시적 커밋(implicit commit)을 유발
 * - 이로 인해 RefreshDatabase의 트랜잭션이 깨져서 다음 테스트에서 migrate:fresh가 재실행됨
 * - 각 테스트마다 12-14초의 추가 시간 소요
 *
 * 해결 방법:
 * - DDL을 유발하는 테스트들을 하나의 통합 테스트로 묶음
 * - DDL을 유발하지 않는 테스트(list, cache-clear 등)는 개별 테스트로 유지
 * - DDL 테스트는 클래스 마지막에 배치하여 다른 테스트에 영향 최소화
 *
 * 테스트 구조:
 * 1. Non-DDL 테스트 (빠름, 0.1초 이내)
 *    - list 커맨드 테스트
 *    - cache-clear 커맨드 테스트
 *    - 존재하지 않는 모듈에 대한 실패 테스트
 *
 * 2. DDL 통합 테스트 (마지막에 배치)
 *    - install/activate/deactivate/uninstall 워크플로우
 *    - 중복 설치/활성화 경고 테스트
 *    - uninstall 확인 프롬프트 테스트
 *
 * ⚠️ 주의사항:
 * - 새로운 테스트 추가 시, 모듈 설치/활성화가 필요한 테스트는 반드시 통합 테스트에 포함
 * - 개별 테스트로 분리하면 각 테스트마다 12-14초 추가 소요
 * - DDL 테스트는 반드시 클래스 마지막에 배치 (test_z_ 접두사 사용)
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/implicit-commit.html
 */
class ModuleArtisanCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 모듈 매니저가 모듈 디렉토리를 스캔하도록 초기화
        $moduleManager = app(\App\Extension\ModuleManager::class);
        $moduleManager->loadModules();
    }

    // ========================================================================
    // Non-DDL 테스트: 모듈 설치 없이 실행 가능한 빠른 테스트들
    // 이 테스트들은 DDL을 유발하지 않으므로 트랜잭션 격리가 유지됨
    // ========================================================================

    /**
     * module:list 커맨드가 모듈 목록을 정상적으로 출력하는지 테스트
     */
    public function test_list_command_displays_modules(): void
    {
        $this->artisan('module:list')
            ->assertExitCode(0);
    }

    /**
     * module:list 커맨드가 상태 필터를 정상적으로 처리하는지 테스트
     */
    public function test_list_command_filters_by_status(): void
    {
        // active 상태 필터
        $this->artisan('module:list', ['--status' => 'active'])
            ->assertExitCode(0);

        // uninstalled 상태 필터
        $this->artisan('module:list', ['--status' => 'uninstalled'])
            ->assertExitCode(0);

        // 잘못된 상태
        $this->artisan('module:list', ['--status' => 'invalid'])
            ->assertExitCode(1);
    }

    /**
     * module:list 커맨드가 모듈이 없을 때 적절한 메시지를 출력하는지 테스트
     */
    public function test_list_command_shows_message_when_no_modules(): void
    {
        $this->artisan('module:list', ['--status' => 'installed'])
            ->expectsOutput(__('modules.commands.list.no_modules'))
            ->assertExitCode(0);
    }

    /**
     * module:install 커맨드가 존재하지 않는 모듈에 대해 실패하는지 테스트
     *
     * 이 테스트는 DDL을 유발하지 않음 (모듈이 없어서 마이그레이션 실행 안 됨)
     */
    public function test_install_command_fails_for_nonexistent_module(): void
    {
        $identifier = 'nonexistent-module';

        $this->artisan('module:install', ['identifier' => $identifier])
            ->assertExitCode(1);
    }

    /**
     * module:check-updates 커맨드가 존재하지 않는 모듈에 대해 실패하는지 테스트
     */
    public function test_check_updates_fails_for_nonexistent_module(): void
    {
        $identifier = 'nonexistent-module';

        $this->artisan('module:check-updates', ['identifier' => $identifier])
            ->assertExitCode(1);
    }

    /**
     * module:update 커맨드가 미설치 모듈에 대해 실패하는지 테스트
     */
    public function test_update_fails_for_noninstalled_module(): void
    {
        $identifier = 'sirsoft-board';

        $this->artisan('module:update', ['identifier' => $identifier])
            ->assertExitCode(1);
    }

    /**
     * module:update --force 커맨드가 미설치 모듈에 대해 실패하는지 테스트
     */
    public function test_update_force_fails_for_noninstalled_module(): void
    {
        $identifier = 'sirsoft-board';

        $this->artisan('module:update', ['identifier' => $identifier, '--force' => true])
            ->assertExitCode(1);
    }

    /**
     * module:cache-clear 커맨드가 모든 캐시를 삭제하는지 테스트
     */
    public function test_cache_clear_command_clears_all_caches(): void
    {
        $this->artisan('module:cache-clear')
            ->expectsOutput(__('modules.commands.cache_clear.clearing_all'))
            ->assertExitCode(0);
    }

    /**
     * module:cache-clear 커맨드가 특정 모듈의 캐시만 삭제하는지 테스트
     */
    public function test_cache_clear_command_clears_single_module_cache(): void
    {
        $identifier = 'sirsoft-board';

        $this->artisan('module:cache-clear', ['identifier' => $identifier])
            ->expectsOutput(__('modules.commands.cache_clear.clearing_single', ['module' => $identifier]))
            ->assertExitCode(0);
    }

    /**
     * module:cache-clear 커맨드가 존재하지 않는 모듈에 대해 실패하는지 테스트
     */
    public function test_cache_clear_command_fails_for_nonexistent_module(): void
    {
        $identifier = 'nonexistent-module';

        $this->artisan('module:cache-clear', ['identifier' => $identifier])
            ->assertExitCode(1);
    }

    // ========================================================================
    // DDL 통합 테스트: 모듈 설치가 필요한 모든 테스트를 하나로 통합
    //
    // ⚠️ 중요: DDL을 유발하는 테스트는 반드시 하나의 테스트 메서드로 통합
    // PHPUnit은 테스트 메서드 간에 트랜잭션을 롤백하는데, DDL(CREATE TABLE 등)은
    // 암시적 커밋을 유발하여 트랜잭션을 깨뜨림. 다음 테스트에서 migrate:fresh 재실행 필요.
    //
    // 개별 테스트로 분리하면 각각 12-14초 소요 (마이그레이션 재실행)
    // 하나의 테스트로 통합하면 전체 1-2초 내에 완료
    // ========================================================================

    /**
     * 모듈 Artisan 커맨드 전체 워크플로우 통합 테스트
     *
     * ⚠️ DDL 유발: 모듈 설치 시 마이그레이션 실행
     *
     * 이 테스트는 모듈 설치/활성화/비활성화/삭제와 관련된 모든 시나리오를
     * 하나의 테스트 메서드에서 순차적으로 실행합니다.
     *
     * 테스트 시나리오:
     * ─────────────────────────────────────────────────────────────────────
     * Part 1: 미설치 상태에서의 실패 케이스
     *   - 미설치 모듈 활성화 실패
     *   - 미설치 모듈 비활성화 실패
     *   - 미설치 모듈 삭제 실패
     *
     * Part 2: 모듈 설치 워크플로우
     *   - 모듈 설치 성공
     *   - 이미 설치된 모듈 재설치 경고
     *   - 비활성 상태에서 비활성화 시도 실패
     *
     * Part 3: 모듈 활성화/비활성화 워크플로우
     *   - 모듈 활성화 성공
     *   - 이미 활성화된 모듈 재활성화 경고
     *   - 모듈 비활성화 성공
     *
     * Part 4: 모듈 삭제 워크플로우
     *   - 삭제 확인에서 거절 → 모듈 유지
     *   - 삭제 확인에서 승인 → 모듈 삭제 및 권한 삭제
     *
     * Part 5: --force 옵션 테스트
     *   - 모듈 재설치 → --force 옵션으로 확인 없이 삭제
     *
     * Part 6: 전체 라이프사이클 워크플로우
     *   - install → activate → deactivate → uninstall 순서 실행
     * ─────────────────────────────────────────────────────────────────────
     */
    public function test_z_module_commands_full_workflow(): void
    {
        $identifier = 'sirsoft-board';

        // =====================================================================
        // Part 1: 미설치 상태에서의 실패 케이스
        // =====================================================================

        // 미설치 모듈 활성화 실패
        $this->artisan('module:activate', ['identifier' => $identifier])
            ->assertExitCode(1);

        // 미설치 모듈 비활성화 실패
        $this->artisan('module:deactivate', ['identifier' => $identifier])
            ->assertExitCode(1);

        // 미설치 모듈 삭제 실패
        $this->artisan('module:uninstall', ['identifier' => $identifier])
            ->assertExitCode(1);

        // =====================================================================
        // Part 2: 모듈 설치 워크플로우
        // =====================================================================

        // 모듈 설치 성공
        $this->artisan('module:install', ['identifier' => $identifier])
            ->expectsOutput('✅ '.__('modules.commands.install.success', ['module' => $identifier]))
            ->assertExitCode(0);

        $this->assertDatabaseHas('modules', [
            'identifier' => $identifier,
            'vendor' => 'sirsoft',
            'status' => ExtensionStatus::Inactive->value,
        ]);

        // 이미 설치된 모듈 재설치 경고
        $this->artisan('module:install', ['identifier' => $identifier])
            ->expectsOutput('⚠️  '.__('modules.commands.install.already_installed', ['module' => $identifier]))
            ->assertExitCode(1);

        // 비활성 상태에서 비활성화 시도 (실패)
        $this->artisan('module:deactivate', ['identifier' => $identifier])
            ->assertExitCode(1);

        // =====================================================================
        // Part 3: 모듈 활성화/비활성화 워크플로우
        // =====================================================================

        // 모듈 활성화 성공
        $this->artisan('module:activate', ['identifier' => $identifier])
            ->expectsOutput('✅ '.__('modules.commands.activate.success', ['module' => $identifier]))
            ->assertExitCode(0);

        $this->assertDatabaseHas('modules', [
            'identifier' => $identifier,
            'status' => ExtensionStatus::Active->value,
        ]);

        // 이미 활성화된 모듈 재활성화 경고
        $this->artisan('module:activate', ['identifier' => $identifier])
            ->expectsOutput('⚠️  '.__('modules.commands.activate.already_active', ['module' => $identifier]))
            ->assertExitCode(1);

        // 모듈 비활성화 성공
        $this->artisan('module:deactivate', ['identifier' => $identifier])
            ->expectsOutput('✅ '.__('modules.commands.deactivate.success', ['module' => $identifier]))
            ->assertExitCode(0);

        $this->assertDatabaseHas('modules', [
            'identifier' => $identifier,
            'status' => ExtensionStatus::Inactive->value,
        ]);

        // =====================================================================
        // Part 4: 모듈 삭제 워크플로우
        // =====================================================================

        // 삭제 확인에서 거절 → 모듈 유지
        $this->artisan('module:uninstall', ['identifier' => $identifier])
            ->expectsConfirmation(__('modules.commands.uninstall.confirm_question'), 'no')
            ->expectsOutput(__('modules.commands.uninstall.aborted'))
            ->assertExitCode(0);

        $this->assertDatabaseHas('modules', [
            'identifier' => $identifier,
        ]);

        // 삭제 확인에서 승인 → 모듈 삭제
        $this->artisan('module:uninstall', ['identifier' => $identifier])
            ->expectsConfirmation(__('modules.commands.uninstall.confirm_question'), 'yes')
            ->expectsOutput('✅ '.__('modules.commands.uninstall.success', ['module' => $identifier]))
            ->assertExitCode(0);

        $this->assertDatabaseMissing('modules', [
            'identifier' => $identifier,
        ]);

        // 권한도 삭제되었는지 확인
        $this->assertEquals(0, Permission::byExtension(ExtensionOwnerType::Module, $identifier)->count());

        // =====================================================================
        // Part 5: check-updates / update 커맨드 테스트
        // =====================================================================

        // 재설치
        $this->artisan('module:install', ['identifier' => $identifier])
            ->assertExitCode(0);

        // check-updates 전체 확인 (설치된 모듈이 있는 상태)
        $this->artisan('module:check-updates')
            ->assertExitCode(0);

        // check-updates 단일 확인
        $this->artisan('module:check-updates', ['identifier' => $identifier])
            ->assertExitCode(0);

        // update 커맨드 (업데이트 없는 경우 → 최신 상태 메시지)
        $this->artisan('module:update', ['identifier' => $identifier])
            ->assertExitCode(0);

        // 삭제
        $this->artisan('module:uninstall', ['identifier' => $identifier, '--force' => true])
            ->assertExitCode(0);

        // =====================================================================
        // Part 6: --force 옵션 테스트
        // =====================================================================

        // 재설치
        $this->artisan('module:install', ['identifier' => $identifier])
            ->assertExitCode(0);

        // --force 옵션으로 확인 없이 삭제
        $this->artisan('module:uninstall', ['identifier' => $identifier, '--force' => true])
            ->expectsOutput('✅ '.__('modules.commands.uninstall.success', ['module' => $identifier]))
            ->assertExitCode(0);

        $this->assertDatabaseMissing('modules', [
            'identifier' => $identifier,
        ]);

        // =====================================================================
        // Part 7: 전체 라이프사이클 워크플로우
        // install → activate → deactivate → uninstall
        // =====================================================================

        // Install
        $this->artisan('module:install', ['identifier' => $identifier])
            ->assertExitCode(0);

        $this->assertDatabaseHas('modules', [
            'identifier' => $identifier,
            'status' => ExtensionStatus::Inactive->value,
        ]);

        // Activate
        $this->artisan('module:activate', ['identifier' => $identifier])
            ->assertExitCode(0);

        $this->assertDatabaseHas('modules', [
            'identifier' => $identifier,
            'status' => ExtensionStatus::Active->value,
        ]);

        // Deactivate
        $this->artisan('module:deactivate', ['identifier' => $identifier])
            ->assertExitCode(0);

        $this->assertDatabaseHas('modules', [
            'identifier' => $identifier,
            'status' => ExtensionStatus::Inactive->value,
        ]);

        // Uninstall
        $this->artisan('module:uninstall', ['identifier' => $identifier, '--force' => true])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('modules', [
            'identifier' => $identifier,
        ]);
    }

    /**
     * 잘못된 식별자 형식으로 모듈 설치 시 FAILURE 반환
     */
    public function test_install_fails_with_invalid_identifier_format(): void
    {
        $this->artisan('module:install', ['identifier' => 'sirsoftboard'])
            ->assertExitCode(1);
    }

    /**
     * 숫자로 시작하는 단어가 포함된 식별자로 모듈 설치 시 FAILURE 반환
     */
    public function test_install_fails_with_digit_starting_identifier(): void
    {
        $this->artisan('module:install', ['identifier' => 'sirsoft-2shop'])
            ->assertExitCode(1);
    }

    /**
     * 대문자가 포함된 식별자로 모듈 설치 시 FAILURE 반환
     */
    public function test_install_fails_with_uppercase_identifier(): void
    {
        $this->artisan('module:install', ['identifier' => 'Sirsoft-Board'])
            ->assertExitCode(1);
    }
}
