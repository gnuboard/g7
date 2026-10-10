<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\AdminClaimRequestPreviewRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkClaimStatusRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ClaimCompletionPreviewRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ClaimListRequest as AdminClaimListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreEcommerceSettingsRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\ClaimListRequest as UserClaimListRequest;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 형식이 틀린 입력(문자열 칸에 목록)은 서버 오류가 아니라 입력 오류로 돌아간다 (#599 — 16차 병합 후속)
 *
 * 규칙 계산(`rules()`)은 검증보다 먼저 돌고, 사후 검증(`after`)은 규칙이 실패해도 돈다. 그 안에서 입력을
 * `(string)` 으로 바꾸면 목록이 온 요청에서 「Array to string conversion」 으로 500 이 난다 — 규칙이 이미
 * 422 로 막으려던 요청이다. 브라우저 실측에서 관리자 일괄 상태 변경이 500 을 돌려준 뒤, 같은 형 변환을 가진
 * 클레임 요청을 전수로 돌려 찾은 지점들이다. 요청은 인가를 건너뛰고 실제 검증 경로(값 준비 → 규칙 →
 * 사후 검증)만 돈다. 트러블슈팅 `[case:backend-46]` 의 회귀 그물이다.
 *
 * 소유 시나리오: `claim-request-malformed-input`
 */
class ClaimRequestMalformedInputTest extends ModuleTestCase
{
    /**
     * 요청마다 문자열 칸에 목록을 넣은 본문과 오류가 나야 할 칸
     *
     * @return array<string, array{0: class-string<FormRequest>, 1: array<string, mixed>, 2: string}> 요청 · 본문 · 오류 칸
     */
    public static function malformedInputs(): array
    {
        return [
            '관리자 직권 접수 미리보기 — 유형' => [AdminClaimRequestPreviewRequest::class, ['type' => ['return']], 'type'],
            '관리자 일괄 상태 변경 — 유형' => [BulkClaimStatusRequest::class, ['type' => ['return'], 'ids' => [1], 'status' => 'approved'], 'type'],
            '관리자 완료 미리보기 — 유형' => [ClaimCompletionPreviewRequest::class, ['claim_type' => ['exchange'], 'claims' => [['id' => 1, 'item_ids' => [1]]]], 'claim_type'],
            '관리자 클레임 목록 — 유형' => [AdminClaimListRequest::class, ['type' => ['return']], 'type'],
            '관리자 클레임 목록 — 통화' => [AdminClaimListRequest::class, ['currency_code' => ['KRW']], 'currency_code'],
            '마이페이지 클레임 목록 — 유형' => [UserClaimListRequest::class, ['type' => ['return']], 'type'],
            '환경설정 저장 — 배송사 코드' => [StoreEcommerceSettingsRequest::class, ['_tab' => 'shipping', 'shipping' => ['carriers' => [['id' => 1, 'code' => ['cj']]]]], 'shipping.carriers.0.code'],
            '환경설정 저장 — 배송유형 코드' => [StoreEcommerceSettingsRequest::class, ['_tab' => 'shipping', 'shipping' => ['types' => [['code' => ['parcel']]]]], 'shipping.types.0.code'],
            '환경설정 저장 — 배송 국가 코드' => [StoreEcommerceSettingsRequest::class, ['_tab' => 'shipping', 'shipping' => ['available_countries' => [['code' => ['KR']]], 'default_country' => ['KR']]], 'shipping.available_countries.0.code'],
            '환경설정 저장 — 통화 코드' => [StoreEcommerceSettingsRequest::class, ['_tab' => 'language_currency', 'language_currency' => ['currencies' => [['code' => ['KRW']]]]], 'language_currency.currencies.0.code'],
            '환경설정 저장 — 교환 사유 코드' => [StoreEcommerceSettingsRequest::class, ['_tab' => 'claim', 'claim' => ['exchange_reasons' => [['code' => ['size']]]]], 'claim.exchange_reasons.0.code'],
        ];
    }

    /**
     * 문자열 칸에 목록이 오면 검증이 예외 없이 끝나고 그 칸에 오류가 난다.
     *
     * 수정 전 실패 이유: 규칙 계산·사후 검증이 입력을 `(string)` 으로 바꿔 ErrorException(500)이 났다.
     *
     * @param  class-string<FormRequest>  $class  요청 클래스
     * @param  array<string, mixed>  $input  요청 본문
     * @param  string  $field  오류가 나야 할 칸
     * @return void 반환값 없음
     *
     * @scenario input=list_in_string_field
     *
     * @effects malformed_claim_input_returns_validation_error_not_server_error
     */
    #[DataProvider('malformedInputs')]
    public function test_list_in_a_string_field_is_a_validation_error_not_a_server_error(string $class, array $input, string $field): void
    {
        $request = $class::create('/', 'POST', $input);
        $request->setContainer($this->app);

        (function () {
            if (method_exists($this, 'prepareForValidation')) {
                $this->prepareForValidation();
            }
        })->call($request);

        $validator = Validator::make($request->all(), $request->rules(), $request->messages(), $request->attributes());

        (function () use ($validator) {
            if (method_exists($this, 'withValidator')) {
                $this->withValidator($validator);
            }
        })->call($request);

        $this->assertTrue($validator->fails(), '형식이 틀린 입력은 검증 오류여야 한다');
        $this->assertArrayHasKey($field, $validator->errors()->toArray(), $field.' 칸에 오류가 나야 한다');
    }
}
