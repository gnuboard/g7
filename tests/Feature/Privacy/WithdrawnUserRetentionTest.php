<?php

namespace Tests\Feature\Privacy;

use App\Enums\UserStatus;
use App\Models\User;
use App\Privacy\Handlers\WithdrawnUserRetentionHandler;
use App\Repositories\JsonConfigRepository;
use App\Support\ExtensionSettingsMirror;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * 탈퇴 회원 잔여 개인정보 2차 파기 회귀 테스트 (이슈 #636 S4)
 *
 * `User::withdraw()` 는 원값을 접미사로 보존한 채 탈퇴시키므로, 보존기간이 지난 뒤
 * **실제로 지우는** 이 단계가 없으면 "파기했다" 는 말이 사실이 아닙니다.
 *
 * @scenario target=privacy_withdrawn_users, row_age=within_retention, row_age=beyond_retention
 *
 * @effects withdrawn_residual_pii_is_erased_after_retention, withdrawn_erasure_is_idempotent
 */
class WithdrawnUserRetentionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 보유기간 설정을 켭니다.
     *
     * @param  int  $days  보존기간(일)
     * @return void 반환값 없음
     */
    private function enableRetention(int $days = 365): void
    {
        Config::set('g7_settings.core.privacy.withdrawn_retention_enabled', true);
        Config::set('g7_settings.core.privacy.withdrawn_retention_days', $days);
    }

    /**
     * 개인정보가 채워진 탈퇴 회원을 만듭니다.
     *
     * @param  int  $withdrawnDaysAgo  며칠 전에 탈퇴했는지
     * @return User 생성된 회원
     */
    private function createWithdrawnUser(int $withdrawnDaysAgo): User
    {
        return User::factory()->create([
            'status' => UserStatus::Withdrawn->value,
            'withdrawn_at' => now()->subDays($withdrawnDaysAgo),
            'name' => '홍길동_탈퇴_20250101',
            'nickname' => '길동_탈퇴',
            'mobile' => '010-1234-5678',
            'phone' => '02-123-4567',
            'zipcode' => '06234',
            'address' => '서울시 어딘가',
            'address_detail' => '101동 202호',
            'homepage' => 'https://example.test',
            'bio' => '자기소개',
            'signature' => '서명',
            'ip_address' => '203.0.113.7',
            'identity_hash' => str_repeat('a', 64),
            'identity_verified_at' => now()->subYears(3),
            'identity_verified_provider' => 'g7:core.mail',
            'identity_verified_purpose_last' => 'signup',
            'mobile_verified_at' => now()->subYears(3),
        ]);
    }

    /**
     * B1: 꺼져 있으면 아무것도 지우지 않는다 (기본 꺼짐).
     *
     * @return void 반환값 없음
     *
     * @scenario toggle=off, row_age=beyond_retention, run_mode=real_run
     *
     * @effects disabled_handler_is_reported_separately_from_zero_targets
     */
    public function test_disabled_handler_erases_nothing(): void
    {
        $user = $this->createWithdrawnUser(400);

        $handler = app(WithdrawnUserRetentionHandler::class);

        $this->assertFalse($handler->isEnabled(), '기본값은 꺼짐이다');

        $handler->process(dryRun: false);

        $this->assertSame('010-1234-5678', $user->fresh()->mobile);
    }

    /**
     * B2: 보존기간이 지난 탈퇴 회원의 잔여 개인정보를 전부 지운다.
     *
     * 본인인증 파생 컬럼(동일인 식별자)까지 함께 지우는지도 본다 — 연락처만 지우고
     * `identity_hash` 를 남기면 이 기능의 존재 이유가 무너진다.
     *
     * @return void 반환값 없음
     *
     * @scenario toggle=on, row_age=beyond_retention, run_mode=real_run
     *
     * @effects withdrawn_residual_pii_is_erased_after_retention
     */
    public function test_residual_pii_is_erased_after_the_retention_period(): void
    {
        $this->enableRetention(365);
        $user = $this->createWithdrawnUser(400);

        $result = app(WithdrawnUserRetentionHandler::class)->process(dryRun: false);

        $this->assertSame(1, $result['count']);
        $this->assertSame('anonymize', $result['mode']);

        $fresh = $user->fresh();

        foreach ([
            'mobile', 'phone', 'zipcode', 'address', 'address_detail', 'homepage',
            'bio', 'signature', 'ip_address', 'nickname',
            'identity_hash', 'identity_verified_at', 'identity_verified_provider',
            'identity_verified_purpose_last', 'mobile_verified_at',
        ] as $column) {
            $this->assertNull($fresh->{$column}, "{$column} 이 지워지지 않았다");
        }

        $this->assertSame('탈퇴회원', $fresh->name);
        $this->assertSame('withdrawn_'.$user->id.'@removed.invalid', $fresh->email);
    }

    /**
     * B3: 보존기간이 지나지 않은 탈퇴 회원은 건드리지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario toggle=on, row_age=within_retention, run_mode=real_run
     *
     * @effects rows_within_retention_are_kept
     */
    public function test_user_within_retention_is_untouched(): void
    {
        $this->enableRetention(365);
        $user = $this->createWithdrawnUser(10);

        app(WithdrawnUserRetentionHandler::class)->process(dryRun: false);

        $this->assertSame('010-1234-5678', $user->fresh()->mobile);
    }

    /**
     * B4: 탈퇴하지 않은 회원은 대상이 아니다.
     *
     * @return void 반환값 없음
     *
     * @scenario toggle=on, row_age=within_retention, run_mode=real_run
     *
     * @effects rows_within_retention_are_kept
     */
    public function test_active_user_is_never_a_target(): void
    {
        $this->enableRetention(1);
        $user = User::factory()->create([
            'status' => UserStatus::Active->value,
            'mobile' => '010-0000-0000',
            'created_at' => now()->subYears(5),
        ]);

        app(WithdrawnUserRetentionHandler::class)->process(dryRun: false);

        $this->assertSame('010-0000-0000', $user->fresh()->mobile);
    }

    /**
     * B5: 재실행해도 같은 행을 다시 세지 않는다 (멱등).
     *
     * @return void 반환값 없음
     *
     * @scenario toggle=on, row_age=beyond_retention, run_mode=real_run
     *
     * @effects withdrawn_erasure_is_idempotent
     */
    public function test_processing_is_idempotent(): void
    {
        $this->enableRetention(365);
        $this->createWithdrawnUser(400);

        $handler = app(WithdrawnUserRetentionHandler::class);

        $this->assertSame(1, $handler->process(dryRun: false)['count']);
        $this->assertSame(0, $handler->process(dryRun: false)['count'], '두 번째 실행은 0건이어야 한다');
        $this->assertSame(0, $handler->preview(), '미리보기도 0건이어야 한다');
    }

    /**
     * B6: dry-run 건수와 실제 처리 건수가 같다.
     *
     * 어긋나면 운영자가 화면에서 본 숫자와 실제로 지워진 양이 다르다.
     *
     * @return void 반환값 없음
     *
     * @scenario toggle=on, row_age=beyond_retention, run_mode=dry_run
     *
     * @effects dry_run_deletes_nothing
     */
    public function test_dry_run_count_matches_real_run_count(): void
    {
        $this->enableRetention(365);
        $this->createWithdrawnUser(400);
        $this->createWithdrawnUser(500);

        $handler = app(WithdrawnUserRetentionHandler::class);

        $dryRun = $handler->process(dryRun: true);
        $this->assertSame(2, $dryRun['count']);

        $this->assertSame('010-1234-5678', User::query()->where('status', UserStatus::Withdrawn->value)->first()->mobile, 'dry-run 은 아무것도 바꾸지 않는다');

        $this->assertSame(2, $handler->process(dryRun: false)['count']);
    }

    /**
     * B7: 저장된 privacy 설정이 `g7_core_settings()` 경유로 읽힌다.
     *
     * `ExtensionSettingsMirror::CORE_CATEGORIES` 에 'privacy' 가 빠지면 관리자가 토글을 켜고
     * 저장에 성공하고 privacy.json 에 true 가 찍혀도 커맨드는 영원히 기본값을 읽어
     * **"대상 0건" 이라는 정상 출력**을 낸다 — 실패가 아니라 정상처럼 보이므로 이 단언이
     * 없으면 아무도 알아채지 못한다. 그래서 JSON 직독(`getSetting()`)이 아니라 미러 경유로 잰다.
     *
     * @return void 반환값 없음
     *
     * @scenario toggle=on, row_age=beyond_retention, run_mode=dry_run
     *
     * @effects core_settings_mirror_exposes_the_privacy_category
     */
    public function test_saved_privacy_setting_is_visible_through_the_config_mirror(): void
    {
        $this->assertContains('privacy', ExtensionSettingsMirror::CORE_CATEGORIES);

        $repository = new JsonConfigRepository;
        $repository->saveCategory('privacy', [
            'withdrawn_retention_enabled' => true,
            'withdrawn_retention_days' => 30,
        ]);

        app(ExtensionSettingsMirror::class)->refreshCore($repository);

        $this->assertTrue((bool) g7_core_settings('privacy.withdrawn_retention_enabled'));
        $this->assertSame(30, (int) g7_core_settings('privacy.withdrawn_retention_days'));
        $this->assertTrue(app(WithdrawnUserRetentionHandler::class)->isEnabled());
    }
}
