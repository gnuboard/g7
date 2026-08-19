<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * KCP 표준창 인증 종료 콜백(Ret_URL) 본문을 수신합니다.
 *
 * 규칙이 비어 있는 이유: KCP 는 매뉴얼에 명시되지 않은 필드를 함께 보내며 그 목록이
 * 가맹점 설정·인증 수단에 따라 달라집니다. 여기서 화이트리스트를 닫으면 정상 콜백이
 * 422 로 거부되어 인증이 끊깁니다. 콜백 본문의 의미 검증(서명·거래 대조)은
 * `KcpCallbackResolver` 가 단독으로 담당합니다.
 *
 * base `Request` 대신 이 클래스를 두는 것은, "검증하지 않는다" 가 누락이 아니라
 * 결정임을 코드에 남기기 위해서입니다 (kginicis InicisCallbackRequest 선례).
 */
class KcpCallbackRequest extends FormRequest
{
    /**
     * 요청 권한 — 외부 PG 가 호출하는 공개 콜백이므로 true 고정.
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
     * @return array<string, array<int, mixed>> 빈 규칙 (사유는 클래스 설명 참조)
     */
    public function rules(): array
    {
        return [];
    }
}
