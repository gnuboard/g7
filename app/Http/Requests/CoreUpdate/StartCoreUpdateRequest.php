<?php

// audit:allow api-doc-coverage 609 후속 — 검증 오류 문구에 쓰는 항목 이름(attributes)만 더했다. 라우트, 요청 키, 검증 규칙, 응답 모양은 그대로이고 API 문서는 오류 문구 원문을 싣지 않아 반영할 표면이 없다.

namespace App\Http\Requests\CoreUpdate;

use App\CoreUpdate\CoreUpdateOptions;
use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 웹 코어 업데이트 착수 요청 검증.
 *
 * **여기 없는 것이 규정이다** — `force`·`prune`·`no_backup`·`no_maintenance` 는 받지
 * 않는다 (D27). 검증에서 거르는 것이 아니라 **입력 표면 자체를 두지 않는다**: 규칙으로
 * 거르면 「언젠가 규칙만 풀면 켜지는 값」이 되고, 그 상태로는 「웹은 안전 기본값 고정」이
 * 선언으로만 남는다.
 *
 * @since 7.1.0
 */
class StartCoreUpdateRequest extends FormRequest
{
    /**
     * 권한 판정은 라우트 미들웨어가 담당합니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙.
     *
     * @return array<string, mixed> 규칙 배열
     */
    public function rules(): array
    {
        $rules = [
            'update_bundled_extensions' => ['sometimes', 'boolean'],
            'rebuild_search_index' => ['sometimes', 'boolean'],
            'layout_strategy' => ['sometimes', Rule::in([
                CoreUpdateOptions::LAYOUT_OVERWRITE,
                CoreUpdateOptions::LAYOUT_KEEP,
            ])],
            // 디렉토리는 서버가 정한다 — 파일 이름만 받는다 (㉯ — D18).
            'zip_file' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[^\/\\\\]+\.zip$/i'],
            // 미서명 배포본 확인 후 계속(7.2.0) — 409 경고 창의 「미서명으로 계속」이 같은 요청에 1 을 실어 다시 보낸다.
            // 「미서명 패키지 차단」이 켜져 있으면 이 값으로도 진행하지 않는다.
            'allow_unsigned' => ['sometimes', 'nullable', 'boolean'],
            // 무결성을 아는 화면의 표식(7.2.0) — 새 관리자 화면은 늘 true 를 싣는다. 없으면 「미서명으로 계속」을 그릴 수 없는
            // 옛 화면(7.1.x 템플릿 복사본)으로 보고 미서명 배포본을 7.1 동작대로 허용한다(차단 스위치·서명 불일치·미신뢰는 그대로 거절).
            'integrity_aware' => ['sometimes', 'nullable', 'boolean'],
        ];

        // 확장이 자기 선택 항목을 착수 화면에 얹을 수 있는 지점. **위 안전 기본값을
        // 뒤집는 키는 여기서도 받지 않는다** — 훅으로 `force` 를 되살릴 수 있으면 D27 이
        // 선언으로만 남는다. 오케스트레이터가 읽는 것은 `CoreUpdateOptions` 의 필드뿐이다.
        return HookManager::applyFilters('core.core_update.start_validation_rules', $rules, $this);
    }

    /**
     * 검증 전 값 정규화 — 체크박스가 문자열로 도착하는 경우를 흡수합니다.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['update_bundled_extensions', 'rebuild_search_index', 'allow_unsigned', 'integrity_aware'] as $key) {
            if (! $this->has($key)) {
                continue;
            }

            $value = $this->input($key);

            // 해석할 수 없는 값은 건드리지 않는다 — null 로 접으면 오타가 「기본값」으로
            // 통과해 운영자가 고른 것과 다른 동작이 된다.
            if (is_string($value) && in_array(strtolower($value), ['true', 'false', '1', '0', ''], true)) {
                $normalized[$key] = in_array(strtolower($value), ['true', '1'], true);
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /**
     * 검증 오류 문구에 쓰는 항목 이름.
     *
     * @return array<string, string> 필드 이름 → 번역된 항목 이름
     */
    public function attributes(): array
    {
        return [
            'update_bundled_extensions' => (string) __('settings.core_update.web.attributes.update_bundled_extensions'),
            'rebuild_search_index' => (string) __('settings.core_update.web.attributes.rebuild_search_index'),
            'layout_strategy' => (string) __('settings.core_update.web.attributes.layout_strategy'),
            'zip_file' => (string) __('settings.core_update.web.attributes.zip_file'),
            'allow_unsigned' => (string) __('extension_integrity.attributes.allow_unsigned'),
            'integrity_aware' => (string) __('extension_integrity.attributes.integrity_aware'),
        ];
    }

    /**
     * 검증 메시지.
     *
     * @return array<string, string> 메시지 배열
     */
    public function messages(): array
    {
        return [
            'zip_file.regex' => (string) __('settings.core_update.web.zip_file_invalid'),
        ];
    }
}
