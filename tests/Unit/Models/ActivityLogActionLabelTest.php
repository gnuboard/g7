<?php

namespace Tests\Unit\Models;

use App\Enums\ActivityLogType;
use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * ActivityLog::getActionLabelAttribute() 5단계 fallback 검증
 *
 * 모듈 lang 전체키 → 모듈 lang 마지막세그먼트 → 코어 lang 전체키
 *  → 코어 lang 마지막세그먼트 → raw fallback 의 우선순위가 의도대로 동작하는지 회귀 차단.
 */
class ActivityLogActionLabelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 기본 로케일을 한국어로 맞춥니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        App::setLocale('ko');
    }

    /**
     * 1단계: loggable_type 으로부터 모듈 lang 의 전체 키 우선 매칭
     *
     * @scenario origin_type=module, module_lang_state=full_key_defined
     *
     * @effects module_full_key_takes_precedence_over_module_last_segment
     *
     * @return void 반환값 없음
     */
    public function test_module_lang_full_key_takes_precedence_over_core_lang(): void
    {
        Lang::addNamespace('sirsoft-ecommerce', __DIR__);
        Lang::addLines([
            'activity_log.action.mileage.earn' => '마일리지 적립 (모듈)',
        ], 'ko', 'sirsoft-ecommerce');
        Lang::addLines([
            'activity_log.action.mileage.earn' => '마일리지 적립 (코어)',
        ], 'ko');

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::User,
            'action' => 'mileage.earn',
            'loggable_type' => 'Modules\\Sirsoft\\Ecommerce\\Models\\Order',
            'loggable_id' => 1,
        ]);

        $this->assertSame('마일리지 적립 (모듈)', $log->action_label);
    }

    /**
     * 2단계: 모듈 lang 의 마지막 세그먼트 매칭 (전체키 미정의)
     *
     * @scenario origin_type=module, module_lang_state=last_segment_defined
     *
     * @effects module_last_segment_takes_precedence_over_core_full_key
     *
     * @return void 반환값 없음
     */
    public function test_falls_back_to_module_last_segment_when_full_key_missing(): void
    {
        Lang::addNamespace('sirsoft-ecommerce', __DIR__);
        Lang::addLines([
            'activity_log.action.confirm' => '구매 확정 (모듈)',
        ], 'ko', 'sirsoft-ecommerce');

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::User,
            'action' => 'order.confirm',
            'loggable_type' => 'Modules\\Sirsoft\\Ecommerce\\Models\\Order',
            'loggable_id' => 1,
        ]);

        $this->assertSame('구매 확정 (모듈)', $log->action_label);
    }

    /**
     * 3단계: 코어 lang 의 전체 키 매칭 (모듈 lang 정의 없음)
     *
     * @scenario origin_type=module, module_lang_state=neither_defined
     *
     * @effects core_full_key_takes_precedence_over_core_last_segment
     *
     * @return void 반환값 없음
     */
    public function test_falls_back_to_core_full_key_when_module_lang_missing(): void
    {
        Lang::addLines([
            'activity_log.action.user.create' => '사용자 생성 전체키',
        ], 'ko');

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.create',
            'loggable_type' => 'App\\Models\\User',
            'loggable_id' => 1,
        ]);

        $this->assertSame('사용자 생성 전체키', $log->action_label);
    }

    /**
     * 4단계: 코어 lang 의 마지막 세그먼트 매칭
     *
     * @scenario origin_type=core, core_lang_state=last_segment_defined
     *
     * @effects core_full_key_takes_precedence_over_core_last_segment
     *
     * @return void 반환값 없음
     */
    public function test_falls_back_to_core_last_segment(): void
    {
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.create',
            'loggable_type' => 'App\\Models\\User',
            'loggable_id' => 1,
        ]);

        // 코어 lang/ko/activity_log.php 의 'create' => '생성'
        $this->assertSame('생성', $log->action_label);
    }

    /**
     * 5단계: raw action 문자열 fallback (어디에도 정의 없음)
     *
     * @scenario origin_type=unknown, core_lang_state=neither_defined
     *
     * @effects raw_action_returned_when_no_lang_match
     *
     * @return void 반환값 없음
     */
    public function test_returns_raw_action_when_no_lang_match(): void
    {
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::System,
            'action' => 'unknown.bizarre_segment_xyz',
        ]);

        $this->assertSame('unknown.bizarre_segment_xyz', $log->action_label);
    }

    /**
     * loggable_type 미지정 + properties.extension_origin 으로 origin 추론
     *
     * @scenario origin_type=module, loggable_state=properties_origin_set
     *
     * @effects extension_origin_resolved_from_properties_when_loggable_null
     *
     * @return void 반환값 없음
     */
    public function test_resolves_origin_from_properties_extension_origin_when_loggable_missing(): void
    {
        Lang::addNamespace('sirsoft-ecommerce', __DIR__);
        Lang::addLines([
            'activity_log.action.mileage.expire' => '마일리지 소멸 (모듈)',
        ], 'ko', 'sirsoft-ecommerce');

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::System,
            'action' => 'mileage.expire',
            'loggable_type' => null,
            'loggable_id' => null,
            'properties' => ['extension_origin' => 'sirsoft-ecommerce'],
        ]);

        $this->assertSame('마일리지 소멸 (모듈)', $log->action_label);
    }

    /**
     * loggable·extension_origin 이 모두 없는 옛 행은 설명 키의 네임스페이스로 origin 을 추론한다.
     *
     * 출처 자동 주입(extension_origin)이 들어오기 전에 쌓인 행은 properties 가 비어 있어, 확장 lang 의 라벨을
     * 찾지 못하고 `category.reorder` 같은 원문 키가 목록에 보였다(#138 실측 ⑧ 전수 조사).
     *
     * @scenario origin_type=module, loggable_state=properties_origin_empty
     *
     * @effects legacy_rows_resolve_origin_from_description_key
     *
     * @return void 반환값 없음
     */
    public function test_resolves_origin_from_description_key_namespace_for_legacy_rows(): void
    {
        Lang::addNamespace('sirsoft-ecommerce', __DIR__);
        Lang::addLines([
            'activity_log.action.category.reorder' => '카테고리 순서 변경 (모듈)',
        ], 'ko', 'sirsoft-ecommerce');

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'category.reorder',
            'loggable_type' => null,
            'loggable_id' => null,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.category_reorder',
            'properties' => null,
        ]);

        $this->assertSame('카테고리 순서 변경 (모듈)', $log->action_label);
    }

    /**
     * 코어 origin 라벨이 모듈 변경에도 회귀 없이 동작 (하위 호환)
     *
     * @scenario origin_type=core, loggable_state=model_with_core_fqcn
     *
     * @effects core_origin_action_unaffected_by_module_lang
     *
     * @return void 반환값 없음
     */
    public function test_core_origin_action_label_unchanged_after_introduction(): void
    {
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'auth.login',
            'loggable_type' => 'App\\Models\\User',
            'loggable_id' => 1,
        ]);

        $this->assertSame('로그인', $log->action_label);
    }
}
