<?php

namespace App\Http\Requests\Public\Template;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 레이아웃 편집기 스펙 묶음 요청 검증.
 *
 * `GET /api/templates/{identifier}/editor-spec-bundle?modules[]=..&plugins[]=..`
 * 편집기가 `window.G7Config.activeModules` / `activePlugins` 에서 고른 식별자를 보낸다.
 */
class ServeEditorSpecBundleRequest extends FormRequest
{
    /**
     * 한 종류당 받는 식별자 수 상한.
     *
     * 공개 엔드포인트라 한 요청이 서버에서 파일을 읽는 양을 묶어 둔다. 한 사이트의 활성 확장 수는
     * 이보다 훨씬 적다(개발 서버 모듈·플러그인 합계 26개).
     */
    public const MAX_IDENTIFIERS = 200;

    /**
     * 확장 식별자 형식 — `vendor-name` (영숫자·`_`·`-`·`.`).
     *
     * 식별자는 설치본 경로(`modules/{id}/editor-spec.json`)에 들어가므로 경로 구분자와 상위 디렉토리
     * 표기를 받지 않는다. `..` 는 아래 `not_regex` 로 따로 막는다.
     */
    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_.-]+$/';

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 개별 편집기 스펙 엔드포인트와 같은 공개 엔드포인트다(인증 불필요).
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed> 검증 규칙 배열
     */
    // audit:allow core-formrequest-hook-filter reason: 식별자 형식 검증은 설치본 경로 조립의 방어선이다.
    // 확장이 필터로 규칙을 대체하면 경로 탈출이 열린다. 확장이 더할 동적 필드가 없는 요청이다.
    public function rules(): array
    {
        $identifier = ['string', 'max:100', 'regex:'.self::IDENTIFIER_PATTERN, 'not_regex:/\.\./'];

        return [
            'identifier' => ['required', ...$identifier],
            'modules' => ['sometimes', 'array', 'max:'.self::MAX_IDENTIFIERS],
            'modules.*' => ['required', ...$identifier],
            'plugins' => ['sometimes', 'array', 'max:'.self::MAX_IDENTIFIERS],
            'plugins.*' => ['required', ...$identifier],
        ];
    }

    /**
     * 검증 오류 문구에 쓸 필드 라벨
     *
     * 오류 문구에 `modules.0` 같은 키가 그대로 나오지 않게 확장 종류 이름(ko·en·ja 모두 있는 키)을 쓴다.
     *
     * @return array<string, string> 필드 라벨
     */
    public function attributes(): array
    {
        return [
            'modules' => __('extension_owner_type.module'),
            'modules.*' => __('extension_owner_type.module'),
            'plugins' => __('extension_owner_type.plugin'),
            'plugins.*' => __('extension_owner_type.plugin'),
        ];
    }

    /**
     * 검증을 위한 데이터 준비 — 라우트 세그먼트의 템플릿 식별자를 검증 데이터에 넣는다.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'identifier' => $this->route('identifier'),
        ]);
    }

    /**
     * 검증된 모듈 식별자 목록을 반환합니다.
     *
     * @return array<int, string> 모듈 식별자 목록 (요청 순서)
     */
    public function moduleIdentifiers(): array
    {
        return array_values($this->validated('modules', []));
    }

    /**
     * 검증된 플러그인 식별자 목록을 반환합니다.
     *
     * @return array<int, string> 플러그인 식별자 목록 (요청 순서)
     */
    public function pluginIdentifiers(): array
    {
        return array_values($this->validated('plugins', []));
    }
}
