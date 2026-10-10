<?php

namespace Tests\Feature\Api\Admin;

use App\Extension\HookManager;
use App\Http\Requests\User\UserListRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\CountsQueries;
use Tests\TestCase;

/**
 * 관리자 회원 목록 필터 12축 테스트 (#705)
 *
 * 회원관리 목록이 검색어 한 줄만 받던 것을 계정 상태·권한 그룹·관리자 여부·잠금·가입일·최근 로그인·
 * 휴면 전환일·상태 변경일·언어·국가·이메일 인증·본인인증 12축으로 넓혔다. 각 축을 단독·조합·경계·
 * 무효값(422)으로 찌른다.
 *
 * 로컬 MySQL 은 migrate:fresh 의 인덱스 DDL 에서 OOM 이 나므로 트랜잭션 격리를 쓴다 — 기존 행과
 * 공존하므로 이 테스트가 만든 회원만 보도록 모든 요청에 이메일 표식(`@lf{고유값}.test`) 검색을 건다.
 */
#[Group('core')]
#[Group('admin')]
#[Group('user')]
class UserControllerListFilterTest extends TestCase
{
    use CountsQueries;
    use DatabaseTransactions;

    private User $admin;

    private string $marker;

    /**
     * 목록 권한을 가진 운영자와 이메일 표식을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->marker = '@lf'.Str::lower(Str::random(10)).'.test';
        $this->admin = $this->createOperator(['core.users.read'], 'lf-admin');

        // 날짜 칸은 보는 사람의 시간대 하루로 해석된다 — 아래 경계 테스트들은 저장값(UTC)으로 경계를 적으므로
        // 운영자 시간대를 UTC 로 둔다. 시간대 해석 자체는 test_date_ranges_follow_viewer_timezone_day 가 잠근다.
        $this->admin->forceFill(['timezone' => 'UTC'])->saveQuietly();
    }

    // ==========================================
    // 계정 상태 (status[])
    // ==========================================

    /**
     * 계정 상태 하나를 고르면 그 상태 회원만 남는다.
     *
     * @scenario filter_axis=status, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_status_single_value_narrows_to_that_status(): void
    {
        $blocked = $this->member(['status' => 'blocked']);
        $this->member(['status' => 'active']);

        $this->assertListed([$blocked], ['status' => ['blocked']]);
    }

    /**
     * 계정 상태 여럿은 OR 로 묶인다.
     *
     * @scenario filter_axis=status, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_status_multiple_values_are_or_combined(): void
    {
        $blocked = $this->member(['status' => 'blocked']);
        $dormant = $this->member(['status' => 'dormant']);
        $this->member(['status' => 'active']);

        $this->assertListed([$blocked, $dormant], ['status' => ['blocked', 'dormant']]);
    }

    /**
     * 여섯 상태를 모두 고르면 조건이 없는 것과 같다.
     *
     * @scenario filter_axis=status, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_status_all_six_selected_equals_no_condition(): void
    {
        $users = collect(['active', 'inactive', 'blocked', 'withdrawn', 'pending_verification', 'dormant'])
            ->map(fn ($status) => $this->member(['status' => $status]))
            ->all();

        $this->assertListed($users, ['status' => ['active', 'inactive', 'blocked', 'withdrawn', 'pending_verification', 'dormant']]);
        $this->assertListed($users, []);
    }

    /**
     * 「전체」가 떨어뜨린 빈 원소는 조건 없음으로 본다.
     *
     * @scenario filter_axis=status, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_status_empty_element_means_all(): void
    {
        $a = $this->member(['status' => 'active']);
        $b = $this->member(['status' => 'blocked']);

        $this->assertListed([$a, $b], ['status' => ['']]);
    }

    /**
     * Enum 밖 상태값은 422 로 거부한다.
     *
     * @scenario filter_axis=status, outcome=rejected
     *
     * @effects admin_user_list_filter_invalid_values_rejected
     *
     * @return void 반환값 없음
     */
    public function test_status_invalid_value_is_rejected(): void
    {
        $this->list(['status' => ['bogus']])->assertStatus(422)->assertJsonValidationErrors(['status.0']);
    }

    // ==========================================
    // 권한 그룹 (role_ids[])
    // ==========================================

    /**
     * 권한 그룹 여럿 중 하나라도 가진 회원이 남는다.
     *
     * @scenario filter_axis=role, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_role_filter_keeps_members_of_any_selected_role(): void
    {
        $roleA = $this->role('lf-role-a');
        $roleB = $this->role('lf-role-b');
        $roleC = $this->role('lf-role-c');

        $inA = $this->member();
        $inA->roles()->attach($roleA->id);
        $inB = $this->member();
        $inB->roles()->attach($roleB->id);
        $inC = $this->member();
        $inC->roles()->attach($roleC->id);
        $this->member();

        $this->assertListed([$inA], ['role_ids' => [$roleA->id]]);
        $this->assertListed([$inA, $inB], ['role_ids' => [$roleA->id, $roleB->id]]);
    }

    /**
     * 권한 그룹과 계정 상태는 AND 로 묶인다.
     *
     * @scenario filter_axis=role, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_role_filter_combined_with_status(): void
    {
        $role = $this->role('lf-role-combo');
        $activeInRole = $this->member(['status' => 'active']);
        $activeInRole->roles()->attach($role->id);
        $blockedInRole = $this->member(['status' => 'blocked']);
        $blockedInRole->roles()->attach($role->id);

        $this->assertListed([$blockedInRole], ['role_ids' => [$role->id], 'status' => ['blocked']]);
    }

    /**
     * 존재하지 않는 역할 id 는 422 로 거부한다.
     *
     * @scenario filter_axis=role, outcome=rejected
     *
     * @effects admin_user_list_filter_invalid_values_rejected
     *
     * @return void 반환값 없음
     */
    public function test_role_filter_unknown_role_is_rejected(): void
    {
        $this->list(['role_ids' => [999999999]])->assertStatus(422)->assertJsonValidationErrors(['role_ids.0']);
    }

    // ==========================================
    // 관리자 여부 (admin_scope)
    // ==========================================

    /**
     * 관리자 여부 — 슈퍼관리자·admin 권한 역할 보유자와 그 밖을 가른다.
     *
     * @scenario filter_axis=admin_scope, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_admin_scope_splits_admins_and_members(): void
    {
        $adminRole = $this->role('lf-admin-role', 'admin');
        $userRole = $this->role('lf-user-role', 'user');

        $viaRole = $this->member();
        $viaRole->roles()->attach($adminRole->id);
        $super = $this->member(['is_super' => true]);
        $plain = $this->member();
        $plain->roles()->attach($userRole->id);
        $bare = $this->member();

        $this->assertListed([$viaRole, $super], ['admin_scope' => 'admin']);
        $this->assertListed([$plain, $bare], ['admin_scope' => 'member']);
        $this->assertListed([$viaRole, $super, $plain, $bare], ['admin_scope' => 'all']);
    }

    /**
     * 관리자 여부 허용값 밖은 422 로 거부한다.
     *
     * @scenario filter_axis=admin_scope, outcome=rejected
     *
     * @effects admin_user_list_filter_invalid_values_rejected
     *
     * @return void 반환값 없음
     */
    public function test_admin_scope_invalid_value_is_rejected(): void
    {
        $this->list(['admin_scope' => 'root'])->assertStatus(422)->assertJsonValidationErrors(['admin_scope']);
    }

    // ==========================================
    // 계정 잠금 (lock_state)
    // ==========================================

    /**
     * 잠김 = 영구 잠금 또는 해제 시각이 미래. 지난 잠금은 정상이다(경계).
     *
     * @scenario filter_axis=lock_state, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_lock_state_locked_includes_permanent_and_future_lock(): void
    {
        $permanent = $this->member(['locked_permanently' => true]);
        $temporary = $this->member(['locked_until' => now()->addHour()]);
        $expired = $this->member(['locked_until' => now()->subMinute()]);
        $clean = $this->member();

        $this->assertListed([$permanent, $temporary], ['lock_state' => 'locked']);
        // 경계: 해제 시각이 지난 잠금은 정상으로 본다.
        $this->assertListed([$expired, $clean], ['lock_state' => 'unlocked']);
    }

    /**
     * 잠금 조건 허용값 밖은 422 로 거부한다.
     *
     * @scenario filter_axis=lock_state, outcome=rejected
     *
     * @effects admin_user_list_filter_invalid_values_rejected
     *
     * @return void 반환값 없음
     */
    public function test_lock_state_invalid_value_is_rejected(): void
    {
        $this->list(['lock_state' => 'frozen'])->assertStatus(422)->assertJsonValidationErrors(['lock_state']);
    }

    // ==========================================
    // 가입일 (start_date / end_date)
    // ==========================================

    /**
     * 가입일 범위는 시작일 0시부터 종료일 하루 끝까지 포함한다(경계).
     *
     * @scenario filter_axis=created_at, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_created_at_range_includes_end_day_whole(): void
    {
        $inside = $this->member(['created_at' => '2026-03-10 23:59:59']);
        $startEdge = $this->member(['created_at' => '2026-03-01 00:00:00']);
        $this->member(['created_at' => '2026-02-28 23:59:59']);
        $this->member(['created_at' => '2026-03-11 00:00:00']);

        $this->assertListed([$inside, $startEdge], ['start_date' => '2026-03-01', 'end_date' => '2026-03-10']);
    }

    /**
     * 가입일 종료가 시작보다 앞서면 422 로 거부한다.
     *
     * @scenario filter_axis=created_at, outcome=rejected
     *
     * @effects admin_user_list_filter_invalid_values_rejected
     *
     * @return void 반환값 없음
     */
    public function test_created_at_end_before_start_is_rejected(): void
    {
        $this->list(['start_date' => '2026-03-10', 'end_date' => '2026-03-01'])
            ->assertStatus(422)->assertJsonValidationErrors(['end_date']);
    }

    // ==========================================
    // 최근 로그인 (last_login_start / last_login_end / never_logged_in)
    // ==========================================

    /**
     * 최근 로그인 기간과 「로그인 이력 없음」 조건을 가른다.
     *
     * @scenario filter_axis=last_login, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_last_login_range_and_never_logged_in(): void
    {
        $recent = $this->member(['last_login_at' => '2026-05-15 12:00:00']);
        $old = $this->member(['last_login_at' => '2025-01-01 12:00:00']);
        $never = $this->member(['last_login_at' => null]);

        $this->assertListed([$recent], ['last_login_start' => '2026-05-01', 'last_login_end' => '2026-05-31']);
        $this->assertListed([$never], ['never_logged_in' => 'true']);
        // 「로그인 이력 없음」을 켜면 기간은 무시된다(둘은 동시에 참일 수 없다).
        $this->assertListed([$never], ['never_logged_in' => 'true', 'last_login_start' => '2026-05-01']);
        $this->assertListed([$recent, $old, $never], ['never_logged_in' => 'false']);
    }

    /**
     * 최근 로그인 종료가 시작보다 앞서면 422 로 거부한다.
     *
     * @scenario filter_axis=last_login, outcome=rejected
     *
     * @effects admin_user_list_filter_invalid_values_rejected
     *
     * @return void 반환값 없음
     */
    public function test_last_login_end_before_start_is_rejected(): void
    {
        $this->list(['last_login_start' => '2026-05-10', 'last_login_end' => '2026-05-01'])
            ->assertStatus(422)->assertJsonValidationErrors(['last_login_end']);
    }

    /**
     * 로그인 이력 없음은 참/거짓만 받는다.
     *
     * @scenario filter_axis=last_login, outcome=rejected
     *
     * @effects admin_user_list_filter_invalid_values_rejected
     *
     * @return void 반환값 없음
     */
    public function test_never_logged_in_invalid_value_is_rejected(): void
    {
        $this->list(['never_logged_in' => 'maybe'])->assertStatus(422)->assertJsonValidationErrors(['never_logged_in']);
    }

    // ==========================================
    // 휴면 전환일 (dormant_start / dormant_end)
    // ==========================================

    /**
     * 휴면 전환일 범위로 거른다.
     *
     * @scenario filter_axis=dormant_at, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_dormant_range(): void
    {
        $dormant = $this->member(['status' => 'dormant', 'dormant_at' => '2026-06-15 08:00:00']);
        $this->member(['status' => 'dormant', 'dormant_at' => '2026-01-15 08:00:00']);
        $this->member(['dormant_at' => null]);

        $this->assertListed([$dormant], ['dormant_start' => '2026-06-01', 'dormant_end' => '2026-06-30']);
    }

    /**
     * 휴면 전환일이 날짜가 아니면 422 로 거부한다.
     *
     * @scenario filter_axis=dormant_at, outcome=rejected
     *
     * @effects admin_user_list_filter_invalid_values_rejected
     *
     * @return void 반환값 없음
     */
    public function test_dormant_invalid_date_is_rejected(): void
    {
        $this->list(['dormant_start' => 'not-a-date'])->assertStatus(422)->assertJsonValidationErrors(['dormant_start']);
    }

    // ==========================================
    // 상태 변경일 (status_changed_type / _start / _end)
    // ==========================================

    /**
     * 상태 변경일 — 종류(탈퇴/차단)에 따라 다른 칸을 보고, 날짜가 없으면 그 일이 있었던 회원 전부다.
     *
     * @scenario filter_axis=status_changed, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_status_changed_type_with_and_without_dates(): void
    {
        $withdrawnJune = $this->member(['status' => 'withdrawn', 'withdrawn_at' => '2026-06-10 10:00:00']);
        $withdrawnJan = $this->member(['status' => 'withdrawn', 'withdrawn_at' => '2026-01-10 10:00:00']);
        $blockedJune = $this->member(['status' => 'blocked', 'blocked_at' => '2026-06-10 10:00:00']);

        $this->assertListed([$withdrawnJune], ['status_changed_type' => 'withdrawn', 'status_changed_start' => '2026-06-01', 'status_changed_end' => '2026-06-30']);
        $this->assertListed([$blockedJune], ['status_changed_type' => 'blocked', 'status_changed_start' => '2026-06-01']);
        // 종류만 고르면 그 일이 있었던 회원 전부.
        $this->assertListed([$withdrawnJune, $withdrawnJan], ['status_changed_type' => 'withdrawn']);
    }

    /**
     * 상태 변경 종류 허용값 밖은 422 로 거부한다.
     *
     * @scenario filter_axis=status_changed, outcome=rejected
     *
     * @effects admin_user_list_filter_invalid_values_rejected
     *
     * @return void 반환값 없음
     */
    public function test_status_changed_type_invalid_value_is_rejected(): void
    {
        $this->list(['status_changed_type' => 'deleted'])->assertStatus(422)->assertJsonValidationErrors(['status_changed_type']);
    }

    // ==========================================
    // 언어 · 국가
    // ==========================================

    /**
     * 언어로 거른다.
     *
     * @scenario filter_axis=language, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_language_filter(): void
    {
        $en = $this->member(['language' => 'en']);
        $this->member(['language' => 'ko']);

        $this->assertListed([$en], ['language' => 'en']);
    }

    /**
     * 지원하지 않는 언어는 422 로 거부한다.
     *
     * @scenario filter_axis=language, outcome=rejected
     *
     * @effects admin_user_list_filter_invalid_values_rejected
     *
     * @return void 반환값 없음
     */
    public function test_language_unsupported_is_rejected(): void
    {
        $this->list(['language' => 'xx'])->assertStatus(422)->assertJsonValidationErrors(['language']);
    }

    /**
     * 국가 코드는 대소문자와 무관하게 거른다.
     *
     * @scenario filter_axis=country, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_country_filter_is_case_insensitive(): void
    {
        $kr = $this->member(['country' => 'KR']);
        $this->member(['country' => 'US']);
        $this->member(['country' => null]);

        $this->assertListed([$kr], ['country' => 'KR']);
        $this->assertListed([$kr], ['country' => 'kr']);
    }

    /**
     * 국가 코드가 영문 2자리가 아니면 422 로 거부한다.
     *
     * @scenario filter_axis=country, outcome=rejected
     *
     * @effects admin_user_list_filter_invalid_values_rejected
     *
     * @return void 반환값 없음
     */
    public function test_country_invalid_format_is_rejected(): void
    {
        $this->list(['country' => 'KOR'])->assertStatus(422)->assertJsonValidationErrors(['country']);
        $this->list(['country' => 'K1'])->assertStatus(422)->assertJsonValidationErrors(['country']);
    }

    /**
     * 언어와 국가는 AND 로 묶인다.
     *
     * @scenario filter_axis=country, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_language_and_country_combined(): void
    {
        $match = $this->member(['language' => 'en', 'country' => 'US']);
        $this->member(['language' => 'en', 'country' => 'KR']);
        $this->member(['language' => 'ko', 'country' => 'US']);

        $this->assertListed([$match], ['language' => 'en', 'country' => 'US']);
    }

    // ==========================================
    // 이메일 인증 · 본인인증
    // ==========================================

    /**
     * 이메일 인증 완료/미완료/전체를 가른다.
     *
     * @scenario filter_axis=email_verified, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_email_verified_filter(): void
    {
        $verified = $this->member(['email_verified_at' => now()]);
        $unverified = $this->member(['email_verified_at' => null]);

        $this->assertListed([$verified], ['email_verified' => 'verified']);
        $this->assertListed([$unverified], ['email_verified' => 'unverified']);
        $this->assertListed([$verified, $unverified], ['email_verified' => 'all']);
    }

    /**
     * 본인인증 조건은 이메일 인증 조건과 AND 로 묶인다.
     *
     * @scenario filter_axis=identity_verified, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_identity_verified_filter_combined_with_email(): void
    {
        $both = $this->member(['email_verified_at' => now(), 'identity_verified_at' => now()]);
        $emailOnly = $this->member(['email_verified_at' => now(), 'identity_verified_at' => null]);

        $this->assertListed([$both], ['identity_verified' => 'verified']);
        $this->assertListed([$emailOnly], ['identity_verified' => 'unverified', 'email_verified' => 'verified']);
    }

    /**
     * 이메일 인증 여부 허용값 밖은 422 로 거부한다.
     *
     * @scenario filter_axis=email_verified, outcome=rejected
     *
     * @effects admin_user_list_filter_invalid_values_rejected
     *
     * @return void 반환값 없음
     */
    public function test_email_verified_invalid_value_is_rejected(): void
    {
        $this->list(['email_verified' => 'yes'])->assertStatus(422)->assertJsonValidationErrors(['email_verified']);
    }

    /**
     * 본인인증 여부 허용값 밖은 422 로 거부한다.
     *
     * @scenario filter_axis=identity_verified, outcome=rejected
     *
     * @effects admin_user_list_filter_invalid_values_rejected
     *
     * @return void 반환값 없음
     */
    public function test_identity_verified_invalid_value_is_rejected(): void
    {
        $this->list(['identity_verified' => 'yes'])->assertStatus(422)->assertJsonValidationErrors(['identity_verified']);
    }

    // ==========================================
    // 응답 형태 · 메시지 · 조합
    // ==========================================

    /**
     * 422 메시지는 번역 키가 아니라 번역된 문장이다.
     *
     * @scenario filter_axis=status, outcome=rejected
     *
     * @effects admin_user_list_filter_invalid_values_rejected
     *
     * @return void 반환값 없음
     */
    public function test_validation_message_is_localized_not_raw_key(): void
    {
        app()->setLocale('ko');

        $response = $this->list(['status' => ['bogus']])->assertStatus(422);

        $message = $response->json('errors')['status.0'][0] ?? '';
        $this->assertSame(__('user.validation.list_status_invalid'), $message);
        $this->assertStringNotContainsString('user.validation', $message);
    }

    /**
     * 여러 축을 한꺼번에 걸면 전부 만족하는 회원만 남는다.
     *
     * @scenario filter_axis=status, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_many_axes_combined(): void
    {
        $role = $this->role('lf-many');
        $target = $this->member([
            'status' => 'active', 'language' => 'en', 'country' => 'JP',
            'email_verified_at' => now(), 'created_at' => '2026-04-05 09:00:00', 'last_login_at' => '2026-04-20 09:00:00',
        ]);
        $target->roles()->attach($role->id);
        $near = $this->member([
            'status' => 'active', 'language' => 'en', 'country' => 'JP',
            'email_verified_at' => null, 'created_at' => '2026-04-05 09:00:00', 'last_login_at' => '2026-04-20 09:00:00',
        ]);
        $near->roles()->attach($role->id);

        $this->assertListed([$target], [
            'status' => ['active'], 'role_ids' => [$role->id], 'admin_scope' => 'member', 'lock_state' => 'unlocked',
            'start_date' => '2026-04-01', 'end_date' => '2026-04-30',
            'last_login_start' => '2026-04-01', 'last_login_end' => '2026-04-30',
            'language' => 'en', 'country' => 'JP', 'email_verified' => 'verified', 'identity_verified' => 'unverified',
        ]);
    }

    /**
     * 활동 축 조합 — 관리자 여부·잠금·가입일·최근 로그인·휴면 전환일을 함께 건다(휴면 회원 기준).
     *
     * @scenario filter_axis=dormant_at, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_activity_axes_combined_around_dormant_member(): void
    {
        $base = [
            'status' => 'dormant', 'dormant_at' => '2026-06-15 08:00:00',
            'created_at' => '2025-03-10 09:00:00', 'last_login_at' => '2025-06-01 09:00:00',
        ];
        $target = $this->member($base);
        $this->member(array_merge($base, ['locked_permanently' => true]));
        $this->member(array_merge($base, ['is_super' => true]));
        $this->member(array_merge($base, ['dormant_at' => '2026-02-15 08:00:00']));
        $this->member(array_merge($base, ['last_login_at' => '2026-05-01 09:00:00']));

        $this->assertListed([$target], [
            'status' => ['dormant'], 'admin_scope' => 'member', 'lock_state' => 'unlocked',
            'start_date' => '2025-03-01', 'end_date' => '2025-03-31',
            'last_login_start' => '2025-05-01', 'last_login_end' => '2025-06-30',
            'dormant_start' => '2026-06-01', 'dormant_end' => '2026-06-30',
        ]);
    }

    /**
     * 상태 변경일 조합 — 차단일 범위를 계정 상태·잠금·권한 그룹과 함께 건다.
     *
     * @scenario filter_axis=status_changed, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_status_changed_combined_with_status_lock_and_role(): void
    {
        $role = $this->role('lf-sc-role');
        $base = ['status' => 'blocked', 'blocked_at' => '2026-06-10 10:00:00', 'locked_permanently' => true];

        $target = $this->member($base);
        $target->roles()->attach($role->id);
        $this->member($base); // 역할 없음
        $other = $this->member(array_merge($base, ['blocked_at' => '2026-01-10 10:00:00']));
        $other->roles()->attach($role->id);

        $this->assertListed([$target], [
            'status' => ['blocked'], 'lock_state' => 'locked', 'role_ids' => [$role->id],
            'status_changed_type' => 'blocked', 'status_changed_start' => '2026-06-01', 'status_changed_end' => '2026-06-30',
        ]);
    }

    /**
     * 휴면 전환일 × 상태 변경일(탈퇴일) — 휴면을 거쳐 탈퇴한 회원만 두 범위를 함께 만족한다.
     *
     * @scenario filter_axis=status_changed, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_dormant_range_combined_with_withdrawn_range(): void
    {
        $both = $this->member(['status' => 'withdrawn', 'dormant_at' => '2026-03-05 08:00:00', 'withdrawn_at' => '2026-06-20 08:00:00']);
        $this->member(['status' => 'withdrawn', 'dormant_at' => null, 'withdrawn_at' => '2026-06-20 08:00:00']);
        $this->member(['status' => 'dormant', 'dormant_at' => '2026-03-05 08:00:00']);

        $this->assertListed([$both], [
            'dormant_start' => '2026-03-01', 'dormant_end' => '2026-03-31',
            'status_changed_type' => 'withdrawn', 'status_changed_start' => '2026-06-01', 'status_changed_end' => '2026-06-30',
        ]);
    }

    /**
     * 권한 그룹 경계 — 고른 역할을 둘 다 가진 회원도 목록에 한 번만 나온다(서브쿼리 IN 이라 행이 불지 않는다).
     *
     * @scenario filter_axis=role, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_role_member_of_two_selected_roles_is_listed_once(): void
    {
        $roleA = $this->role('lf-dup-a');
        $roleB = $this->role('lf-dup-b');
        $both = $this->member();
        $both->roles()->attach([$roleA->id, $roleB->id]);

        $uuids = collect($this->list(['role_ids' => [$roleA->id, $roleB->id]])->assertOk()->json('data.data'))->pluck('uuid')->all();

        $this->assertSame([$both->uuid], $uuids);
    }

    /**
     * 휴면 전환일 경계 — 종료일 당일 23:59:59 는 포함하고 다음 날 0시는 뺀다.
     *
     * @scenario filter_axis=dormant_at, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_dormant_end_day_is_inclusive(): void
    {
        $lastSecond = $this->member(['status' => 'dormant', 'dormant_at' => '2026-06-30 23:59:59']);
        $this->member(['status' => 'dormant', 'dormant_at' => '2026-07-01 00:00:00']);

        $this->assertListed([$lastSecond], ['dormant_start' => '2026-06-01', 'dormant_end' => '2026-06-30']);
    }

    /**
     * 날짜 칸(가입일·최근 로그인·휴면 전환일·상태 변경일)은 목록이 날짜를 그리는 것과 같은 하루 — 보는 사람의
     * 시간대 — 로 해석한다. 저장값은 UTC 라, UTC 하루로 자르면 한국 시간 새벽에 가입한 회원이 목록에는
     * 「2026-03-10」 으로 보이는데 2026-03-10 으로 거르면 빠지고, 빠른 기간 「오늘」 이 오늘 아침 가입자를 놓친다
     * (#736 영역 C 실측).
     *
     * @scenario filter_axis=created_at, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_date_ranges_follow_viewer_timezone_day(): void
    {
        $this->admin->forceFill(['timezone' => 'Asia/Seoul'])->saveQuietly();

        // 2026-03-09 15:30 UTC = 2026-03-10 00:30 KST(그날 안), 2026-03-10 15:30 UTC = 2026-03-11 00:30 KST(다음날)
        $early = $this->member([
            'created_at' => '2026-03-09 15:30:00',
            'last_login_at' => '2026-03-09 15:30:00',
            'dormant_at' => '2026-03-09 15:30:00',
            'blocked_at' => '2026-03-09 15:30:00',
        ]);
        $this->member([
            'created_at' => '2026-03-10 15:30:00',
            'last_login_at' => '2026-03-10 15:30:00',
            'dormant_at' => '2026-03-10 15:30:00',
            'blocked_at' => '2026-03-10 15:30:00',
        ]);

        $day = '2026-03-10';
        $this->assertListed([$early], ['start_date' => $day, 'end_date' => $day]);
        $this->assertListed([$early], ['last_login_start' => $day, 'last_login_end' => $day]);
        $this->assertListed([$early], ['dormant_start' => $day, 'dormant_end' => $day]);
        $this->assertListed([$early], ['status_changed_type' => 'blocked', 'status_changed_start' => $day, 'status_changed_end' => $day]);
    }

    /**
     * 언어 경계 — 지원 로케일인데 그 언어 회원이 0명이면 422 가 아니라 빈 목록이다(허용값 판정과 결과 0건을 가른다).
     *
     * @scenario filter_axis=language, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_language_supported_but_unused_returns_empty_list(): void
    {
        $this->member(['language' => 'ko']);
        $this->member(['language' => 'ko']);

        $this->assertListed([], ['language' => 'en']);
    }

    /**
     * 본인인증 경계 — 「전체」는 조건이 없는 것과 같다.
     *
     * @scenario filter_axis=identity_verified, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_identity_verified_all_equals_no_condition(): void
    {
        $verified = $this->member(['identity_verified_at' => now()]);
        $unverified = $this->member(['identity_verified_at' => null]);

        $this->assertListed([$verified, $unverified], ['identity_verified' => 'all']);
        $this->assertListed([$verified, $unverified], []);
    }

    /**
     * 응답 형태(data·statistics·abilities·pagination)는 그대로이고 새 열 값이 실린다.
     *
     * @scenario filter_axis=status, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_response_shape_is_unchanged(): void
    {
        $this->member();

        $this->list([])
            ->assertOk()
            ->assertJsonStructure(['data' => ['data', 'statistics', 'abilities', 'pagination' => ['total', 'current_page', 'last_page']]])
            ->assertJsonStructure(['data' => ['data' => [['uuid', 'status', 'country', 'identity_verified_at', 'email_verified_at']]]]);
    }

    /**
     * 필터 12축을 모두 걸어도 회원이 늘 때 쿼리 수가 늘지 않는다(R4 — 역할·관리자 판정이 행마다 돌지 않는다).
     *
     * @scenario filter_axis=role, outcome=narrows
     *
     * @effects admin_user_list_filter_axes_narrow_results
     *
     * @return void 반환값 없음
     */
    public function test_all_filters_query_count_is_stable_as_rows_grow(): void
    {
        $role = $this->role('lf-perf', 'user');
        $seed = function (int $count) use ($role) {
            for ($i = 0; $i < $count; $i++) {
                $this->member(['last_login_at' => '2026-04-10 10:00:00', 'created_at' => '2026-04-05 10:00:00'])->roles()->attach($role->id);
            }
        };
        $seed(3);

        $query = [
            'status' => ['active', 'blocked'], 'role_ids' => [$role->id], 'admin_scope' => 'member', 'lock_state' => 'unlocked',
            'start_date' => '2026-04-01', 'end_date' => '2026-04-30', 'last_login_start' => '2026-04-01', 'last_login_end' => '2026-04-30',
            'dormant_start' => '2020-01-01', 'status_changed_type' => 'blocked', 'language' => 'ko', 'country' => 'KR',
            'email_verified' => 'unverified', 'identity_verified' => 'unverified',
        ];

        $this->assertQueryCountStableAsDataGrows(
            measure: fn () => $this->list($query)->assertOk(),
            grow: fn () => $seed(10),
            context: '회원 목록 필터 12축',
        );
    }

    /**
     * 날짜 조건은 컬럼을 함수로 감싸지 않는 범위식으로 건다 — `whereDate` 는 `DATE(col)` 이 되어 인덱스를 못 탄다.
     *
     * 결과 동치 테스트는 `whereDate` 로 바꿔도 초록이라 SQL 모양을 따로 잰다. 날짜 키는 게이트 규칙에서 파생한다.
     *
     * @scenario filter_axis=created_at, outcome=narrows
     *
     * @effects admin_user_list_date_filters_use_indexable_ranges
     *
     * @return void 반환값 없음
     */
    public function test_date_filters_keep_indexable_range_conditions(): void
    {
        $dateKeys = array_keys(array_filter(
            (new UserListRequest)->rules(),
            fn ($rule): bool => in_array('date', is_array($rule) ? $rule : explode('|', (string) $rule), true)
        ));

        $this->assertNotSame([], $dateKeys, '게이트 규칙에서 날짜 키를 하나도 파생하지 못했습니다.');

        // 상태 변경일은 종류가 있어야 날짜 조건이 걸린다.
        $query = array_fill_keys($dateKeys, '2026-04-10') + ['status_changed_type' => 'withdrawn'];

        $sql = [];
        DB::listen(function ($executed) use (&$sql) {
            if (str_contains($executed->sql, 'users')) {
                $sql[] = $executed->sql;
            }
        });

        $this->list($query)->assertOk();

        $this->assertNotSame([], $sql, '회원 목록 쿼리를 하나도 잡지 못했습니다.');

        $wrapped = array_values(array_filter($sql, fn (string $statement): bool => (bool) preg_match('/\bdate\s*\(/i', $statement)));

        $this->assertSame([], $wrapped, '날짜 컬럼을 DATE() 로 감싼 조건이 있습니다 — 인덱스를 쓰지 못합니다. 키: '.implode(', ', $dateKeys));
    }

    /**
     * 확장 값 칸(`ext_filters[]`)의 원소 수 상한을 넘으면 422, 상한까지는 받는다.
     *
     * 상한은 게이트 규칙에서 읽는다 — 규칙에서 상한을 지우면 파생이 실패해 red 가 된다.
     *
     * @scenario filter_axis=extension, outcome=rejected
     *
     * @effects admin_user_list_filter_invalid_values_rejected
     *
     * @return void 반환값 없음
     */
    public function test_extension_value_count_is_capped(): void
    {
        $rule = (string) ((new UserListRequest)->rules()['ext_filters'] ?? '');
        $this->assertSame(1, preg_match('/(?:^|\|)max:(\d+)(?:\||$)/', $rule, $m), 'ext_filters 규칙에 원소 수 상한이 없습니다: '.$rule);
        $limit = (int) $m[1];

        $values = fn (int $count): array => array_map(fn (int $i): string => 'lf-test.k'.$i.'=1', range(1, $count));

        $this->list(['ext_filters' => $values($limit)])->assertOk();
        $this->list(['ext_filters' => $values($limit + 1)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ext_filters');
    }

    /**
     * 목록 응답은 행마다 확장 데이터 훅을 발화하지 않는다 — 발화하면 회원 수만큼 확장 조회가 는다(N+1).
     *
     * @scenario filter_axis=extension, outcome=narrows
     *
     * @effects admin_user_list_rows_fire_no_resource_hook
     *
     * @return void 반환값 없음
     */
    public function test_list_rows_do_not_fire_resource_data_hook(): void
    {
        $this->member();
        $this->member();

        $fired = 0;
        $counter = function ($data) use (&$fired) {
            $fired++;

            return $data;
        };

        HookManager::addFilter('core.user.filter_resource_data', $counter);

        try {
            $rows = $this->list([])->assertOk()->json('data.data');
        } finally {
            HookManager::removeFilter('core.user.filter_resource_data', $counter);
        }

        $this->assertGreaterThanOrEqual(2, count($rows), '회원 행이 목록에 나오지 않았습니다.');
        $this->assertSame(0, $fired, '목록 응답이 행마다 core.user.filter_resource_data 를 발화합니다.');
    }

    // ==========================================
    // 도우미
    // ==========================================

    /**
     * 표식이 붙은 회원을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return User 만든 회원
     */
    private function member(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'email' => Str::lower(Str::random(12)).$this->marker,
            'status' => 'active',
            'language' => 'ko',
            'country' => null,
            'is_super' => false,
            'last_login_at' => null,
            'email_verified_at' => null,
        ], array_diff_key($attributes, ['created_at' => 1])));

        // created_at 은 fillable 밖일 수 있어 강제로 채운다.
        if (array_key_exists('created_at', $attributes)) {
            $user->forceFill(['created_at' => $attributes['created_at']])->saveQuietly();
        }

        foreach (['locked_until', 'locked_permanently', 'dormant_at', 'withdrawn_at', 'blocked_at', 'identity_verified_at'] as $column) {
            if (array_key_exists($column, $attributes)) {
                $user->forceFill([$column => $attributes[$column]])->saveQuietly();
            }
        }

        return $user->fresh();
    }

    /**
     * 역할을 만듭니다. `$permissionType` 을 주면 그 타입의 권한 하나를 붙인다.
     *
     * @param  string  $key  식별자 접두
     * @param  string|null  $permissionType  붙일 권한 타입(admin·user), null 이면 권한 없음
     * @return Role 만든 역할
     */
    private function role(string $key, ?string $permissionType = null): Role
    {
        $role = Role::create([
            'name' => ['ko' => $key, 'en' => $key],
            'identifier' => $key.'-'.Str::lower(Str::random(6)),
            'is_active' => true,
        ]);

        if ($permissionType !== null) {
            $permission = Permission::firstOrCreate(
                ['identifier' => 'lf.test.'.$permissionType],
                ['name' => ['ko' => $permissionType, 'en' => $permissionType], 'type' => $permissionType, 'order' => 1]
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return $role;
    }

    /**
     * 권한 식별자를 가진 운영자를 만듭니다.
     *
     * @param  array<int, string>  $identifiers  권한 식별자
     * @param  string  $roleKey  역할 식별자 접두
     * @return User 운영자
     */
    private function createOperator(array $identifiers, string $roleKey): User
    {
        $user = User::factory()->create(['email' => Str::lower(Str::random(12)).'@operator.test']);

        $role = Role::create([
            'name' => ['ko' => $roleKey, 'en' => $roleKey],
            'identifier' => $roleKey.'-'.$user->id,
            'is_active' => true,
        ]);

        foreach ($identifiers as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin', 'order' => 1]
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    /**
     * 이 테스트가 만든 회원만 보도록 표식 검색을 붙여 목록을 부릅니다.
     *
     * @param  array<string, mixed>  $query  목록 쿼리
     * @return TestResponse 응답
     */
    private function list(array $query)
    {
        $query = array_merge([
            'filters' => [['field' => 'email', 'value' => $this->marker, 'operator' => 'like']],
            'per_page' => 100,
        ], $query);

        return $this->actingAs($this->admin)->getJson('/api/admin/users?'.http_build_query($query));
    }

    /**
     * 목록 결과가 기대한 회원 집합과 정확히 같은지 봅니다.
     *
     * @param  array<int, User>  $expected  기대 회원
     * @param  array<string, mixed>  $query  목록 쿼리
     * @return void 반환값 없음
     */
    private function assertListed(array $expected, array $query): void
    {
        $response = $this->list($query)->assertOk();

        $actual = collect($response->json('data.data'))->pluck('uuid')->sort()->values()->all();
        $wanted = collect($expected)->pluck('uuid')->sort()->values()->all();

        $this->assertSame($wanted, $actual, 'query='.json_encode($query));
    }
}
