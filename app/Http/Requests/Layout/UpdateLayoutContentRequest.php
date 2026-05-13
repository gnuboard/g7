<?php

namespace App\Http\Requests\Layout;

use App\Extension\HookManager;
use App\Rules\NoExternalUrls;
use App\Rules\ValidDataSourceMerge;
use App\Rules\ValidLayoutStructure;
use App\Rules\ValidParentLayout;
use App\Rules\ValidPermissionStructure;
use App\Rules\ValidSlotStructure;
use App\Rules\WhitelistedEndpoint;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 레이아웃 Content 업데이트 요청 검증
 *
 * 레이아웃의 content JSON 구조를 검증합니다.
 * Custom Rule을 통해 레이아웃 구조, 엔드포인트, 슬롯, 데이터소스 병합을 검증합니다.
 */
class UpdateLayoutContentRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전 데이터 전처리
     *
     * content가 JSON 문자열로 전송된 경우 배열로 변환합니다.
     * 프론트엔드에서 CodeEditor의 값을 그대로 전송할 수 있으므로
     * 문자열/배열 모두 처리할 수 있도록 합니다.
     *
     * 또한, ValidParentLayout 규칙에서 template_id를 사용하므로
     * 라우트 파라미터에서 templateName을 가져와 template_id로 변환합니다.
     */
    protected function prepareForValidation(): void
    {
        $content = $this->input('content');

        // content가 JSON 문자열인 경우 배열로 변환
        if (is_string($content)) {
            $decoded = json_decode($content, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->merge(['content' => $decoded]);
            }
            // JSON 파싱 실패 시 원본 유지 (검증에서 'array' 규칙으로 실패 처리)
        }

        // 라우트 파라미터에서 templateName을 가져와 template_id로 변환
        // ValidParentLayout 규칙에서 template_id를 사용하기 때문에 필요
        $templateName = $this->route('templateName');
        if ($templateName && ! $this->has('template_id')) {
            $template = \App\Models\Template::where('identifier', $templateName)->first();
            if ($template) {
                $this->merge(['template_id' => $template->id]);
            }
        }
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * extends 레이아웃과 standalone 레이아웃의 구조 차이를 고려합니다:
     * - standalone: endpoint, components 필수
     * - extends: extends, slots 사용 (endpoint, components는 부모에서 상속)
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $content = $this->input('content');

        // extends 레이아웃 여부 (extends 필드가 있고 null이 아닌 경우)
        $isExtending = is_array($content) && isset($content['extends']) && $content['extends'] !== null;

        // Base 레이아웃 여부 (slots를 정의하고 있는 경우 - 다른 레이아웃이 상속받는 용도)
        $isBaseLayout = is_array($content) && isset($content['slots']) && is_array($content['slots']);

        $rules = [
            // content 전체 검증 (ValidLayoutStructure에서 extends/standalone 분기 처리)
            'content' => [
                'required',
                'array',
                new ValidLayoutStructure,
            ],

            // 버전 필드
            'content.version' => ['required', 'string'],

            // 레이아웃명
            'content.layout_name' => ['required', 'string', 'max:255'],

            // 상속 검증 (extends가 있는 경우)
            'content.extends' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, Closure $fail) {
                    if ($value === null) {
                        return;
                    }
                    $layoutId = request()->route('id');
                    $validator = new ValidParentLayout($layoutId);
                    $validator->validate($attribute, $value, $fail);
                },
            ],

            // 슬롯 검증 (extends 레이아웃에서 사용)
            'content.slots' => [
                'nullable',
                'array',
                new ValidSlotStructure,
            ],

            // 데이터소스 검증
            'content.data_sources' => [
                'nullable',
                'array',
                new ValidDataSourceMerge,
            ],

            // 메타데이터 (legacy - metadata 키 사용하는 경우)
            'content.metadata' => ['nullable', 'array'],

            // 메타 정보 (title, description, auth_required 등)
            'content.meta' => ['nullable', 'array'],
            'content.meta.title' => ['nullable', 'string'],
            'content.meta.description' => ['nullable', 'string'],
            'content.meta.auth_required' => ['nullable', 'boolean'],
            'content.meta.is_base' => ['nullable', 'boolean'],

            // SEO 메타데이터
            'content.meta.seo' => ['nullable', 'array'],
            'content.meta.seo.enabled' => ['nullable', 'boolean'],
            'content.meta.seo.data_sources' => ['nullable', 'array'],
            'content.meta.seo.data_sources.*' => ['string'],
            'content.meta.seo.priority' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'content.meta.seo.changefreq' => ['nullable', 'string', 'in:always,hourly,daily,weekly,monthly,yearly,never'],
            'content.meta.seo.og' => ['nullable', 'array'],
            'content.meta.seo.structured_data' => ['nullable', 'array'],

            // 모달 컴포넌트 정의
            'content.modals' => ['nullable', 'array'],

            // 상태 정의 (레이아웃 레벨 초기 상태)
            'content.state' => ['nullable', 'array'],

            // 초기화 액션 (레이아웃 로드 시 실행)
            'content.init_actions' => ['nullable', 'array'],

            // 정의 (재사용 가능한 컴포넌트 조각)
            'content.defines' => ['nullable', 'array'],

            // 초기 상태 (init_state - state의 대체 키)
            'content.init_state' => ['nullable', 'array'],

            // 라우트 정의
            'content.routes' => ['nullable', 'array'],

            // 계산된 속성
            'content.computed' => ['nullable', 'array'],

            // Named Actions (재사용 가능한 액션 정의)
            'content.named_actions' => ['nullable', 'array'],
            'content.named_actions.*' => ['array'],

            // 권한 (레이아웃 접근에 필요한 권한 식별자 배열 또는 OR/AND 구조)
            'content.permissions' => ['nullable', new ValidPermissionStructure],

            // 전역 헤더 (API 호출 시 자동 적용되는 HTTP 헤더)
            'content.globalHeaders' => ['nullable', 'array'],
            'content.globalHeaders.*.pattern' => ['required', 'string'],
            'content.globalHeaders.*.headers' => ['required', 'array'],
            'content.globalHeaders.*.headers.*' => ['string'],

            // 전환 오버레이 설정 (페이지 전환 시 stale DOM 방지)
            'content.transition_overlay' => ['nullable'],
            'content.transition_overlay.enabled' => ['nullable', 'boolean'],
            'content.transition_overlay.style' => ['nullable', 'string', Rule::in(['opaque', 'blur', 'fade', 'skeleton', 'spinner'])],
            'content.transition_overlay.target' => ['nullable', 'string', 'max:100'],
            'content.transition_overlay.fallback_target' => ['nullable', 'string', 'max:100'],
            'content.transition_overlay.skeleton' => ['nullable', 'array'],
            'content.transition_overlay.skeleton.component' => ['nullable', 'required_with:content.transition_overlay.skeleton', 'string', 'max:100'],
            'content.transition_overlay.skeleton.animation' => ['nullable', 'string', Rule::in(['pulse', 'wave', 'none'])],
            'content.transition_overlay.skeleton.iteration_count' => ['nullable', 'integer', 'min:1', 'max:50'],
            'content.transition_overlay.spinner' => ['nullable', 'array'],
            'content.transition_overlay.spinner.component' => ['nullable', 'string', 'max:100'],
            'content.transition_overlay.spinner.text' => ['nullable', 'string', 'max:200'],
            // wait_for: spinner 가 명시된 progressive/blocking 데이터소스 fetch 완료까지 표시되도록 가드
            // background/websocket 데이터소스는 의도상 사용자 차단 불가 → withValidator 에서 cross-field 검증
            'content.transition_overlay.wait_for' => ['nullable', 'array'],
            'content.transition_overlay.wait_for.*' => ['string', 'max:100'],
        ];

        // extends 레이아웃 또는 Base 레이아웃이 아닌 경우에만 endpoint, components 필수
        // - extends 레이아웃: 부모로부터 endpoint 상속
        // - Base 레이아웃 (slots 정의): 자식 레이아웃이 endpoint 정의
        if (! $isExtending && ! $isBaseLayout) {
            $rules['content.endpoint'] = [
                'required',
                'string',
                new WhitelistedEndpoint,
                new NoExternalUrls,
            ];
            $rules['content.components'] = ['required', 'array'];
        } else {
            // extends 또는 Base 레이아웃은 endpoint가 선택적
            $rules['content.endpoint'] = [
                'nullable',
                'string',
                new WhitelistedEndpoint,
                new NoExternalUrls,
            ];
            // extends 레이아웃은 components 또는 slots 중 하나 사용
            // ValidLayoutStructure에서 상세 검증
            $rules['content.components'] = ['nullable', 'array'];
        }

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.layout.update_content_validation_rules', $rules, $this);
    }

    /**
     * Cross-field 검증 — transition_overlay.wait_for 가 가리키는 데이터소스의 type/loading_strategy 검증
     *
     * wait_for 는 spinner 가 fetch 완료까지 표시되어야 할 데이터소스 ID 목록이지만,
     * 의미상 사용자를 차단할 수 없는 background/websocket 데이터소스는 사전에 차단한다.
     */
    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Contracts\Validation\Validator $v): void {
            $waitFor = $this->input('content.transition_overlay.wait_for');
            if (! is_array($waitFor) || empty($waitFor)) {
                return;
            }

            $dataSources = $this->input('content.data_sources');
            if (! is_array($dataSources)) {
                return;
            }

            $byId = [];
            foreach ($dataSources as $source) {
                if (is_array($source) && isset($source['id'])) {
                    $byId[$source['id']] = $source;
                }
            }

            foreach ($waitFor as $index => $id) {
                if (! is_string($id) || ! isset($byId[$id])) {
                    continue; // 미존재 ID 는 엔진에서 자동 무시됨 (가드 무시)
                }
                $source = $byId[$id];
                $type = $source['type'] ?? 'api';
                $strategy = $source['loading_strategy'] ?? 'progressive';
                if ($type === 'websocket') {
                    $v->errors()->add(
                        "content.transition_overlay.wait_for.$index",
                        __('validation.layout.transition_overlay.wait_for.websocket', ['id' => $id])
                    );
                } elseif ($strategy === 'background') {
                    $v->errors()->add(
                        "content.transition_overlay.wait_for.$index",
                        __('validation.layout.transition_overlay.wait_for.background', ['id' => $id])
                    );
                }
            }
        });
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content.required' => __('validation.layout.content.required'),
            'content.array' => __('validation.layout.content.array'),
            'content.version.required' => __('validation.layout.version.required'),
            'content.version.string' => __('validation.layout.version.string'),
            'content.layout_name.required' => __('validation.layout.layout_name.required'),
            'content.layout_name.string' => __('validation.layout.layout_name.string'),
            'content.layout_name.max' => __('validation.layout.layout_name.max'),
            'content.endpoint.required' => __('validation.layout.endpoint.required'),
            'content.endpoint.string' => __('validation.layout.endpoint.string'),
            'content.extends.string' => __('validation.layout.extends.string'),
            'content.slots.array' => __('validation.layout.slots.array'),
            'content.data_sources.array' => __('validation.layout.data_sources.array'),
            'content.components.required' => __('validation.layout.components.required'),
            'content.components.array' => __('validation.layout.components.array'),
            'content.metadata.array' => __('validation.layout.metadata.array'),
            'content.meta.array' => __('validation.layout.meta.array'),
            'content.meta.title.string' => __('validation.layout.meta.title.string'),
            'content.meta.description.string' => __('validation.layout.meta.description.string'),
            'content.meta.auth_required.boolean' => __('validation.layout.meta.auth_required.boolean'),
            'content.meta.is_base.boolean' => __('validation.layout.meta.is_base.boolean'),
            'content.meta.seo.array' => __('validation.layout.meta.seo.array'),
            'content.meta.seo.enabled.boolean' => __('validation.layout.meta.seo.enabled.boolean'),
            'content.meta.seo.data_sources.array' => __('validation.layout.meta.seo.data_sources.array'),
            'content.meta.seo.data_sources.*.string' => __('validation.layout.meta.seo.data_sources.string'),
            'content.meta.seo.priority.numeric' => __('validation.layout.meta.seo.priority.numeric'),
            'content.meta.seo.priority.min' => __('validation.layout.meta.seo.priority.min'),
            'content.meta.seo.priority.max' => __('validation.layout.meta.seo.priority.max'),
            'content.meta.seo.changefreq.string' => __('validation.layout.meta.seo.changefreq.string'),
            'content.meta.seo.changefreq.in' => __('validation.layout.meta.seo.changefreq.in'),
            'content.meta.seo.og.array' => __('validation.layout.meta.seo.og.array'),
            'content.meta.seo.structured_data.array' => __('validation.layout.meta.seo.structured_data.array'),
            'content.modals.array' => __('validation.layout.modals.array'),
            'content.state.array' => __('validation.layout.state.array'),
            'content.init_actions.array' => __('validation.layout.init_actions.array'),
            'content.defines.array' => __('validation.layout.defines.array'),
            'content.init_state.array' => __('validation.layout.init_state.array'),
            'content.routes.array' => __('validation.layout.routes.array'),
            'content.computed.array' => __('validation.layout.computed.array'),
            'content.permissions' => __('validation.layout.permissions.array'),
            'content.globalHeaders.array' => __('validation.layout.globalHeaders.array'),
            'content.globalHeaders.*.pattern.required' => __('validation.layout.globalHeaders.pattern.required'),
            'content.globalHeaders.*.pattern.string' => __('validation.layout.globalHeaders.pattern.string'),
            'content.globalHeaders.*.headers.required' => __('validation.layout.globalHeaders.headers.required'),
            'content.globalHeaders.*.headers.array' => __('validation.layout.globalHeaders.headers.array'),
            'content.globalHeaders.*.headers.*.string' => __('validation.layout.globalHeaders.headers.string'),

            // transition_overlay
            'content.transition_overlay.enabled.boolean' => __('validation.layout.transition_overlay.enabled.boolean'),
            'content.transition_overlay.style.string' => __('validation.layout.transition_overlay.style.string'),
            'content.transition_overlay.style.in' => __('validation.layout.transition_overlay.style.in'),
            'content.transition_overlay.target.string' => __('validation.layout.transition_overlay.target.string'),
            'content.transition_overlay.target.max' => __('validation.layout.transition_overlay.target.max'),
            'content.transition_overlay.fallback_target.string' => __('validation.layout.transition_overlay.fallback_target.string'),
            'content.transition_overlay.fallback_target.max' => __('validation.layout.transition_overlay.fallback_target.max'),
            'content.transition_overlay.skeleton.array' => __('validation.layout.transition_overlay.skeleton.array'),
            'content.transition_overlay.skeleton.component.string' => __('validation.layout.transition_overlay.skeleton.component.string'),
            'content.transition_overlay.skeleton.component.max' => __('validation.layout.transition_overlay.skeleton.component.max'),
            'content.transition_overlay.skeleton.animation.string' => __('validation.layout.transition_overlay.skeleton.animation.string'),
            'content.transition_overlay.skeleton.animation.in' => __('validation.layout.transition_overlay.skeleton.animation.in'),
            'content.transition_overlay.skeleton.iteration_count.integer' => __('validation.layout.transition_overlay.skeleton.iteration_count.integer'),
            'content.transition_overlay.skeleton.iteration_count.min' => __('validation.layout.transition_overlay.skeleton.iteration_count.min'),
            'content.transition_overlay.skeleton.iteration_count.max' => __('validation.layout.transition_overlay.skeleton.iteration_count.max'),
        ];
    }
}
