<?php

namespace App\Http\Requests\Public;

use App\Http\Requests\Concerns\ResolvesDualModeFilePath;
use App\Rules\SafeTemplatePath;
use App\Services\PublicStorageService;
use Illuminate\Foundation\Http\FormRequest;

class ServePublicStorageFileRequest extends FormRequest
{
    use ResolvesDualModeFilePath;

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 공개 스토리지(`storage/app/public`)는 정의상 공개 자산이므로 인증이 없습니다.
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
    // audit:allow core-formrequest-hook-filter reason: 자산 서빙 경로 검증은 파일시스템
    // 봉쇄(SafeTemplatePath + 서비스의 링크 비추적)가 유일한 방어선이다. 확장이 필터로
    // 규칙을 대체할 수 있으면 경로 탈출·임의 파일 읽기가 열린다. 동적 필드도 없다.
    public function rules(): array
    {
        return [
            'path' => [
                'required',
                'string',
                // 루트는 서비스가 소유한다 — 두 곳이 같은 리터럴을 적으면 운영자가
                // `disks.public.root` 를 옮긴 배치에서 검증과 서빙의 기준이 갈린다.
                // 점으로 시작하는 이름의 거부는 **이 규칙이 아니라 서비스**가 한다 —
                // 그 통로의 계약은 404 이고(정적 서빙과 같은 코드), 여기서 422 를 내면
                // 같은 파일이 링크 유무로 다른 코드를 받는다.
                new SafeTemplatePath(PublicStorageService::rootPath(), rejectHiddenSegments: false),
            ],
        ];
    }

    /**
     * 검증을 위한 데이터 준비
     */
    protected function prepareForValidation(): void
    {
        // 확장자 모드는 `{path}` 라우트 세그먼트, 확장자 없는 모드는 `?file=` 쿼리로 온다
        // (dualAsset 이중 등록 — R33). 어느 형태로 오든 컨트롤러는 `path` 만 본다.
        // 두 모드는 **같은 값**으로 접혀야 한다.
        //
        // 라우트 세그먼트(`{path}`)는 전역 `TrimStrings`·`ConvertEmptyStringsToNull` 의 대상이
        // 아니지만 쿼리(`?file=`)는 대상이다. 그래서 종전에는 같은 입력이 두 모드에서 다른
        // 판정을 받았다 — `?file=logo.png%00` 은 널바이트가 잘려 200, 세그먼트 모드는 422.
        // 선행·후행 공백도 마찬가지로 한쪽에서만 잘려 **다른 파일**을 서빙했다(POSIX 에서
        // 공백은 정당한 파일 이름 문자다). 이중 모드는 같은 파일을 두 형태로 여는 장치이지
        // 검사 강도를 가르는 장치가 아니므로, 쿼리 원문을 직접 읽어 그 비대칭을 없앤다.
        $this->merge([
            'path' => $this->dualModeFilePath(),
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
            'path.required' => __('validation.asset.path.required'),
            'path.string' => __('validation.asset.path.string'),
        ];
    }
}
