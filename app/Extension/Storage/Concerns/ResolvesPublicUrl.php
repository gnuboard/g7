<?php

namespace App\Extension\Storage\Concerns;

use App\Extension\Helpers\StorageLinkHelper;
use App\Extension\HookManager;
use App\Support\AssetUrl;
use Illuminate\Support\Facades\Storage;

/**
 * 스토리지 드라이버 공용 공개 URL 해석 trait
 *
 * public 디스크이거나 `filesystems.disks.{disk}.url` 이 설정된 디스크(S3+CDN 등)면
 * 직접 URL 을 생성하고, 그 외에는 null 을 생성합니다. 생성 결과는 디스크 종류와 무관하게
 * `core.storage.filter_url` 필터 훅을 항상 통과하므로, 확장이 URL 을 공급/수정/차단할 수 있습니다.
 *
 * public 디스크에는 한 갈래가 더 있습니다 — 그 디스크 URL 이 표준 `/storage` 경로를 가리키는데
 * `public/storage` 심볼릭 링크가 없으면 그 URL 은 동작하지 않으므로 읽기 전용 서빙 라우트로
 * 전환합니다(B-3). 운영자가 그 설정을 다른 위치(CDN·별도 경로)로 지정한 배치는 로컬 링크와
 * 무관하므로 전환하지 않고 설정값을 그대로 씁니다.
 */
trait ResolvesPublicUrl
{
    /**
     * 파일의 공개 URL을 반환합니다.
     *
     * @param  string  $category  카테고리
     * @param  string  $path  파일 경로 (카테고리 하위 상대 경로)
     * @return string|null 파일 URL (직접 URL 불가 디스크이고 훅 공급도 없으면 null)
     */
    public function url(string $category, string $path): ?string
    {
        $fullPath = $this->resolvePath($category, $path);

        $url = null;
        if ($this->diskSupportsDirectUrl()) {
            // public 디스크의 **기본** 직접 URL(`{APP_URL}/storage/...`)은 `public/storage`
            // 링크가 있어야만 동작한다. 링크를 만들 수 없는 호스팅(B-3)에서는 읽기 전용
            // 서빙 라우트 URL 로 전환한다 — 형태는 서버가 정하고(SSoT) 화면은 받은 URL 을
            // 그대로 쓴다.
            //
            // 다만 그 전환은 **그 URL 이 표준 `/storage` 를 가리킬 때에 한한다.** 운영자가
            // `filesystems.disks.public.url` 을 다른 위치(CDN·별도 경로)로 지정했다면 그
            // 배치는 로컬 `public/storage` 링크가 애초에 필요 없고 파일도 그쪽이 내준다 —
            // 링크 부재를 이유로 로컬 라우트로 끌어오면 그 사이트의 공개 자산이 통째로
            // 어긋난다. 형제 판정 `diskSupportsDirectUrl()` 이 다른 디스크에 대해 이 설정을
            // 권위로 삼는 것과 같은 규칙이다 (제품 결정 2026-08-25).
            $url = ($this->disk === 'public'
                && $this->pointsAtLocalPublicStorage()
                && ! StorageLinkHelper::publicStorageLinkUsable())
                ? AssetUrl::absolute(AssetUrl::publicStorage($fullPath))
                : Storage::disk($this->disk)->url($fullPath);
        }

        $filtered = HookManager::applyFilters('core.storage.filter_url', $url, array_merge(
            $this->urlHookContext(),
            [
                'disk' => $this->disk,
                'category' => $category,
                'path' => $path,
                'full_path' => $fullPath,
            ]
        ));

        return (is_string($filtered) && trim($filtered) !== '') ? $filtered : null;
    }

    /**
     * public 디스크 URL 이 **이 사이트 자신의 `/storage` 경로**를 가리키는지 판정합니다.
     *
     * 대체 서빙으로 전환해야 하는 것은 그 경우뿐이다 — `public/storage` 심볼릭 링크가
     * 있어야만 동작하는 URL 이기 때문이다. 운영자가 그 설정을 외부 호스트나 다른 경로로
     * 돌렸다면 파일은 그쪽이 내주므로 로컬 링크 유무와 무관하고, 그런데도 전환하면 그
     * 사이트의 공개 자산 URL 이 통째로 앱 오리진으로 옮겨 가 전부 404 가 된다.
     *
     * 판정은 **경로**로 한다 — `/storage` 이면 Laravel 표준 심볼릭 링크 형태이고, 그 외는
     * 운영자가 지정한 다른 위치다. 「설정값 == 기본값 문자열」로 비교하지 않는 이유는
     * `config/filesystems.php` 의 기본식이 `env('APP_URL').'/storage'` 인데 그 `APP_URL` 과
     * 런타임 `config('app.url')` 이 갈릴 수 있고(설정 동기·테스트 환경), config 캐시가 구워진
     * 사이트에서는 `env()` 가 런타임에 null 이기 때문이다.
     *
     * 호스트는 보지 않는다. 경계 사례로 `https://cdn.example.com/storage` 처럼 **다른 호스트의
     * `/storage`** 를 가리키는 배치는 로컬로 판정되는데, 그 형태는 대개 이 사이트의
     * `/storage` 를 origin-pull 하는 CDN 이라 결국 같은 심볼릭 링크에 의존한다. 호스트까지
     * 보려 하면 「이 사이트의 호스트」를 무엇으로 볼지(설정값·요청·콘솔)가 환경마다 갈려
     * 판정이 불안정해진다.
     *
     * 다만 **사이트 경로 접두사**는 벗기고 본다. 하위 폴더 마운트(`https://host/g7/`)에서
     * 기본식 `env('APP_URL').'/storage'` 는 `/g7/storage` 가 되는데, 접두사를 벗기지 않으면
     * 그 배치가 언제나 「운영자가 다른 위치로 지정했다」로 오분류되어 **대체 서빙이 절대
     * 켜지지 않는다** — 링크를 만들 수 없는 그 사이트의 공개 이미지가 전부 404 가 된다.
     * 접두사의 출처는 서버가 자산 URL 을 만들 때 쓰는 값(`AssetUrl::basePrefix()`)과
     * 같고, 요청이 없는 문맥(큐·콘솔)에서는 `config('app.url')` 의 경로 부분을 쓴다.
     *
     * @return bool `/storage` 를 가리키면 true
     */
    private function pointsAtLocalPublicStorage(): bool
    {
        // 드라이버가 로컬이 아니면 이 URL 이 가리키는 것은 원격 버킷·CDN 이고, 그
        // 트리는 `storage/app/public` 이 아니다. 경로 모양만 보고 로컬 서빙 라우트로
        // 끌어오면 그 사이트의 공개 자산이 전량 404 가 된다. 형제 판정
        // `PublicStorageService::rootPath()` 는 같은 축에서 이미 드라이버를 본다 —
        // 두 판정이 갈리면 「URL 은 로컬로, 서빙 루트는 폴백으로」 라는 반쪽 상태가 된다.
        // 판정을 형제와 **같은 술어**로 맞춘다. 종전에는 이쪽이 `!== 'local'`,
        // `PublicStorageService::rootPath()` 가 `!== null && !== 'local'` 이라
        // **미설정(`null`)을 서로 반대로** 읽었다: 서빙 루트는 운영자 값을 신뢰하는데
        // URL 은 `/storage` 로 나가는, 두 독블록이 각각 「막으려 한다」고 적어 둔 바로
        // 그 반쪽 상태다. 미설정은 Laravel 기본값(로컬)이므로 로컬로 읽는다
        // (15차 G3 라운드).
        $driver = config('filesystems.disks.public.driver');

        if ($driver !== null && $driver !== 'local') {
            return false;
        }

        $configured = config('filesystems.disks.public.url');

        if (! is_string($configured) || trim($configured) === '') {
            // 값이 비면 Laravel 이 `/storage` 를 파생한다 — 기본 동선과 같다.
            return true;
        }

        $path = parse_url($configured, PHP_URL_PATH);

        if (! is_string($path)) {
            return false;
        }

        // 슬래시가 연달아 나오면 하나로 접는다.
        //
        // `config/filesystems.php` 의 기본식은 `env('APP_URL').'/storage'` 인데, 운영자가
        // `APP_URL` 을 슬래시로 끝맺는 것은 흔한 표기다(`https://example.com/`). 그러면
        // 파생값이 `https://example.com//storage` 가 되어 경로가 `//storage` 로 오고,
        // 접기 전에는 `=== '/storage'` 가 거짓이라 **대체 서빙이 켜지지 않았다** —
        // 링크를 만들 수 없는 호스팅에서 그 사이트의 공개 이미지가 전부 404 가 되는데
        // 오류도 로그도 남지 않는다. 슬래시 하나가 기능 전체를 끄는 형태였다
        // (14차 G3 라운드).
        $normalized = preg_replace('#/{2,}#', '/', $this->stripSiteBasePrefix($path)) ?? '';

        return rtrim($normalized, '/') === '/storage';
    }

    /**
     * 사이트가 마운트된 경로 접두사를 벗깁니다 (하위 폴더 배치 대응).
     *
     * @param  string  $path  URL 의 경로 부분 (예: `/g7/storage`)
     * @return string 접두사를 벗긴 경로 (예: `/storage`)
     */
    private function stripSiteBasePrefix(string $path): string
    {
        // 두 후보를 모두 시도한다 — `basePrefix()` 는 요청이 있으면 그 값을 쓰는데,
        // 운영자가 `disks.public.url` 을 적을 때 기준으로 삼는 것은 `app.url` 이므로
        // 둘이 다른 사이트(요청 마운트 ≠ 설정 경로)에서는 설정 쪽도 벗겨야 한다.
        foreach ([AssetUrl::basePrefix(), AssetUrl::configuredBasePath()] as $candidate) {
            $prefix = rtrim((string) $candidate, '/');

            if ($prefix === '') {
                continue;
            }

            // 판정은 `AssetUrl::hasBasePrefix()` 한 곳이 소유한다 — 종전에는 이 자리가
            // `prefix.'/'` 만 보는 네 번째 독립 사본이었다. 접두사만 남는 값(`/g7`)은
            // 루트 `/` 로 되돌린다(프론트 `stripBasePrefix()` 와 같은 결과).
            if (AssetUrl::hasBasePrefix($path, $prefix)) {
                $rest = substr($path, strlen($prefix));

                return str_starts_with($rest, '/') ? $rest : '/'.$rest;
            }
        }

        return $path;
    }

    /**
     * 현재 디스크가 직접 URL 생성을 지원하는지 판정합니다.
     *
     * public 디스크는 항상 지원, 그 외 디스크는 `filesystems.disks.{disk}.url` 이
     * 비어 있지 않은 문자열로 설정된 경우에만 지원합니다 (AWS_URL 미설정/빈값 방어).
     *
     * @return bool 직접 URL 지원 여부
     */
    private function diskSupportsDirectUrl(): bool
    {
        if ($this->disk === 'public') {
            return true;
        }

        $base = config("filesystems.disks.{$this->disk}.url");

        return is_string($base) && trim($base) !== '';
    }

    /**
     * `core.storage.filter_url` 훅 컨텍스트의 드라이버별 식별 정보를 반환합니다.
     *
     * @return array{scope: string, identifier: ?string} scope(core|module|plugin) + 확장 식별자(코어는 null)
     */
    abstract protected function urlHookContext(): array;
}
