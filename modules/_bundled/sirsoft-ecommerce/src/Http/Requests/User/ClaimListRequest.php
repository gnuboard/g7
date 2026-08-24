<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Helpers\ResponseHelper;
use App\Support\Query\PaginationLimits;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;

/**
 * 회원 클레임 신청 내역 목록 요청 (유형 서브탭 단위)
 *
 * 상태 어휘는 유형마다 다르므로(교환 14 / 반품 11 / 환불 7 / 취소 2) 각 유형의 Enum 을 단일 출처로 삼아
 * `status` 를 게이트한다. 게이트 없이 저장소 `where(status = 값)` 에 흘려보내면 오타·구버전 상태값이
 * 422 없이 **빈 목록**으로 응답되어, 사용자에게는 "해당 신청 없음" 과 구분되지 않는다.
 */
class ClaimListRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed> 검증 규칙 배열
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', Rule::in(ClaimTypeEnum::values())],
            'status' => ['nullable', 'string', 'max:30', Rule::in($this->allowedStatuses())],
            // 기간 두 축은 이 모듈의 목록 요청 관례(`date`)를 따르고, 하루 경계 해석은 저장소가 맡는다
            // (`Carbon::parse()->startOfDay()/endOfDay()`). 요청이 `' 23:59:59'` 를 이어 붙이던 종전 방식은
            // 시각이 섞인 입력에서 규격 밖 문자열을 만들었고, 그것을 막으려 `to` 에만 형식을 고정하면
            // `from` 이 무가공으로 SQL 에 흘러드는 축은 그대로 남는 데다 같은 날 조회가 막힌다.
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            // 페이지 상한이 없으면 `?page=200000` 이 OFFSET 1,000만 을 만들고 그 위에서 소유자 EXISTS 를
            // 평가한다. 상한은 코어 단일 해석을 쓰고(0=무제한이면 null), 정상 탐색은 그대로 열려 있다
            // — 형제 선례 `App\Http\Requests\Public\SearchRequest`.
            'page' => array_filter([
                'nullable',
                'integer',
                'min:1',
                ($maxPage = PaginationLimits::maxPage('ecommerce.claims')) !== null ? 'max:'.$maxPage : null,
            ]),
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 메시지 배열
     */
    public function messages(): array
    {
        return [
            'type.in' => __('sirsoft-ecommerce::validation.claims.type.in'),
            'status.in' => __('sirsoft-ecommerce::validation.claims.status.in'),
            'page.integer' => __('sirsoft-ecommerce::validation.orders.page.integer'),
            'page.min' => __('sirsoft-ecommerce::validation.orders.page.min'),
            'per_page.integer' => __('sirsoft-ecommerce::validation.orders.per_page.integer'),
            'per_page.min' => __('sirsoft-ecommerce::validation.orders.per_page.min'),
            'per_page.max' => __('sirsoft-ecommerce::validation.orders.per_page.max'),
        ];
    }

    /**
     * 조회 유형에 허용되는 상태 어휘를 반환합니다.
     *
     * 어휘의 단일 출처는 유형별 Enum 이다 — 목록 필터·라벨·실제 기록이 같은 집합을 쓰도록 여기서
     * 리터럴을 다시 적지 않는다. 유형이 없거나 해석되지 않으면 `claimType()` 과 같은 기본값(교환)을
     * 쓰고, 잘못된 유형 자체는 `type.in` 이 따로 거절한다.
     *
     * @return array<int, string> 허용 상태 값
     */
    private function allowedStatuses(): array
    {
        return match (ClaimTypeEnum::tryFrom((string) $this->input('type'))) {
            ClaimTypeEnum::RETURN => ReturnStatusEnum::values(),
            ClaimTypeEnum::REFUND => RefundStatusEnum::values(),
            ClaimTypeEnum::CANCEL => CancelStatusEnum::values(),
            default => ExchangeStatusEnum::values(),
        };
    }

    /**
     * 조회 유형을 반환합니다. (기본 교환 — 서브탭 순서 D16)
     *
     * @return ClaimTypeEnum 유형
     */
    public function claimType(): ClaimTypeEnum
    {
        $type = $this->validated('type');

        return $type ? ClaimTypeEnum::from((string) $type) : ClaimTypeEnum::EXCHANGE;
    }

    /**
     * 저장소 필터를 반환합니다.
     *
     * @return array{status: ?string, from: ?string, to: ?string} 필터
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'status' => $validated['status'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ];
    }

    /**
     * 페이지당 건수를 반환합니다.
     *
     * @return int 페이지당 건수
     */
    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 20);
    }

    /**
     * 검증 실패 응답을 형제 클레임 요청과 같은 봉투로 맞춥니다.
     *
     * 이 요청만 기본 처리에 맡기면 `success` 키가 없는 본문이 나가, 같은 화면의 다른 호출과
     * 형태가 갈려 클라이언트의 실패 분기가 목록에서만 통과한다.
     *
     * @param  Validator  $validator  검증기
     *
     * @throws ValidationException 항상
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator, ResponseHelper::error(
            $validator->errors()->first(),
            422,
            $validator->errors()->toArray()
        ));
    }
}
