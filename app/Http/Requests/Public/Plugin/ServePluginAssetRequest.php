<?php

namespace App\Http\Requests\Public\Plugin;

use App\Rules\AllowedPluginFileType;
use App\Rules\SafePluginPath;
use App\Support\Routing\DualExtensionRoute;
use Illuminate\Foundation\Http\FormRequest;

class ServePluginAssetRequest extends FormRequest
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
    // 화이트리스트(SafePluginPath + AllowedPluginFileType)가 유일한 방어선이다.
    // 확장이 필터로 규칙을 대체할 수 있으면 경로 탈출·임의 파일 읽기가 열린다.
    public function rules(): array
    {
        // 플러그인 식별자로부터 기준 경로 구성 (플러그인 루트)
        $identifier = $this->route('identifier');
        $basePath = base_path("plugins/{$identifier}");

        return [
            'identifier' => ['required', 'string'],
            'path' => [
                'required',
                'string',
                new SafePluginPath($basePath),
                new AllowedPluginFileType,
            ],
        ];
    }

    /**
     * 검증을 위한 데이터 준비
     */
    protected function prepareForValidation(): void
    {
        // 라우트 파라미터를 검증 데이터에 병합
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
