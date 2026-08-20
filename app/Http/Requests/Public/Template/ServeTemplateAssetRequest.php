<?php

namespace App\Http\Requests\Public\Template;

use App\Rules\AllowedTemplateFileType;
use App\Rules\SafeTemplatePath;
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
        // 템플릿 식별자로부터 기준 경로 구성
        $identifier = $this->route('identifier');
        $basePath = base_path("templates/{$identifier}/dist");

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
     * 검증을 위한 데이터 준비
     */
    protected function prepareForValidation(): void
    {
        // 라우트 파라미터를 검증 데이터에 병합.
        // 확장자 없는 모드에서는 파일 경로가 경로 세그먼트가 아니라 `?file=` 쿼리로 온다
        // (nginx 정적 최적화 블록이 URL 경로의 확장자만 보고 가로채는 것을 회피).
        // 어느 형태로 오든 컨트롤러는 동일하게 `path` 만 본다.
        $this->merge([
            'identifier' => $this->route('identifier'),
            'path' => $this->route('path') ?? $this->query(DualExtensionRoute::FILE_QUERY_PARAM),
        ]);
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
