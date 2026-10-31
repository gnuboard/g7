<?php

namespace App\Http\Requests\User;

use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserListRequest extends FormRequest
{
    /**
     * 검색 가능한 필드 목록
     *
     * `contact` 는 휴대폰·전화 두 칸을, `identifier` 는 회원번호(id)·고유ID(uuid) 를 묶은 가상 필드다
     * — 실제 컬럼으로 푸는 일은 저장소가 한다.
     */
    public const SEARCHABLE_FIELDS = ['name', 'nickname', 'email', 'contact', 'identifier', 'admin_memo'];

    /**
     * 확장 필터 채널(`ext_filters[]`) 원소 형식 — `{확장id}.{키}={값}`.
     */
    public const EXT_FILTER_PATTERN = '/^[a-z0-9_.\-]+=[^&]{0,255}$/';

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool 항상 true — 권한은 라우트 미들웨어(core.users.read)가 판정한다
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // 'all'은 전체 필드 검색을 의미하는 특수 값
        $searchableFields = implode(',', array_merge(['all'], self::SEARCHABLE_FIELDS));
        $supportedLocales = implode(',', config('app.supported_locales', ['ko', 'en']));

        $rules = [
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100',

            // 다중 검색 조건
            'filters' => 'nullable|array|max:10',
            'filters.*.field' => "required_with:filters|string|in:{$searchableFields}",
            'filters.*.value' => 'required_with:filters|string|max:255',
            'filters.*.operator' => 'nullable|string|in:like,eq,starts_with,ends_with',

            // 계정·권한
            'status' => 'nullable|array',
            'status.*' => ['string', Rule::in(UserStatus::values())],
            'role_ids' => 'nullable|array',
            'role_ids.*' => ['integer', Rule::exists(Role::class, 'id')],
            'admin_scope' => 'nullable|string|in:all,admin,member',
            'lock_state' => 'nullable|string|in:all,locked,unlocked',

            // 날짜 — 가입일은 기존 키(start_date/end_date)를 그대로 쓴다
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'date_filter' => 'nullable|string|in:all,week,month,custom',
            'last_login_start' => 'nullable|date',
            'last_login_end' => 'nullable|date|after_or_equal:last_login_start',
            'never_logged_in' => 'nullable|boolean',
            'dormant_start' => 'nullable|date',
            'dormant_end' => 'nullable|date|after_or_equal:dormant_start',
            'status_changed_type' => 'nullable|string|in:withdrawn,blocked',
            'status_changed_start' => 'nullable|date',
            'status_changed_end' => 'nullable|date|after_or_equal:status_changed_start',

            // 프로필·인증
            'language' => 'nullable|string|in:'.$supportedLocales,
            'country' => 'nullable|string|size:2|alpha',
            'email_verified' => 'nullable|string|in:all,verified,unverified',
            'identity_verified' => 'nullable|string|in:all,verified,unverified',

            // 확장 필터 채널 — 원소는 1차 방어만 하고, 키별 규칙은 확장이 `ext.{확장id}.{키}` 에 건다
            'ext_filters' => 'nullable|array|max:20',
            'ext_filters.*' => ['string', 'regex:'.self::EXT_FILTER_PATTERN],
            'ext' => 'nullable|array',

            // 허용 정렬 — 저장소 SORTABLE_COLUMNS 와 같은 집합. 목록 표가 status·language·country 머리글을
            // 정렬 가능으로 그리므로 여기서 막으면 머리글을 누르는 순간 422 가 난다. 목록을 상수로 빼지 않고
            // 리터럴로 두는 이유: SortWhitelistGateParityTest 가 이 선언을 소스에서 읽어 저장소와 대조한다.
            'sort_by' => 'nullable|string|in:created_at,name,email,last_login_at,status,language,country',
            'sort_order' => 'nullable|string|in:asc,desc',
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.user.list_validation_rules', $rules, $this);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'page.integer' => __('user.validation.page_integer'),
            'page.min' => __('user.validation.page_min'),
            'per_page.integer' => __('user.validation.per_page_integer'),
            'per_page.min' => __('user.validation.per_page_min'),
            'per_page.max' => __('user.validation.per_page_max'),
            'start_date.date' => __('user.validation.start_date_invalid'),
            'end_date.date' => __('user.validation.end_date_invalid'),
            'end_date.after_or_equal' => __('user.validation.end_date_after_start'),
            'date_filter.in' => __('user.validation.date_filter_invalid'),
            'sort_by.in' => __('user.validation.sort_by_invalid'),
            'sort_order.in' => __('user.validation.sort_order_invalid'),
            // 다중 검색 조건 관련 메시지
            'filters.array' => __('user.validation.filters_array'),
            'filters.max' => __('user.validation.filters_max'),
            'filters.*.field.required_with' => __('user.validation.filter_field_required'),
            'filters.*.field.in' => __('user.validation.filter_field_invalid'),
            'filters.*.value.required_with' => __('user.validation.filter_value_required'),
            'filters.*.value.max' => __('user.validation.filter_value_max'),
            'filters.*.operator.in' => __('user.validation.filter_operator_invalid'),
            // 목록 필터
            'status.array' => __('user.validation.list_status_array'),
            'status.*.in' => __('user.validation.list_status_invalid'),
            'role_ids.array' => __('user.validation.list_role_ids_array'),
            'role_ids.*.integer' => __('user.validation.list_role_ids_invalid'),
            'role_ids.*.exists' => __('user.validation.list_role_ids_invalid'),
            'admin_scope.in' => __('user.validation.list_admin_scope_invalid'),
            'lock_state.in' => __('user.validation.list_lock_state_invalid'),
            'last_login_start.date' => __('user.validation.list_date_invalid'),
            'last_login_end.date' => __('user.validation.list_date_invalid'),
            'last_login_end.after_or_equal' => __('user.validation.end_date_after_start'),
            'never_logged_in.boolean' => __('user.validation.list_never_logged_in_invalid'),
            'dormant_start.date' => __('user.validation.list_date_invalid'),
            'dormant_end.date' => __('user.validation.list_date_invalid'),
            'dormant_end.after_or_equal' => __('user.validation.end_date_after_start'),
            'status_changed_type.in' => __('user.validation.list_status_changed_type_invalid'),
            'status_changed_start.date' => __('user.validation.list_date_invalid'),
            'status_changed_end.date' => __('user.validation.list_date_invalid'),
            'status_changed_end.after_or_equal' => __('user.validation.end_date_after_start'),
            'language.in' => __('user.validation.language_invalid'),
            'country.size' => __('user.validation.country_size'),
            'country.alpha' => __('user.validation.country_alpha'),
            'email_verified.in' => __('user.validation.list_verified_invalid'),
            'identity_verified.in' => __('user.validation.list_verified_invalid'),
            'ext_filters.array' => __('user.validation.list_ext_filters_invalid'),
            'ext_filters.max' => __('user.validation.list_ext_filters_max'),
            'ext_filters.*.regex' => __('user.validation.list_ext_filters_invalid'),
        ];
    }

    /**
     * 검증 항목명을 반환합니다 — 오류 문구에 필드 키 대신 화면에서 쓰는 이름이 나오게 합니다.
     *
     * 확장이 `core.user.list_validation_rules` 로 `ext.{확장id}.{키}` 규칙을 더했다면 같은 키의
     * 항목명은 `core.user.list_validation_attributes` 로 더한다 — 없으면 오류 문구에 내부 키가 그대로 나온다.
     *
     * @return array<string, string> 필드 => 항목명
     */
    public function attributes(): array
    {
        return HookManager::applyFilters('core.user.list_validation_attributes', [
            'status' => __('user.list_filter_attributes.status'),
            'status.*' => __('user.list_filter_attributes.status'),
            'role_ids' => __('user.list_filter_attributes.role_ids'),
            'role_ids.*' => __('user.list_filter_attributes.role_ids'),
            'admin_scope' => __('user.list_filter_attributes.admin_scope'),
            'lock_state' => __('user.list_filter_attributes.lock_state'),
            'start_date' => __('user.list_filter_attributes.start_date'),
            'end_date' => __('user.list_filter_attributes.end_date'),
            'date_filter' => __('user.list_filter_attributes.date_filter'),
            'last_login_start' => __('user.list_filter_attributes.last_login_start'),
            'last_login_end' => __('user.list_filter_attributes.last_login_end'),
            'never_logged_in' => __('user.list_filter_attributes.never_logged_in'),
            'dormant_start' => __('user.list_filter_attributes.dormant_start'),
            'dormant_end' => __('user.list_filter_attributes.dormant_end'),
            'status_changed_type' => __('user.list_filter_attributes.status_changed_type'),
            'status_changed_start' => __('user.list_filter_attributes.status_changed_start'),
            'status_changed_end' => __('user.list_filter_attributes.status_changed_end'),
            'language' => __('user.list_filter_attributes.language'),
            'country' => __('user.list_filter_attributes.country'),
            'email_verified' => __('user.list_filter_attributes.email_verified'),
            'identity_verified' => __('user.list_filter_attributes.identity_verified'),
            'ext_filters' => __('user.list_filter_attributes.ext_filters'),
            'ext_filters.*' => __('user.list_filter_attributes.ext_filters'),
            'ext' => __('user.list_filter_attributes.ext_filters'),
            'filters' => __('user.list_filter_attributes.filters'),
            'filters.*.field' => __('user.list_filter_attributes.filter_field'),
            'filters.*.value' => __('user.list_filter_attributes.filter_value'),
            'filters.*.operator' => __('user.list_filter_attributes.filter_operator'),
            'sort_by' => __('user.list_filter_attributes.sort_by'),
            'sort_order' => __('user.list_filter_attributes.sort_order'),
        ], $this);
    }

    /**
     * Prepare the data for validation.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        // value가 비어있는 filters 제거
        $filters = $this->filters;
        if (is_array($filters)) {
            $filters = array_filter($filters, function ($filter) {
                $value = $filter['value'] ?? null;

                // 「0」 도 검색어다 — empty() 는 문자열 "0" 을 빈 값으로 본다.
                return is_string($value) || is_int($value)
                    ? trim((string) $value) !== ''
                    : ! empty($value);
            });
            // 인덱스 재정렬
            $filters = array_values($filters);
            $this->merge(['filters' => $filters ?: null]);
        }

        // 다중선택 키는 화면이 「전체」를 빈 값으로 떨어뜨린다 — 빈 원소를 걷어 조건 없음으로 만든다.
        foreach (['status', 'role_ids'] as $key) {
            $value = $this->input($key);
            if (is_string($value) && $value !== '') {
                $value = [$value];
            }
            if (is_array($value)) {
                $value = array_values(array_filter($value, static fn ($v) => $v !== null && $v !== ''));
                $this->merge([$key => $value ?: null]);
            }
        }

        // 쿼리스트링은 불리언을 'true'/'false' 글자로 싣는다 — boolean 규칙은 그 글자를 받지 않는다.
        if ($this->has('never_logged_in')) {
            $flag = filter_var($this->input('never_logged_in'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $this->merge(['never_logged_in' => $this->input('never_logged_in') === '' ? null : ($flag ?? $this->input('never_logged_in'))]);
        }

        $this->mergeExtensionFilters();

        // 기본값 설정
        $this->merge([
            'per_page' => $this->per_page ?? 15,
            'page' => $this->page ?? 1,
            'sort_by' => $this->sort_by ?? 'created_at',
            'sort_order' => $this->sort_order ?? 'desc',
            'date_filter' => $this->date_filter ?? 'all',
        ]);

        // 날짜 필터에 따른 기본 날짜 설정
        if ($this->date_filter === 'week' && ! $this->start_date && ! $this->end_date) {
            $this->merge([
                'start_date' => now()->subWeek()->format('Y-m-d'),
                'end_date' => now()->format('Y-m-d'),
            ]);
        } elseif ($this->date_filter === 'month' && ! $this->start_date && ! $this->end_date) {
            $this->merge([
                'start_date' => now()->subMonth()->format('Y-m-d'),
                'end_date' => now()->format('Y-m-d'),
            ]);
        }
    }

    /**
     * 확장 필터 채널(`ext_filters[]`)을 `ext.{확장id}.{키}` 중첩 배열로 폅니다.
     *
     * 화면의 navigate·데이터소스 파라미터는 속성 맵이라 확장이 키를 늘릴 수 없어, 코어가 배열
     * 칸 하나를 열고 원소에 `{확장id}.{키}={값}` 을 싣는다. 검증 경로는 점을 중첩으로 읽으므로
     * 평면 키(`'sirsoft-ecommerce.mileage_min'`)로 펴면 확장이 건 `ext.sirsoft-ecommerce.mileage_min`
     * 규칙이 닿지 않는다 — 그래서 확장id·키 두 단계 중첩으로 편다.
     *
     * 형식이 어긋난 원소는 여기서 건너뛴다. 거부(422)는 `ext_filters.*` 정규식 규칙이 맡는다.
     * `ext` 는 이 채널에서만 만든다 — 요청이 `ext[...]` 를 직접 실어도 버린다(원소 형식 검사 우회 차단).
     *
     * @return void 반환값 없음
     */
    private function mergeExtensionFilters(): void
    {
        $raw = $this->input('ext_filters');

        if (is_string($raw) && $raw !== '') {
            $raw = [$raw];
        }

        if (! is_array($raw)) {
            $this->merge(['ext' => null]);

            return;
        }

        $raw = array_values(array_filter($raw, static fn ($v) => is_string($v) && $v !== ''));
        $ext = [];

        foreach ($raw as $entry) {
            if (! preg_match(self::EXT_FILTER_PATTERN, $entry)) {
                continue;
            }

            [$path, $value] = explode('=', $entry, 2);

            if (! str_contains($path, '.')) {
                continue;
            }

            [$extensionId, $key] = explode('.', $path, 2);

            if ($extensionId === '' || $key === '' || $value === '') {
                continue;
            }

            $ext[$extensionId][$key] = $value;
        }

        $this->merge([
            'ext_filters' => $raw ?: null,
            'ext' => $ext ?: null,
        ]);
    }
}
