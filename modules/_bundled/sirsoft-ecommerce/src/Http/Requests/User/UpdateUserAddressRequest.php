<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Validator;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\MapsAddressBookFields;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\UserAddressRepositoryInterface;

/**
 * 사용자 배송지 수정 요청
 */
class UpdateUserAddressRequest extends FormRequest
{
    use MapsAddressBookFields;

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool 항상 true (권한은 미들웨어 체인이 담당)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청 데이터 전처리
     */
    protected function prepareForValidation(): void
    {
        // is_default: 빈 문자열/null → false 정규화 (DB NOT NULL 제약 보호)
        if ($this->has('is_default')) {
            $this->merge([
                'is_default' => filter_var($this->is_default, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            ]);
        }
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * 주소 필수 조합(국내 = 우편번호+주소 / 해외 = 주소+도시+우편번호)은 규칙 배열이
     * 아니라 withValidator() 에서 검증한다. StoreUserAddressRequest 의
     * required_with/required_without 를 그대로 옮기면 부분 수정이 깨지기 때문이다 —
     * 상세 근거는 validateAddressPresence() docblock 참조.
     *
     * @return array 주소록 수정 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            // 배송지명
            'name' => ['sometimes', 'string', 'max:100'],

            // 수령인 정보
            'recipient_name' => 'sometimes|string|max:50',
            'recipient_phone' => 'sometimes|string|max:20',

            // 국가 코드
            'country_code' => 'nullable|string|size:2',

            // 국내 배송 주소
            // audit:allow formrequest-store-update-conditional-symmetry reason: 주소 필수 조합은
            // 페이로드 단독이 아니라 "기존 레코드 + 페이로드" 결과 상태 기준으로 판정한다
            // (validateAddressPresence). Store 의 required_without 를 그대로 옮기면 배송지명만
            // 바꾸는 요청과 해외 배송지 수정 요청이 422 가 된다.
            'zipcode' => 'nullable|string|max:10',
            'province_code' => 'nullable|string|max:10',
            'city' => 'nullable|string|max:100',
            // audit:allow formrequest-store-update-conditional-symmetry reason: 결과 상태 기준 판정 (validateAddressPresence)
            'address' => 'nullable|string|max:255',
            'address_detail' => 'nullable|string|max:255',
            'address_type_code' => 'nullable|string|in:R,J',

            // 해외 배송 주소 (필수 조합은 위와 동일하게 validateAddressPresence 가 담당)
            // audit:allow formrequest-store-update-conditional-symmetry reason: 결과 상태 기준 판정 (validateAddressPresence)
            'address_line_1' => 'nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            // audit:allow formrequest-store-update-conditional-symmetry reason: 결과 상태 기준 판정 (validateAddressPresence)
            'intl_city' => 'nullable|string|max:100',
            'intl_state' => 'nullable|string|max:100',
            // audit:allow formrequest-store-update-conditional-symmetry reason: 결과 상태 기준 판정 (validateAddressPresence)
            'intl_postal_code' => 'nullable|string|max:20',

            // 기본 배송지 여부
            'is_default' => 'nullable|boolean',
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.user_address.update_validation_rules', $rules, $this);
    }

    /**
     * 검증기에 주소 필수 조합 검증을 추가합니다.
     *
     * @param  Validator  $validator  검증기
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateAddressPresence($validator);
        });
    }

    /**
     * 주소 필수 조합을 "기존 레코드 + 요청 페이로드" 결과 상태 기준으로 검증합니다.
     *
     * StoreUserAddressRequest 는 required_with/required_without 로 같은 정책을 강제하지만,
     * 그 규칙을 수정 요청에 그대로 쓰면 두 가지 정상 경로가 막힌다.
     *
     *  1) 부분 수정 — 기본 배송지 토글처럼 주소를 건드리지 않는 요청도 required_without 이
     *     "상대 필드 부재" 자체를 발화 조건으로 삼아 422 가 된다.
     *  2) 해외 배송지 수정 — 조회 응답은 해외 주소를 city/state/postal_code 로 내보내고
     *     폼은 intl_* 로 제출하므로, 사용자가 도시/우편번호를 다시 입력하지 않으면 요청에
     *     intl_city/intl_postal_code 가 없다. 페이로드만 보면 값이 비어 보인다.
     *
     * 따라서 요청에 없는 필드는 저장된 값으로 메워 판정한다. 판정 기준 국가는
     * MapsAddressBookFields 의 매핑 기준과 동일하게 country_code(미전송 시 기존 값)다.
     *
     * @param  Validator  $validator  검증기
     */
    protected function validateAddressPresence(Validator $validator): void
    {
        $payload = $this->all();

        // 주소를 정의하는 필드도 국가도 건드리지 않는 요청은 판정 대상이 아니다.
        $addressKeys = ['zipcode', 'address', 'address_line_1', 'intl_city', 'intl_postal_code', 'country_code'];

        if (! array_intersect($addressKeys, array_keys($payload))) {
            return;
        }

        $existing = $this->findExistingAddress();

        // 저장된 컬럼명 → 요청 키 매핑 (해외는 city/state/postal_code 컬럼에 저장된다)
        $fallback = [
            'zipcode' => $existing?->zipcode,
            'address' => $existing?->address,
            'address_line_1' => $existing?->address_line_1,
            'intl_city' => $existing?->city,
            'intl_postal_code' => $existing?->postal_code,
        ];

        $effective = [];

        foreach ($fallback as $key => $storedValue) {
            $effective[$key] = array_key_exists($key, $payload) ? $payload[$key] : $storedValue;
        }

        $country = array_key_exists('country_code', $payload)
            ? strtoupper((string) $payload['country_code'])
            : strtoupper((string) ($existing?->country_code ?? 'KR'));

        if ($country === '') {
            $country = 'KR';
        }

        if ($country === 'KR') {
            if (! $this->isFilled($effective['zipcode'])) {
                $validator->errors()->add('zipcode', __('sirsoft-ecommerce::validation.user_address.zipcode_required'));
            }

            if (! $this->isFilled($effective['address'])) {
                $validator->errors()->add('address', __('sirsoft-ecommerce::validation.user_address.address_required'));
            }

            return;
        }

        if (! $this->isFilled($effective['address_line_1'])) {
            $validator->errors()->add('address_line_1', __('sirsoft-ecommerce::validation.user_address.address_line_1_required'));
        }

        if (! $this->isFilled($effective['intl_city'])) {
            $validator->errors()->add('intl_city', __('sirsoft-ecommerce::validation.user_address.intl_city_required'));
        }

        if (! $this->isFilled($effective['intl_postal_code'])) {
            $validator->errors()->add('intl_postal_code', __('sirsoft-ecommerce::validation.user_address.intl_postal_code_required'));
        }
    }

    /**
     * 수정 대상 배송지를 조회합니다. (본인 소유 스코프)
     *
     * @return UserAddress|null 배송지 (없으면 null — 404 는 컨트롤러가 판정)
     */
    protected function findExistingAddress(): ?UserAddress
    {
        $userId = Auth::id();
        $addressId = (int) $this->route('id');

        if ($userId === null || $addressId <= 0) {
            return null;
        }

        // Repository 인터페이스 경유 — FormRequest 의 Model 직접 호출 금지 규정 준수
        return app(UserAddressRepositoryInterface::class)->findByUserIdAndId($userId, $addressId);
    }

    /**
     * 값이 실제로 채워져 있는지 판정합니다. (공백만 있는 문자열은 빈 값)
     *
     * @param  mixed  $value  판정 대상 값
     * @return bool 채워져 있으면 true
     */
    private function isFilled(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        return trim((string) $value) !== '';
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array 필드별 다국어 검증 메시지
     */
    public function messages(): array
    {
        return [
            'name.string' => __('sirsoft-ecommerce::validation.user_address.name_string'),
            'recipient_name.string' => __('sirsoft-ecommerce::validation.user_address.recipient_name_string'),
            'recipient_phone.string' => __('sirsoft-ecommerce::validation.user_address.recipient_phone_string'),
        ];
    }
}
