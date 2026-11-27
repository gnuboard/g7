<?php

namespace App\Http\Requests\Plugin;

use App\Extension\HookManager;
use App\Extension\Layout\LayoutSyncOptions;
use App\Http\Requests\Concerns\HasPackageIntegrityRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 플러그인 업데이트 실행 요청 검증
 *
 * layout_strategy: 레이아웃 전략 (overwrite 또는 keep)
 * vendor_mode: Vendor 설치 모드 (auto, composer, bundled)
 */
class PerformPluginUpdateRequest extends FormRequest
{
    use HasPackageIntegrityRules;

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 항상 true (권한은 미들웨어 체인에서 검증)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'layout_strategy' => ['nullable', 'string', 'in:overwrite,keep'],
            'vendor_mode' => ['nullable', 'string', 'in:auto,composer,bundled'],
            // 코어 버전 비호환 강제 우회 플래그 (위험 인지 후 사용자 명시 필요)
            'force' => ['nullable', 'boolean'],
            // 업데이트 후 검색 인덱스 재생성 여부 — 인덱스 잠금·재색인 비용이 있어 기본은 미수행
            'rebuild_search_index' => ['nullable', 'boolean'],
            // 레이아웃 설치 소스(auto|json) — 이 요청 동안만 설정을 덮는다. 생략하면 설정(LAYOUT_INSTALL_SOURCE)을 따른다.
            'layout_source' => ['nullable', 'string', 'in:auto,json'],
            // 업데이트 소스(auto|bundled|github) — 터미널 --source 와 같다. 생략하면 auto(지금 동작)
            'source' => ['nullable', 'string', 'in:auto,bundled,github'],
            // 수동 ZIP — 대기 폴더(storage/app/extension_pending)에 올린 파일 이름만 받는다(경로 금지, 터미널 --zip 과 같은 효과)
            'zip_file' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+\.zip$/i'],
            // 업데이트 미리보기가 내려받은 새 판(`_pending`)을 다시 쓰는 토큰 — 미리보기 응답의 pending_token
            'pending_token' => ['nullable', 'string', 'alpha_num', 'size:40'],
            // 화면별 결정 — 키는 미리보기 응답의 layouts.entries[].key, 값은 take-theirs(새 판)|keep-ours(운영자 판)
            'decisions' => ['nullable', 'array'],
            'decisions.*' => ['string', Rule::in([LayoutSyncOptions::TAKE_THEIRS, LayoutSyncOptions::KEEP_OURS])],
        ];

        // 패키지 무결성 입력 — 미서명 계속(allow_unsigned) (7.2.0)
        $rules = array_merge($rules, $this->packageIntegrityRules());

        return HookManager::applyFilters('core.plugin.perform_update_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->packageIntegrityMessages() + [
            'layout_strategy.in' => __('plugins.errors.invalid_layout_strategy'),
            'vendor_mode.in' => __('plugins.errors.invalid_vendor_mode'),
            'layout_source.in' => __('plugins.errors.invalid_layout_source'),
            'source.in' => __('extensions.errors.invalid_update_source'),
            'zip_file.regex' => __('extensions.errors.invalid_manual_zip_name'),
        ];
    }

    /**
     * 검증 오류 문구에 쓸 칸 이름 라벨을 돌려줍니다.
     *
     * @return array<string, string> 필드 => 라벨
     */
    public function attributes(): array
    {
        return $this->packageIntegrityAttributes() + [
            'pending_token' => __('validation.attributes.pending_token'),
            'zip_file' => __('validation.attributes.update_zip_file'),
            'source' => __('validation.attributes.update_source'),
            'force' => __('validation.attributes.update_force'),
            'decisions' => __('validation.attributes.decisions'),
            'decisions.*' => __('validation.attributes.decisions.*'),
        ];
    }
}
