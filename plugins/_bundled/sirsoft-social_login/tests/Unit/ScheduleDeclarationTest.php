<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Unit;

use App\Enums\ExtensionOwnerType;
use App\Extension\Helpers\ExtensionScheduleSyncHelper;
use App\Models\Schedule;
use Plugins\Sirsoft\SocialLogin\Plugin;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;
use Tests\Support\Concerns\AssertsExtensionScheduleDeclarations;

/**
 * sirsoft-social_login 스케줄 선언 (코어 7.2.0 DB 스케줄 등록부)
 *
 * 선언은 설치·갱신 때 코어가 스케줄 표로 동기화한다. 이름·설명이 한 언어뿐이면 다른 언어 관리자 화면에도 그 언어로 보인다 —
 * 다국어 `label`·`description` 으로 선언하고, 옛 형식이 자동 이름으로 만든 행은 운영자 수정·실행 이력과 함께 이어받아야 한다.
 */
class ScheduleDeclarationTest extends PluginTestCase
{
    use AssertsExtensionScheduleDeclarations;

    /** @var string 옛 형식 선언의 설명(한 언어) — 옛 행을 만들 때 쓴다 */
    private const LEGACY_DESCRIPTION = '소셜 로그인 보존 기간 정리 (원본 프로필 · 시도 기록 · 웹훅 수신 이력 · 모의 제공자 표지)';

    /**
     * 선언이 새 형식(name·type·ko/en 이름과 설명)이고 정규화·검증을 통과하는지 확인합니다.
     *
     * @scenario schedule=declared
     *
     * @effects schedule_declared_multilingual
     *
     * @return void 반환값 없음
     */
    public function test_declarations_are_syncable(): void
    {
        $this->assertScheduleDeclarationsSyncable((new Plugin)->getSchedules(), ExtensionOwnerType::Plugin, 'sirsoft-social_login');
    }

    /**
     * 옛 선언(매일 0시, 사이트 시간대, 조건 없음)과 같은 명령·실행 시각을 유지하는지 확인합니다.
     *
     * @scenario schedule=declared
     *
     * @effects schedule_timing_preserved
     *
     * @return void 반환값 없음
     */
    public function test_legacy_timing_preserved(): void
    {
        $this->assertSame([
            'prune' => ['sirsoft-social_login:prune', '0 0 * * *', null, []],
        ], $this->scheduleDeclarationTable((new Plugin)->getSchedules()));
    }

    /**
     * 옛 형식이 자동 이름(`sirsoft-social-login-prune`)으로 만든 행을 새 형식 업데이트가 지우지 않고 이어받는지 확인합니다.
     *
     * @scenario schedule=declared
     *
     * @effects legacy_named_rows_adopted_on_format_upgrade
     *
     * @return void 반환값 없음
     */
    public function test_legacy_rows_adopted_on_format_upgrade(): void
    {
        $this->assertLegacyRowsAdoptedOnFormatUpgrade((new Plugin)->getSchedules(), ExtensionOwnerType::Plugin, 'sirsoft-social_login');
    }

    /**
     * 이미 설치된 사이트: 옛 선언으로 만든 행(이름·설명 ko 한 벌)에 운영자가 주기·사용 여부·한국어 이름을 바꿔 둔 뒤 새 선언으로
     * 다시 동기화하면, 같은 행에 영어 이름·설명이 생기고 운영자가 바꾼 값은 그대로 남는다.
     *
     * @scenario schedule=declared
     *
     * @effects existing_row_gains_locales_and_keeps_operator_changes
     *
     * @return void 반환값 없음
     */
    public function test_existing_legacy_row_gains_english_and_keeps_operator_changes(): void
    {
        $helper = app(ExtensionScheduleSyncHelper::class);
        $owned = fn () => Schedule::ownedBy(ExtensionOwnerType::Plugin, 'sirsoft-social_login');
        $owned()->get()->each(fn (Schedule $row) => $row->delete());

        $helper->syncSchedules(ExtensionOwnerType::Plugin, 'sirsoft-social_login', [[
            'command' => 'sirsoft-social_login:prune',
            'schedule' => 'daily',
            'description' => self::LEGACY_DESCRIPTION,
        ]]);
        $legacy = $owned()->firstOrFail();
        $this->assertSame('sirsoft-social-login-prune', $legacy->name);
        $this->assertSame([config('app.locale') => self::LEGACY_DESCRIPTION], $legacy->label);

        $legacy->update(['expression' => '30 3 * * *', 'is_active' => false]);
        $untouched = $owned()->firstOrFail();

        $helper->syncSchedules(ExtensionOwnerType::Plugin, 'sirsoft-social_login', (new Plugin)->getSchedules());

        $rows = $owned()->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame($untouched->id, $row->id);
        $this->assertSame('prune', $row->name);
        $this->assertSame('30 3 * * *', $row->expression);
        $this->assertFalse((bool) $row->is_active);
        foreach (['label', 'description'] as $field) {
            $this->assertNotEmpty($row->{$field}['en'] ?? null, "{$field}.en 이 채워지지 않았습니다");
            $this->assertDoesNotMatchRegularExpression('/[가-힣]/u', $row->{$field}['en'], "{$field}.en 에 한국어가 남았습니다");
            $this->assertNotEmpty($row->{$field}['ko'] ?? null, "{$field}.ko 가 비었습니다");
        }
    }

    /**
     * 운영자가 한국어 이름을 고쳐 두었으면 새 선언 동기화가 그 한국어 이름은 덮지 않고 영어 이름만 더한다.
     *
     * @scenario schedule=declared
     *
     * @effects existing_row_gains_locales_and_keeps_operator_changes
     *
     * @return void 반환값 없음
     */
    public function test_operator_korean_label_kept_while_english_added(): void
    {
        $helper = app(ExtensionScheduleSyncHelper::class);
        $owned = fn () => Schedule::ownedBy(ExtensionOwnerType::Plugin, 'sirsoft-social_login');
        $owned()->get()->each(fn (Schedule $row) => $row->delete());

        $helper->syncSchedules(ExtensionOwnerType::Plugin, 'sirsoft-social_login', [[
            'command' => 'sirsoft-social_login:prune',
            'schedule' => 'daily',
            'description' => self::LEGACY_DESCRIPTION,
        ]]);
        $owned()->firstOrFail()->update(['label' => ['ko' => '운영자가 정한 정리 작업']]);

        $helper->syncSchedules(ExtensionOwnerType::Plugin, 'sirsoft-social_login', (new Plugin)->getSchedules());

        $row = $owned()->firstOrFail();
        $this->assertSame('운영자가 정한 정리 작업', $row->label['ko']);
        $this->assertNotEmpty($row->label['en'] ?? null);
    }
}
