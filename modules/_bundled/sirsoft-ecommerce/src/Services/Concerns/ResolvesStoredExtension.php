<?php

namespace Modules\Sirsoft\Ecommerce\Services\Concerns;

use Illuminate\Http\UploadedFile;

/**
 * 저장 파일명 확장자 해석 trait (이미지 서비스 공용)
 *
 * 업로드 검증(`image`/`mimes`)의 통과 판정은 **내용 기반**(`guessExtension()`)이고,
 * 클라이언트가 준 확장자를 보는 것은 그 안의 `shouldBlockPhpUpload()` 뿐이며 대상도
 * php 계열에 한정된다. 그래서 내용이 정상 이미지이고 파일명만 `.html`·`.js` 인 업로드는
 * 검증을 통과하는데, 그 값을 저장 경로에 그대로 쓰면 그 디스크를 서빙하는 웹서버가
 * **확장자로** 처리해 동일 오리진 실행 파일이 된다 (공개 자산 디스크 운용에서 특히).
 *
 * 저장 경로의 확장자는 검증이 실제로 본 값 — 내용에서 추론한 확장자 — 을 쓴다.
 * 원본 파일명(`original_name`)은 표시용이므로 손대지 않는다.
 */
trait ResolvesStoredExtension
{
    /**
     * 저장 파일명에 쓸 확장자를 반환합니다. (내용 기준)
     *
     * @param  UploadedFile  $file  업로드 파일
     * @param  string  $fallback  내용에서 확장자를 얻지 못했을 때 쓸 값
     * @return string 확장자 (점 없음)
     */
    protected function storedExtension(UploadedFile $file, string $fallback = 'jpg'): string
    {
        $extension = $file->guessExtension();

        return is_string($extension) && $extension !== '' ? $extension : $fallback;
    }
}
