<?php

namespace App\CoreUpdate;

/**
 * 웹 코어 업데이트의 착수 선택 (Value Object).
 *
 * **웹에 없는 선택이 이 객체에도 없다** — `--force` · `--prune` · `--no-backup` ·
 * `--no-maintenance` 는 안전 기본값으로 고정되어 웹 동선에 노출되지 않는다 (D27).
 * 필드로 두고 기본값만 정하는 형태를 택하지 않은 이유는, 그러면 요청 본문 하나로
 * 그 값이 켜지는 표면이 생기고 「웹은 안전 기본값 고정」이 선언으로만 남기 때문이다.
 *
 * 여기 있는 넷은 CLI 의 대화형 질의를 그대로 옮긴 것이다:
 * 번들 확장·언어팩 일괄(D28) · 검색 인덱스 재생성 · 전역 레이아웃 전략(D29) ·
 * 수동 ZIP 소스(㉯ — D18).
 *
 * @since 7.1.0
 */
final class CoreUpdateOptions
{
    /** 레이아웃 전략 — 새 버전으로 덮어쓰기 (CLI 기본과 동일). */
    public const LAYOUT_OVERWRITE = 'overwrite';

    /** 레이아웃 전략 — 운영자가 수정한 레이아웃 보존. */
    public const LAYOUT_KEEP = 'keep';

    /**
     * @param  bool  $updateBundledExtensions  번들 확장·언어팩을 함께 업데이트할지 (기본 켬)
     * @param  bool  $rebuildSearchIndex  일괄 업데이트 후 검색 인덱스를 재생성할지 (기본 끔, 그 창에서만 유효)
     * @param  string  $layoutStrategy  전역 레이아웃 전략 (`overwrite`|`keep`)
     * @param  string|null  $zipFile  수동 ZIP 파일명 (`storage/app/core_pending` 하위, null 이면 자동 내려받기)
     */
    public function __construct(
        public readonly bool $updateBundledExtensions = true,
        public readonly bool $rebuildSearchIndex = false,
        public readonly string $layoutStrategy = self::LAYOUT_OVERWRITE,
        public readonly ?string $zipFile = null,
    ) {}

    /**
     * 검증된 요청 값에서 만듭니다.
     *
     * @param  array<string, mixed>  $validated  FormRequest 의 `validated()` 결과
     * @return self 착수 선택
     */
    public static function fromValidated(array $validated): self
    {
        $strategy = (string) ($validated['layout_strategy'] ?? self::LAYOUT_OVERWRITE);

        return new self(
            updateBundledExtensions: (bool) ($validated['update_bundled_extensions'] ?? true),
            rebuildSearchIndex: (bool) ($validated['rebuild_search_index'] ?? false),
            layoutStrategy: $strategy === self::LAYOUT_KEEP ? self::LAYOUT_KEEP : self::LAYOUT_OVERWRITE,
            zipFile: self::normalizeZipFile($validated['zip_file'] ?? null),
        );
    }

    /**
     * 저장된 상태에서 복원합니다.
     *
     * @param  array<string, mixed>|null  $stored  상태 파일의 `options`
     * @return self 착수 선택
     */
    public static function fromArray(?array $stored): self
    {
        return self::fromValidated(is_array($stored) ? $stored : []);
    }

    /**
     * 상태 파일에 실을 배열 표현.
     *
     * @return array<string, mixed> 직렬화 형태
     */
    public function toArray(): array
    {
        return [
            'update_bundled_extensions' => $this->updateBundledExtensions,
            'rebuild_search_index' => $this->rebuildSearchIndex,
            'layout_strategy' => $this->layoutStrategy,
            'zip_file' => $this->zipFile,
        ];
    }

    /**
     * 수동 ZIP 소스를 지정했는지 여부 (㉯ — D18).
     *
     * @return bool 지정 여부
     */
    public function usesManualZip(): bool
    {
        return $this->zipFile !== null;
    }

    /**
     * ZIP 파일명을 정규화합니다 (경로 성분 제거 — 디렉토리는 서버가 정한다).
     *
     * 운영자는 파일을 FTP 로 지정 위치에 올리고 **이름만** 고른다. 경로를 받으면 그
     * 값이 곧 파일시스템 탐색 표면이 되므로 이름 이외는 여기서 잘라 낸다.
     *
     * @param  mixed  $value  요청 값
     * @return string|null 정규화된 파일명 (없으면 null)
     */
    private static function normalizeZipFile(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $name = basename(strtr(trim($value), ['\\' => '/']));

        return $name === '' || $name === '.' || $name === '..' ? null : $name;
    }
}
