<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Privacy;

use App\Models\User;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 게시판 개인정보 보유기간 설정·미리보기 API 테스트 (이슈 #636 S5)
 *
 * 설정 저장 경로(FormRequest 5곳 — 정수·불리언 캐스팅, 규칙, 카테고리 화이트리스트)와
 * 대상 건수 미리보기 API 의 「꺼짐 = null」 계약을 고정합니다. 카테고리 화이트리스트에서
 * 빠지면 저장이 200 으로 성공하고도 값이 조용히 버려진다.
 */
class BoardPrivacyRetentionSettingsTest extends ModuleTestCase
{
    /** 설정 권한을 가진 관리자 */
    private User $admin;

    /** 설정 저장 경로 */
    private string $settingsPath;

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser([
            'sirsoft-board.settings.read',
            'sirsoft-board.settings.update',
        ]);

        $this->settingsPath = ExtensionStoragePath::module('sirsoft-board', 'settings');

        if (File::isDirectory($this->settingsPath)) {
            File::deleteDirectory($this->settingsPath);
        }
    }

    /**
     * 테스트가 저장한 설정 파일을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (File::isDirectory($this->settingsPath)) {
            File::deleteDirectory($this->settingsPath);
        }

        parent::tearDown();
    }

    /**
     * 기본값은 꺼짐·IP 365일·비회원 미적용·익명화이며 조회 응답에 한계값이 실린다.
     *
     * @return void 반환값 없음
     *
     * @scenario axis=guest, toggle=off, guest_mode=anonymize
     *
     * @effects board_retention_is_off_by_default
     */
    #[Test]
    public function defaults_are_off_and_limits_are_exposed(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/modules/sirsoft-board/admin/settings');

        $response->assertOk()
            ->assertJsonPath('data.privacy_retention.enabled', false)
            ->assertJsonPath('data.privacy_retention.ip_retention_days', 365)
            ->assertJsonPath('data.privacy_retention.guest_retention_days', 0)
            ->assertJsonPath('data.privacy_retention.guest_mode', 'anonymize')
            ->assertJsonPath('data._meta.limits.privacy_ip_retention_days_min', 1)
            ->assertJsonPath('data._meta.limits.privacy_guest_retention_days_min', 0);
    }

    /**
     * 「개인정보 보유기간」 탭 저장(`_tab=privacy_retention`)이 보유기간 값을 정수·불리언으로 저장하고
     * 다시 읽힌다. 탭 이름이 `_tab` 허용 목록에서 빠지면 이 탭의 저장이 422 로 막힌다.
     *
     * @return void 반환값 없음
     *
     * @scenario axis=guest, toggle=on, guest_mode=delete
     *
     * @effects board_retention_settings_are_saved
     */
    #[Test]
    public function privacy_tab_save_persists_retention_values_with_types(): void
    {
        $this->actingAs($this->admin)->putJson('/api/modules/sirsoft-board/admin/settings', [
            '_tab' => 'privacy_retention',
            'privacy_retention' => [
                'enabled' => '1',
                'ip_retention_days' => '400',
                'guest_retention_days' => '90',
                'guest_mode' => 'delete',
            ],
        ])->assertOk();

        $saved = $this->actingAs($this->admin)->getJson('/api/modules/sirsoft-board/admin/settings/privacy_retention');

        $saved->assertOk();
        $this->assertSame(true, $saved->json('data.settings.enabled'));
        $this->assertSame(400, $saved->json('data.settings.ip_retention_days'));
        $this->assertSame(90, $saved->json('data.settings.guest_retention_days'));
        $this->assertSame('delete', $saved->json('data.settings.guest_mode'));
    }

    /**
     * 하한·모드 위반은 422 — 되돌릴 수 없는 파기라 서버가 강제한다.
     *
     * @return void 반환값 없음
     *
     * @scenario axis=guest, toggle=on, guest_mode=delete
     *
     * @effects board_retention_settings_are_validated
     */
    #[Test]
    public function invalid_retention_values_are_rejected(): void
    {
        $this->actingAs($this->admin)->putJson('/api/modules/sirsoft-board/admin/settings', [
            '_tab' => 'privacy_retention',
            'privacy_retention' => [
                'enabled' => true,
                'ip_retention_days' => 0,
                'guest_retention_days' => -1,
                'guest_mode' => 'purge',
            ],
        ])->assertStatus(422)->assertJsonValidationErrors([
            'privacy_retention.ip_retention_days',
            'privacy_retention.guest_retention_days',
            'privacy_retention.guest_mode',
        ]);
    }

    /**
     * 미리보기 API: 꺼진 축도 건수를 세고(켜기 전에 규모를 본다) 꺼짐은 enabled 로 구분한다.
     * 비회원 보존기간 0(미적용)이면 비회원 건수는 0 이다. 명단 권한은 게시판 관리 읽기까지 있어야
     * 참이다. 권한 없는 사용자는 403.
     *
     * @return void 반환값 없음
     *
     * @scenario axis=guest, toggle=on, guest_mode=delete
     *
     * @effects board_retention_preview_api_counts_while_off
     */
    #[Test]
    public function preview_api_distinguishes_disabled_from_zero(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/modules/sirsoft-board/admin/settings/privacy-retention-preview')
            ->assertOk()
            ->assertJsonPath('data.ip_enabled', false)
            ->assertJsonPath('data.guest_enabled', false)
            ->assertJsonPath('data.guest', 0)
            ->assertJsonPath('data.guest_mode', 'anonymize')
            ->assertJsonPath('data.can_view_targets', false);

        $this->assertIsInt($response->json('data.ip'), '꺼져 있어도 IP 축 건수를 센다');

        $this->actingAs($this->admin)->putJson('/api/modules/sirsoft-board/admin/settings', [
            '_tab' => 'privacy_retention',
            'privacy_retention' => ['enabled' => true, 'ip_retention_days' => 365, 'guest_retention_days' => 30, 'guest_mode' => 'anonymize'],
        ])->assertOk();

        $viewer = $this->createAdminUser(['sirsoft-board.settings.read', 'sirsoft-board.boards.read']);
        $response = $this->actingAs($viewer)
            ->getJson('/api/modules/sirsoft-board/admin/settings/privacy-retention-preview')
            ->assertOk()
            ->assertJsonPath('data.ip_enabled', true)
            ->assertJsonPath('data.guest_enabled', true)
            ->assertJsonPath('data.can_view_targets', true);

        $this->assertIsInt($response->json('data.ip'));
        $this->assertIsInt($response->json('data.guest'));

        $this->actingAs($this->createUser())
            ->getJson('/api/modules/sirsoft-board/admin/settings/privacy-retention-preview')
            ->assertStatus(403);
    }
}
