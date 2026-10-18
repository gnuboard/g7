<?php

namespace App\Extension;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\DeactivationReason;
use App\Support\ConfigCacheHelper;
use App\Support\CoreVersionDiskReader;
use App\Support\RouteCacheHelper;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 부팅 시 현재 코어 버전과 맞지 않는 활성 확장을 자동 비활성화합니다.
 *
 * `CoreServiceProvider::boot()` 가 업데이트 트리 밖에서 모듈·플러그인·템플릿마다 한 번씩 부른다. 판정 결과는
 * 코어 버전별 캐시(`ext.version_check.{type}.{version}`, 1시간)에 저장해 매 요청 반복하지 않는다.
 *
 * 규칙 (#145, #137):
 *   - 안전망: 비호환이 1건 이상인데 현재 코어 버전이 디스크 코드 버전(config/app.php 기본값)보다 낮으면 아무것도
 *     끄지 않는다. 옛 업데이터가 옛 버전을 구운 config 캐시나 옛 env 를 문 상주 프로세스가 원인이므로, config 캐시를
 *     비워 다음 요청이 `.env` 로 다시 판정하게 한다. 판정 캐시도 저장하지 않는다.
 *   - 결과 처리: 매니저가 실제로 끄지 못한 확장(의존 확장 경고, 예외)은 알림·「자동 해제」 로그에 넣지 않고 차단 경고를
 *     남긴다. 그 회차는 판정 캐시를 저장하지 않아 다음 부팅이 다시 시도한다.
 *   - 재생성 묶기: 1건마다 돌던 config·라우트 캐시 재생성(일회용 앱 부팅)을 묶어 마지막에 한 번만 한다
 *     (17건 연쇄에서 MySQL 연결 151개가 고갈된 실측).
 *
 * 자동 재활성화는 하지 않는다(#302) — 재호환 감지는 `CoreServiceProvider::detectRecoveredExtensions()` 가 알림만 남긴다.
 */
class IncompatibleExtensionDeactivator
{
    /**
     * 이 프로세스에서 안전망 경고·config 캐시 비우기를 이미 했는지 여부 (모듈·플러그인·템플릿 세 번 중복 방지).
     */
    private bool $staleHandled = false;

    /**
     * @param  CacheInterface  $cache  판정·알림 캐시
     * @param  TemplateRepositoryInterface  $templateRepository  템플릿 설치 기록 조회
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly TemplateRepositoryInterface $templateRepository,
    ) {}

    /**
     * 비호환 모듈 또는 플러그인을 자동 비활성화합니다.
     *
     * @param  ModuleManager|PluginManager  $manager  확장 매니저
     * @param  string  $type  확장 타입 (modules, plugins)
     * @return array{deactivated: array<int, array{identifier: string, required: string}>, blocked: array<int, array<string, mixed>>, skipped_stale: bool} 처리 결과
     */
    public function deactivateIncompatibleExtensions($manager, string $type): array
    {
        $cacheKey = CoreVersionChecker::getCacheKey($type);
        if ($this->cache->has($cacheKey)) {
            return $this->result();
        }

        $activeMethod = $type === 'modules' ? 'getActiveModules' : 'getActivePlugins';
        $deactivateMethod = $type === 'modules' ? 'deactivateModule' : 'deactivatePlugin';

        $candidates = [];
        foreach ($manager->$activeMethod() as $identifier => $extension) {
            $requiredVersion = $extension->getRequiredCoreVersion();
            if (! CoreVersionChecker::isCompatible($requiredVersion)) {
                $candidates[(string) $identifier] = $requiredVersion;
            }
        }

        return $this->process($type, $cacheKey, $candidates, function (string $identifier, string $required) use ($manager, $deactivateMethod): array {
            // 자동 비활성화: reason='incompatible_core' + 요구 버전 전달
            // (수동 비활성화와 DB 레벨 구분 → UI 라벨링 / 알림 영속화 / 재호환 복구 판정)
            $result = $manager->$deactivateMethod($identifier, false, DeactivationReason::IncompatibleCore->value, $required);

            if (is_array($result) && ($result['success'] ?? false) === true) {
                return ['ok' => true];
            }

            return [
                'ok' => false,
                'dependents' => $this->dependentIdentifiers(is_array($result) ? $result : []),
                'reason' => is_array($result) ? ($result['message'] ?? $result['reason'] ?? null) : null,
            ];
        });
    }

    /**
     * 비호환 템플릿을 자동 비활성화합니다 (설치되고 활성인 템플릿만).
     *
     * @param  TemplateManager  $templateManager  템플릿 매니저
     * @return array{deactivated: array<int, array{identifier: string, required: string}>, blocked: array<int, array<string, mixed>>, skipped_stale: bool} 처리 결과
     */
    public function deactivateIncompatibleTemplates(TemplateManager $templateManager): array
    {
        $cacheKey = CoreVersionChecker::getCacheKey('templates');
        if ($this->cache->has($cacheKey)) {
            return $this->result();
        }

        $candidates = [];
        foreach ($templateManager->getAllTemplates() as $identifier => $template) {
            $record = $this->templateRepository->findByIdentifier($identifier);
            if (! $record || $record->status !== 'active') {
                continue;
            }

            $requiredVersion = $template['g7_version'] ?? null;
            if (! CoreVersionChecker::isCompatible($requiredVersion)) {
                $candidates[(string) $identifier] = $requiredVersion;
            }
        }

        return $this->process('templates', $cacheKey, $candidates, function (string $identifier, string $required) use ($templateManager): array {
            $failureReason = null;
            $ok = $templateManager->deactivateTemplate($identifier, DeactivationReason::IncompatibleCore->value, $required, $failureReason);

            return ['ok' => $ok === true, 'dependents' => [], 'reason' => $failureReason];
        });
    }

    /**
     * 비호환 후보를 안전망 판정 후 하나씩 끄고 결과를 기록합니다.
     *
     * @param  string  $type  확장 타입 (modules, plugins, templates)
     * @param  string  $cacheKey  판정 캐시 키
     * @param  array<string, string>  $candidates  식별자 → 요구 버전
     * @param  callable(string, string): array{ok: bool, dependents?: array<int, string>, reason?: mixed}  $deactivate  1건 비활성화
     * @return array{deactivated: array<int, array{identifier: string, required: string}>, blocked: array<int, array<string, mixed>>, skipped_stale: bool} 처리 결과
     */
    private function process(string $type, string $cacheKey, array $candidates, callable $deactivate): array
    {
        if ($candidates === []) {
            $this->cache->put($cacheKey, true, CoreVersionChecker::getCacheTtl());

            return $this->result();
        }

        // 안전망은 비호환 후보가 있을 때만 디스크를 읽는다 — 정상 사이트 비용 0.
        if ($this->isStaleVersion()) {
            return $this->result(skippedStale: true);
        }

        // 리스너(config 캐시)와 매니저(라우트 캐시)의 1건당 재생성을 묶어 마지막에 한 번만 한다.
        [$deactivated, $blocked] = ConfigCacheHelper::deferRebuilds(
            fn () => RouteCacheHelper::deferRebuilds(fn () => $this->deactivateCandidates($type, $candidates, $deactivate))
        );

        if ($deactivated !== []) {
            $this->storeDeactivatedExtensionsAlert($type, $deactivated);
        }

        // 끄지 못한 확장이 있으면 다음 부팅이 다시 시도하도록 판정 캐시를 남기지 않는다.
        if ($blocked === []) {
            $this->cache->put($cacheKey, true, CoreVersionChecker::getCacheTtl());
        }

        return $this->result($deactivated, $blocked);
    }

    /**
     * 후보를 하나씩 끄고, 끈 것과 끄지 못한 것을 나눠 로그를 남깁니다.
     *
     * @param  string  $type  확장 타입 (modules, plugins, templates)
     * @param  array<string, string>  $candidates  식별자 → 요구 버전
     * @param  callable(string, string): array{ok: bool, dependents?: array<int, string>, reason?: mixed}  $deactivate  1건 비활성화
     * @return array{0: array<int, array{identifier: string, required: string}>, 1: array<int, array<string, mixed>>} [끈 확장, 끄지 못한 확장]
     */
    private function deactivateCandidates(string $type, array $candidates, callable $deactivate): array
    {
        $deactivated = [];
        $blocked = [];
        $coreVersion = CoreVersionChecker::getCoreVersion();

        foreach ($candidates as $identifier => $required) {
            try {
                $outcome = $deactivate($identifier, $required);
            } catch (Throwable $e) {
                $outcome = ['ok' => false, 'dependents' => [], 'reason' => $e->getMessage()];
            }

            if ($outcome['ok']) {
                $deactivated[] = ['identifier' => $identifier, 'required' => $required];

                // 문구의 :type·:identifier 자리를 채운다 — 예전에는 맥락 배열만 넘겨 로그에 자리표시자가 그대로 남았다.
                Log::warning(__('extensions.warnings.auto_deactivated', ['type' => $type, 'identifier' => $identifier]), [
                    'type' => $type,
                    'identifier' => $identifier,
                    'required_version' => $required,
                    'core_version' => $coreVersion,
                ]);

                continue;
            }

            $blocked[] = ['identifier' => $identifier, 'required' => $required, 'dependents' => $outcome['dependents'] ?? []];

            Log::warning(__('extensions.warnings.auto_deactivate_blocked'), [
                'type' => $type,
                'identifier' => $identifier,
                'required_version' => $required,
                'core_version' => $coreVersion,
                'dependents' => $outcome['dependents'] ?? [],
                'reason' => $outcome['reason'] ?? null,
            ]);
        }

        return [$deactivated, $blocked];
    }

    /**
     * 현재 코어 버전이 디스크 코드 버전보다 낮은지 판정하고, 낮으면 config 캐시를 비우고 경고를 남깁니다.
     *
     * @return bool 낮으면 true (자동 비활성화를 건너뛴다)
     */
    private function isStaleVersion(): bool
    {
        $current = CoreVersionChecker::getCoreVersion();
        $shipped = CoreVersionDiskReader::shippedVersion();

        if ($shipped === null || $current === '' || ! version_compare($current, $shipped, '<')) {
            return false;
        }

        if ($this->staleHandled) {
            return true;
        }
        $this->staleHandled = true;

        $configCached = $this->isConfigurationCached();
        if ($configCached) {
            try {
                ConfigCacheHelper::clear();
            } catch (Throwable $e) {
                Log::warning('[Config] 자동 비활성화 안전망의 config 캐시 비우기 실패', ['error' => $e->getMessage()]);
            }
        }

        Log::warning(__('extensions.warnings.auto_deactivate_skipped_stale_version'), [
            'current' => $current,
            'shipped' => $shipped,
            'config_cached' => $configCached,
        ]);

        return true;
    }

    /**
     * 현재 앱이 config 캐시로 부팅했는지 여부를 반환합니다.
     *
     * @return bool config 캐시 파일이 있으면 true
     */
    protected function isConfigurationCached(): bool
    {
        return app()->configurationIsCached();
    }

    /**
     * 매니저의 의존 확장 경고 응답에서 의존 확장 식별자를 모읍니다.
     *
     * @param  array<string, mixed>  $result  deactivateModule/deactivatePlugin 반환값
     * @return array<int, string> 의존 확장 식별자 목록
     */
    private function dependentIdentifiers(array $result): array
    {
        $identifiers = [];
        foreach (['dependent_templates', 'dependent_modules', 'dependent_plugins'] as $key) {
            foreach ($result[$key] ?? [] as $dependent) {
                if (is_array($dependent) && isset($dependent['identifier'])) {
                    $identifiers[] = (string) $dependent['identifier'];
                }
            }
        }

        return $identifiers;
    }

    /**
     * 비활성화된 확장 알림을 캐시에 저장합니다 (관리자 대시보드·확장 목록 배너용, 24시간).
     *
     * @param  string  $type  확장 타입 (modules, plugins, templates)
     * @param  array<int, array{identifier: string, required: string}>  $deactivated  비활성화된 확장 목록
     * @return void 반환값 없음
     */
    private function storeDeactivatedExtensionsAlert(string $type, array $deactivated): void
    {
        $alerts = $this->cache->get('ext.compatibility_alerts', []);
        $alerts[$type] = [
            'deactivated' => $deactivated,
            'core_version' => CoreVersionChecker::getCoreVersion(),
            'timestamp' => now()->toIso8601String(),
        ];
        $this->cache->put('ext.compatibility_alerts', $alerts, 86400);
    }

    /**
     * 처리 결과 배열을 만듭니다.
     *
     * @param  array<int, array{identifier: string, required: string}>  $deactivated  끈 확장
     * @param  array<int, array<string, mixed>>  $blocked  끄지 못한 확장
     * @param  bool  $skippedStale  안전망으로 건너뛰었는지
     * @return array{deactivated: array<int, array{identifier: string, required: string}>, blocked: array<int, array<string, mixed>>, skipped_stale: bool} 처리 결과
     */
    private function result(array $deactivated = [], array $blocked = [], bool $skippedStale = false): array
    {
        return ['deactivated' => $deactivated, 'blocked' => $blocked, 'skipped_stale' => $skippedStale];
    }
}
