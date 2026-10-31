<?php

namespace Tests\Unit\Models\Concerns;

use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 배열 캐스트 칸을 모델 저장으로 바꿀 때 「표기만 다른 값」 을 수정으로 기록하지 않는다 (#699 실측 결함).
 *
 * `Model::save()` 는 바뀐 칸을 **직렬화한 값**(배열 → JSON 문자열)으로 쿼리 빌더에 넘긴다. 그 빌더
 * (`UserOverridesAwareBuilder`)가 다시 수정 여부를 계산하는데, 원래 값(null)과 문자열 `'[]'` 을 비교해 「바뀌었다」 로
 * 판정했다. 그 결과 수신자 규칙이 없는(null) 알림 템플릿 — 본인인증 메일 문구가 그렇다 — 을 편집 창에서 아무것도
 * 바꾸지 않고 저장해도 `recipients` 가 운영자 수정으로 기록되고 「기본」 표시가 풀렸다.
 */
class HasUserOverridesSerializedArrayTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 수신자 규칙이 비어 있는(null) 템플릿 하나를 만든다.
     *
     * @return NotificationTemplate 만든 템플릿
     */
    private function templateWithoutRecipients(): NotificationTemplate
    {
        $definition = NotificationDefinition::create([
            'type' => 'serialized-array-test',
            'hook_prefix' => 'core.identity',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '테스트'],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => [],
            'is_active' => true,
            'is_default' => true,
        ]);

        return NotificationTemplate::create([
            'definition_id' => $definition->id,
            'channel' => 'mail',
            'subject' => ['ko' => '제목'],
            'body' => ['ko' => '본문'],
            'recipients' => null,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    /**
     * 빈 수신자 배열로 저장해도(원래 null) 운영자 수정이 기록되지 않는다.
     *
     * @scenario surface=value_comparator
     *
     * @effects serialized_empty_array_save_is_not_a_change
     *
     * @return void 반환값 없음
     */
    public function test_saving_empty_array_over_null_records_no_override(): void
    {
        $template = $this->templateWithoutRecipients();

        $template->update(['recipients' => []]);

        $this->assertNull($template->fresh()->user_overrides, '표기만 다른 저장([] ↔ null)이 수정으로 기록되었다');
    }

    /**
     * 실제로 수신자 규칙을 넣으면 종전대로 `recipients` 가 기록된다(판정을 약화시키지 않았다).
     *
     * @scenario surface=value_comparator
     *
     * @effects serialized_real_array_change_is_recorded
     *
     * @return void 반환값 없음
     */
    public function test_saving_real_recipients_records_override(): void
    {
        $template = $this->templateWithoutRecipients();

        $template->update(['recipients' => [['type' => 'trigger_user']]]);

        $this->assertSame(['recipients'], $template->fresh()->user_overrides);
    }
}
