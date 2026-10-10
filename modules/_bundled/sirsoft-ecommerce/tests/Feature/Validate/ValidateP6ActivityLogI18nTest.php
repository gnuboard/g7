<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Validate;

use App\Enums\ActivityLogType;
use App\Models\ActivityLog;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonTypeEnum;
use Modules\Sirsoft\Ecommerce\Listeners\ActivityLogDescriptionResolver;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 활동 로그 표시 문구의 다국어 결함 수정 검증 (#599 validate P6)
 *
 * - 클레임 사유 로그의 `:reason_type` 을 표시 시점 로케일 라벨로 해석한다
 * - 이름을 되찾지 못한 엔티티의 `ID:` 표기를 다국어 키로 옮긴다(ko·en·ja 세 벌)
 */
class ValidateP6ActivityLogI18nTest extends ModuleTestCase
{
    /**
     * 로케일을 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        app()->setLocale('ko');

        parent::tearDown();
    }

    /**
     * 클레임 사유 로그의 유형이 원시 값이 아니라 표시 로케일의 라벨로 나온다.
     *
     * 수정 전 실패 이유: 기록 시점의 원시 값(`exchange`)이 그대로 치환되어, 어느 언어 화면에서든
     * 「클레임 사유 수정 (exchange · size_mismatch)」 로 보였다.
     *
     * @scenario defect=reason_type_label
     *
     * @effects claim_reason_log_type_is_resolved_at_display_time
     *
     * @return void 반환값 없음
     */
    public function test_claim_reason_log_type_is_resolved_at_display_time(): void
    {
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'claim_reason.update',
            'description_key' => 'sirsoft-ecommerce::activity_log.description.claim_reason_update',
            'description_params' => ['reason_type' => 'exchange', 'reason_code' => 'size_mismatch'],
            'properties' => [],
        ]);

        foreach (['ko', 'en'] as $locale) {
            app()->setLocale($locale);
            $label = ClaimReasonTypeEnum::EXCHANGE->label();

            $this->assertNotSame('exchange', $label, "{$locale} 라벨이 정의되어 있어야 합니다");
            $this->assertStringContainsString($label, $log->fresh()->localized_description, "{$locale} 화면에 원시 유형 값이 보입니다");
        }
    }

    /**
     * 모르는 유형 값은 원시 값을 그대로 둔다 (해석 실패가 문구를 지우지 않는다).
     *
     * 수정 전에도 통과한다 — 해석을 넣으면서 모르는 값을 빈칸으로 만들지 않음을 잠그는 회귀 가드다.
     *
     * @scenario defect=reason_type_label
     *
     * @effects unknown_claim_reason_type_is_left_as_is
     *
     * @return void 반환값 없음
     */
    public function test_unknown_claim_reason_type_is_left_as_is(): void
    {
        $params = app(ActivityLogDescriptionResolver::class)->resolveDescriptionParams(
            ['reason_type' => 'legacy_type', 'reason_code' => 'x'],
            'sirsoft-ecommerce::activity_log.description.claim_reason_delete',
            [],
        );

        $this->assertSame('legacy_type', $params['reason_type']);
    }

    /**
     * 이름을 되찾지 못한 엔티티의 ID 표기가 다국어 키를 거치고, 세 언어에 모두 정의되어 있다.
     *
     * 수정 전 실패 이유: `"ID: {$id}"` 가 코드에 박혀 있어 번역할 자리가 없었고, 그 키는 어느 언어
     * 파일에도 없었다.
     *
     * @scenario defect=id_hardcode
     *
     * @effects unresolved_entity_id_uses_a_translated_key
     *
     * @return void 반환값 없음
     */
    public function test_unresolved_entity_id_uses_a_translated_key(): void
    {
        $base = $this->getModuleBasePath();
        $ja = include base_path('lang-packs/_bundled/g7-module-sirsoft-ecommerce-ja/backend/ja/activity_log.php');

        foreach (['ko' => include $base.'/src/lang/ko/activity_log.php', 'en' => include $base.'/src/lang/en/activity_log.php', 'ja' => $ja] as $locale => $lang) {
            $this->assertArrayHasKey('entity_id_fallback', $lang, "{$locale} 에 entity_id_fallback 키가 없습니다");
            $this->assertStringContainsString(':id', $lang['entity_id_fallback']);
        }

        $params = app(ActivityLogDescriptionResolver::class)->resolveDescriptionParams(
            ['brand_id' => 2_000_000_000],
            'sirsoft-ecommerce::activity_log.description.brand_update',
            [],
        );

        $this->assertSame(__('sirsoft-ecommerce::activity_log.entity_id_fallback', ['id' => '2000000000']), $params['brand_name']);

        $source = (string) file_get_contents($base.'/src/Listeners/ActivityLogDescriptionResolver.php');
        $this->assertStringNotContainsString('"ID: ', $source, '해석기에 ID 표기가 하드코딩되어 있습니다');
        $this->assertStringNotContainsString("'ID: '", $source, '해석기에 ID 표기가 하드코딩되어 있습니다');
    }
}
