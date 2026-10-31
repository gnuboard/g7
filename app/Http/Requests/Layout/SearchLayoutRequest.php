<?php

namespace App\Http\Requests\Layout;

use App\Extension\HookManager;
use App\Services\LayoutFileService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 레이아웃 **파일명·내용 검색** 요청 검증.
 *
 * `q` 최소 2자를 서버에서도 강제한다. 화면이 이미 2자 미만을 보내지 않지만, 1자 검색은
 * 템플릿 전체(102개 이상)를 역변환해 훑는 일이라 비용이 크고 결과는 쓸모가 없다 —
 * 클라이언트 규약 하나에만 기대면 다른 호출부가 생겼을 때 그대로 뚫린다.
 */
class SearchLayoutRequest extends FormRequest
{
    /** `q` 최소 길이 */
    public const MIN_KEYWORD_LENGTH = 2;

    /** `q` 최대 길이 */
    public const MAX_KEYWORD_LENGTH = 200;

    /**
     * 권한은 라우트의 permission 미들웨어가 판정합니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙.
     *
     * @return array<string, mixed> 규칙 배열
     */
    public function rules(): array
    {
        $rules = [
            'q' => ['required', 'string', 'min:'.self::MIN_KEYWORD_LENGTH, 'max:'.self::MAX_KEYWORD_LENGTH],
            'scope' => ['nullable', 'string', 'in:name,content,both'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.LayoutFileService::SEARCH_MAX_FILES],
        ];

        return HookManager::applyFilters('core.layout.search_validation_rules', $rules, $this);
    }

    /**
     * 검증 통과분 + 기본값.
     *
     * @return array{q: string, scope: string, limit: int} 정규화된 검색 조건
     */
    public function criteria(): array
    {
        $validated = $this->validated();

        return [
            'q' => (string) $validated['q'],
            'scope' => (string) ($validated['scope'] ?? 'both'),
            'limit' => (int) ($validated['limit'] ?? LayoutFileService::SEARCH_MAX_FILES),
        ];
    }
}
