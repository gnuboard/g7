<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

use App\Contracts\Repositories\NotificationDefinitionRepositoryInterface;
use App\Models\NotificationDefinition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Board\Services\BoardPermissionService;
use Modules\Sirsoft\Board\Services\BoardSettingsService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 단건 설정 저장(setSetting)의 파이프라인 경유 / 캐시 정합 테스트 (공개 #114 동종)
 *
 * 이커머스와 같은 결함에 더해 한 단계 더 나쁜 상태였다 — 저장 후 자기 캐시(`$settings`)를
 * 비우지 않아, 같은 요청 안에서 곧이은 조회가 저장 전 값을 반환했다. 기존 테스트는
 * `clearCache()` 를 수동으로 끼워 넣어 그 결함을 우회하고 있었다.
 *
 * 벌크 저장의 boolean backfill 은 폼 Toggle-OFF 미전송 대응이라 단건 저장에 그대로 태우면
 * 안 된다 — 제출하지 않은 boolean 이 전부 false 로 박제되어 기본값 true 인 항목이 뒤집힌다.
 * 그래서 공통 본문만 공유하고 backfill 여부만 갈라 놓는다.
 */
class BoardSetSettingPipelineTest extends ModuleTestCase
{
    private BoardSettingsService $service;

    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $permissionService = $this->createMock(BoardPermissionService::class);
        $notificationDefinitionRepository = $this->createMock(NotificationDefinitionRepositoryInterface::class);
        $notificationDefinitionRepository->method('getByExtension')->willReturn(new Collection);

        $this->service = new BoardSettingsService($permissionService, $notificationDefinitionRepository);
        $this->storagePath = storage_path('framework/testing/modules/sirsoft-board/settings');

        if (File::isDirectory($this->storagePath)) {
            File::deleteDirectory($this->storagePath);
        }
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->storagePath)) {
            File::deleteDirectory($this->storagePath);
        }

        parent::tearDown();
    }

    /**
     * 카테고리 저장 파일을 그대로 읽습니다.
     *
     * @param  string  $category  카테고리명
     * @return array 저장 파일의 디코드 결과 (파일 부재 시 빈 배열)
     */
    private function savedFile(string $category): array
    {
        $path = $this->storagePath.'/'.$category.'.json';

        if (! File::exists($path)) {
            return [];
        }

        return json_decode(File::get($path), true) ?? [];
    }

    /**
     * 단건 저장 직후 같은 인스턴스의 조회가 신값을 반환한다. (실패-먼저)
     *
     * @scenario save_path=single_key
     *
     * @effects single_key_save_invalidates_service_cache
     */
    public function test_single_key_save_invalidates_cache_without_manual_clear(): void
    {
        // 조회로 캐시를 채운 뒤 저장
        $this->assertNotSame(31, $this->service->getSetting('basic_defaults.per_page'));

        $this->service->setSetting('basic_defaults.per_page', 31);

        $this->assertSame(
            31,
            $this->service->getSetting('basic_defaults.per_page'),
            '저장 후에도 조회가 저장 전 값을 반환했습니다 (캐시 무효화 누락).'
        );
    }

    /**
     * 단건 저장은 미제출 boolean 을 false 로 박제하지 않는다.
     *
     * 벌크 저장의 backfill 은 폼 전체 제출을 전제로 한 보정이므로, 부분 저장에 태우면
     * 기본값 true 인 항목이 통째로 뒤집힌다.
     *
     * @scenario save_path=single_key
     *
     * @effects single_key_save_does_not_backfill_booleans
     */
    public function test_single_key_save_does_not_backfill_unsubmitted_booleans(): void
    {
        $this->service->setSetting('basic_defaults.per_page', 25);

        $saved = $this->savedFile('basic_defaults');

        $this->assertArrayNotHasKey(
            'use_comment',
            $saved,
            '제출하지 않은 boolean 이 단건 저장으로 박제되었습니다.'
        );
        $this->assertTrue(
            $this->service->getSetting('basic_defaults.use_comment'),
            '기본값 true 인 boolean 설정이 단건 저장으로 뒤집혔습니다.'
        );
    }

    /**
     * 벌크 저장의 boolean backfill 은 그대로 유지된다. (비회귀 pin)
     *
     * @scenario save_path=bulk
     *
     * @effects bulk_save_backfills_unsubmitted_booleans
     */
    public function test_bulk_save_still_backfills_unsubmitted_booleans(): void
    {
        $this->service->saveSettings(['basic_defaults' => ['per_page' => 25]]);

        $saved = $this->savedFile('basic_defaults');

        $this->assertArrayHasKey('use_comment', $saved, '벌크 저장의 boolean backfill 이 사라졌습니다.');
        $this->assertFalse($saved['use_comment']);
    }

    /**
     * 신고 정책 단건 저장도 알림 강제 활성 규칙을 거친다. (실패-먼저)
     *
     * @scenario save_path=single_key
     *
     * @effects report_notification_flags_forced_on_single_key_save
     */
    public function test_single_key_save_forces_report_notification_flags(): void
    {
        $this->service->setSetting('report_policy', [
            'auto_hide_threshold' => 7,
            'notify_admin_on_report' => false,
            'notify_author_on_report_action' => false,
        ]);

        $saved = $this->savedFile('report_policy');

        $this->assertTrue(
            $saved['notify_admin_on_report'] ?? false,
            '신고 알림 강제 활성 규칙이 단건 저장 경로에서 적용되지 않았습니다.'
        );
        $this->assertTrue($saved['notify_author_on_report_action'] ?? false);
        $this->assertSame(7, $saved['auto_hide_threshold'] ?? null);
    }

    /**
     * 신고 정책 단건 저장은 알림 정의의 활성 상태까지 동기화한다.
     *
     * 저장 파일만 보면 강제 활성 규칙이 통과한 것처럼 보인다. 그러나 알림 정의가 비활성인
     * 채로 남으면 `NotificationHookListener` 가 훅을 구독하지 않아 설정은 켜져 있는데
     * 알림만 오지 않는다 — 화면에도 로그에도 흔적이 없다.
     *
     * @scenario save_path=single_key
     *
     * @effects report_notification_flags_forced_on_single_key_save
     */
    public function test_single_key_save_syncs_notification_definition_status(): void
    {
        $definition = new NotificationDefinition(['type' => 'report_received_admin']);
        $definition->is_active = false;

        $repository = $this->createMock(NotificationDefinitionRepositoryInterface::class);
        $repository->method('getByExtension')->willReturn(new Collection([$definition]));
        $repository->expects($this->once())
            ->method('update')
            ->with($definition, ['is_active' => true]);

        $service = new BoardSettingsService($this->createMock(BoardPermissionService::class), $repository);

        $service->setSetting('report_policy', [
            'auto_hide_threshold' => 7,
            'notify_admin_on_report' => false,
        ]);
    }

    /**
     * 이미 원하는 상태인 알림 정의는 다시 쓰지 않는다. (불필요 쓰기 차단)
     *
     * @scenario save_path=single_key
     *
     * @effects report_notification_flags_forced_on_single_key_save
     */
    public function test_single_key_save_skips_notification_definition_update_when_already_active(): void
    {
        $definition = new NotificationDefinition(['type' => 'report_received_admin']);
        $definition->is_active = true;

        $repository = $this->createMock(NotificationDefinitionRepositoryInterface::class);
        $repository->method('getByExtension')->willReturn(new Collection([$definition]));
        $repository->expects($this->never())->method('update');

        $service = new BoardSettingsService($this->createMock(BoardPermissionService::class), $repository);

        $service->setSetting('report_policy', [
            'auto_hide_threshold' => 7,
            'notify_admin_on_report' => true,
        ]);
    }

    /**
     * 단건 저장도 defaults 스키마 기준 숫자 정규화를 거친다. (실패-먼저)
     *
     * @scenario save_path=single_key
     *
     * @effects numeric_string_normalized_on_single_key_save
     */
    public function test_single_key_save_normalizes_numeric_strings(): void
    {
        $this->service->setSetting('basic_defaults.per_page', '42');

        $saved = $this->savedFile('basic_defaults');

        $this->assertSame(42, $saved['per_page'] ?? null, '숫자 문자열이 정규화되지 않고 저장되었습니다.');
    }

    /**
     * 테스트 실행 중에는 운영 설정 경로를 쓰지 않는다. (실패-먼저)
     *
     * @effects board_settings_storage_isolated_in_tests
     */
    public function test_storage_path_is_isolated_during_tests(): void
    {
        // audit:allow extension-storage-path-hand-assembled 운영 경로를 **의도적으로** 가리킨다 —
        // 이 단언의 대상은 "테스트가 운영 파일을 건드리지 않았는가" 이므로 해석기를 쓰면 검사가 성립하지 않는다.
        $productionPath = storage_path('app/modules/sirsoft-board/settings/basic_defaults.json');
        // 운영 파일의 존재 여부·내용을 그대로 스냅샷 (환경마다 상태가 다르므로 변화 없음만 단언)
        $before = File::exists($productionPath) ? File::get($productionPath) : null;

        $this->service->setSetting('basic_defaults.per_page', 33);

        $this->assertFileExists(
            $this->storagePath.'/basic_defaults.json',
            '테스트 격리 경로에 저장되지 않았습니다.'
        );

        $after = File::exists($productionPath) ? File::get($productionPath) : null;
        $this->assertSame($before, $after, '테스트가 운영 설정 파일을 생성/변경했습니다.');
    }
}
