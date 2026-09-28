<?php

namespace App\Http\Requests\LanguagePack;

use App\Extension\HookManager;
use App\Http\Requests\LanguagePack\Concerns\ValidatesLanguagePackArchive;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 언어팩 manifest 미리보기 요청.
 *
 * 설치 전 ZIP 의 manifest 와 검증 결과만 추출하여 반환합니다 (실제 설치 X).
 *
 * 파일 규칙은 설치 요청과 **같은 하나**를 읽습니다 — 미리보기가 더 좁으면 "설치는 되는데
 * 내용은 확인할 수 없는 팩" 이 생깁니다.
 */
class ManifestPreviewRequest extends FormRequest
{
    use ValidatesLanguagePackArchive;

    /**
     * 권한 체크는 라우트 미들웨어가 담당.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 정의합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'file' => $this->languagePackArchiveRules(),
        ];

        // 코어 FormRequest 는 확장이 필드를 더할 수 있도록 이 훅을 노출한다(하네스 규율
        // `core-formrequest-hook-filter`). **`use` 를 빠뜨리면 같은 네임스페이스의 없는
        // 클래스로 해석돼 이 엔드포인트 전건이 500 이 된다** — 문법 오류가 아니라 런타임
        // 해석 실패라 `php -l` 도 통과한다. 실제로 그렇게 죽어 있었다.
        return HookManager::applyFilters('core.language_packs.manifest_preview_validation_rules', $rules, $this);
    }

    /**
     * 검증 메시지를 정의합니다.
     *
     * @return array<string, string> 검증 메시지
     */
    public function messages(): array
    {
        return $this->languagePackArchiveMessages();
    }
}
