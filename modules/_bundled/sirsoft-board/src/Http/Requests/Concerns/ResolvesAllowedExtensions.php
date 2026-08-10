<?php

namespace Modules\Sirsoft\Board\Http\Requests\Concerns;

/**
 * 허용 확장자 목록 해석 트레이트
 *
 * `$board->allowed_extensions ?? $기본값` 패턴은 null 만 잡고 빈 배열([])을 통과시킵니다.
 * 그 결과 `implode(',', [])` 가 `mimes:` 빈 규칙을 만들어 해당 게시판의 모든 파일 업로드가
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
