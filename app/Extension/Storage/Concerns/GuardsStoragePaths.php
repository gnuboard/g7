<?php

namespace App\Extension\Storage\Concerns;

use App\Exceptions\UnsafeStoragePathException;
use Illuminate\Support\Facades\Log;

/**
 * 스토리지 드라이버 공용 경로 가드 trait
 *
 * 드라이버는 카테고리와 경로를 문자열로 이어 붙여 디스크에 넘긴다. Flysystem 기본 정규화는
 * `..` 세그먼트를 앞 세그먼트와 상쇄하므로, 호출부가 사용자 입력을 경로에 섞으면
 * `{식별자}/{카테고리}/x/../../..` 가 확장 루트·디스크 루트로 축약되고, 그 경로로
 * deleteDirectory 가 불리면 다른 확장의 저장소까지 통째로 지워진다(KVE-2026-2349).
 *
 * 세 단계로 막는다.
 * 1. 결합 전 — 카테고리·경로 조각에 순회·절대경로·인코딩 변형이 없는지 검사
 * 2. 결합 후 — 결과가 기준 디렉터리({식별자}/{카테고리}) 하위인지 세그먼트 단위로 재확인
 * 3. 파괴 작업 — 대상이 디스크·확장 루트면 거부하고 보안 로그를 남김 (카테고리 루트 삭제는 인터페이스 계약대로 허용)
 *
 * 사용 클래스는 `$disk` 속성과 `urlHookContext()`(ResolvesPublicUrl)를 제공해야 한다.
 */
trait GuardsStoragePaths
{
    /**
     * 결합 전 경로 조각을 검사합니다.
     *
     * 빈 문자열은 "기준 디렉터리 자신" 을 뜻하므로 허용합니다. 파괴 작업에서 빈 경로가
     * 루트를 가리키는 경우는 assertNotDestructiveOnRoot() 가 따로 막습니다.
     *
     * @param  string  $path  검사할 조각 (카테고리 또는 상대 경로)
     * @param  string  $label  로그에 남길 조각 이름 (category|path)
     * @return void
     *
     * @throws UnsafeStoragePathException 안전하지 않은 조각
     */
    protected function assertSafeRelativePath(string $path, string $label): void
    {
        if ($path === '') {
            return;
        }

        $reason = match (true) {
            str_contains($path, "\0") => 'null_byte',
            str_contains($path, '\\') => 'backslash',
            str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1 => 'absolute',
            // 디코딩 계층(웹서버·프록시·원격 어댑터)을 한 번 더 거치면 `.`·`/`·`\` 로 바뀌는 변형
            preg_match('/%(2e|2f|5c|00)/i', $path) === 1 => 'encoded',
            default => $this->findUnsafeSegment($path),
        };

        if ($reason !== null) {
            $this->rejectStoragePath($reason, $label, $path);
        }
    }

    /**
     * 결합·정규화한 결과가 기준 디렉터리 하위(또는 자신)인지 재확인합니다.
     *
     * 조각 검사를 통과했다면 항상 성립하지만, 결합 규칙이 바뀌거나 조각 검사가 약해져도
     * 기준 밖을 가리키는 경로가 디스크에 닿지 않도록 독립적으로 한 번 더 판정합니다.
     *
     * @param  string  $resolved  결합된 전체 경로
     * @param  string  $base  기준 디렉터리 (빈 문자열이면 디스크 루트)
     * @return void
     *
     * @throws UnsafeStoragePathException 기준 디렉터리를 벗어난 경로
     */
    protected function assertResolvedWithinBase(string $resolved, string $base): void
    {
        $normalized = $this->normalizeStoragePath($resolved);

        $within = $normalized !== null
            && ($base === ''
                || $normalized === $base
                || str_starts_with($normalized, $base.'/'));

        if (! $within) {
            $this->rejectStoragePath('outside_base', 'path', $resolved);
        }
    }

    /**
     * 파괴 작업의 대상이 보호 루트(디스크 루트·드라이버가 넘긴 확장 루트)인지 검사합니다.
     *
     * @param  string  $resolved  삭제 대상 전체 경로
     * @param  array<int, string>  $protectedRoots  삭제를 허용하지 않는 루트 목록
     * @param  string  $operation  작업 이름 (deleteDirectory|deleteAll)
     * @param  string  $category  카테고리 (로그용)
     * @return void
     *
     * @throws UnsafeStoragePathException 보호 루트를 겨냥한 파괴 작업
     */
    protected function assertNotDestructiveOnRoot(
        string $resolved,
        array $protectedRoots,
        string $operation,
        string $category
    ): void {
        $normalized = $this->normalizeStoragePath($resolved);

        if ($normalized !== null && $normalized !== '' && ! in_array($normalized, $protectedRoots, true)) {
            return;
        }

        Log::warning('스토리지 루트를 겨냥한 삭제 작업을 차단했습니다', array_merge(
            $this->urlHookContext(),
            [
                'disk' => $this->disk,
                'operation' => $operation,
                'category' => $category,
                'path' => $resolved,
                'caller' => $this->storageCaller(),
            ]
        ));

        throw new UnsafeStoragePathException('destructive_on_root', 'exceptions.storage.destructive_on_root');
    }

    /**
     * 스토리지 드라이버를 부른 호출 지점(드라이버 밖 첫 프레임)을 찾습니다.
     *
     * 보안 로그에서 어느 서비스가 위험한 경로를 만들었는지 바로 추적하기 위해서다.
     *
     * @return string|null `클래스::메서드` 또는 `파일:줄` (찾지 못하면 null)
     */
    private function storageCaller(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
            $class = $frame['class'] ?? '';
            if ($class !== '' && str_starts_with($class, 'App\\Extension\\Storage\\')) {
                continue;
            }

            if ($class !== '') {
                return $class.'::'.($frame['function'] ?? '?');
            }

            if (isset($frame['file'])) {
                return $frame['file'].':'.($frame['line'] ?? 0);
            }
        }

        return null;
    }

    /**
     * 경로에서 빈 세그먼트(`//`, 앞뒤 `/`)나 `.`/`..` 세그먼트를 찾습니다.
     *
     * @param  string  $path  검사할 경로
     * @return string|null 거부 사유 코드 (문제 없으면 null)
     */
    private function findUnsafeSegment(string $path): ?string
    {
        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                return 'empty_segment';
            }

            if ($segment === '.' || $segment === '..') {
                return 'dot_segment';
            }
        }

        return null;
    }

    /**
     * 경로를 세그먼트 단위로 정규화합니다 (`.` 제거, `..` 상쇄).
     *
     * @param  string  $path  정규화할 경로
     * @return string|null 정규화 결과 (루트 위로 벗어나면 null)
     */
    private function normalizeStoragePath(string $path): ?string
    {
        $parts = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($parts === []) {
                    return null;
                }

                array_pop($parts);

                continue;
            }

            $parts[] = $segment;
        }

        return implode('/', $parts);
    }

    /**
     * 안전하지 않은 경로를 보안 로그에 남기고 거부합니다.
     *
     * @param  string  $reason  거부 사유 코드
     * @param  string  $label  조각 이름 (category|path)
     * @param  string  $value  거부된 값
     * @return never
     *
     * @throws UnsafeStoragePathException 항상
     */
    private function rejectStoragePath(string $reason, string $label, string $value): never
    {
        Log::warning('안전하지 않은 스토리지 경로를 거부했습니다', array_merge(
            $this->urlHookContext(),
            [
                'disk' => $this->disk,
                'reason' => $reason,
                'field' => $label,
                'value' => $value,
                'caller' => $this->storageCaller(),
            ]
        ));

        throw new UnsafeStoragePathException($reason);
    }
}
