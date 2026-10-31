<?php

namespace App\Http\Requests\Public\Analytics;

use App\Analytics\AnalyticsConfig;
use App\Analytics\TrackingRequestFactory;
use App\Enums\Analytics\ActionType;
use App\Enums\Analytics\CollectFailureReason;
use App\Extension\HookManager;
use App\Services\Analytics\CollectFailureLogger;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * 방문자 통계 비콘 수집 요청 검증
 *
 * 형식이 어긋난 페이로드를 걸러 원장에 이상한 값이 들어가지 않게 합니다.
 *
 * 다만 검증 실패가 **응답을 바꾸지는 않습니다**. 컨트롤러가 실패를 잡아 204 로
 * 돌려주고 사유만 로그로 남깁니다 — 비콘에 4xx 를 주면 방문자 브라우저 콘솔에
 * 빨간 오류가 뜨고, 운영자는 사이트가 고장 난 줄 압니다.
 */
class CollectRequest extends FormRequest
{
    /**
     * 요청 권한을 확인합니다.
     *
     * 공개 수집 경로라 인증을 요구하지 않습니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            // 사이트 내부 검색은 서버 훅만 적는다 — 결과 건수는 서버만 아는 값이라,
            // 브라우저가 보낼 수 있게 열어 두면 누구나 인기 검색어를 위조할 수 있다.
            't' => ['required', 'string', Rule::in(self::beaconActionTypes())],
            'url' => ['required', 'string', 'url', 'max:2048'],
            'title' => ['nullable', 'string', 'max:512'],
            'ref' => ['nullable', 'string', 'url', 'max:1024'],
            'res' => ['nullable', 'string', 'regex:/^\d{2,5}x\d{2,5}$/'],
            'lang' => ['nullable', 'string', 'max:16'],
            'locale' => ['nullable', 'string', 'max:10'],
            'prev' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'init' => ['nullable', 'boolean'],
            'target' => ['nullable', 'string', 'url', 'max:1024'],
            'name' => ['nullable', 'string', 'max:255'],
            'goal' => ['nullable', 'string', 'max:100'],
            'attrs' => ['nullable', 'array'],
            'dims' => ['nullable', 'array', 'max:20'],
            'dims.*' => ['nullable', 'string', 'max:255'],
            'ch' => ['nullable', 'array'],
            'wd' => ['nullable', 'boolean'],
        ];

        // 확장이 자기 차원·목표에 필요한 필드를 더할 수 있게 연다.
        return HookManager::applyFilters('core.analytics.collect_validation_rules', $rules, $this);
    }

    /**
     * 브라우저 비콘이 보낼 수 있는 액션 종류를 반환합니다.
     *
     * @return array<int, string> 허용 액션 값 (사이트 검색 제외)
     */
    public static function beaconActionTypes(): array
    {
        return array_values(array_diff(ActionType::values(), [ActionType::SiteSearch->value]));
    }

    /**
     * 검증 실패를 204 로 돌려줍니다.
     *
     * 기본 동작인 422 를 그대로 두면 방문자 브라우저 콘솔에 빨간 오류가 뜹니다.
     * 그것을 본 운영자는 사이트가 고장 났다고 판단합니다 — 통계를 못 남긴 것이
     * 사이트 장애로 보여선 안 됩니다. 실패 사유는 컨트롤러가 전용 로그에 남깁니다.
     *
     * @param  Validator  $validator  검증기
     * @return void
     *
     * @throws HttpResponseException 언제나 204 응답으로 중단한다
     */
    protected function failedValidation(Validator $validator): void
    {
        // 응답을 끊기 전에 사유를 남긴다 — 여기서 남기지 않으면 「왜 방문이 비는가」에
        // 답할 단서가 어디에도 없다(응답은 정상 204 로 보이기 때문이다).
        app(CollectFailureLogger::class)->log(
            CollectFailureReason::Validation,
            implode(' / ', $validator->errors()->all()),
            TrackingRequestFactory::fromHttp($this, $this->all()),
            AnalyticsConfig::fromSettings()->ipMaskOctets,
        );

        throw new HttpResponseException(response()->noContent());
    }

    /**
     * 검증 메시지를 반환합니다.
     *
     * @return array<string, string> 검증 메시지
     */
    public function messages(): array
    {
        return [
            't.required' => __('validation.analytics.type_required'),
            't.in' => __('validation.analytics.type_invalid'),
            'url.required' => __('validation.analytics.url_required'),
            'url.max' => __('validation.analytics.url_too_long'),
            'res.regex' => __('validation.analytics.resolution_invalid'),
            'dims.max' => __('validation.analytics.dimensions_too_many'),
        ];
    }

    /**
     * 검증 항목명을 반환합니다 — 오류 문구에 필드 키 대신 화면에서 쓰는 이름이 나오게 합니다.
     *
     * @return array<string, string> 필드 => 항목명
     */
    public function attributes(): array
    {
        return [
            't' => __('analytics.attributes.t'),
            'url' => __('analytics.attributes.url'),
            'title' => __('analytics.attributes.title'),
            'ref' => __('analytics.attributes.ref'),
            'res' => __('analytics.attributes.res'),
            'lang' => __('analytics.attributes.lang'),
            'locale' => __('analytics.attributes.locale'),
            'prev' => __('analytics.attributes.prev'),
            'init' => __('analytics.attributes.init'),
            'target' => __('analytics.attributes.target'),
            'name' => __('analytics.attributes.name'),
            'goal' => __('analytics.attributes.goal'),
            'attrs' => __('analytics.attributes.attrs'),
            'dims' => __('analytics.attributes.dims'),
            'dims.*' => __('analytics.attributes.dims'),
            'ch' => __('analytics.attributes.ch'),
            'wd' => __('analytics.attributes.wd'),
        ];
    }
}
