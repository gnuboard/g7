<?php

namespace App\Http\Requests\Admin\Extension;

use App\Extension\HookManager;
use App\Http\Requests\Layout\SearchLayoutRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 사용자 추가 에셋 **검색** 요청 검증
 *
 * 최소 2자를 서버에서도 강제한다 — 1자 검색은 `custom/` 의 텍스트 파일을 전부 열어 훑는 일이라
 * 비용은 크고 결과는 쓸모가 없다. 클라이언트 규약 하나에만 기대면 다른 호출부가 생겼을 때
 * 그대로 뚫린다({@see SearchLayoutRequest} 와 같은 하한).
 */
class SearchExtensionCustomAssetRequest extends FormRequest
{
    /** `q` 최소 길이 */
    public const MIN_KEYWORD_LENGTH = 2;

    /** `q` 최대 길이 */
    public const MAX_KEYWORD_LENGTH = 200;

    /**
     * 요청 권한 확인 — 권한은 permission 미들웨어가 담당.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'q' => ['required', 'string', 'min:'.self::MIN_KEYWORD_LENGTH, 'max:'.self::MAX_KEYWORD_LENGTH],
        ];

        return HookManager::applyFilters('core.extension_custom_asset.search_validation_rules', $rules, $this);
    }
}
