<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Console;

use App\Enums\UserStatus;
use App\Models\FieldDefinition;
use App\Models\FieldValue;
use App\Models\User;
use Carbon\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\Feature\Coupon\AutoIssue\Concerns\CreatesAutoIssueCoupons;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 생일 쿠폰 발급 명령 (#952 P7·P12)
 */
class IssueBirthdayCouponsCommandTest extends ModuleTestCase
{
    use CreatesAutoIssueCoupons;

    /** 테스트 설정 파일 */
    private const SETTINGS_FILE = 'framework/testing/modules/sirsoft-ecommerce/settings/promotion.json';

    /**
     * 시각을 고정하고 설정 파일을 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $file = storage_path(self::SETTINGS_FILE);
        if (file_exists($file)) {
            unlink($file);
        }
        parent::tearDown();
    }

    /**
     * 쿠폰 탭 설정을 씁니다.
     *
     * @param  array<string, mixed>  $values  설정 값
     * @return void 반환값 없음
     */
    private function writePromotionSettings(array $values): void
    {
        app(EcommerceSettingsService::class)->setSetting('promotion', array_merge([
            'birthday_field_key' => null,
            'coupon_expiry_notification_enabled' => true,
            'coupon_expiry_notification_days_before' => 7,
        ], $values));
    }

    /**
     * 날짜 유형·값 테이블 저장 회원 항목을 만듭니다.
     *
     * @param  string  $key  항목 키
     * @param  string  $type  유형
     * @param  string  $storage  저장 방식
     * @return FieldDefinition 항목
     */
    private function makeBirthField(string $key = 'i952_birth', string $type = 'date', string $storage = 'value'): FieldDefinition
    {
        return FieldDefinition::query()->create([
            'field_set' => 'core.user',
            'key' => $key,
            'source_type' => 'custom',
            'source_identifier' => 'custom',
            'type' => $type,
            'storage' => $storage,
            'name' => ['ko' => '생일', 'en' => 'Birthday'],
            'enabled' => true,
            'sort_order' => 900,
        ]);
    }

    /**
     * 회원의 생일 값을 씁니다.
     *
     * @param  FieldDefinition  $field  항목
     * @param  User  $user  회원
     * @param  string  $date  날짜 (Y-m-d)
     * @return void 반환값 없음
     */
    private function setBirthday(FieldDefinition $field, User $user, string $date): void
    {
        FieldValue::query()->create([
            'field_definition_id' => $field->id,
            'owner_type' => FieldValue::OWNER_USER,
            'owner_id' => $user->id,
            'value_date' => $date.' 00:00:00',
        ]);
    }

    /**
     * 생일 당일(사이트 시간대) 활성 회원에게 해마다 1회 발급하고, 같은 날 다시 돌려도 2회차는 0건이다. 비활성 회원은 빠진다.
     *
     * @scenario case=issue_birthday_coupons_today
     *
     * @effects birthday_coupon_issued_once_a_year
     *
     * @return void 반환값 없음
     */
    public function test_issues_once_per_year_for_active_members(): void
    {
        config(['app.default_user_timezone' => 'Asia/Seoul']);
        $field = $this->makeBirthField();
        $this->writePromotionSettings(['birthday_field_key' => 'i952_birth']);
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::BIRTHDAY);
        $active = $this->createUser();
        $blocked = $this->createUser();
        $blocked->forceFill(['status' => UserStatus::Blocked->value])->save();
        $other = $this->createUser();
        $this->setBirthday($field, $active, '1990-03-15');
        $this->setBirthday($field, $blocked, '1991-03-15');
        $this->setBirthday($field, $other, '1992-03-16');

        $this->artisan('sirsoft-ecommerce:issue-birthday-coupons', ['--date' => '2027-03-15'])->assertSuccessful();
        $this->artisan('sirsoft-ecommerce:issue-birthday-coupons', ['--date' => '2027-03-15'])->assertSuccessful();

        $issues = CouponIssue::query()->where('coupon_id', $coupon->id)->get();
        $this->assertCount(1, $issues);
        $this->assertSame($active->id, (int) $issues->first()->user_id);
        $this->assertSame('birthday:2027', $issues->first()->auto_issue_key);

        // 다음 해에는 다시 받는다
        $this->artisan('sirsoft-ecommerce:issue-birthday-coupons', ['--date' => '2028-03-15'])->assertSuccessful();
        $this->assertSame(2, CouponIssue::query()->where('coupon_id', $coupon->id)->where('user_id', $active->id)->count());
    }

    /**
     * 2월 29일생은 평년 2월 28일에 받고, 윤년에는 2월 28일에 받지 않는다.
     *
     * @scenario case=issue_birthday_coupons_feb29
     *
     * @effects birthday_feb29_on_feb28_in_common_years
     *
     * @return void 반환값 없음
     */
    public function test_feb_29_birthday_falls_on_feb_28_in_common_years(): void
    {
        $field = $this->makeBirthField();
        $this->writePromotionSettings(['birthday_field_key' => 'i952_birth']);
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::BIRTHDAY);
        $leapling = $this->createUser();
        $this->setBirthday($field, $leapling, '2000-02-29');

        $this->artisan('sirsoft-ecommerce:issue-birthday-coupons', ['--date' => '2028-02-28'])->assertSuccessful();
        $this->assertSame(0, CouponIssue::query()->where('coupon_id', $coupon->id)->count(), '윤년 2/28 에는 받지 않는다');

        $this->artisan('sirsoft-ecommerce:issue-birthday-coupons', ['--date' => '2027-02-28'])->assertSuccessful();
        $this->assertSame(1, CouponIssue::query()->where('coupon_id', $coupon->id)->count());
    }

    /**
     * 생일 항목을 지정하지 않았거나, 지정한 항목이 날짜 유형·값 테이블 저장이 아니면 안내만 하고 발급하지 않는다. dry-run 도 발급하지 않는다.
     *
     * @scenario case=issue_birthday_coupons_not_configured
     *
     * @effects birthday_coupon_not_issued
     *
     * @return void 반환값 없음
     */
    public function test_not_configured_or_invalid_field_or_dry_run_issues_nothing(): void
    {
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::BIRTHDAY);
        $field = $this->makeBirthField();
        $textField = $this->makeBirthField('i952_text', 'text');
        $user = $this->createUser();
        $this->setBirthday($field, $user, '1990-05-05');

        $this->writePromotionSettings(['birthday_field_key' => null]);
        $this->artisan('sirsoft-ecommerce:issue-birthday-coupons', ['--date' => '2027-05-05'])->assertSuccessful();

        $this->writePromotionSettings(['birthday_field_key' => $textField->key]);
        $this->artisan('sirsoft-ecommerce:issue-birthday-coupons', ['--date' => '2027-05-05'])->assertSuccessful();

        $this->writePromotionSettings(['birthday_field_key' => 'i952_birth']);
        $this->artisan('sirsoft-ecommerce:issue-birthday-coupons', ['--date' => '2027-05-05', '--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, CouponIssue::query()->where('coupon_id', $coupon->id)->count());
    }

    /**
     * 날짜 유형이라도 값 테이블이 아니라 회원 테이블 칸(column)에 저장하는 항목을 지정해 두면 안내만 하고 발급하지 않는다.
     *
     * @scenario case=issue_birthday_coupons_column_field
     *
     * @effects birthday_coupon_not_issued
     *
     * @return void 반환값 없음
     */
    public function test_column_stored_date_field_is_not_used(): void
    {
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::BIRTHDAY);
        $valueField = $this->makeBirthField();
        $columnField = $this->makeBirthField('i952_birth_column', 'date', 'column');
        $user = $this->createUser();
        // 같은 회원에게 값 테이블 생일이 있어도, 지정한 항목이 column 저장이면 쓰지 않는다
        $this->setBirthday($valueField, $user, '1990-06-06');

        $this->writePromotionSettings(['birthday_field_key' => $columnField->key]);
        $this->artisan('sirsoft-ecommerce:issue-birthday-coupons', ['--date' => '2027-06-06'])->assertSuccessful();

        $this->assertSame(0, CouponIssue::query()->where('coupon_id', $coupon->id)->count());
    }

    /**
     * 탈퇴한 회원은 생일이 오늘이어도 받지 않는다.
     *
     * @scenario case=issue_birthday_coupons_withdrawn
     *
     * @effects birthday_coupon_not_issued
     *
     * @return void 반환값 없음
     */
    public function test_withdrawn_member_is_excluded(): void
    {
        $field = $this->makeBirthField();
        $this->writePromotionSettings(['birthday_field_key' => 'i952_birth']);
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::BIRTHDAY);
        $withdrawn = $this->createUser();
        $withdrawn->forceFill(['status' => UserStatus::Withdrawn->value])->save();
        $active = $this->createUser();
        $this->setBirthday($field, $withdrawn, '1988-07-07');
        $this->setBirthday($field, $active, '1989-07-07');

        $this->artisan('sirsoft-ecommerce:issue-birthday-coupons', ['--date' => '2027-07-07'])->assertSuccessful();

        $this->assertSame(0, CouponIssue::query()->where('coupon_id', $coupon->id)->where('user_id', $withdrawn->id)->count());
        $this->assertSame(1, CouponIssue::query()->where('coupon_id', $coupon->id)->where('user_id', $active->id)->count());
    }

    /**
     * 기준 날짜를 주지 않으면 사이트 시간대의 오늘로 판정한다 — UTC 로는 전날 15:30 이라도 서울 기준 당일이면 발급한다.
     *
     * @scenario case=issue_birthday_coupons_utc_boundary
     *
     * @effects birthday_coupon_uses_site_timezone
     *
     * @return void 반환값 없음
     */
    public function test_today_follows_site_timezone_across_utc_boundary(): void
    {
        config(['app.default_user_timezone' => 'Asia/Seoul']);
        Carbon::setTestNow(Carbon::parse('2027-03-14 15:30:00', 'UTC'));
        \Carbon\CarbonImmutable::setTestNow(\Carbon\CarbonImmutable::parse('2027-03-14 15:30:00', 'UTC'));
        $field = $this->makeBirthField();
        $this->writePromotionSettings(['birthday_field_key' => 'i952_birth']);
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::BIRTHDAY);
        $user = $this->createUser();
        $this->setBirthday($field, $user, '1995-03-15');

        try {
            $this->artisan('sirsoft-ecommerce:issue-birthday-coupons')->assertSuccessful();
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }

        $this->assertSame(1, CouponIssue::query()->where('coupon_id', $coupon->id)->where('user_id', $user->id)->count());
    }

    /**
     * 모듈이 두 명령을 매일 스케줄로 선언하고, 각자 설정이 켜졌을 때만 돈다.
     *
     * @scenario case=schedules
     *
     * @effects coupon_commands_scheduled
     *
     * @return void 반환값 없음
     */
    public function test_commands_are_declared_as_daily_schedules(): void
    {
        $module = app(\App\Extension\ModuleManager::class)->getModule('sirsoft-ecommerce');
        $schedules = collect($module->getSchedules())->keyBy('name');

        $this->assertSame('sirsoft-ecommerce:issue-birthday-coupons', $schedules['issue-birthday-coupons']['command']);
        $this->assertSame('5 0 * * *', $schedules['issue-birthday-coupons']['expression']);
        $this->assertSame('sirsoft-ecommerce:notify-expiring-coupons', $schedules['notify-expiring-coupons']['command']);
        $this->assertSame('10 0 * * *', $schedules['notify-expiring-coupons']['expression']);
        $this->assertArrayHasKey('enabled_condition', $schedules['issue-birthday-coupons']);
    }
}
