<?php

namespace App\Upgrades\Data\V7_1_1\Migrations;

use App\Contracts\Repositories\LanguagePackRepositoryInterface;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use App\Services\LanguagePack\LanguagePackBundledRegistrar;
use App\Services\LanguagePackService;
use App\Support\ConfigCacheHelper;
use App\Support\RouteCacheHelper;
use Composer\Semver\Semver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 7.0.x → 7.1.0 업데이트 뒤 「코어 버전 호환성 문제」 로 잘못 꺼진 확장과 그때 함께 꺼진 언어팩을 한 번 다시 켭니다 (#145, #137).
 *
 * 7.0.0~7.0.10 부모 `core:update` 가 옛 APP_VERSION 을 env 로 물려줘 config 캐시에 옛 버전(7.0.0)이 구워졌고, 업데이트 뒤
 * 첫 프로세스가 `>=7.1.0` 을 요구하는 확장을 `incompatible_core` 로 껐다. 콘솔(크론·큐 워커)이 먼저 부팅하면 게시판·페이지
 * 모듈이, 웹 요청이 먼저면 관리자·사용자 템플릿이 꺼졌다.
 *
 * 평소에는 재호환된 확장을 자동으로 켜지 않는다(#302 — 알림만). 이 스텝은 잘못 꺼진 확장만 되돌리는 1회 예외다.
 *   - 대상: `deactivated_reason=incompatible_core` 이고 기록된 요구 버전(`incompatible_required_version`)을 이번 대상 버전이
 *     충족하는 행. 충족하지 않는 행(진짜 비호환)은 건드리지 않는다.
 *   - 순서: 모듈 → 플러그인 → 템플릿 (템플릿이 모듈·플러그인에 의존한다). 매니저의 일반 활성화(force=false)를 쓰므로 현재
 *     manifest 의 요구 버전·의존성 검사를 그대로 거친다. 실패는 경고만 남기고 계속한다.
 *   - 언어팩: 켠 호스트마다 (1) `host_deactivated` 표시 팩, 없으면 (2) 7.1.0 이 캐시에 남긴 목록, 그것도 없으면 (3) 같은 대상의
 *     사유 없는 inactive 팩 중 호스트가 꺼진 시각부터 10분 안에 바뀐 팩을 켠다(7.1.0 은 연쇄 사유를 DB 에 남기지 않았다).
 *   - 멱등: 다시 돌려도 바꿀 것이 없다(켠 행은 더 이상 incompatible_core 가 아니다).
 *
 * V-1 안전 예외: 데이터 스텝은 서비스 호출을 피하는 것이 원칙이지만, 확장 활성화는 레이아웃·권한·훅·캐시를 함께 다루는
 * 매니저 흐름이라 DB 행만 바꾸면 반쪽 활성화가 된다. 이 스텝은 자식(디스크의 새 코드)에서 돌므로 매니저를 직접 부르고,
 * 메서드가 없으면(이론상 in-process 대체 실행) 운영자 복구 명령을 경고로 남긴다.
 */
class RecoverFalselyAutoDeactivatedExtensions implements DataMigration
{
    /** 운영자 복구 명령 (공개 업그레이드 안내 문서와 같은 문장) */
    private const RECOVERY_COMMAND = 'php artisan core:execute-upgrade-steps --from=7.1.0 --to=7.1.1 --force --skip-migrations --skip-bundled-updates';

    /** 언어팩 3단 규칙의 시각 창 (호스트가 꺼진 시각부터, 초) */
    private const LANG_PACK_WINDOW_SECONDS = 600;

    /** 테이블 → [매니저 클래스, 로드 메서드, 활성화 메서드, 언어팩 스코프] (실행 순서 그대로) */
    private const TARGETS = [
        'modules' => [ModuleManager::class, 'loadModules', 'activateModule', 'module'],
        'plugins' => [PluginManager::class, 'loadPlugins', 'activatePlugin', 'plugin'],
        'templates' => [TemplateManager::class, 'loadTemplates', 'activateTemplate', 'template'],
    ];

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'RecoverFalselyAutoDeactivatedExtensions';
    }

    /**
     * 잘못 자동 비활성화된 확장과 그 언어팩을 다시 켭니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        $work = function () use ($context) {
            foreach (self::TARGETS as $table => [$managerClass, $loadMethod, $activateMethod, $scope]) {
                $this->recoverTable($context, $table, $managerClass, $loadMethod, $activateMethod, $scope);
            }
        };

        // 확장마다 돌던 config·라우트 캐시 재생성을 묶는다(7.1.1 의 deferRebuilds — 없으면 그대로 실행).
        $config = ConfigCacheHelper::class;
        $route = RouteCacheHelper::class;
        if (method_exists($config, 'deferRebuilds') && method_exists($route, 'deferRebuilds')) {
            $config::deferRebuilds(fn () => $route::deferRebuilds($work));
        } else {
            $work();
        }
    }

    /**
     * 한 확장 테이블의 잘못 꺼진 행을 다시 켭니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @param  string  $table  확장 테이블 (modules, plugins, templates)
     * @param  string  $managerClass  매니저 클래스
     * @param  string  $loadMethod  매니저 로드 메서드
     * @param  string  $activateMethod  매니저 활성화 메서드
     * @param  string  $scope  언어팩 스코프 (module, plugin, template)
     * @return void 반환값 없음
     */
    private function recoverTable(UpgradeContext $context, string $table, string $managerClass, string $loadMethod, string $activateMethod, string $scope): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'deactivated_reason') || ! Schema::hasColumn($table, 'incompatible_required_version')) {
            $context->logger->info("[7.1.1] {$table} 복구 건너뜀 — 자동 비활성화 기록 칸이 없음");

            return;
        }

        $rows = DB::table($table)
            ->where('status', 'inactive')
            ->where('deactivated_reason', 'incompatible_core')
            ->orderBy('id')
            ->get(['identifier', 'incompatible_required_version', 'deactivated_at']);

        $candidates = $rows->filter(fn ($row) => $this->satisfies($context->toVersion, (string) $row->incompatible_required_version));
        foreach ($rows->diffKeys($candidates) as $row) {
            $context->logger->info("[7.1.1] {$table} {$row->identifier} 은(는) 이번 코어로도 호환되지 않아 그대로 둡니다 (필요 버전: {$row->incompatible_required_version})");
        }

        if ($candidates->isEmpty()) {
            $context->logger->info("[7.1.1] {$table} 에 코어 버전 오판으로 꺼진 확장이 없습니다");

            return;
        }

        // audit:allow upgrade-step-vone-safety reason: 확장 활성화는 레이아웃·권한·훅·캐시를 함께 다루는 매니저 흐름이라 DB 행만 바꾸면 반쪽 활성화가 된다 — 자식(새 코드)에서 매니저를 부르고 method_exists 로 가드한다 (#145 D2)
        // audit:allow upgrade-step-data-snapshot reason: 위와 같음 — 동결 사본으로 대신할 수 없는 활성화 흐름이다
        $manager = app($managerClass);
        if (! method_exists($manager, $activateMethod)) {
            $context->logger->warning("[7.1.1] {$table} 복구 건너뜀 — 이 프로세스의 매니저에 {$activateMethod} 가 없음. 업데이트 뒤 실행: ".self::RECOVERY_COMMAND);

            return;
        }

        if (method_exists($manager, $loadMethod)) {
            try {
                $manager->$loadMethod();
            } catch (\Throwable $e) {
                $context->logger->warning("[7.1.1] {$table} 목록을 불러오지 못했습니다 — 업데이트 뒤 실행: ".self::RECOVERY_COMMAND, ['error' => $e->getMessage()]);

                return;
            }
        }

        foreach ($candidates as $row) {
            try {
                $result = $manager->$activateMethod($row->identifier, false);
            } catch (\Throwable $e) {
                $context->logger->warning("[7.1.1] {$table} {$row->identifier} 다시 켜기 실패 — 업데이트 뒤 실행: ".self::RECOVERY_COMMAND, ['error' => $e->getMessage()]);

                continue;
            }

            if (! is_array($result) || ($result['success'] ?? false) !== true) {
                $context->logger->warning("[7.1.1] {$table} {$row->identifier} 다시 켜기 실패 — 업데이트 뒤 실행: ".self::RECOVERY_COMMAND, [
                    'message' => is_array($result) ? ($result['message'] ?? $result['reason'] ?? null) : null,
                ]);

                continue;
            }

            $locales = $this->recoverLanguagePacks($context, $scope, (string) $row->identifier, $row->deactivated_at);
            $context->logger->info("[7.1.1] 코어 버전 오판으로 꺼졌던 {$table} {$row->identifier} 을(를) 다시 켰습니다".($locales === [] ? '' : ' (언어팩 함께 켬: '.implode(', ', $locales).')'));
        }
    }

    /**
     * 다시 켠 호스트의 언어팩을 3단 규칙으로 찾아 켭니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @param  string  $scope  언어팩 스코프 (module, plugin, template)
     * @param  string  $identifier  호스트 식별자
     * @param  mixed  $hostDeactivatedAt  호스트가 꺼진 시각 (DB 값)
     * @return array<int, string> 켠 언어팩 로케일
     */
    private function recoverLanguagePacks(UpgradeContext $context, string $scope, string $identifier, mixed $hostDeactivatedAt): array
    {
        if (! Schema::hasTable('language_packs')) {
            return [];
        }

        // (1) host_deactivated 표시 + (2) 7.1.0 캐시 목록 — 새 코드의 재활성화 후보 조회가 둘을 합쳐 돌려준다.
        $ids = [];
        // audit:allow upgrade-step-vone-safety reason: 7.1.0 이 캐시에 남긴 재활성화 목록을 읽는 유일한 경로 — method_exists 로 가드한다 (#145 D2)
        $registrar = app(LanguagePackBundledRegistrar::class);
        if (method_exists($registrar, 'getPendingForReactivation')) {
            $ids = array_column($registrar->getPendingForReactivation($scope, $identifier), 'id');
        }

        // (3) 7.1.0 은 연쇄 사유를 DB 에 남기지 않았다 — 호스트가 꺼진 시각부터 10분 안에 꺼진 사유 없는 팩
        if ($ids === [] && $hostDeactivatedAt !== null) {
            $from = Carbon::parse($hostDeactivatedAt);
            $ids = DB::table('language_packs')
                ->where('scope', $scope)
                ->where('target_identifier', $identifier)
                ->where('status', 'inactive')
                ->whereNull('deactivated_reason')
                ->whereBetween('updated_at', [$from, $from->copy()->addSeconds(self::LANG_PACK_WINDOW_SECONDS)])
                ->orderBy('id')
                ->pluck('id')
                ->all();
        }

        if ($ids === []) {
            return [];
        }

        // audit:allow upgrade-step-vone-safety reason: 언어팩 활성화는 슬롯 정리·레지스트리 캐시·훅을 함께 다루는 서비스 흐름이다 — method_exists 로 가드한다 (#145 D2)
        // audit:allow upgrade-step-data-snapshot reason: 위와 같음 — 동결 사본으로 대신할 수 없는 활성화 흐름이다
        $service = app(LanguagePackService::class);
        $repository = app(LanguagePackRepositoryInterface::class);
        if (! method_exists($service, 'activate')) {
            return [];
        }

        $locales = [];
        foreach ($ids as $id) {
            $pack = $repository->findById((int) $id);
            if (! $pack) {
                continue;
            }

            try {
                $service->activate($pack);
                $locales[] = (string) $pack->locale;
            } catch (\Throwable $e) {
                $context->logger->warning("[7.1.1] {$scope} {$identifier} 의 언어팩 {$pack->identifier} 다시 켜기 실패 (관리자 화면 > 언어팩에서 켤 수 있습니다)", ['error' => $e->getMessage()]);
            }
        }

        return $locales;
    }

    /**
     * 대상 버전이 기록된 요구 버전을 충족하는지 판정합니다.
     *
     * @param  string  $version  이번 업데이트 대상 코어 버전
     * @param  string  $constraint  기록된 요구 버전 제약
     * @return bool 충족하면 true (제약이 비었거나 해석할 수 없으면 false)
     */
    private function satisfies(string $version, string $constraint): bool
    {
        if (trim($constraint) === '') {
            return false;
        }

        try {
            return Semver::satisfies($version, $constraint);
        } catch (\Throwable) {
            return false;
        }
    }
}
