<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;

/**
 * 클레임 신청 수정 요청 (D13 — 승인 전 사유·상세 사유·첨부 / D14 — 교환 재발송지)
 *
 * 대상 신청서와 유형은 컨트롤러가 클레임 번호로 해석하므로(회원 소유권 / 비회원 토큰) 여기서는 형태만 본다.
 * 재발송지 국가 불변은 서비스(`OrderExchangeService::updateRequest`)가 접수와 같은 가드로 판정한다 —
 * 이 요청은 주문을 알지 못한다.
 */
class UpdateClaimRequest extends FormRequest
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
            'reason_code' => ['nullable', 'string', 'max:30'],
            'reason_detail' => ['nullable', 'string', 'max:1000'],
            'charge_method' => ['nullable', 'string', Rule::in(ClaimChargeMethodEnum::values())],
            'add_image_ids' => ['nullable', 'array', 'max:'.$this->maxImages()],
            'add_image_ids.*' => ['integer'],
            'remove_image_ids' => ['nullable', 'array', 'max:'.$this->maxImages()],
            'remove_image_ids.*' => ['integer'],
            'redelivery_address' => ['nullable', 'array'],
            'redelivery_address.recipient_name' => ['nullable', 'string', 'max:50'],
            'redelivery_address.phone' => ['nullable', 'string', 'max:20'],
            'redelivery_address.zipcode' => ['nullable', 'string', 'max:20'],
            'redelivery_address.address1' => ['nullable', 'string', 'max:255'],
            'redelivery_address.address2' => ['nullable', 'string', 'max:255'],
            'redelivery_address.country_code' => ['nullable', 'string', 'size:2'],
            'redelivery_address.changed_by_user' => ['nullable', 'boolean'],
            // 환불 계좌 — 접수(Store*Request)와 같은 키·같은 상한을 쓴다. 형태가 갈리면
            // 같은 값이 접수에서는 통과하고 수정에서만 거절되어, 구매자는 무엇이 잘못됐는지
            // 알 수 없다.
            'refund_bank' => ['nullable', 'array'],
            'refund_bank.bank_code' => ['nullable', 'string', 'max:10'],
            'refund_bank.account_number' => ['nullable', 'string', 'max:50'],
            'refund_bank.holder' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * 추가 검증 규칙을 적용합니다.
     *
     * @param  Validator  $validator  검증기
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // 환불 계좌는 세 필드가 함께 와야 한다 — 하나만 오면 은행만 바뀌고 계좌번호는
            // 옛 값으로 남아, 운영자가 존재하지 않는 계좌로 이체를 시도한다. 접수 경로
            // (Store*Request::withValidator)와 같은 등식이다.
            $bank = (array) $this->input('refund_bank', []);
            $filled = array_values(array_filter(
                ['bank_code', 'account_number', 'holder'],
                fn (string $field) => filled($bank[$field] ?? null)
            ));

            if ($filled !== [] && count($filled) !== 3) {
                $validator->errors()->add('refund_bank', __('sirsoft-ecommerce::validation.claims.refund_bank.incomplete'));
            }
        });
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 메시지 배열
     */
    public function messages(): array
    {
        return [
            'reason_detail.max' => __('sirsoft-ecommerce::validation.claims.reason_detail.max'),
            'charge_method.in' => __('sirsoft-ecommerce::validation.claims.charge_method.in'),
            'add_image_ids.array' => __('sirsoft-ecommerce::validation.claims.image_ids.array'),
            'add_image_ids.max' => __('sirsoft-ecommerce::validation.claims.image_ids.max'),
            'remove_image_ids.array' => __('sirsoft-ecommerce::validation.claims.image_ids.array'),
            'redelivery_address.array' => __('sirsoft-ecommerce::validation.claims.redelivery_address.array'),
            'redelivery_address.country_code.size' => __('sirsoft-ecommerce::validation.claims.redelivery_address.country_code_size'),
            'redelivery_address.changed_by_user.boolean' => __('sirsoft-ecommerce::validation.claims.redelivery_address.changed_by_user_boolean'),
        ];
    }

    /**
     * 검증 실패 시 응답 커스터마이징
     *
     * @param  Validator  $validator  검증기
     *
     * @throws ValidationException
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator, ResponseHelper::error(
            $validator->errors()->first(),
            422,
            $validator->errors()->toArray()
        ));
    }

    /**
     * 서비스에 넘길 수정 페이로드를 반환합니다. (전송된 키만)
     *
     * @return array 수정 페이로드
     */
    public function toServicePayload(): array
    {
        $validated = $this->validated();
        $payload = [];

        foreach (['reason_code', 'reason_detail', 'charge_method', 'redelivery_address'] as $key) {
            if (array_key_exists($key, $validated)) {
                $payload[$key] = $validated[$key];
            }
        }

        // 환불 계좌는 세 값이 다 찬 경우에만 싣는다. 빈 채로 실으면 구매자가 손대지 않은
        // 화면에서 저장했을 때 이미 기록된 계좌가 지워진다 — 표시 응답은 마스킹된 값이라
        // 화면이 원문을 되돌려 보낼 수 없고, 지워진 사실은 환불 실행 때가 되어서야 드러난다.
        $bank = $validated['refund_bank'] ?? null;

        if (is_array($bank)
            && filled($bank['bank_code'] ?? null)
            && filled($bank['account_number'] ?? null)
            && filled($bank['holder'] ?? null)
        ) {
            $payload['refund_bank'] = [
                'bank_code' => (string) $bank['bank_code'],
                'account_number' => (string) $bank['account_number'],
                'holder' => (string) $bank['holder'],
            ];
        }

        return $payload;
    }

    /**
     * 귀속할 첨부 ID 목록을 반환합니다.
     *
     * @return array<int, int> 첨부 ID 목록
     */
    public function addImageIds(): array
    {
        return array_map('intval', (array) ($this->validated('add_image_ids') ?? []));
    }

    /**
     * 삭제할 첨부 ID 목록을 반환합니다.
     *
     * @return array<int, int> 첨부 ID 목록
     */
    public function removeImageIds(): array
    {
        return array_map('intval', (array) ($this->validated('remove_image_ids') ?? []));
    }

    /**
     * 첨부 장수 상한을 반환합니다. (설정 단일 출처)
     *
     * 리터럴을 두면 운영자가 `claim.max_images` 를 바꿨을 때 화면 안내와 실제 거절선이 갈린다 —
     * 상한을 낮추면 요청 게이트가 놓치고 서비스가 뒤늦게 거절하고, 올리면 게이트가 먼저 막는다.
     *
     * @return int 첨부 장수 상한
     */
    protected function maxImages(): int
    {
        return app(ClaimAttachmentService::class)->maxImages();
    }
}
