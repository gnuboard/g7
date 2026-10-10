<?php

namespace Modules\Sirsoft\Board\Http\Requests\Concerns;

use App\Rules\AttachmentFileContent;

/**
 * 허용 확장자 목록 해석 트레이트
 *
 * `$board->allowed_extensions ?? $기본값` 패턴은 null 만 잡고 빈 배열([])을 통과시킵니다.
 * 그 결과 `implode(',', [])` 가 `extensions:`(7.1.0 이전 `mimes:`) 빈 규칙을 만들어 해당 게시판의 모든 파일 업로드가
 * 거부됩니다. 빈 배열과 null 은 "확장자 미지정" 이라는 같은 의미이므로 동일하게 취급합니다.
 *
 * 저장 시점에도 빈 배열을 null 로 정규화하지만(Board 모델 mutator), 이미 저장된 레거시 행을
 * 소급 치유하지는 못하므로 소비 시점 방어를 함께 둡니다.
 */
trait ResolvesAllowedExtensions
{
    /**
     * 모듈 설정도 비어 있을 때 사용할 최종 확장자 목록
     */
    private const FALLBACK_ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'zip'];

    /**
     * 게시판의 허용 확장자를 해석합니다.
     *
     * 게시판 값이 비어 있으면 모듈 환경설정 기본값 → 최종 기본값 순으로 폴백합니다.
     *
     * @param  mixed  $boardExtensions  게시판의 allowed_extensions 값
     * @param  mixed  $defaultExtensions  모듈 환경설정 기본값 (없으면 null)
     * @return array<int, string> 비어있지 않은 확장자 목록
     */
    protected function resolveAllowedExtensions(mixed $boardExtensions, mixed $defaultExtensions = null): array
    {
        foreach ([$boardExtensions, $defaultExtensions] as $candidate) {
            $normalized = self::normalizeAllowedExtensions($candidate);
            if ($normalized !== []) {
                return $normalized;
            }
        }

        return self::FALLBACK_ALLOWED_EXTENSIONS;
    }

    /**
     * 허용 확장자 목록으로 파일 형식 검증 규칙을 만듭니다.
     *
     * 코어 첨부(`ValidatesAttachmentFileType`)와 같은 기준이다 — 파일 이름 확장자(`extensions:`)와
     * 내용으로 추론한 확장자(`AttachmentFileContent`)가 **모두** 허용 목록에 들어야 통과한다.
     * Laravel `mimes:` 만 걸면 내용만 보고 이름은 보지 않아, 허용하지 않은 이름(`x.html`)의 PNG 가
     * 올라가고 내용이 일반 텍스트로만 추론되는 md·csv 는 허용 목록에 있어도 거부된다.
     * 0바이트 파일은 `AttachmentFileContent` 가 빈 파일 안내로 거부한다.
     *
     * @param  array<int, string>  $allowedExtensions  비어 있지 않은 허용 확장자 목록
     * @return array<int, mixed> 파일 형식 검증 규칙
     */
    protected function attachmentFileTypeRules(array $allowedExtensions): array
    {
        return ['extensions:'.implode(',', $allowedExtensions), new AttachmentFileContent($allowedExtensions)];
    }

    /**
     * 확장자 목록을 정규화합니다.
     *
     * 배열이 아니거나 빈 문자열/비문자열 항목은 제거하고, 소문자 + 중복 제거된
     * 순차 배열로 반환합니다.
     *
     * @param  mixed  $extensions  원본 값
     * @return array<int, string> 정규화된 확장자 목록 (없으면 빈 배열)
     */
    public static function normalizeAllowedExtensions(mixed $extensions): array
    {
        if (! is_array($extensions)) {
            return [];
        }

        $normalized = [];

        foreach ($extensions as $extension) {
            if (! is_string($extension)) {
                continue;
            }

            $extension = mb_strtolower(trim($extension, " \t\n\r\0\x0B."));

            if ($extension !== '') {
                $normalized[] = $extension;
            }
        }

        return array_values(array_unique($normalized));
    }
}
