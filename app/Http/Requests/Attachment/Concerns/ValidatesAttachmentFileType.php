<?php

namespace App\Http\Requests\Attachment\Concerns;

use App\Extension\HookManager;
use App\Rules\AttachmentFileContent;

/**
 * 첨부파일 업로드 요청의 파일 형식 검증 규칙을 한곳에서 조립합니다.
 *
 * 파일명 확장자(`extensions:`)만 검사하면 SVG 내용을 `evil.png` 로 이름만 바꿔 올릴 수 있고,
 * 저장은 내용 기반 MIME(image/svg+xml)으로 되어 서빙 단계에서 이미지로 오인된다.
 * 그래서 같은 허용 목록으로 내용 추론 확장자도 검사해({@see AttachmentFileContent}) **이름과 내용이
 * 모두** 허용 목록에 들 때만 통과시킨다. 단건·일괄 업로드 요청이 이 trait 를 함께 쓴다.
 */
trait ValidatesAttachmentFileType
{
    /**
     * 허용 확장자 목록으로 파일 형식 검증 규칙을 만듭니다.
     *
     * 목록이 비면 확장자 제한 없음(탈출구)이므로 규칙을 붙이지 않는다 — 빈 규칙을 붙이면 전 파일이 거부된다.
     *
     * @return array<int, mixed> 파일 형식 검증 규칙 (`extensions:`·내용 검사 규칙) 또는 빈 배열
     */
    protected function fileTypeRules(): array
    {
        $extensions = $this->allowedExtensions();

        if ($extensions === []) {
            return [];
        }

        return ['extensions:'.implode(',', $extensions), new AttachmentFileContent($extensions)];
    }

    /**
     * 허용 확장자 목록을 반환합니다 (소문자 정규화 + 확장 훅 적용).
     *
     * 빈 배열은 "확장자 제한 없음" 을 의미합니다.
     *
     * @return array<int, string> 허용 확장자 목록
     */
    protected function allowedExtensions(): array
    {
        $extensions = (array) config('attachment.allowed_extensions', []);

        $extensions = array_values(array_filter(array_map(
            static fn ($ext) => strtolower(trim(ltrim((string) $ext, '.'))),
            $extensions
        )));

        return array_values(array_unique(
            (array) HookManager::applyFilters('core.attachment.allowed_extensions', $extensions, $this)
        ));
    }
}
