<?php

namespace Tests\Feature\Settings;

use App\Extension\HookManager;
use App\Http\Requests\Settings\SaveSettingsRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 환경설정 검증 문구의 필드 라벨 계약 테스트
 *
 * Laravel 은 `attributes()` 에 라벨이 없으면 필드 키를 풀어쓴 영문을 :attribute 에 넣는다.
 * 화면 라벨은 한국어인데 오류 문구만 "seo.bot_user_agents.0 필드는 …" 으로 나오면 운영자는
 * 어느 칸을 고쳐야 하는지 알 수 없다. 인덱스가 붙는 배열 항목과 탭 묶음이 특히 그랬다.
 *
 * 라벨을 지우면 이 테스트가 원시 키를 발견해 red 가 된다.
 */
class ValidationAttributeLabelTest extends TestCase
{
    /**
     * 필드에 일부러 잘못된 값을 넣고 만들어진 검증 문구를 돌려줍니다.
     *
     * 와일드카드 키는 인덱스 0 으로 펼쳐 실제 요청과 같은 모양으로 검사합니다.
     *
     * @param  string  $field  규칙 키 (와일드카드 허용)
     * @param  array  $rules  검사에 쓸 규칙
     * @param  mixed  $invalid  그 규칙을 반드시 어기는 값
     * @return array{0: string, 1: string, 2: string} 문구 · 라벨 · 펼친 키
     */
    private function messageFor(string $field, array $rules, mixed $invalid): array
    {
        $this->app->setLocale('ko');

        $attributes = (new SaveSettingsRequest)->attributes();
        $this->assertArrayHasKey($field, $attributes, "{$field} 라벨이 없습니다 — 검증 문구에 키가 그대로 노출됩니다.");

        $concrete = str_replace('*', '0', $field);
        $payload = [];
        data_set($payload, $concrete, $invalid);

        $message = Validator::make($payload, [$field => $rules], [], $attributes)->errors()->first();
        $this->assertNotSame('', $message, "{$field} 검증이 아무 문구도 만들지 못했습니다 — 탐침 값을 확인하세요.");

        return [$message, $attributes[$field], $concrete];
    }

    /**
     * 문구가 라벨을 쓰고 원시 키를 쓰지 않는지 단언합니다.
     *
     * @param  string  $field  규칙 키
     * @param  array  $rules  검사에 쓸 규칙
     * @param  mixed  $invalid  규칙을 어기는 값
     * @return void 반환값 없음
     */
    private function assertUsesLabel(string $field, array $rules, mixed $invalid): void
    {
        [$message, $label, $concrete] = $this->messageFor($field, $rules, $invalid);

        $this->assertStringNotContainsString($concrete, $message, "{$field} 문구에 원시 키가 실렸습니다: {$message}");
        $this->assertStringContainsString($label, $message, "{$field} 문구에 라벨이 실리지 않았습니다: {$message}");
    }

    /**
     * 탭 묶음(general·security·notifications)의 문구가 운영자 라벨을 씁니다.
     *
     * @return void 반환값 없음
     *
     * @scenario field_shape=tab_container
     *
     * @effects tab_container_message_uses_operator_label, message_never_contains_raw_field_key
     */
    #[Test]
    public function test_tab_container_message_uses_operator_label(): void
    {
        foreach (['general', 'security', 'notifications', '_tab'] as $field) {
            $this->assertUsesLabel($field, ['array'], 'not-an-array');
        }
    }

    /**
     * 중첩 스칼라 키의 문구가 운영자 라벨을 씁니다.
     *
     * 점이 포함된 키는 코어 lang 의 평탄 `attributes` 배열로는 해소되지 않으므로
     * FormRequest 가 직접 라벨을 줘야 한다 — 그 배선을 잠근다.
     *
     * @return void 반환값 없음
     *
     * @scenario field_shape=nested_scalar
     *
     * @effects nested_scalar_message_uses_operator_label, message_never_contains_raw_field_key
     */
    #[Test]
    public function test_nested_scalar_message_uses_operator_label(): void
    {
        $this->assertUsesLabel('notifications.channels.*.id', ['required'], null);
        $this->assertUsesLabel('notifications.channels.*.is_active', ['boolean'], 'not-a-bool');
        $this->assertUsesLabel('notifications.channels.*.sort_order', ['integer'], 'not-a-number');
    }

    /**
     * 배열 항목 키의 문구에 인덱스가 실리지 않습니다.
     *
     * 와일드카드 라벨 한 줄이 모든 인덱스를 덮는지 인덱스 두 개로 확인한다.
     *
     * @return void 반환값 없음
     *
     * @scenario field_shape=wildcard_item
     *
     * @effects wildcard_label_covers_every_array_index, message_never_contains_raw_field_key
     */
    #[Test]
    public function test_wildcard_label_covers_every_array_index(): void
    {
        $this->assertUsesLabel('seo.bot_user_agents.*', ['string', 'max:100'], str_repeat('x', 300));
        $this->assertUsesLabel('general.site_logo.*', ['string'], 123);
        $this->assertUsesLabel('seo.og_image_default.*', ['string'], 123);
        $this->assertUsesLabel('advanced.outbound_proxy_bypass.*', ['string'], 123);
        $this->assertUsesLabel('security.ip_restriction_rules.*', ['array'], 'not-an-array');

        $this->app->setLocale('ko');
        $attributes = (new SaveSettingsRequest)->attributes();
        $messages = Validator::make(
            ['seo' => ['bot_user_agents' => [str_repeat('x', 300), str_repeat('y', 300)]]],
            ['seo.bot_user_agents.*' => ['string', 'max:100']],
            [],
            $attributes
        )->errors()->all();

        $this->assertCount(2, $messages, '두 항목 모두 문구가 나와야 인덱스 커버를 확인할 수 있습니다.');
        foreach ($messages as $index => $message) {
            $this->assertStringNotContainsString("seo.bot_user_agents.{$index}", $message, "인덱스 {$index} 문구에 원시 키가 실렸습니다: {$message}");
        }
    }

    /**
     * 확장이 규칙을 얹은 자리에 라벨도 얹을 수 있는지 단언합니다.
     *
     * `rules()` 는 `core.settings.save_validation_rules` 로 확장의 규칙 키를 받는다. 라벨 쪽에
     * 짝이 되는 훅이 없으면 **확장이 얹은 필드는 라벨을 줄 방법 자체가 없어** 오류 문구에 원시
     * 키가 그대로 나간다. 감사 룰은 정적 리터럴만 읽어 이 경로를 재지 못하므로 여기서 잠근다.
     *
     * @return void 반환값 없음
     *
     * @scenario field_shape=nested_scalar
     *
     * @effects nested_scalar_message_uses_operator_label, message_never_contains_raw_field_key
     */
    #[Test]
    public function test_extension_added_rule_keys_can_carry_labels(): void
    {
        $this->app->setLocale('ko');

        HookManager::addFilter('core.settings.save_validation_attributes', static function (array $attributes): array {
            $attributes['vendor_ext.api_secret'] = '연동 비밀키';

            return $attributes;
        });

        $attributes = (new SaveSettingsRequest)->attributes();

        $this->assertSame('연동 비밀키', $attributes['vendor_ext.api_secret'] ?? null, '라벨 훅이 반영되지 않았습니다 — 확장은 라벨을 줄 자리가 없습니다.');

        $message = Validator::make(
            ['vendor_ext' => ['api_secret' => str_repeat('x', 300)]],
            ['vendor_ext.api_secret' => ['string', 'max:10']],
            [],
            $attributes
        )->errors()->first();

        $this->assertStringContainsString('연동 비밀키', $message, "확장이 준 라벨이 문구에 실려야 합니다: {$message}");
        $this->assertStringNotContainsString('vendor_ext', $message, "문구에 원시 키가 실렸습니다: {$message}");
    }
}
