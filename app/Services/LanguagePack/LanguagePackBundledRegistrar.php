<?php

namespace App\Services\LanguagePack;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\LanguagePackRepositoryInterface;
use App\Enums\LanguagePackDeactivationReason;
use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Models\LanguagePack;
use App\Services\LanguagePackService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 확장(모듈/플러그인/템플릿) 설치/제거 시 내장 번역을 자동으로 가상 등록하는 헬퍼.
 *
 * 동작 (계획서 §3.6):
 *  - 확장 설치 후: 확장의 lang 디렉토리에서 locale 별 디렉토리를 스캔 → `bundled_with_extension`
 *    소스 타입의 가상 레코드를 `language_packs` 테이블에 등록
 *  - 확장 제거 후: 해당 target_identifier 의 `bundled_with_extension` 레코드 cascade 삭제
 *  - 외부 벤더 non-bundled 언어팩은 대상 확장 미존재 상태가 되므로 status='error' 전환
 *
 * 가상 레코드 특성:
 *  - source_type='bundled_with_extension'
 *  - source_url=확장의 lang 디렉토리 상대 경로
 *  - is_protected=true — bundled_with_extension 은 부모 확장 lifecycle 에 종속되므로 독립 제거 불가 (부모 uninstall 시 함께 제거)
 *  - 슬롯이 비어있으면 active, 이미 active 가 있으면 installed
 */
class LanguagePackBundledRegistrar
{
    /**
     * @param  LanguagePackRepositoryInterface  $repository  언어팩 Repository
     * @param  LanguagePackRegistry  $registry  런타임 레지스트리
     * @param  CacheInterface  $cache  캐시 인터페이스
     */
    public function __construct(
        private readonly LanguagePackRepositoryInterface $repository,
        private readonly LanguagePackRegistry $registry,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 확장 설치/업데이트 후 호출되어 내장 locale 디렉토리를 스캔하고 가상 등록을 수행합니다.
     *
     * @param  string  $scope  스코프 (module/plugin/template)
     * @param  string  $targetIdentifier  대상 확장 식별자
     * @param  string  $vendor  확장 벤더 (manifest.vendor 또는 identifier 첫 segment)
     * @param  string  $version  확장 버전
     * @param  string  $langDirectoryRelative  확장 lang 디렉토리 상대 경로 (base_path 기준)
     * @return void 반환값 없음
     */
    public function syncFromExtension(
        string $scope,
        string $targetIdentifier,
        string $vendor,
        string $version,
        string $langDirectoryRelative,
    ): void {
        if (! LanguagePackScope::isValid($scope) || $scope === LanguagePackScope::Core->value) {
            return;
        }

        $absolute = base_path($langDirectoryRelative);
        $foundLocales = $this->scanLocales($absolute);

        $existingByLocale = $this->repository
            ->getPacksForTarget($scope, $targetIdentifier)
            ->where('source_type', 'bundled_with_extension')
            ->keyBy('locale');

        foreach ($foundLocales as $locale) {
            if ($existingByLocale->has($locale)) {
                $pack = $existingByLocale->get($locale);
                $this->repository->update($pack, [
                    'version' => $version,
                    'source_url' => $langDirectoryRelative,
                ]);
                $existingByLocale->forget($locale);

                continue;
            }

            $this->createBundledRecord(
                scope: $scope,
                targetIdentifier: $targetIdentifier,
                vendor: $vendor,
                version: $version,
                locale: $locale,
                langDirectoryRelative: $langDirectoryRelative,
            );
        }

        // 새 스캔 결과에 없는 locale 의 가상 레코드는 삭제 (locale 제거 케이스)
        foreach ($existingByLocale as $stale) {
            $this->repository->delete($stale);
        }

        $this->registry->invalidate();
    }

    /**
     * 확장 제거 후 호출되어 해당 target_identifier 의 가상 레코드를 모두 삭제하고
     * 외부 벤더 언어팩은 error 상태로 전환합니다.
     *
     * @param  string  $scope  스코프
     * @param  string  $targetIdentifier  대상 확장 식별자
     * @return void 반환값 없음
     */
    public function cleanupForExtension(string $scope, string $targetIdentifier): void
    {
        if (! LanguagePackScope::isValid($scope) || $scope === LanguagePackScope::Core->value) {
            return;
        }

        $packs = $this->repository->getPacksForTarget($scope, $targetIdentifier);

        foreach ($packs as $pack) {
            if ($pack->source_type === 'bundled_with_extension') {
                $this->repository->delete($pack);

                continue;
            }

            // 외부 벤더 언어팩 — 대상 확장 없음 상태로 error 전환 (자동 삭제 안 함)
            $this->repository->update($pack, [
                'status' => LanguagePackStatus::Error->value,
            ]);
        }

        $this->registry->invalidate();
    }

    /**
     * 호스트 확장 비활성화 시 해당 확장에 종속된 언어팩을 cascade 비활성화합니다 (요구사항 #6).
     *
     * 꺼진 팩은 `LanguagePackService::deactivate(cascadeFromHost: true)` 가 `deactivated_reason=host_deactivated` 로
     * 표시한다. 재활성화 시 §6 모달과 CLI activate 가 그 표시로 다시 켤 후보를 찾는다. 예전에는 후보 목록을 캐시에만
     * 두어 `cache:clear` 로 사라졌다(#145 F7).
     *
     * @param  string  $scope  스코프 (module/plugin/template)
     * @param  string  $targetIdentifier  대상 확장 식별자
     * @return array<int, int> 비활성화된 LanguagePack ID 배열
     */
    public function deactivateForExtension(string $scope, string $targetIdentifier): array
    {
        if (! LanguagePackScope::isValid($scope) || $scope === LanguagePackScope::Core->value) {
            return [];
        }

        $deactivated = [];
        $packs = $this->repository->getPacksForTarget($scope, $targetIdentifier);
        $service = app(LanguagePackService::class);

        foreach ($packs as $pack) {
            if ($pack->status !== LanguagePackStatus::Active->value) {
                continue;
            }

            try {
                $service->deactivate($pack, cascadeFromHost: true);
                $deactivated[] = $pack->id;
            } catch (Throwable $e) {
                Log::warning('language-pack cascade deactivate 실패', [
                    'pack_id' => $pack->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->registry->invalidate();

        return $deactivated;
    }

    /**
     * 호스트 확장 활성화 시, 켜 달라는 요청으로 설치됐지만 그때 호스트가 꺼져 있어 꺼진 채 남은 언어팩을 함께 켭니다.
     *
     * 대상은 `status=installed` 이면서 `deactivated_reason=awaiting_host` 인 팩뿐이다 — 켜 달라고 하지 않고 설치한 팩
     * (installed, 사유 없음)·사용자가 끈 팩(inactive)·호스트를 끌 때 함께 꺼진 팩(inactive, 「언어팩 다시 켜기」 창이
     * 묻는다)은 건드리지 않는다. 같은 언어 자리를 이미 다른 팩이 쓰고 있으면 밀어내지 않고(force 없이 activate) 건너뛴다.
     * 실패는 호스트 활성화를 막지 않도록 기록만 하고, 대기 표시는 남겨 다음 활성화 때 다시 시도한다.
     *
     * @param  string  $scope  스코프 (module/plugin/template)
     * @param  string  $targetIdentifier  대상 확장 식별자
     * @return array<int, int> 켠 LanguagePack ID 배열
     */
    public function activateAwaitingForExtension(string $scope, string $targetIdentifier): array
    {
        if (! LanguagePackScope::isValid($scope) || $scope === LanguagePackScope::Core->value || $targetIdentifier === '') {
            return [];
        }

        $activated = [];
        $packs = $this->repository->getPacksForTarget($scope, $targetIdentifier)
            ->filter(fn (LanguagePack $pack) => $pack->status === LanguagePackStatus::Installed->value
                && $pack->deactivated_reason === LanguagePackDeactivationReason::AwaitingHost->value);

        if ($packs->isEmpty()) {
            return [];
        }

        $service = app(LanguagePackService::class);

        foreach ($packs as $pack) {
            try {
                $service->activate($pack);
                $activated[] = $pack->id;
            } catch (Throwable $e) {
                Log::warning('language-pack awaiting-host activate 실패', [
                    'pack_id' => $pack->id,
                    'identifier' => $pack->identifier,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->registry->invalidate();

        return $activated;
    }

    /**
     * 비활성화된 팩 ID 목록을 cache 에 보관합니다 (레거시 — 7.1.0 이하의 재활성화 후보 보관 방식).
     *
     * 7.1.1 부터 연쇄 비활성화는 DB 표시(`host_deactivated`)만 남기고 이 메서드를 부르지 않는다. 업데이트 전에 쌓인
     * 캐시 목록을 `getPendingForReactivation()` 이 한 번 읽어 합치므로 키 형식을 유지한다.
     *
     * @param  string  $scope  스코프
     * @param  string  $targetIdentifier  대상 확장 식별자
     * @param  array<int, int>  $packIds  팩 ID 배열
     * @return void 반환값 없음
     */
    public function stashDeactivatedForReactivation(string $scope, string $targetIdentifier, array $packIds): void
    {
        $key = $this->reactivationCacheKey($scope, $targetIdentifier);
        $this->cache->put($key, $packIds, 30 * 24 * 60 * 60);
    }

    /**
     * 호스트 확장 재활성화 시 모달에 표시할 pending 언어팩 목록을 반환합니다 (요구사항 #7).
     *
     * 대상 확장의 팩 중 `status=inactive` 이고 `deactivated_reason=host_deactivated` 인 팩(호스트를 끌 때 함께 꺼진 팩)과,
     * 7.1.0 이하가 캐시에 남긴 레거시 목록 중 지금도 inactive 인 팩을 합쳐 ID 순으로 돌려준다. 레거시 목록은 읽은 뒤 지운다.
     * DB 표시는 팩을 켤 때 지워지므로, 운영자가 창을 닫으면 다음 활성화 때 다시 묻는다.
     * 빈 배열을 반환하면 프론트엔드는 모달을 띄우지 않습니다 (요구사항 #8).
     *
     * @param  string  $scope  스코프
     * @param  string  $targetIdentifier  대상 확장 식별자
     * @return array<int, array<string, mixed>> 모달 표시용 정보 배열
     */
    public function getPendingForReactivation(string $scope, string $targetIdentifier): array
    {
        $packs = [];
        foreach ($this->repository->getPacksForTarget($scope, $targetIdentifier) as $pack) {
            if ($pack->status === LanguagePackStatus::Inactive->value
                && $pack->deactivated_reason === LanguagePackDeactivationReason::HostDeactivated->value) {
                $packs[$pack->id] = $pack;
            }
        }

        // 레거시: 7.1.0 이하가 캐시에 남긴 목록 (한 번 읽고 지운다)
        $key = $this->reactivationCacheKey($scope, $targetIdentifier);
        $legacyIds = $this->cache->get($key, []);
        if (! empty($legacyIds)) {
            foreach ((array) $legacyIds as $id) {
                $pack = $this->repository->findById((int) $id);
                if ($pack && $pack->status === LanguagePackStatus::Inactive->value) {
                    $packs[$pack->id] = $pack;
                }
            }
            $this->cache->forget($key);
        }

        ksort($packs);

        return array_values(array_map(fn (LanguagePack $pack) => [
            'id' => $pack->id,
            'identifier' => $pack->identifier,
            'locale' => $pack->locale,
            'locale_native_name' => $pack->locale_native_name,
        ], $packs));
    }

    /**
     * 재활성화 cache 키를 생성합니다.
     *
     * @param  string  $scope  스코프
     * @param  string  $targetIdentifier  대상 확장 식별자
     * @return string cache 키
     */
    private function reactivationCacheKey(string $scope, string $targetIdentifier): string
    {
        return sprintf('language-pack:reactivation:%s:%s', $scope, $targetIdentifier);
    }

    /**
     * lang 디렉토리에서 locale 서브디렉토리 또는 fragment 디렉토리를 스캔합니다.
     *
     * 지원 패턴:
     *  - `{lang}/{locale}/*.php` (Laravel 표준)
     *  - `{lang}/partial/{locale}/*.json` (G7 템플릿 fragment)
     *
     * @param  string  $absoluteLangDir  lang 디렉토리 절대 경로
     * @return array<int, string> 발견된 locale 배열
     */
    private function scanLocales(string $absoluteLangDir): array
    {
        if (! File::isDirectory($absoluteLangDir)) {
            return [];
        }

        $locales = [];

        foreach (File::directories($absoluteLangDir) as $directory) {
            $name = basename($directory);
            if ($name === 'partial') {
                foreach (File::directories($directory) as $partialDir) {
                    $locales[] = basename($partialDir);
                }

                continue;
            }
            $locales[] = $name;
        }

        return array_values(array_unique(array_filter($locales, function ($locale) {
            return preg_match('/^[a-z]{2,3}(-[A-Z]{2})?$/', $locale) === 1;
        })));
    }

    /**
     * 가상 등록 레코드를 새로 생성합니다.
     *
     * 슬롯이 비어있으면 active, 아니면 installed 로 진입합니다.
     *
     * @param  string  $scope  스코프
     * @param  string  $targetIdentifier  대상 식별자
     * @param  string  $vendor  벤더
     * @param  string  $version  버전
     * @param  string  $locale  로케일
     * @param  string  $langDirectoryRelative  lang 디렉토리 상대 경로
     * @return void 반환값 없음
     */
    private function createBundledRecord(
        string $scope,
        string $targetIdentifier,
        string $vendor,
        string $version,
        string $locale,
        string $langDirectoryRelative,
    ): void {
        $identifier = sprintf('%s-%s-%s-%s', $vendor, $scope, $targetIdentifier, $locale);

        if ($this->repository->findByIdentifier($identifier)) {
            return;
        }

        $existingActive = $this->repository->findActiveForSlot($scope, $targetIdentifier, $locale);
        $status = $existingActive
            ? LanguagePackStatus::Installed->value
            : LanguagePackStatus::Active->value;

        try {
            $this->repository->create([
                'identifier' => $identifier,
                'vendor' => $vendor,
                'scope' => $scope,
                'target_identifier' => $targetIdentifier,
                'locale' => $locale,
                'locale_name' => strtoupper($locale),
                'locale_native_name' => $this->resolveNativeName($locale),
                'text_direction' => 'ltr',
                'version' => $version,
                'is_protected' => true,
                'status' => $status,
                'manifest' => [
                    'identifier' => $identifier,
                    'vendor' => $vendor,
                    'scope' => $scope,
                    'target_identifier' => $targetIdentifier,
                    'locale' => $locale,
                    'version' => $version,
                    'bundled_with_extension' => true,
                ],
                'source_type' => 'bundled_with_extension',
                'source_url' => $langDirectoryRelative,
                'installed_at' => now(),
                'activated_at' => $status === LanguagePackStatus::Active->value ? now() : null,
            ]);
        } catch (Throwable $e) {
            Log::warning('language-pack 가상 등록 실패', [
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * locale 코드로부터 일반적인 native name 을 반환합니다.
     *
     * @param  string  $locale  로케일
     * @return string native name
     */
    private function resolveNativeName(string $locale): string
    {
        return match ($locale) {
            'ko' => '한국어',
            'en' => 'English',
            'ja' => '日本語',
            'zh-CN', 'zh' => '中文',
            'es' => 'Español',
            'fr' => 'Français',
            'de' => 'Deutsch',
            default => $locale,
        };
    }
}
