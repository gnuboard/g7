<?php

namespace Tests\Feature\Privacy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 비밀번호 만료 건수·명단 API 테스트 (#736 A10·A13·D22)
 *
 * 건수(`password-expiry-preview`)가 설정 그룹의 `{key}` 와일드카드에 흡수되지 않고, 명단이 건수·배치와
 * **같은 모집단**을 만료가 가까운 순으로 보여 주며, 명단 권한(설정 읽기 + 회원 읽기, 둘 다 전체 범위)이
 * 없으면 버튼 표시값이 false 이고 직접 호출은 403 인지 본다.
 */
class PasswordExpiryTargetApiTest extends TestCase
{
    use PasswordPolicyFixtures;
    use RefreshDatabase;

    /**
     * 건수 API 는 꺼져 있어도 저장값으로 세고, 명단은 같은 모집단을 만료가 가까운 순으로 돌려준다.
     *
     * @scenario stage=notice|expire, toggle=off, actor=full_reader
     *
     * @effects preview_and_targets_share_population
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function preview_and_targets_share_the_population(): void
    {
        $this->enableExpiry(['password_expiry_enabled' => false]);
        $older = $this->makeMember(['password_changed_at' => now()->subDays(120), 'name' => '먼저 만료']);
        $newer = $this->makeAdmin(['password_changed_at' => now()->subDays(100), 'name' => '나중 만료']);
        $this->makeMember(['password_changed_at' => now()->subDays(84)]);
        $viewer = $this->makeLimitedAdmin(['core.settings.read', 'core.users.read']);

        $preview = $this->actingAs($viewer)->getJson('/api/admin/settings/password-expiry-preview')->assertOk();
        $this->assertFalse($preview->json('data.enabled'));
        $this->assertSame(1, $preview->json('data.notice'));
        $this->assertSame(2, $preview->json('data.expire'));
        $this->assertTrue($preview->json('data.can_view_targets'));

        $targets = $this->actingAs($viewer)->getJson('/api/admin/privacy/password-expiry-targets/expire')->assertOk();
        $rows = $targets->json('data.data');
        $this->assertSame([$older->id, $newer->id], array_column($rows, 'id'), '만료가 가까운(기준일이 이른) 순서');
        $this->assertSame(__('user.password_expiry_target.admin'), $rows[1]['context']);
        $this->assertSame('/admin/users/'.$older->uuid, $rows[0]['url']);
    }

    /**
     * 날짜 열 이름은 단계마다 다르다 — 사전 안내 명단은 아직 오지 않은 날이라 「만료 예정일」, 만료 처리 명단은
     * 「만료일」(2026-10-04 결정). 나머지 열(이름·이메일·구분)은 같다.
     *
     * @scenario stage=notice|expire, actor=full_reader
     *
     * @effects target_date_column_label_follows_stage
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_date_column_label_follows_the_stage(): void
    {
        $this->enableExpiry();
        $viewer = $this->makeLimitedAdmin(['core.settings.read', 'core.users.read']);

        $notice = $this->actingAs($viewer)->getJson('/api/admin/privacy/password-expiry-targets/notice')->assertOk();
        $expire = $this->actingAs($viewer)->getJson('/api/admin/privacy/password-expiry-targets/expire')->assertOk();

        $this->assertSame(__('user.password_expiry_target.column_expires_due'), $notice->json('data.columns.date'));
        $this->assertSame(__('user.password_expiry_target.column_expires_at'), $expire->json('data.columns.date'));
        $this->assertNotSame($notice->json('data.columns.date'), $expire->json('data.columns.date'));
        $this->assertSame(__('user.password_expiry_target.column_type'), $notice->json('data.columns.context'));
    }

    /**
     * 설정 읽기만 있으면 명단 버튼 값이 false 이고 직접 호출은 403, 회원 읽기가 부분 범위여도 403 이다.
     *
     * @scenario actor=settings_reader|scoped_users_reader
     *
     * @effects targets_require_full_read_permissions
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_target_list_requires_full_read_permissions(): void
    {
        $this->enableExpiry();
        $settingsOnly = $this->makeLimitedAdmin(['core.settings.read']);

        $this->actingAs($settingsOnly)->getJson('/api/admin/settings/password-expiry-preview')
            ->assertOk()
            ->assertJsonPath('data.can_view_targets', false);
        $this->actingAs($settingsOnly)->getJson('/api/admin/privacy/password-expiry-targets/notice')->assertForbidden();

        $scoped = $this->makeLimitedAdmin(['core.settings.read', 'core.users.read'], ['core.users.read' => 'self']);
        $this->actingAs($scoped)->getJson('/api/admin/privacy/password-expiry-targets/notice')->assertForbidden();
    }

    /**
     * 모르는 단계는 404 다.
     *
     * @scenario stage=unknown
     *
     * @effects unknown_stage_is_404
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function an_unknown_stage_is_404(): void
    {
        $viewer = $this->makeLimitedAdmin(['core.settings.read', 'core.users.read']);

        $this->actingAs($viewer)->getJson('/api/admin/privacy/password-expiry-targets/purge')->assertNotFound();
    }
}
