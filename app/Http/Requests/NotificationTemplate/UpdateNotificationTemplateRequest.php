<?php

namespace App\Http\Requests\NotificationTemplate;

use App\Extension\HookManager;
use App\Models\NotificationTemplate;
use App\Rules\LocaleRequiredTranslatable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNotificationTemplateRequest extends FormRequest
{
    /**
     * 권한 확인 (미들웨어에서 처리).
     *
     * @return bool 항상 true (권한은 미들웨어에서 검증)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array 필드별 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            // subject 컬럼은 nullable 이다 (제목 개념이 없는 채널이 존재).
            // 스키마와 정합하도록 미전송/null 을 허용한다.
            'subject' => ['sometimes', 'nullable', 'array', new LocaleRequiredTranslatable(maxLength: 500)],
            'body' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 65535)],
            'click_url' => ['nullable', 'string', 'max:500'],
            'recipients' => ['sometimes', 'nullable', 'array'],
            'recipients.*.type' => ['required', 'string', 'in:trigger_user,related_user,role,specific_users'],
            // 값의 모양은 타입이 정한다. `nullable` 하나만 두면 역할 칸에 객체가, 사용자 칸에
            // 문자열이 들어와도 그대로 저장되고 **발송 시점에 수신자 0명**으로만 드러난다 —
            // 예외도 로그도 화면에 남지 않는다.
            'recipients.*.value' => ['nullable'],
            'recipients.*.relation' => ['nullable', 'string', 'max:100'],
            'recipients.*.exclude_trigger_user' => ['nullable', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        foreach ((array) $this->input('recipients', []) as $index => $rule) {
            $rules["recipients.{$index}.value"] = $this->valueRulesFor(is_array($rule) ? ($rule['type'] ?? null) : null);

            if (is_array($rule) && ($rule['type'] ?? null) === 'specific_users') {
                $rules["recipients.{$index}.value.*"] = ['string', 'max:64'];
            }

            if (is_array($rule) && ($rule['type'] ?? null) === 'role') {
                $rules["recipients.{$index}.value.*"] = ['string', 'max:100'];
            }

            if (is_array($rule) && ($rule['type'] ?? null) === 'related_user') {
                $declared = $this->declaredRelations();
                // NULL = 아직 선언 전(업그레이드 직후 확장이 재동기화되기 전) — 선언 밖으로 막지 않는다.
                // [] = 선언했는데 relation 이 없음 — 어떤 키도 통과시키지 않는다.
                $rules["recipients.{$index}.relation"] = $declared === null
                    ? ['required', 'string', 'max:100']
                    : ['required', 'string', 'max:100', Rule::in($declared)];
            }
        }

        $rules = HookManager::applyFilters(
            'core.notification_template.update_validation_rules',
            $rules,
            $this->route('template')
        );

        // 7.0.x 본인인증 메시지 템플릿 수정 검증 필터 — 본인인증 분류 템플릿은 옛 이름으로도 거른다(@deprecated 7.1.0, 8.0 제거).
        $template = $this->route('template');
        if ($template instanceof NotificationTemplate && $template->definition?->category === 'identity') {
            $rules = HookManager::applyFilters('core.identity.message_template.update_validation_rules', $rules, $template);
        }

        return $rules;
    }

    /**
     * 이 템플릿이 속한 정의가 선언한 relation 키 목록을 반환합니다.
     *
     * 선언 밖의 키를 저장하면 발송 시점에 **수신자 0명**이 되고 예외도 로그도 남지 않는다.
     * 선언이 비어 있으면(`[]`) 어떤 키도 통과시키지 않는다 — 화면도 그 타입을 감춘다.
     * 선언 자체가 없으면(`NULL` — 7.1.0 업그레이드 직후 확장이 재동기화되기 전) null 을 돌려
     * 호출부가 선언 밖으로 막지 않게 한다. 막으면 기존 related_user 규칙이 든 템플릿을 저장할 수 없다.
     *
     * @return array<int, string>|null 선언된 relation 키 (선언 전이면 null)
     */
    private function declaredRelations(): ?array
    {
        $template = $this->route('template');
        $relations = $template?->definition?->relations;

        if ($relations === null) {
            return null;
        }

        return is_array($relations) ? array_values(array_filter($relations, 'is_string')) : [];
    }

    /**
     * 수신자 타입별 `value` 규칙을 반환합니다.
     *
     * - `role`: 식별자 문자열 **또는** 문자열 배열(다중 선택). 기존 저장값이 문자열이라
     *   둘 다 받는다 — 배열만 받으면 손대지 않은 8행이 저장 시점에 422 가 된다.
     * - `specific_users`: UUID 배열.
     * - 그 외(`trigger_user`·`related_user`): 값 축이 없다.
     *
     * @param  mixed  $type  수신자 타입
     * @return array<int, mixed> 검증 규칙
     */
    private function valueRulesFor(mixed $type): array
    {
        if ($type === 'specific_users') {
            // 키가 붙은 객체(`{"a":"uuid"}`)는 배열 규칙을 통과하지만 목록이 아니다 — 저장되면 편집 창이
            // 그 값을 목록으로 다루다 어긋난다. 목록만 받는다.
            return ['nullable', 'array', 'list'];
        }

        if ($type !== 'role') {
            return ['nullable'];
        }

        return ['nullable', function (string $attribute, mixed $value, callable $fail): void {
            if ($value === null || is_string($value)) {
                return;
            }

            // 문자열만 담긴 **목록**이어야 한다 — 키가 붙은 객체는 문자열만 담겨도 거절한다.
            if (is_array($value) && array_is_list($value) && count($value) === count(array_filter($value, 'is_string'))) {
                return;
            }

            $fail(__('notification.validation.role_value_shape'));
        }];
    }

    /**
     * 검증을 통과한 값을 반환합니다 — 수신자 규칙은 보낸 줄 순서의 목록으로 맞춥니다.
     *
     * 검증기는 통과 값을 **규칙 키 순서대로** 다시 조립한다. 줄마다 따로 거는 값 규칙
     * (`recipients.{줄}.value`)이 공용 규칙(`recipients.*.type`)보다 앞에 놓이므로, 값 칸이 없는
     * 규칙(이벤트 유발자) 뒤에 역할 규칙이 오면 둘째 줄이 먼저 자리를 잡아 `{"1":…,"0":…}` 객체로
     * 저장됐고, 다시 열면 순서가 뒤집혔다(2026-10-07 실측).
     *
     * @param  array|int|string|null  $key  가져올 키 (없으면 전체)
     * @param  mixed  $default  키가 없을 때 기본값
     * @return mixed 검증 통과 값
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated();

        if (isset($validated['recipients']) && is_array($validated['recipients'])) {
            ksort($validated['recipients'], SORT_NUMERIC);
            $validated['recipients'] = array_values($validated['recipients']);
        }

        return data_get($validated, $key, $default);
    }

    /**
     * 검증 메시지를 반환합니다.
     *
     * @return array 규칙별 오류 메시지
     */
    public function messages(): array
    {
        return [
            'subject.required' => __('validation.required', ['attribute' => __('notification.subject')]),
            'body.required' => __('validation.required', ['attribute' => __('notification.body')]),
        ];
    }

    /**
     * 검증 항목 이름을 반환합니다.
     *
     * 수신자 칸은 규칙 줄 번호가 붙는 배열이라 이름을 주지 않으면 「recipients.0.relation」 같은
     * 내부 키가 안내 문구에 그대로 나온다.
     *
     * @return array<string, string> 필드별 항목 이름
     */
    public function attributes(): array
    {
        return [
            'subject' => __('notification.validation.attributes.subject'),
            'body' => __('notification.validation.attributes.body'),
            'recipients' => __('notification.validation.attributes.recipients'),
            'recipients.*.type' => __('notification.validation.attributes.recipient_type'),
            'recipients.*.value' => __('notification.validation.attributes.recipient_value'),
            'recipients.*.value.*' => __('notification.validation.attributes.recipient_value'),
            'recipients.*.relation' => __('notification.validation.attributes.recipient_relation'),
            'recipients.*.exclude_trigger_user' => __('notification.validation.attributes.recipient_exclude_trigger_user'),
        ];
    }
}
