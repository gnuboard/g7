<?php

namespace Tests\Feature\Console\Commands\Template;

use App\Enums\ExtensionStatus;
use App\Models\Template;
use App\Models\TemplateLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TemplateArtisanCommandsTest - 템플릿 Artisan 커맨드 테스트
 *
 * ⚠️ 중요: DDL(Data Definition Language) 트랜잭션 격리 문제
 * ============================================================
 *
 * 이 테스트 클래스는 템플릿 설치/활성화 시 발생하는 DDL로 인해
 * RefreshDatabase 트랜잭션이 깨지는 문제를 해결하기 위해 특별한 구조로 설계되었습니다.
 *
 * 문제 원인:
 * - 템플릿 설치 시 레이아웃 생성 등 DB 작업이 수행됨
 * - MySQL에서 DDL 문은 암시적 커밋(implicit commit)을 유발
 * - 이로 인해 RefreshDatabase의 트랜잭션이 깨져서 다음 테스트에서 migrate:fresh가 재실행됨
 * - 각 테스트마다 12-14초의 추가 시간 소요
 *
 * 해결 방법:
 * - DDL을 유발하는 테스트들을 하나의 통합 테스트로 묶음
 * - DDL을 유발하지 않는 테스트(nonexistent 등)는 개별 테스트로 유지
 * - DDL 테스트는 클래스 마지막에 배치하여 다른 테스트에 영향 최소화
 *
 * 테스트 구조:
 * 1. Non-DDL 테스트 (빠름, 0.1초 이내)
 *    - 존재하지 않는 템플릿에 대한 실패 테스트
 *
 * 2. DDL 통합 테스트 (마지막에 배치)
 *    - install/activate/deactivate/uninstall 워크플로우
 *    - list, cache-clear 커맨드 테스트
 *    - uninstall 확인 프롬프트 테스트
 *
 * ⚠️ 주의사항:
 * - 새로운 테스트 추가 시, 템플릿 설치가 필요한 테스트는 반드시 통합 테스트에 포함
 * - 개별 테스트로 분리하면 각 테스트마다 12-14초 추가 소요
 * - DDL 테스트는 반드시 클래스 마지막에 배치 (test_z_ 접두사 사용)
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/implicit-commit.html
 */
class TemplateArtisanCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 템플릿 매니저가 템플릿 디렉토리를 스캔하도록 초기화
        $templateManager = app(\App\Extension\TemplateManager::class);
        $templateManager->loadTemplates();
    }

    // ========================================================================
    // Non-DDL 테스트: 템플릿 설치 없이 실행 가능한 빠른 테스트들
    // 이 테스트들은 DDL을 유발하지 않으므로 트랜잭션 격리가 유지됨
    // ========================================================================

    /**
     * template:install 커맨드가 존재하지 않는 템플릿에 대해 실패하는지 테스트
     *
     * 이 테스트는 DDL을 유발하지 않음 (템플릿이 없어서 설치 실행 안 됨)
     */
    public function test_install_command_fails_for_nonexistent_template(): void
    {
        $identifier = 'nonexistent-template';

        $this->artisan('template:install', ['identifier' => $identifier])
            ->assertExitCode(1);
    }

    /**
     * template:activate 커맨드가 미설치 템플릿에 대해 실패하는지 테스트
     */
    public function test_activate_command_fails_for_uninstalled_template(): void
    {
        $identifier = 'sirsoft-admin_basic';

        $this->artisan('template:activate', ['identifier' => $identifier])
            ->assertExitCode(1);
    }

    /**
     * template:deactivate 커맨드가 미설치 템플릿에 대해 실패하는지 테스트
     */
    public function test_deactivate_command_fails_for_uninstalled_template(): void
    {
        $identifier = 'sirsoft-admin_basic';

        $this->artisan('template:deactivate', ['identifier' => $identifier])
            ->assertExitCode(1);
    }

    /**
     * template:uninstall 커맨드가 미설치 템플릿에 대해 실패하는지 테스트
     */
    public function test_uninstall_command_fails_for_uninstalled_template(): void
    {
        $identifier = 'sirsoft-admin_basic';

        $this->artisan('template:uninstall', ['identifier' => $identifier])
            ->assertExitCode(1);
    }

    /**
     * template:cache-clear 커맨드가 존재하지 않는 템플릿에 대해 실패하는지 테스트
     */
    public function test_cache_clear_command_fails_for_nonexistent_template(): void
    {
        $identifier = 'nonexistent-template';

        $this->artisan('template:cache-clear', ['identifier' => $identifier])
            ->assertExitCode(1);
    }

    /**
     * template:list 커맨드가 잘못된 타입에 대해 실패하는지 테스트
     */
    public function test_list_command_fails_for_invalid_type(): void
    {
        $this->artisan('template:list', ['--type' => 'invalid'])
            ->assertExitCode(1);
    }

    /**
     * template:list 커맨드가 잘못된 상태에 대해 실패하는지 테스트
     */
    public function test_list_command_fails_for_invalid_status(): void
    {
        $this->artisan('template:list', ['--status' => 'invalid'])
            ->assertExitCode(1);
    }

    /**
     * template:check-updates 커맨드가 존재하지 않는 템플릿에 대해 실패하는지 테스트
     */
    public function test_check_updates_fails_for_nonexistent_template(): void
    {
        $identifier = 'nonexistent-template';

        $this->artisan('template:check-updates', ['identifier' => $identifier])
            ->assertExitCode(1);
    }

    /**
     * template:update 커맨드가 미설치 템플릿에 대해 실패하는지 테스트
     */
    public function test_update_fails_for_noninstalled_template(): void
    {
        $identifier = 'sirsoft-admin_basic';

        $this->artisan('template:update', ['identifier' => $identifier])
            ->assertExitCode(1);
    }

    /**
     * template:update --force 커맨드가 미설치 템플릿에 대해 실패하는지 테스트
     */
    public function test_update_force_fails_for_noninstalled_template(): void
    {
        $identifier = 'sirsoft-admin_basic';

        $this->artisan('template:update', ['identifier' => $identifier, '--force' => true])
            ->assertExitCode(1);
    }

    /**
     * template:update 커맨드가 잘못된 레이아웃 전략에 대해 실패하는지 테스트
     */
    public function test_update_fails_for_invalid_layout_strategy(): void
    {
        $this->artisan('template:update', [
            'identifier' => 'sirsoft-admin_basic',
            '--layout-strategy' => 'invalid',
        ])
            ->assertExitCode(1);
    }

    /**
     * template:list 커맨드가 템플릿이 없을 때 적절한 메시지를 출력하는지 테스트
     *
     * 참고: 템플릿 list 커맨드는 파일 시스템에서 템플릿을 스캔하므로
     * 실제 템플릿 파일이 있으면 uninstalled 상태로 표시됩니다.
     * 따라서 --status=installed로 필터링하여 DB에 설치된 템플릿만 조회합니다.
     */
    public function test_list_command_shows_message_when_no_templates(): void
    {
        // DB에 설치된 템플릿이 없으므로 --status=installed 필터 시 빈 결과
        $this->artisan('template:list', ['--status' => 'installed'])
            ->expectsOutput(__('templates.commands.list.no_templates'))
            ->assertExitCode(0);
    }

    // ========================================================================
    // DDL 통합 테스트: 템플릿 설치가 필요한 모든 테스트를 하나로 통합
    //
    // ⚠️ 중요: DDL을 유발하는 테스트는 반드시 하나의 테스트 메서드로 통합
    // PHPUnit은 테스트 메서드 간에 트랜잭션을 롤백하는데, DDL(CREATE TABLE 등)은
    // 암시적 커밋을 유발하여 트랜잭션을 깨뜨림. 다음 테스트에서 migrate:fresh 재실행 필요.
    //
    // 개별 테스트로 분리하면 각각 12-14초 소요 (마이그레이션 재실행)
    // 하나의 테스트로 통합하면 전체 1-2초 내에 완료
    // ========================================================================

    /**
     * 템플릿 Artisan 커맨드 전체 워크플로우 통합 테스트
     *
     * ⚠️ DDL 유발: 템플릿 설치 시 레이아웃 생성
     *
     * 이 테스트는 템플릿 설치/활성화/비활성화/삭제와 관련된 모든 시나리오를
     * 하나의 테스트 메서드에서 순차적으로 실행합니다.
     *
     * 테스트 시나리오:
     * ─────────────────────────────────────────────────────────────────────
     * Part 1: 템플릿 설치 워크플로우
     *   - 템플릿 설치 성공
     *   - 레이아웃 생성 확인
     *   - 이미 설치된 템플릿 재설치 경고
     *   - 비활성 상태에서 비활성화 시도 실패
     *
     * Part 2: 템플릿 활성화/비활성화 워크플로우
     *   - 템플릿 활성화 성공
     *   - 이미 활성화된 템플릿 재활성화 경고
     *   - 템플릿 비활성화 성공
     *
     * Part 3: 템플릿 삭제 워크플로우
     *   - 삭제 확인에서 거절 → 템플릿 유지
     *   - 삭제 확인에서 승인 → 템플릿 삭제 및 레이아웃 삭제
     *
     * Part 4: list, cache-clear 커맨드 테스트
     *   - 템플릿 목록 출력
     *   - 타입/상태 필터링
     *   - 캐시 삭제
     *
     * Part 5: 전체 라이프사이클 워크플로우
     *   - install → activate → deactivate → uninstall 순서 실행
     * ─────────────────────────────────────────────────────────────────────
     */
    public function test_z_template_commands_full_workflow(): void
    {
        $identifier = 'sirsoft-admin_basic';

        // =====================================================================
        // Part 1: 템플릿 설치 워크플로우
        // =====================================================================

        // 템플릿 설치 성공
        $this->artisan('template:install', ['identifier' => $identifier])
            ->expectsOutput('✅ '.__('templates.commands.install.success', ['template' => $identifier]))
            ->assertExitCode(0);

        $this->assertDatabaseHas('templates', [
            'identifier' => $identifier,
            'vendor' => 'sirsoft',
            'type' => 'admin',
            'status' => ExtensionStatus::Inactive->value,
        ]);

        // 레이아웃이 생성되었는지 확인
        $template = Template::where('identifier', $identifier)->first();
        $this->assertNotNull($template);
        $this->assertGreaterThan(0, $template->layouts()->count());

        // 이미 설치된 템플릿 재설치 (updateOrCreate로 인해 성공)
        // 템플릿은 모듈과 달리 updateOrCreate를 사용하므로 재설치가 가능함
        $this->artisan('template:install', ['identifier' => $identifier])
            ->assertExitCode(0);

        // 비활성 상태에서 비활성화 시도 (실패)
        $this->artisan('template:deactivate', ['identifier' => $identifier])
            ->assertExitCode(1);

        // =====================================================================
        // Part 2: 템플릿 활성화/비활성화 워크플로우
        // =====================================================================

        // 템플릿 활성화 성공
        $this->artisan('template:activate', ['identifier' => $identifier])
            ->expectsOutput('✅ '.__('templates.commands.activate.success', ['template' => $identifier]))
            ->assertExitCode(0);

        $this->assertDatabaseHas('templates', [
            'identifier' => $identifier,
            'status' => ExtensionStatus::Active->value,
        ]);

        // 이미 활성화된 템플릿 재활성화 시도 (실패)
        // 템플릿은 errors.already_active 예외를 던지며 실패함
        $this->artisan('template:activate', ['identifier' => $identifier])
            ->assertExitCode(1);

        // 템플릿 비활성화 성공
        $this->artisan('template:deactivate', ['identifier' => $identifier])
            ->expectsOutput('✅ '.__('templates.commands.deactivate.success', ['template' => $identifier]))
            ->assertExitCode(0);

        $this->assertDatabaseHas('templates', [
            'identifier' => $identifier,
            'status' => ExtensionStatus::Inactive->value,
        ]);

        // =====================================================================
        // Part 3: 템플릿 삭제 워크플로우
        // =====================================================================

        // 삭제 확인에서 거절 → 템플릿 유지
        $this->artisan('template:uninstall', ['identifier' => $identifier])
            ->expectsQuestion(__('templates.commands.uninstall.confirm_question'), false)
            ->expectsOutput(__('templates.commands.uninstall.aborted'))
            ->assertExitCode(0);

        $this->assertDatabaseHas('templates', [
            'identifier' => $identifier,
        ]);

        // 삭제 확인에서 승인 → 템플릿 삭제
        $templateId = $template->id;
        $this->artisan('template:uninstall', ['identifier' => $identifier])
            ->expectsQuestion(__('templates.commands.uninstall.confirm_question'), true)
            ->expectsOutput('✅ '.__('templates.commands.uninstall.success', ['template' => $identifier]))
            ->assertExitCode(0);

        $this->assertDatabaseMissing('templates', [
            'identifier' => $identifier,
        ]);

        // 레이아웃도 삭제되었는지 확인
        $this->assertEquals(0, TemplateLayout::where('template_id', $templateId)->count());

        // =====================================================================
        // Part 4: list, cache-clear 커맨드 테스트
        // =====================================================================

        // 재설치하여 list, cache-clear 테스트
        $this->artisan('template:install', ['identifier' => $identifier])
            ->assertExitCode(0);

        // list 커맨드 기본 실행
        $this->artisan('template:list')
            ->assertExitCode(0);

        // list 커맨드 타입 필터
        $this->artisan('template:list', ['--type' => 'admin'])
            ->assertExitCode(0);

        // list 커맨드 상태 필터
        $this->artisan('template:list', ['--status' => 'inactive'])
            ->assertExitCode(0);

        // cache-clear 커맨드 (특정 템플릿)
        $this->artisan('template:cache-clear', ['identifier' => $identifier])
            ->expectsOutput(__('templates.commands.cache_clear.clearing_single', ['template' => $identifier]))
            ->assertExitCode(0);

        // 활성화하여 cache-clear 전체 테스트
        $this->artisan('template:activate', ['identifier' => $identifier])
            ->assertExitCode(0);

        // cache-clear 커맨드 (전체)
        $this->artisan('template:cache-clear')
            ->expectsOutput(__('templates.commands.cache_clear.clearing_all'))
            ->assertExitCode(0);

        // 비활성화
        $this->artisan('template:deactivate', ['identifier' => $identifier])
            ->assertExitCode(0);

        // =====================================================================
        // Part 5: check-updates / update 커맨드 테스트
        // =====================================================================

        // check-updates 전체 확인 (설치된 템플릿이 있는 상태)
        $this->artisan('template:check-updates')
            ->assertExitCode(0);

        // check-updates 단일 확인
        $this->artisan('template:check-updates', ['identifier' => $identifier])
            ->assertExitCode(0);

        // update 커맨드 (업데이트 없는 경우 → 최신 상태 메시지)
        $this->artisan('template:update', ['identifier' => $identifier])
            ->assertExitCode(0);

        // 삭제
        $this->artisan('template:uninstall', ['identifier' => $identifier])
            ->expectsQuestion(__('templates.commands.uninstall.confirm_question'), true)
            ->assertExitCode(0);

        // =====================================================================
        // Part 6: 전체 라이프사이클 워크플로우
        // install → activate → deactivate → uninstall
        // =====================================================================

        // Install
        $this->artisan('template:install', ['identifier' => $identifier])
            ->assertExitCode(0);

        $this->assertDatabaseHas('templates', [
            'identifier' => $identifier,
            'status' => ExtensionStatus::Inactive->value,
        ]);

        // Activate
        $this->artisan('template:activate', ['identifier' => $identifier])
            ->assertExitCode(0);

        $this->assertDatabaseHas('templates', [
            'identifier' => $identifier,
            'status' => ExtensionStatus::Active->value,
        ]);

        // Deactivate
        $this->artisan('template:deactivate', ['identifier' => $identifier])
            ->assertExitCode(0);

        $this->assertDatabaseHas('templates', [
            'identifier' => $identifier,
            'status' => ExtensionStatus::Inactive->value,
        ]);

        // Uninstall
        $this->artisan('template:uninstall', ['identifier' => $identifier])
            ->expectsQuestion(__('templates.commands.uninstall.confirm_question'), true)
            ->assertExitCode(0);

        $this->assertDatabaseMissing('templates', [
            'identifier' => $identifier,
        ]);
    }

    /**
     * 잘못된 식별자 형식으로 템플릿 설치 시 FAILURE 반환
     */
    public function test_install_fails_with_invalid_identifier_format(): void
    {
        $this->artisan('template:install', ['identifier' => 'sirsofttemplate'])
            ->assertExitCode(1);
    }

    /**
     * 숫자로 시작하는 단어가 포함된 식별자로 템플릿 설치 시 FAILURE 반환
     */
    public function test_install_fails_with_digit_starting_identifier(): void
    {
        $this->artisan('template:install', ['identifier' => 'sirsoft-2admin'])
            ->assertExitCode(1);
    }

    /**
     * 특수문자가 포함된 식별자로 템플릿 설치 시 FAILURE 반환
     */
    public function test_install_fails_with_special_char_identifier(): void
    {
        $this->artisan('template:install', ['identifier' => 'sirsoft-admin@basic'])
            ->assertExitCode(1);
    }
}
