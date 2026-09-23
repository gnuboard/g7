<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsAdminService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;

/**
 * 특정 설정값 업데이트 요청 검증
 */
class UpdateSettingRequest extends FormRequest
{
    /**
     * 개별 설정도 전체 저장과 같은 값 검증·숫자 정규화를 거칩니다.
     *
     * key/value 봉투는 아래 규칙으로 검증하고, 설정 내용은 기존 FormRequest가 검증합니다.
     * 잘못된 상태·카탈로그 항목이 개별 저장 경로에서만 통과하지 않게 합니다.
     *
     * @return void 설정값 정규화 및 기존 설정 요청 규칙 검증
     *
     * @throws ValidationException 설정값이 저장 규칙을 위반했거나, 규칙이 없는 키·서버 전용 키인 경우
     */
    protected function prepareForValidation(): void
    {
        $key = $this->input('key');
        if (! is_string($key) || $key === '' || strlen($key) > 255 || ! $this->exists('value')) {
            return;
        }

        // 환불 자동 승인 결정 이력(P17)은 서버만 쓴다. 값 검증(`prohibited`)은 빈 배열을 통과시키므로
        // 키 자체를 거절한다 — `[]` 한 번으로 이력이 지워지면 운영자가 뺀 수단이 되살아난다.
        $decidedKey = 'claim.'.EcommerceSettingsAdminService::REFUND_AUTO_APPROVE_DECIDED_KEY;
        if ($key === $decidedKey || str_starts_with($key, $decidedKey.'.')) {
            throw ValidationException::withMessages([
                'key' => __('sirsoft-ecommerce::validation.settings.key_server_managed'),
            ]);
        }

        $category = explode('.', $key, 2)[0];
        $payload = [];
        Arr::set($payload, $key, $this->input('value'));
        // audit:allow formrequest-direct-data-access FormRequest 생성이며 모델/DB 접근이 아니다.
        $settingsRequest = StoreEcommerceSettingsRequest::create($this->url(), 'POST', $payload);
        $settingsRequest->setContainer($this->container)->setRedirector($this->redirector);
        $settingsRequest->setUserResolver($this->getUserResolver());
        // 카테고리 전체를 요구하는 필수 형제 필드만 보충한다. 조회용 기본 카탈로그까지
        // 검증하면 빈 예시 계좌처럼 이번 수정과 무관한 값이 개별 저장을 막는다.
        if (str_contains($key, '.')) {
            $current = app(EcommerceSettingsService::class)->getAllSettings();
            foreach ($settingsRequest->rules() as $field => $rules) {
                if (! str_contains($field, '*') && in_array('required_with:'.$category, (array) $rules, true)
                    && ! Arr::has($payload, $field)) {
                    Arr::set($payload, $field, Arr::get($current, $field));
                }
            }
            $settingsRequest->replace($payload);
        }
        $settingsRequest->validateResolved();

        // 저장 규칙이 다스리지 않는 키는 검증된 설정에 남지 않는다. 그 자리를 원시 입력으로
        // 메우면 검증을 한 번도 거치지 않은 값이 설정 파일에 그대로 영속되므로 422 로 거절한다.
        // 카테고리 통째(`claim`)·중첩 배열 키도 규칙이 있는 하위 값이 하나라도 있으면 남는다.
        $validatedSettings = $settingsRequest->validatedSettings();
        if (! Arr::has($validatedSettings, $key)) {
            throw ValidationException::withMessages([
                'key' => __('sirsoft-ecommerce::validation.settings.key_unknown', ['key' => $key]),
            ]);
        }

        $this->merge(['value' => Arr::get($validatedSettings, $key)]);
    }

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 처리 결과
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:255'],
            'value' => ['present'],
        ];
    }

    /** 단건 통화 저장도 벌크 요청의 환율 관리 계약으로 검증합니다.
     * @param  \Illuminate\Validation\Validator  $validator  validator 입력값
     * @return void 반환값 없음
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            $key = $this->input('key');
            if (! is_string($key) || ($key !== 'language_currency' && ! str_starts_with($key, 'language_currency.'))) {
                return;
            }
            $payload = ['language_currency' => app(EcommerceSettingsService::class)->getSettings('language_currency')];
            Arr::set($payload, $key, $this->input('value'));
            $request = new StoreEcommerceSettingsRequest($payload);
            $request->setContainer(app());
            $nested = Validator::make($payload, $request->rules(), $request->messages());
            $request->withValidator($nested);
            if ($nested->fails()) {
                foreach ($nested->errors()->all() as $message) {
                    $validator->errors()->add('value', $message);
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
            'key.required' => __('sirsoft-ecommerce::validation.settings.key_required'),
            'value.present' => __('sirsoft-ecommerce::validation.settings.value_present'),
        ];
    }
}
