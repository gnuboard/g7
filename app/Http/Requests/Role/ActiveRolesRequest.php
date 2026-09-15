<?php

namespace App\Http\Requests\Role;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 활성 역할 목록(선택 옵션용) 조회 요청
 *
 * 별도 검증 규칙 없이 인증 컨텍스트만 전달하는 엔드포인트지만, 컨트롤러가 base
 * Illuminate\Http\Request 를 직접 주입받지 않도록 전용 FormRequest 서브클래스를 둔다.
 * 인증/권한은 permission 미들웨어 체인이 담당하므로 authorize() 는 true 고정.
 */
class ActiveRolesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool 항상 true (권한은 미들웨어 체인이 검증)
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
        return [];
    }
}
