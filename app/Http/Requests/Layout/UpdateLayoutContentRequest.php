<?php

namespace App\Http\Requests\Layout;

use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\HookManager;
use App\Rules\NoExternalUrls;
use App\Rules\ValidDataSourceMerge;
use App\Rules\ValidLayoutStructure;
use App\Rules\ValidParentLayout;
use App\Rules\ValidPermissionStructure;
use App\Rules\ValidSlotStructure;
use App\Rules\WhitelistedEndpoint;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
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
     *
     * @return bool 항상 true (권한은 미들웨어 체인에서 처리)
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
                $content = $decoded;
            }
            // JSON 파싱 실패 시 원본 유지 (검증에서 'array' 규칙으로 실패 처리)
        }

        // 편집기 응답의 상속/주입/partial 노드 + 편집기 전용 메타 제거.
        // 클라이언트 1차 마스킹(`stripInheritedFromLayoutContent`)이 누락/우회되어도 백엔드가
        // 최종 방어선으로 같은 정책을 적용한다. 정책 상세는 클라이언트 동명 함수 주석 참조.
        if (is_array($content)) {
            $this->merge(['content' => $this->stripInheritedFromLayoutContent($content)]);
        }

        // 라우트 파라미터에서 templateName을 가져와 template_id로 변환
        // ValidParentLayout 규칙에서 template_id를 사용하기 때문에 필요
        // Service-Repository 패턴: Model facade 직접 호출 금지 → Repository Interface 경유.
        $templateName = $this->route('templateName');
        if ($templateName && ! $this->has('template_id')) {
            $template = app(TemplateRepositoryInterface::class)->findByIdentifier($templateName);
            if ($template) {
                $this->merge(['template_id' => $template->id]);
            }
        }
    }

    /**
     * 레이아웃 content 페이로드에서 상속/주입/partial 노드와 편집기 전용 메타를 제거.
     *
     * 편집 모드 응답(`with_source_meta=1`)은 자식 레이아웃의 슬롯에 base 레이아웃 노드를
     * 머지해 노출한다. 이 메타 노드들이 그대로 자식 레이아웃 content 로 저장되면 다음
     * 로드 시 머지가 중복되거나 base/확장/partial 의 변경이 자식에 박힌 사본에 가려진다.
     *
     * 정책 (클라이언트 `stripInheritedFromLayoutContent` 와 동일):
     * - `__editor.original` 은 자식 레이아웃의 **구조 메타** (extends, slots 이름, meta,
     *   data_sources 등) 의 SSoT 일 뿐, **콘텐츠의 SSoT 가 아니다**. 콘텐츠는 사용자가
     *   캔버스에서 누적 편집한 머지된 components 트리에서 마스킹으로 추출한다.
     * - 1) 머지된 components 트리를 마스킹 → 본 자식 레이아웃의 route 콘텐츠만 남김
     * - 2) `__editor.original` 의 구조 메타를 골격으로 차용, 콘텐츠 자리는 1) 결과로 채움
     * - 3) extends 가 있으면 components 키 제거 + slots 로 재구성, 없으면 components 사용
     * - 4) 응답 전용 메타 키(`lock_version`, `__editor`) 제거
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function stripInheritedFromLayoutContent(array $content): array
    {
        // 응답 전용 메타 키 제거 (어느 경로든 페이로드에 박히면 안 됨)
        $content_clean = $content;
        unset($content_clean['lock_version'], $content_clean['__editor']);

        $hasMergedComponents = is_array($content['components'] ?? null) && ! empty($content['components']);
        $hasSlots = is_array($content['slots'] ?? null);
        $hasExtends = isset($content_clean['extends']) && is_string($content_clean['extends']) && $content_clean['extends'] !== '';

        // 경로 A — 클라이언트가 이미 마스킹한 결과 (extends + slots, components 없음 또는 빈 배열).
        // 클라이언트 1차 마스킹의 정상 페이로드. components 트리에서 다시 추출하지 않고
        // slots 안의 각 콘텐츠만 안전망 마스킹 (재마스킹은 메타가 없으므로 no-op 에 가까움).
        if ($hasExtends && $hasSlots && ! $hasMergedComponents) {
            $nextSlots = [];
            foreach ($content_clean['slots'] as $slotName => $slotValue) {
                $nextSlots[$slotName] = is_array($slotValue)
                    ? $this->stripInheritedNodes($slotValue)
                    : $slotValue;
            }
            $content_clean['slots'] = $nextSlots;
            unset($content_clean['components']);

            return $content_clean;
        }

        // 경로 B — 머지된 components 트리가 포함된 페이로드 (편집기 응답 그대로 우회 전송).
        // route 콘텐츠 추출 후 골격에 매핑.
        $original = is_array($content['__editor']['original'] ?? null)
            ? $content['__editor']['original']
            : null;

        $maskedComponents = $hasMergedComponents
            ? $this->stripInheritedNodes($content['components'])
            : [];

        $skeleton = $original !== null ? $original : $content_clean;
        unset($skeleton['lock_version'], $skeleton['__editor']);

        $skeletonHasExtends = isset($skeleton['extends']) && is_string($skeleton['extends']) && $skeleton['extends'] !== '';

        if ($skeletonHasExtends) {
            unset($skeleton['components']);

            $skeletonSlots = is_array($skeleton['slots'] ?? null) ? $skeleton['slots'] : [];
            $slotNames = array_keys($skeletonSlots);

            if (count($slotNames) === 1) {
                $skeleton['slots'] = [$slotNames[0] => $maskedComponents];
            } elseif (count($slotNames) === 0) {
                $skeleton['slots'] = ['content' => $maskedComponents];
            } else {
                $nextSlots = [];
                foreach ($skeletonSlots as $slotName => $slotValue) {
                    $nextSlots[$slotName] = is_array($slotValue)
                        ? $this->stripInheritedNodes($slotValue)
                        : $slotValue;
                }
                $skeleton['slots'] = $nextSlots;
            }
        } else {
            $skeleton['components'] = $maskedComponents;
            unset($skeleton['slots']);
        }

        return $skeleton;
    }

    /**
     * components 배열 마스킹 — 노드 종류에 따라 0개·1개·N개를 펼쳐 누적.
     *
     * @param  array<int, mixed>  $components
     * @return array<int, array<string, mixed>>
     */
    private function stripInheritedNodes(array $components): array
    {
        $result = [];
        foreach ($components as $node) {
            if (! is_array($node)) {
                continue;
            }
            $cleanedList = $this->stripInheritedNode($node);
            foreach ($cleanedList as $cleaned) {
                $result[] = $cleaned;
            }
        }

        return $result;
    }

    /**
     * 단일 노드 마스킹 — 노드 종류에 따라 0개·1개·N개를 반환.
     *
     * `LayoutService::replaceSlots` 가 머지할 때:
     *  - **slot 래퍼**: `__source.kind === 'base'` + `_fromBase` 부재. base 가 정의한
     *    슬롯 위치 컨테이너로, 그 안에 자식 레이아웃의 route 콘텐츠가 끼워진다.
     *    슬롯 자체는 base 소유지만 안의 콘텐츠는 자식 레이아웃 소속이므로,
     *    **자체는 버리되 children 을 부모 배열로 끌어올린다** (재귀 마스킹).
     *  - **일반 base 노드**: `__source.kind === 'base'` + `_fromBase: true`. 헤더/사이드바
     *    /푸터 등 자식 레이아웃에 속하지 않는 base 콘텐츠 → 통째 제거.
     *  - extension/partial 노드: 통째 제거 (별도 SSoT 가 책임).
     *  - route 또는 메타 미부여 노드: 보존 + 메타 제거 + children 재귀 마스킹.
     *
     * @param  array<string, mixed>  $node
     * @return array<int, array<string, mixed>>
     */
    private function stripInheritedNode(array $node): array
    {
        $fromBase = ($node['_fromBase'] ?? false) === true;
        $kind = is_array($node['__source'] ?? null) ? ($node['__source']['kind'] ?? null) : null;

        // extension/partial 노드 — 통째 제거
        if ($kind === 'extension' || $kind === 'partial') {
            return [];
        }

        // base 출처 노드 (slot 래퍼 또는 일반 base 노드) — 자체는 버리되 children 의 route
        // 콘텐츠는 끌어올린다. `LayoutService::replaceSlots` 가 base 의 깊은 자손에 slot
        // 래퍼를 두고 그 안에 route 콘텐츠를 끼우므로, base 노드를 무조건 제거하면 그
        // 자손의 route 콘텐츠까지 사라진다.
        if ($kind === 'base' || $fromBase) {
            $children = is_array($node['children'] ?? null) ? $node['children'] : [];

            return $this->stripInheritedNodes($children);
        }

        unset($node['__source'], $node['_fromBase']);

        if (isset($node['children']) && is_array($node['children'])) {
            $node['children'] = $this->stripInheritedNodes($node['children']);
        }

        return [$node];
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * extends 레이아웃과 standalone 레이아웃의 구조 차이를 고려합니다:
     * - standalone: endpoint, components 필수
     * - extends: extends, slots 사용 (endpoint, components는 부모에서 상속)
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $content = $this->input('content');

        // extends 레이아웃 여부 (extends 필드가 있고 null이 아닌 경우)
        $isExtending = is_array($content) && isset($content['extends']) && $content['extends'] !== null;

        // Base 레이아웃 여부 (slots를 정의하고 있는 경우 - 다른 레이아웃이 상속받는 용도)
        $isBaseLayout = is_array($content) && isset($content['slots']) && is_array($content['slots']);

        $rules = [
            // 낙관적 잠금 — 클라이언트가 로드한 시점의 lock_version 필수 전달
            // Service::updateLayout 가 현재 DB lock_version 과 비교해 불일치 시
            // ConcurrentModificationException 으로 409 반환
            'expected_lock_version' => ['required', 'integer', 'min:0'],

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
            // 주의: content.meta 는 'array' 규칙 + 하위 키 일부 명시 조합이므로 Laravel validated()
            // 는 명시된 하위 키만 추출한다(미명시 키는 저장 시 누락). 엔진이 소비하는 meta 하위 키는
            // 빠짐없이 명시해야 한다.
            'content.meta' => ['nullable', 'array'],
            'content.meta.title' => ['nullable', 'string'],
            'content.meta.description' => ['nullable', 'string'],
            'content.meta.keywords' => ['nullable', 'string'],
            'content.meta.auth_required' => ['nullable', 'boolean'],
            'content.meta.is_base' => ['nullable', 'boolean'],
            // 비로그인 전용 라우트 표식 — _redirect_if_logged_in 가드 + SEO/sitemap 제외 판정에 소비
            'content.meta.guest_only' => ['nullable', 'boolean'],
            // 에러 레이아웃 표식 — ErrorPageHandler 가 소비
            'content.meta.is_error_layout' => ['nullable', 'boolean'],
            'content.meta.error_code' => ['nullable', 'integer'],

            // SEO 메타데이터
            // 주의: content.meta.seo 도 'array' 규칙 + 하위 키 일부 명시이므로 SEO 페이지 생성기가
            // 소비하는 모든 하위 키를 명시해야 validated 에서 보존된다.
            'content.meta.seo' => ['nullable', 'array'],
            'content.meta.seo.enabled' => ['nullable', 'boolean'],
            'content.meta.seo.data_sources' => ['nullable', 'array'],
            'content.meta.seo.data_sources.*' => ['string'],
            'content.meta.seo.priority' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'content.meta.seo.changefreq' => ['nullable', 'string', 'in:always,hourly,daily,weekly,monthly,yearly,never'],
            'content.meta.seo.og' => ['nullable', 'array'],
            'content.meta.seo.structured_data' => ['nullable', 'array'],
            // SEO 페이지 생성기 소비 키 (SeoRenderer/TemplateRouteResolver)
            'content.meta.seo.page_type' => ['nullable', 'string'],
            'content.meta.seo.toggle_setting' => ['nullable', 'string'],
            'content.meta.seo.vars' => ['nullable', 'array'],
            'content.meta.seo.extensions' => ['nullable', 'array'],

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

            // 데이터소스 병합 전 정적 로컬/전역/격리 상태 초기값 (TemplateApp 가 소비)
            // [초기 상태] 탭의 컴포넌트 격리 상태 초기값(initIsolated)
            // 추가. 미명시 시 validated() 가 떨궈 격리 초기값이 저장되지 않음(R9 누락 가드).
            'content.initLocal' => ['nullable', 'array'],
            'content.initGlobal' => ['nullable', 'array'],
            'content.initIsolated' => ['nullable', 'array'],

            // 전역 상태 초기값 (레이아웃 레벨 _global 초기화)
            'content.global_state' => ['nullable', 'array'],

            // 에러 핸들링 정책 (상태 코드별 handler — ErrorHandlingResolver 가 소비)
            'content.errorHandling' => ['nullable', 'array'],

            // 레이아웃 레벨 재사용 액션 정의 (ActionDispatcher 가 id 로 참조)
            'content.actions' => ['nullable', 'array'],

            // 플러그인 설정 레이아웃 전용 — 안내/스키마 (settings UI 렌더가 소비)
            'content.pageConfig' => ['nullable', 'array'],
            'content.schema' => ['nullable', 'array'],

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

        // content.endpoint 는 항상 선택적(nullable)이다.
        //
        // 최상위 endpoint 는 화면이 주로 fetch 하는 데이터 API 경로를 가리키는 레거시 필드로,
        // 로그인/대시보드/정적 페이지처럼 주 데이터 fetch 가 없는 standalone 레이아웃은 정당하게
        // endpoint 가 없다(번들 admin 103 + basic 39 = 전 142 레이아웃이 endpoint 부재 상태로
        // 정상 렌더·동작). 구조 SSoT(ValidLayoutStructure) 도 endpoint 를 필수로 요구하지 않으며,
        // 런타임 어디에서도 최상위 endpoint 를 소비하지 않는다(데이터소스의 endpoint 만 사용).
        //
        // 과거 standalone 분기에서 endpoint 를 required 로 강제하던 규칙은, endpoint 없이 잘
        // 동작하던 전 레이아웃을 편집기로 컴포넌트만 추가해 저장하려 해도 422 로 막는 회귀를
        // 일으켰다. endpoint 가 명시되면 whitelist/외부URL 차단 검증은 그대로 적용한다.
        $rules['content.endpoint'] = [
            'nullable',
            'string',
            new WhitelistedEndpoint,
            new NoExternalUrls,
        ];

        if (! $isExtending && ! $isBaseLayout) {
            // standalone 레이아웃은 components 필수 (ValidLayoutStructure 와 동일 계약)
            $rules['content.components'] = ['required', 'array'];
        } else {
            // extends 레이아웃은 components 또는 slots 중 하나 사용 — ValidLayoutStructure 에서 상세 검증
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
     *
     * @param  Validator  $validator  Laravel 검증기 인스턴스
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
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
            'expected_lock_version.required' => __('validation.layout.expected_lock_version.required'),
            'expected_lock_version.integer' => __('validation.layout.expected_lock_version.integer'),
            'expected_lock_version.min' => __('validation.layout.expected_lock_version.min'),
            'content.required' => __('validation.layout.content.required'),
            'content.array' => __('validation.layout.content.array'),
            'content.version.required' => __('validation.layout.version.required'),
            'content.version.string' => __('validation.layout.version.string'),
            'content.layout_name.required' => __('validation.layout.layout_name.required'),
            'content.layout_name.string' => __('validation.layout.layout_name.string'),
            'content.layout_name.max' => __('validation.layout.layout_name.max'),
            // content.endpoint 는 nullable 이므로 required 메시지 키는 더 이상 발화되지 않는다.
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
            'content.meta.keywords.string' => __('validation.layout.meta.keywords.string'),
            'content.meta.auth_required.boolean' => __('validation.layout.meta.auth_required.boolean'),
            'content.meta.is_base.boolean' => __('validation.layout.meta.is_base.boolean'),
            'content.meta.guest_only.boolean' => __('validation.layout.meta.guest_only.boolean'),
            'content.meta.is_error_layout.boolean' => __('validation.layout.meta.is_error_layout.boolean'),
            'content.meta.error_code.integer' => __('validation.layout.meta.error_code.integer'),
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
            'content.meta.seo.page_type.string' => __('validation.layout.meta.seo.page_type.string'),
            'content.meta.seo.toggle_setting.string' => __('validation.layout.meta.seo.toggle_setting.string'),
            'content.meta.seo.vars.array' => __('validation.layout.meta.seo.vars.array'),
            'content.meta.seo.extensions.array' => __('validation.layout.meta.seo.extensions.array'),
            'content.modals.array' => __('validation.layout.modals.array'),
            'content.state.array' => __('validation.layout.state.array'),
            'content.init_actions.array' => __('validation.layout.init_actions.array'),
            'content.defines.array' => __('validation.layout.defines.array'),
            'content.init_state.array' => __('validation.layout.init_state.array'),
            'content.initLocal.array' => __('validation.layout.initLocal.array'),
            'content.initGlobal.array' => __('validation.layout.initGlobal.array'),
            'content.global_state.array' => __('validation.layout.global_state.array'),
            'content.errorHandling.array' => __('validation.layout.errorHandling.array'),
            'content.actions.array' => __('validation.layout.actions.array'),
            'content.pageConfig.array' => __('validation.layout.pageConfig.array'),
            'content.schema.array' => __('validation.layout.schema.array'),
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
