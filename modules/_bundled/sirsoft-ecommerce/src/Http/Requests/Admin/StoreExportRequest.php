<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorContract;
use Modules\Sirsoft\Ecommerce\Exports\Contracts\ExportTypeInterface;
use Modules\Sirsoft\Ecommerce\Exports\ExportFormatRegistry;
use Modules\Sirsoft\Ecommerce\Exports\ExportRunContext;
use Modules\Sirsoft\Ecommerce\Exports\ExportTypeRegistry;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ExportRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ExportService;

/**
 * 내보내기 요청 검증
 *
 * - 권한: 내보내기 요청 권한은 라우트 미들웨어, 그 유형의 조회 권한(주문=orders.read …)과 개인정보 권한은
 *   컨트롤러가 본다(유형이 검증된 뒤에야 판정할 수 있다)
 * - 목록 필터(「현재 검색조건」)는 그 목록의 검증 규칙을 **그대로 위임** 적용한다 — 규칙을 복제하면 목록에만
 *   새 축이 늘어 화면과 파일이 어긋난다
 * - 기간: 주문·마일리지는 필수, 어느 갈래든 시작일 포함 1년(종료일 ≤ 시작일 + 1년 − 1일) 상한
 * - 요청자당 대기+진행중 3건 상한, 중복 방지 키(`Idempotency-Key` 헤더) 필수
 */
class StoreExportRequest extends FormRequest
{
    /**
     * 위임 검증을 통과한 목록 필터 (서비스에 넘긴다)
     *
     * @var array<string, mixed>
     */
    protected array $validatedFilters = [];

    /**
     * 예상 건수 계산 요청인지 (사유·동시 요청 상한·중복 방지 키를 보지 않는다)
     *
     * @return bool 예상 건수 요청 여부
     */
    protected function isEstimate(): bool
    {
        return false;
    }

    /**
     * 요청한 유형을 레지스트리에서 찾습니다.
     *
     * @return ExportTypeInterface|null 유형
     */
    public function exportType(): ?ExportTypeInterface
    {
        return app(ExportTypeRegistry::class)->find((string) $this->input('type'));
    }

    /**
     * 권한 확인 — 라우트 미들웨어가 요청 권한을 본다. 유형 조회 권한·개인정보 권한은 유형이 검증된 뒤
     * 컨트롤러가 판정한다(거부 시 활동 로그 훅).
     *
     * @return bool 허용 여부
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청한 필드 중 개인정보 필드가 있는지
     *
     * 「데이터 선택」은 보낸 필드 키를 그대로 본다 — 권한 없는 사람이 개인정보 필드를 직접 보내면 여기서 걸려 사유·권한으로 막힌다.
     * 「현재 화면 컬럼」은 화면 컬럼 하나가 개인정보 필드와 아닌 필드를 함께 펼치므로, 창(카탈로그)이 보인 것과 같게 요청자가 고를 수
     * 있는 필드로 좁힌 뒤 본다. 권한 없는 사람의 기본값은 개인정보 필드가 빠진 파일이 되어 사유가 필요 없고(파일 열도 같은 규칙으로
     * 좁힌다 — {@see ExportService::selectedFieldKeys()}), 권한 있는 사람은 종전대로 개인정보 필드가 남아 사유를 요구한다.
     *
     * @return bool 개인정보 필드 포함 여부
     */
    public function requestsPersonalFields(): bool
    {
        $type = $this->exportType();
        if ($type === null) {
            return false;
        }

        $unit = (string) ($this->input('row_unit') ?: array_key_first($type->rowUnits()));
        if (! array_key_exists($unit, $type->rowUnits())) {
            return false;
        }

        $fields = $type->fields($unit);
        if ($this->input('column_mode') === 'custom') {
            $keys = (array) $this->input('fields', []);
        } else {
            $user = $this->user();
            $available = $user !== null ? app(ExportService::class)->availableFields($type, $unit, $user) : $fields;
            $keys = collect((array) $this->input('screen_columns', []))
                ->flatMap(fn ($screenKey) => is_string($screenKey) ? ($type->screenColumns()[$screenKey]['fields'] ?? []) : [])
                ->filter(fn ($key) => is_string($key) && isset($available[$key]))
                ->all();
        }

        foreach ($keys as $key) {
            if (is_string($key) && ($fields[$key] ?? null)?->personal === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * 유효성 검사 규칙
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $type = $this->exportType();
        $service = app(ExportService::class);

        $rules = [
            'type' => ['required', 'string', Rule::in(app(ExportTypeRegistry::class)->keys())],
            'format' => ['required', 'string', Rule::in(app(ExportFormatRegistry::class)->keys())],
            'column_mode' => ['required', Rule::in(['current', 'custom'])],
            'screen_columns' => ['required_if:column_mode,current', 'array'],
            'screen_columns.*' => ['string', Rule::in($type ? array_keys($type->screenColumns()) : [])],
            'fields' => ['required_if:column_mode,custom', 'array'],
            'fields.*' => ['string', 'max:150'],
            'row_unit' => ['nullable', 'string', Rule::in($type ? array_keys($type->rowUnits()) : [])],
            'scope' => ['required', Rule::in(['filter', 'selected', 'all'])],
            'filters' => ['nullable', 'array'],
            'selected_ids' => ['required_if:scope,selected', 'array', 'max:'.ExportService::MAX_SELECTED_IDS],
            'selected_ids.*' => ['integer', 'min:1'],
            'period' => ['nullable', 'array'],
            'period.mode' => ['nullable', Rule::in(['current', 'custom'])],
            'period.date_type' => ['nullable', 'string', Rule::in($type ? array_keys($type->dateTypes()) : [])],
            'period.start_date' => ['nullable', 'date_format:Y-m-d'],
            'period.end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:period.start_date'],
            'locale_mode' => ['required', Rule::in([ExportRunContext::LOCALE_MODE_ONE, ExportRunContext::LOCALE_MODE_PER_COLUMN, ExportRunContext::LOCALE_MODE_SINGLE_COLUMN])],
            'locale' => ['nullable', 'string', Rule::in($service->dataLocales())],
            'include_totals' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.export.request_validation_rules', $rules, $this);
    }

    /**
     * 규칙으로 표현하기 어려운 교차 검증을 더합니다.
     *
     * @param  ValidatorContract  $validator  검증기
     * @return void 반환값 없음
     */
    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator) {
            $type = $this->exportType();
            if ($type === null || $validator->errors()->isNotEmpty()) {
                return;
            }

            $this->validateFilters($validator, $type);
            $this->validateFields($validator, $type);
            $this->validatePeriod($validator, $type);

            if ($this->isEstimate()) {
                return;
            }

            $this->validateReason($validator, $type);
            $this->validateIdempotencyKey($validator);
            $this->validateActiveLimit($validator, $type);
        });
    }

    /**
     * 「현재 검색조건」 필터를 그 목록의 검증 규칙으로 검증합니다(위임).
     *
     * @param  ValidatorContract  $validator  검증기
     * @param  ExportTypeInterface  $type  유형
     * @return void 반환값 없음
     */
    protected function validateFilters(ValidatorContract $validator, ExportTypeInterface $type): void
    {
        $filters = (array) $this->input('filters', []);
        if ($filters === [] || $this->input('scope') === 'selected') {
            $this->validatedFilters = [];

            return;
        }

        // 목록 요청 객체를 그대로 만들어 검증한다 — 그 클래스의 값 정규화(prepareForValidation, 예: "true" →
        // true)까지 같은 경로를 타야 목록과 같은 판정이 된다.
        $listRequest = $type->listRequestClass();
        /** @var FormRequest $list */
        $list = $listRequest::create('/', 'GET', $filters);
        $list->setContainer($this->container)->setRedirector(app('redirect'));
        $list->setUserResolver($this->getUserResolver());

        try {
            $list->validateResolved();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add("filters.{$key}", $message);
                }
            }

            return;
        }

        // 쪽·쪽당 개수는 대상을 제한하지 않는다 — 검증만 하고 버린다
        $this->validatedFilters = array_diff_key($list->validated(), array_flip(['page', 'per_page']));
    }

    /**
     * 「데이터 선택」 필드 키가 이 행 단위에 있는지 확인합니다.
     *
     * @param  ValidatorContract  $validator  검증기
     * @param  ExportTypeInterface  $type  유형
     * @return void 반환값 없음
     */
    protected function validateFields(ValidatorContract $validator, ExportTypeInterface $type): void
    {
        $unit = (string) ($this->input('row_unit') ?: array_key_first($type->rowUnits()));
        $selected = app(ExportService::class)->selectedFieldKeys($type, $unit, $this->all(), $this->user());

        if ($this->input('column_mode') === 'custom') {
            $unknown = array_diff((array) $this->input('fields', []), array_keys($type->fields($unit)));
            if ($unknown !== []) {
                $validator->errors()->add('fields', __('sirsoft-ecommerce::validation.export.fields_unknown'));

                return;
            }
        }

        if ($selected === []) {
            $validator->errors()->add($this->input('column_mode') === 'custom' ? 'fields' : 'screen_columns', __('sirsoft-ecommerce::validation.export.fields_required'));
        }
    }

    /**
     * 범위·기간 조합의 기간을 확인합니다(필수 여부·1년 상한).
     *
     * @param  ValidatorContract  $validator  검증기
     * @param  ExportTypeInterface  $type  유형
     * @return void 반환값 없음
     */
    protected function validatePeriod(ValidatorContract $validator, ExportTypeInterface $type): void
    {
        if ($this->input('scope') === 'selected') {
            return;
        }

        $mode = $this->input('period.mode', 'current');
        if ($mode === 'custom') {
            $start = $this->input('period.start_date');
            $end = $this->input('period.end_date');
            if (! $start || ! $end) {
                $validator->errors()->add('period', __('sirsoft-ecommerce::validation.export.period_custom_required'));

                return;
            }
        } else {
            // 현재 조회 기간 — 「현재 검색조건」·「조건 없음」 모두 화면의 기간 키를 쓴다(위임 검증을 통과한 값)
            $start = $this->validatedFilters['start_date'] ?? null;
            $end = $this->validatedFilters['end_date'] ?? null;
        }

        if (! $start || ! $end) {
            if ($type->periodRequired()) {
                $validator->errors()->add('period', __('sirsoft-ecommerce::validation.export.period_required'));
            }

            return;
        }

        $startDate = CarbonImmutable::parse((string) $start)->startOfDay();
        $endDate = CarbonImmutable::parse((string) $end)->startOfDay();

        if ($endDate->lessThan($startDate)) {
            $validator->errors()->add('period', __('sirsoft-ecommerce::validation.export.period_order'));
        } elseif (! ExportService::periodWithinLimit($startDate, $endDate)) {
            $validator->errors()->add('period', __('sirsoft-ecommerce::validation.export.period_too_long'));
        }
    }

    /**
     * 개인정보 필드가 있으면 다운로드 사유를 요구합니다(D5 — 해제 설정 없음).
     *
     * @param  ValidatorContract  $validator  검증기
     * @param  ExportTypeInterface  $type  유형
     * @return void 반환값 없음
     */
    protected function validateReason(ValidatorContract $validator, ExportTypeInterface $type): void
    {
        if ($this->requestsPersonalFields() && trim((string) $this->input('reason', '')) === '') {
            $validator->errors()->add('reason', __('sirsoft-ecommerce::validation.export.reason_required'));
        }
    }

    /**
     * 중복 방지 키 헤더를 확인합니다.
     *
     * @param  ValidatorContract  $validator  검증기
     * @return void 반환값 없음
     */
    protected function validateIdempotencyKey(ValidatorContract $validator): void
    {
        $key = (string) $this->header('Idempotency-Key', '');
        if ($key === '' || strlen($key) > 64 || ! preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
            $validator->errors()->add('idempotency_key', __('sirsoft-ecommerce::validation.export.idempotency_key_invalid'));
        }
    }

    /**
     * 요청자당 대기+진행중 상한을 확인합니다(같은 키의 재요청은 새 작업이 아니므로 세지 않는다).
     *
     * @param  ValidatorContract  $validator  검증기
     * @param  ExportTypeInterface  $type  유형
     * @return void 반환값 없음
     */
    protected function validateActiveLimit(ValidatorContract $validator, ExportTypeInterface $type): void
    {
        $repository = app(ExportRepositoryInterface::class);
        $userId = (int) $this->user()->id;

        $key = (string) $this->header('Idempotency-Key', '');
        if ($key !== '' && $repository->findByIdempotencyKey($userId, $key) !== null) {
            return;
        }

        if ($repository->countActiveByUser($userId) >= ExportService::MAX_ACTIVE_PER_USER) {
            $validator->errors()->add('type', __('sirsoft-ecommerce::validation.export.too_many_pending', ['count' => ExportService::MAX_ACTIVE_PER_USER]));
            HookManager::doAction('sirsoft-ecommerce.export.request_denied', $type->key(), 'too_many_pending', $this->user());
        }
    }

    /**
     * 서비스에 넘길 검증 결과 (목록 필터는 위임 검증한 값으로 교체)
     *
     * @return array<string, mixed> 요청 데이터
     */
    public function exportData(): array
    {
        $data = $this->validated();
        $data['filters'] = $this->validatedFilters;
        $data['include_totals'] = (bool) ($data['include_totals'] ?? false);

        return $data;
    }

    /**
     * 검증 오류 메시지
     *
     * @return array<string, string> 메시지
     */
    public function messages(): array
    {
        return [
            'type.required' => __('sirsoft-ecommerce::validation.export.type_invalid'),
            'type.in' => __('sirsoft-ecommerce::validation.export.type_invalid'),
            'format.required' => __('sirsoft-ecommerce::validation.export.format_invalid'),
            'format.in' => __('sirsoft-ecommerce::validation.export.format_invalid'),
            'screen_columns.required_if' => __('sirsoft-ecommerce::validation.export.fields_required'),
            'screen_columns.*.in' => __('sirsoft-ecommerce::validation.export.screen_column_unknown'),
            'fields.required_if' => __('sirsoft-ecommerce::validation.export.fields_required'),
            'selected_ids.required_if' => __('sirsoft-ecommerce::validation.export.selected_required'),
            'selected_ids.max' => __('sirsoft-ecommerce::validation.export.selected_too_many', ['max' => ExportService::MAX_SELECTED_IDS]),
            'period.end_date.after_or_equal' => __('sirsoft-ecommerce::validation.export.period_order'),
            'period.start_date.date_format' => __('sirsoft-ecommerce::validation.export.period_date_format'),
            'period.end_date.date_format' => __('sirsoft-ecommerce::validation.export.period_date_format'),
            'locale.in' => __('sirsoft-ecommerce::validation.export.locale_invalid'),
            'reason.max' => __('sirsoft-ecommerce::validation.export.reason_too_long'),
        ];
    }

    /**
     * 검증 필드 라벨
     *
     * @return array<string, string> 필드 → 라벨
     */
    public function attributes(): array
    {
        return [
            'type' => __('sirsoft-ecommerce::validation.export_attributes.type'),
            'format' => __('sirsoft-ecommerce::validation.export_attributes.format'),
            'column_mode' => __('sirsoft-ecommerce::validation.export_attributes.column_mode'),
            'screen_columns' => __('sirsoft-ecommerce::validation.export_attributes.screen_columns'),
            'screen_columns.*' => __('sirsoft-ecommerce::validation.export_attributes.screen_columns'),
            'fields' => __('sirsoft-ecommerce::validation.export_attributes.fields'),
            'fields.*' => __('sirsoft-ecommerce::validation.export_attributes.fields'),
            'row_unit' => __('sirsoft-ecommerce::validation.export_attributes.row_unit'),
            'scope' => __('sirsoft-ecommerce::validation.export_attributes.scope'),
            'filters' => __('sirsoft-ecommerce::validation.export_attributes.filters'),
            'selected_ids' => __('sirsoft-ecommerce::validation.export_attributes.selected_ids'),
            'selected_ids.*' => __('sirsoft-ecommerce::validation.export_attributes.selected_ids'),
            'period' => __('sirsoft-ecommerce::validation.export_attributes.period'),
            'period.mode' => __('sirsoft-ecommerce::validation.export_attributes.period_mode'),
            'period.date_type' => __('sirsoft-ecommerce::validation.export_attributes.date_type'),
            'period.start_date' => __('sirsoft-ecommerce::validation.export_attributes.start_date'),
            'period.end_date' => __('sirsoft-ecommerce::validation.export_attributes.end_date'),
            'locale_mode' => __('sirsoft-ecommerce::validation.export_attributes.locale_mode'),
            'locale' => __('sirsoft-ecommerce::validation.export_attributes.locale'),
            'include_totals' => __('sirsoft-ecommerce::validation.export_attributes.include_totals'),
            'reason' => __('sirsoft-ecommerce::validation.export_attributes.reason'),
        ];
    }
}
