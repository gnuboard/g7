<?php

namespace App\Services\LanguagePack;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\LanguagePackRepositoryInterface;
use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackSourceType;
use App\Enums\LanguagePackStatus;
use App\Models\LanguagePack;
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
     * @return void
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
     * @return void
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
     * 호스트 확장 비활성화 시 해당 확장에 종속된 언어팩을 cascade 비활성화합니다 (PO #6).
     *
     * 비활성화된 active 팩 ID 목록을 cache 에 stash 하여, 재활성화 시 §6 모달이
     * 표시할 후보로 사용됩니다.
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
        $service = app(\App\Services\LanguagePackService::class);

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

        if (! empty($deactivated)) {
            $this->stashDeactivatedForReactivation($scope, $targetIdentifier, $deactivated);
        }

        $this->registry->invalidate();

        return $deactivated;
    }

    /**
     * 비활성화된 팩 ID 목록을 cache 에 보관합니다 (재활성화 모달용).
     *
     * @param  string  $scope  스코프
     * @param  string  $targetIdentifier  대상 확장 식별자
     * @param  array<int, int>  $packIds  팩 ID 배열
     * @return void
     */
    public function stashDeactivatedForReactivation(string $scope, string $targetIdentifier, array $packIds): void
    {
        $key = $this->reactivationCacheKey($scope, $targetIdentifier);
        $this->cache->put($key, $packIds, 30 * 24 * 60 * 60);
    }

    /**
     * 호스트 확장 재활성화 시 모달에 표시할 pending 언어팩 목록을 반환합니다 (PO #7).
     *
     * stash 된 ID 중 현재도 DB 에 존재하고 inactive 상태인 팩만 필터링합니다.
     * 빈 배열을 반환하면 프론트엔드는 모달을 띄우지 않습니다 (PO #8).
     *
     * @param  string  $scope  스코프
     * @param  string  $targetIdentifier  대상 확장 식별자
     * @return array<int, array<string, mixed>> 모달 표시용 정보 배열
     */
    public function getPendingForReactivation(string $scope, string $targetIdentifier): array
    {
        $key = $this->reactivationCacheKey($scope, $targetIdentifier);
        $packIds = $this->cache->get($key, []);

        if (empty($packIds)) {
            return [];
        }

        $pending = [];
        foreach ($packIds as $id) {
            $pack = $this->repository->findById($id);
            if (! $pack || $pack->status !== LanguagePackStatus::Inactive->value) {
                continue;
            }
            $pending[] = [
                'id' => $pack->id,
                'identifier' => $pack->identifier,
                'locale' => $pack->locale,
                'locale_native_name' => $pack->locale_native_name,
            ];
        }

        // 사용 후 cache 비움
        $this->cache->forget($key);

        return $pending;
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
     * @return void
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
