<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 비즈뿌리오 webhook(URL PUSH) 리포트 검증 Request.
 *
 * 필수 필드(부록 C-4): DEVICE(메시지유형) · CMSGID(메시지키) · MSGID(비즈뿌리오키) ·
 * PHONE(수신번호) · MEDIA(실발송유형) · RESULT(결과코드) · REFKEY(우리 부여 키).
 * 선택 필드: TO NAME · WAPINFO · TELRES/TELTIME(대체발송 결과) · KAORES/KAOTIME 등.
 *
 * 인증/권한은 라우트의 IP 화이트리스트 미들웨어가 담당하므로 authorize() 는 true 고정.
 */
class BizppurioWebhookRequest extends FormRequest
{
    /**
     * 권한 검사는 IP 화이트리스트 미들웨어가 담당 — 항상 통과.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * webhook 리포트 검증 규칙.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'DEVICE' => ['nullable', 'string', 'max:20'],
            'CMSGID' => ['nullable', 'string', 'max:64'],
            'MSGID' => ['nullable', 'string', 'max:64'],
            'PHONE' => ['nullable', 'string', 'max:20'],
            'MEDIA' => ['nullable', 'string', 'max:10'],
            'RESULT' => ['required', 'string', 'max:10'],
            'REFKEY' => ['required', 'string', 'max:32'],
            'TELRES' => ['nullable', 'string', 'max:10'],
            'KAORES' => ['nullable', 'string', 'max:10'],
        ];
    }
}
