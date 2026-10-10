<?php

namespace App\Http\Requests\Public\Template;

use App\Rules\AllowedTemplateFileType;
use App\Rules\SafeTemplatePath;
use App\Support\CustomAssets;
use App\Support\Routing\DualExtensionRoute;
use Illuminate\Foundation\Http\FormRequest;

class ServeTemplateAssetRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 항상 true (권한은 미들웨어 책임)
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
    // audit:allow core-formrequest-hook-filter reason: 자산 서빙 경로 검증은 파일시스템
    // 화이트리스트(SafeTemplatePath + AllowedTemplateFileType)가 유일한 방어선이다.
    // 확장이 필터로 규칙을 대체할 수 있으면 경로 탈출·임의 파일 읽기가 열린다.
    // 확장 가능한 "동적 필드" 가 없는 요청이라 룰의 취지(필드 확장)도 해당하지 않는다.
    public function rules(): array
    {
        // 템플릿 식별자로부터 기준 경로 구성.
        //
        // 컨테인먼트 기준은 **실제로 읽는 디렉토리**여야 한다. 템플릿 자산은 `dist/` 이하가
        // 기본이지만 운영자 소유 디렉토리(`custom/`)만은 그 밖에 있고, 서빙측
        // `TemplateService::getAssetFilePath()` 가 그 분기를 갖는다. 기준을 `dist` 로
        // 고정하면 `custom/**` 은 realpath 가 실패해 문자열 접두 비교로만 통과하므로,
        // 검증한 경로와 읽는 경로가 서로 다른 상태가 된다. 두 곳의 분기를 같은 조건으로
        // 맞춰 둔다 — 한쪽만 바뀌면 custom 서빙이 조용히 깨지거나 검증이 헐거워진다.
        $basePath = $this->basePathFor((string) $this->input('path'));

        return [
            'identifier' => ['required', 'string'],
            'path' => [
                'required',
                'string',
                new SafeTemplatePath($basePath),
                new AllowedTemplateFileType,
            ],
        ];
    }

    /**
     * 검증을 위한 데이터 준비 — 라우트 세그먼트 또는 `?file=` 의 경로를 `path` 로 모으고 AMD 모듈 id 를 보완한다.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        // 라우트 파라미터를 검증 데이터에 병합.
        // 확장자 없는 모드에서는 파일 경로가 경로 세그먼트가 아니라 `?file=` 쿼리로 온다
        // (nginx 정적 최적화 블록이 URL 경로의 확장자만 보고 가로채는 것을 회피).
        // 어느 형태로 오든 컨트롤러는 동일하게 `path` 만 본다.
        $this->merge([
            'identifier' => $this->route('identifier'),
            'path' => $this->resolveAmdModulePath($this->route('path') ?? $this->query(DualExtensionRoute::FILE_QUERY_PARAM)),
        ]);
    }

    /**
     * 요청 경로가 읽힐 기준 디렉토리를 정합니다 (검증 컨테인먼트 기준 = 서빙 루트).
     *
     * @param  string  $requestedPath  요청 경로
     * @return string 기준 디렉토리 절대 경로
     */
    private function basePathFor(string $requestedPath): string
    {
        $identifier = $this->route('identifier');

        return str_starts_with($requestedPath, CustomAssets::DIRECTORY.'/')
            ? base_path("templates/{$identifier}")
            : base_path("templates/{$identifier}/dist");
    }

    /**
     * 확장자 없이 온 AMD 모듈 id 를 같은 이름의 `.js` 파일 경로로 보완합니다.
     *
     * AMD 로더(Monaco)는 URL 에 쿼리가 있으면 모듈 id 에 `.js` 를 붙이지 않는다. 확장자를 가로채는 서버에서
     * 디렉토리를 `?file=` 형태로 받은 로더는 `…/editor/editor.main` 을 요청하므로, 그 파일이 없고 같은 이름의
     * `.js` 가 있으면 그 경로로 바꾼다(`editor.main` 처럼 점이 든 id 라 확장자 유무로 가리지 않는다). 보완은 검증
     * 전에 하고, 경로 이탈·확장자 검증은 보완된 경로로 그대로 돈다. 상위 디렉토리 표기가 든 경로는 존재 확인이
     * 기준 디렉토리 밖을 보지 않도록 보완하지 않는다.
     *
     * @param  mixed  $path  요청 경로 (라우트 세그먼트 또는 `?file=` 값)
     * @return mixed 보완된 경로, 해당 없으면 원래 값
     */
    private function resolveAmdModulePath(mixed $path): mixed
    {
        if (! is_string($path) || $path === '' || str_contains($path, '..')) {
            return $path;
        }

        $absolute = $this->basePathFor($path).DIRECTORY_SEPARATOR.ltrim($path, '/\\');

        return ! is_file($absolute) && is_file($absolute.'.js') ? $path.'.js' : $path;
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'identifier.required' => __('validation.asset.identifier.required'),
            'identifier.string' => __('validation.asset.identifier.string'),
            'path.required' => __('validation.asset.path.required'),
            'path.string' => __('validation.asset.path.string'),
        ];
    }
}
