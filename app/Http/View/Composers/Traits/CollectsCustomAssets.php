<?php

namespace App\Http\View\Composers\Traits;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Support\CustomAssets;
use Illuminate\Support\Facades\Log;

/**
 * 사용자 추가 에셋(`custom/`) 수집 Trait
 *
 * 활성 확장의 `custom/` 자산을 모아 프론트로 넘긴다. 로드 자체는 프론트가 하며,
 * **확장 병합 번들 뒤**에 붙는다 — CSS 는 나중에 온 규칙이 이기므로, 운영자가 덧붙인
 * 스타일이 확장 스타일보다 뒤에 와야 재정의가 성립한다.
 *
 * 확장 간 순서는 모듈 → 플러그인 → 템플릿이다. 화면 외관의 최종 책임이 템플릿에 있어,
 * 템플릿 운영자의 재정의가 가장 뒤에 와야 한다.
 *
 * @property ModuleManagerInterface $moduleManager
 * @property PluginManagerInterface $pluginManager
 */
trait CollectsCustomAssets
{
    use ClearsTemplateCaches;

    /**
     * 활성 확장의 사용자 추가 에셋을 수집합니다.
     *
     * 비활성 확장은 제외한다 — 자산 서빙이 활성 확장만 응답하므로, 넣어 봐야 404 가
     * 될 항목을 페이지에 실을 이유가 없다.
     *
     * `?custom=off` 가 붙은 요청은 목록을 비운다 — 탈출구(D33). 운영자가 넣은 CSS 한
     * 줄이 관리자 화면을 조작 불능으로 만들면 그것을 고칠 화면에도 그 CSS 가 실려 있어
     * 스스로 갇힌다. 서버가 목록을 비우면 자산이 페이지에 **도달하지 않으므로**, 이미
     * 깨진 화면에서 자바스크립트가 돌기를 기대할 필요가 없다.
     *
     * @param  string|null  $templateIdentifier  활성 템플릿 식별자
     * @return array<int, array<string, mixed>> 서술자 목록 (로드 순서대로)
     */
    private function collectCustomAssets(?string $templateIdentifier): array
    {
        if ($this->customAssetsDisabledByRequest()) {
            return [];
        }

        $assets = $this->resolveCustomAssets($templateIdentifier);

        // 변경을 감지해 버전이 올랐다면 방금 만든 URL 은 **옛 버전**을 가리킨다.
        // 그대로 내보내면 운영자가 파일을 고친 그 화면에서만 옛 CSS 가 보이고, 새로고침
        // 한 번을 더 해야 반영된다 — 원인을 알 수 없는 한 박자 지연으로만 나타난다.
        // 새 버전으로 다시 해석한다. 게시본은 아직 없으므로 URL 은 API 형태로 떨어지고,
        // 그 응답은 디스크의 최신 내용을 그대로 준다. 다음 요청부터 정적 경로가 된다.
        if ($this->syncCustomAssetCacheVersion($assets, $templateIdentifier)) {
            CustomAssets::flushCache();
            $assets = $this->resolveCustomAssets($templateIdentifier);
        }

        return $assets;
    }

    /**
     * 활성 확장의 사용자 추가 에셋 서술자를 해석합니다.
     *
     * @param  string|null  $templateIdentifier  활성 템플릿 식별자
     * @return array<int, array<string, mixed>> 서술자 목록 (로드 순서대로)
     */
    private function resolveCustomAssets(?string $templateIdentifier): array
    {
        $assets = [];

        foreach ($this->activeExtensionIdentifiers('modules') as $identifier) {
            $assets = array_merge($assets, CustomAssets::forExtension('modules', $identifier));
        }

        foreach ($this->activeExtensionIdentifiers('plugins') as $identifier) {
            $assets = array_merge($assets, CustomAssets::forExtension('plugins', $identifier));
        }

        if (! empty($templateIdentifier)) {
            $assets = array_merge($assets, CustomAssets::forExtension('templates', $templateIdentifier));
        }

        return $assets;
    }

    /**
     * 운영자가 파일을 고쳤으면 확장 캐시 버전을 올립니다.
     *
     * custom 자산은 확장 자산과 **같은 메커니즘**으로 정적 게시되므로 갱신 축도 같아야
     * 한다. 그런데 확장 캐시 버전은 수명주기 이벤트에서만 오르고, 운영자가 FTP 로 파일
     * 하나를 바꾸는 것은 그 이벤트가 아니다. 그래서 파일 서명이 달라진 것을 여기서
     * 감지해 같은 단일 지점(`incrementExtensionCacheVersion`)을 호출한다 — 그 지점이
     * 재게시 예약까지 담당하므로 새로 만들 기계가 없다.
     *
     * 서명이 **저장된 적 없으면 올리지 않는다.** 캐시 스토어가 요청마다 비는 환경
     * (array 스토어 등)에서 "매번 달라짐" 으로 읽혀 버전이 무한히 오르고 재게시가
     * 끝없이 돌게 되기 때문이다. 첫 관측은 기록만 하고 다음 변화부터 반응한다.
     *
     * 버전을 올렸으면 `true` 를 돌려준다 — 호출자가 서술자를 다시 해석해야 한다.
     * 이미 만든 URL 은 옛 버전을 가리키기 때문이다.
     *
     * 서명은 **렌더 스코프별로** 따로 기억한다. 수집 대상이 모듈·플러그인(양쪽 동일)에
     * 더해 `resolveCustomAssets` 가 싣는 **그 렌더의 템플릿 하나**라, 관리자 렌더와
     * 사용자 렌더의 서명은 파일이 그대로여도 정상적으로 다르다. 기억할 자리가 하나면
     * 두 렌더가 번갈아 덮어쓰며 매번 "파일이 바뀌었다" 로 읽혀, 운영자가 아무것도
     * 건드리지 않았는데 페이지를 오갈 때마다 확장 캐시 버전이 오르고 전체 재게시가
     * 예약된다(모든 자산 URL 이 상시 변동 → 브라우저 캐시 무효). 예외도 화면 이상도
     * 없어 로그 외에는 드러나지 않는다.
     *
     * 스코프를 나누되 **키는 하나로 두고 값을 맵으로** 쓴다. `CustomAssetService` 가
     * 쓰기 뒤 이 키 하나를 `forget` 하는 것에 의존하므로(같은 키를 보는 두 소비자),
     * 키를 쪼개면 그 소비자가 일부만 지우게 되어 같은 결함군이 재생산된다.
     *
     * @param  array<int, array<string, mixed>>  $assets  수집된 서술자 목록
     * @param  string|null  $scope  렌더 스코프 (활성 템플릿 식별자)
     * @return bool 확장 캐시 버전을 올렸으면 true
     */
    private function syncCustomAssetCacheVersion(array $assets, ?string $scope = null): bool
    {
        try {
            $parts = [];

            foreach ($assets as $asset) {
                // 파일 출처만 본다 — 외부 URL·훅이 더한 항목은 파일 서명이 없다
                if (($asset['source'] ?? null) !== 'file') {
                    continue;
                }

                $parts[] = ($asset['id'] ?? '').'@'.($asset['version'] ?? '');
            }

            sort($parts, SORT_STRING);
            $signature = $parts === [] ? 'empty' : md5(implode('|', $parts));

            $scopeKey = $scope ?? '';

            $cache = app(CacheInterface::class);

            // 구 배포본이 남긴 스칼라 값은 맵이 아니다 — 어느 스코프의 것인지 알 수 없으므로
            // 서명으로 쓰지 않고 미관측으로 취급한다(첫 관측은 기록만 하니 헛된 bump 가 없다).
            $storedMap = $cache->get(CustomAssets::SIGNATURE_CACHE_KEY);
            $storedMap = is_array($storedMap) ? $storedMap : [];

            $stored = $storedMap[$scopeKey] ?? null;

            if ($stored === $signature) {
                return false;
            }

            $storedMap[$scopeKey] = $signature;
            $cache->put(CustomAssets::SIGNATURE_CACHE_KEY, $storedMap);

            if ($stored === null) {
                return false;
            }

            Log::info('사용자 추가 에셋 변경 감지 — 확장 캐시 버전을 올립니다.', [
                'scope' => $scopeKey,
                'previous' => $stored,
                'current' => $signature,
            ]);

            $this->incrementExtensionCacheVersion();

            return true;
        } catch (\Exception $e) {
            // 감지 실패가 화면을 막지 않는다 — 최악이라도 URL 이 한동안 옛 버전일 뿐이다
            Log::warning('사용자 추가 에셋 변경 감지 실패', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * 이번 요청이 사용자 추가 에셋을 끄도록 요구했는지 판정합니다.
     *
     * 판정을 수집 **맨 앞**에 두는 것이 중요하다. 뒤에 두면 빈 목록이 변경 감지
     * (`syncCustomAssetCacheVersion`)에 도달해 "전부 사라짐" 으로 읽히고, 그 다음 정상
     * 요청이 다시 "전부 생김" 으로 읽혀 요청마다 확장 캐시 버전이 오르내린다.
     *
     * @return bool `?custom=off` 이면 true
     */
    private function customAssetsDisabledByRequest(): bool
    {
        try {
            return request()->query('custom') === 'off';
        } catch (\Exception $e) {
            // 요청 컨텍스트가 없는 렌더(콘솔·SEO 사전 렌더 등)는 끄지 않는다
            return false;
        }
    }

    /**
     * 활성 확장 식별자 목록을 돌려줍니다.
     *
     * @param  string  $extensionType  `modules` | `plugins`
     * @return array<int, string> 식별자 목록
     */
    private function activeExtensionIdentifiers(string $extensionType): array
    {
        try {
            $active = $extensionType === 'modules'
                ? $this->moduleManager->getActiveModules()
                : $this->pluginManager->getActivePlugins();

            return array_keys($active);
        } catch (\Exception $e) {
            Log::warning("Failed to collect active {$extensionType} for custom assets: ".$e->getMessage());

            return [];
        }
    }
}
