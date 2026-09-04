<?php

namespace App\Support\ExtensionDoc;

use App\Extension\AbstractModule;
use App\Extension\AbstractPlugin;
use ReflectionClass;
use Throwable;

/**
 * 확장 선언형 표면 수집기
 *
 * `AbstractModule` / `AbstractPlugin` 이 이미 갖고 있는 선언형 getter 를 실제로 호출해
 * 라우트·권한·메뉴·훅·설정·스케줄 등의 표면을 읽습니다. 정규식으로 소스를 긁는 방식과 달리
 * 상속 기본값(`getRoutes()` 가 파일 존재 여부로 계산하는 값 등)까지 정확히 반영됩니다.
 *
 * 읽기 대상은 항상 `_bundled` 소스입니다. 활성 디렉토리에 같은 FQCN 이 이미 로드되어 있으면
 * PHP 는 클래스를 재정의할 수 없으므로, `ModuleManager::evalFreshModule()` 과 같은 방식으로
 * 진입 클래스명만 바꿔 메모리에 다시 로드합니다 (namespace 유지 → use/extends 정상 동작).
 *
 * 확장 getter 는 DB·파일시스템·다른 확장에 의존할 수 있으므로 개별 호출을 각각 격리합니다.
 * 한 getter 의 실패가 나머지 수집을 중단시키지 않으며, 실패 사유는 `errors` 로 올라가
 * 문서에 "수집 실패" 로 드러납니다 (조용한 누락 금지).
 */
class DeclarativeSurfaceCollector
{
    /**
     * 수집 대상 getter 와 문서상 라벨.
     *
     * 확장 유형에 없는 getter 는 `method_exists` 로 건너뜁니다 (모듈 전용 · 플러그인 전용 혼재).
     *
     * @var array<string, string>
     */
    public const GETTERS = [
        // 라우트·마이그레이션·뷰
        'getRoutes' => '라우트 파일',
        'getMigrations' => '마이그레이션 경로',
        'getViews' => '뷰 경로',
        'getSeeders' => '시더',
        'getDynamicTables' => '동적 테이블',
        // 권한·역할·메뉴
        'getPermissions' => '권한 정의',
        'getDynamicPermissionIdentifiers' => '동적 권한 식별자',
        'getRoles' => '역할 정의',
        'getDynamicRoleIdentifiers' => '동적 역할 식별자',
        'getAdminMenus' => '관리자 메뉴',
        'getCustomMenus' => '사용자 메뉴',
        'getDynamicMenuSlugs' => '동적 메뉴 slug',
        // 확장점
        'getHooks' => '발행 훅 선언',
        'getHookListeners' => '훅 리스너',
        'getChannels' => '브로드캐스트 채널',
        'getSchedules' => '스케줄',
        'getMiddleware' => '미들웨어',
        'getLayoutExtensions' => '레이아웃 확장',
        'getNotificationDefinitions' => '알림 정의',
        'getBenchmarkProfiles' => '성능 계측 프로파일',
        // 본인인증
        'getIdentityPolicies' => 'IDV 정책',
        'getIdentityPurposes' => 'IDV 목적',
        'getIdentityMessages' => 'IDV 메시지',
        // 설정
        'getConfig' => 'config 파일',
        'getConfigValues' => 'config 값',
        'getSettingsSchema' => '설정 스키마',
        'getSettingsDefaultsPath' => '설정 기본값 경로',
        'getSettingsLayout' => '설정 레이아웃',
        'getSettingsRoute' => '설정 라우트',
        'getSeoConfigPath' => 'SEO 설정 경로',
        // 에셋
        'getAssets' => '프론트 에셋',
        'getAssetLoadingConfig' => '에셋 로딩 설정',
        'getBuiltAssetPaths' => '빌드 산출물 경로',
        'getTrustedScriptHosts' => '신뢰 스크립트 호스트',
        'getStorageDisk' => '스토리지 디스크',
        'getCacheStore' => '캐시 스토어',
        // 메타
        'getDependencies' => '의존 확장',
        'getRequiredCoreVersion' => '코어 최소 버전',
        'getLicense' => '라이선스',
        'getGithubUrl' => 'GitHub URL',
    ];

    /**
     * 확장의 선언형 표면을 수집합니다.
     *
     * @param  array<string, mixed>  $record  ExtensionInventory 레코드
     * @return array{available: bool, reason: string|null, values: array<string, mixed>, errors: array<string, string>, endpoints: int}
     *                                                                                                                                  available=false 이면 values 는 비고 reason 에 사유가 담깁니다.
     */
    public function collect(array $record): array
    {
        $empty = ['available' => false, 'reason' => null, 'values' => [], 'errors' => [], 'endpoints' => 0];
        // 확장마다 초기화한다 — 남겨 두면 앞 확장의 실패가 다음 확장의 사유로 새어 나간다.
        $this->pathInjectionError = null;

        if (($record['entryFile'] ?? null) === null || ($record['entryClass'] ?? null) === null) {
            $empty['reason'] = '진입 클래스 없음 (템플릿은 선언형 표면을 갖지 않습니다)';

            return $empty;
        }

        $restore = $this->registerBundledAutoloader($record);

        try {
            $instance = $this->instantiate($record);
        } catch (Throwable $e) {
            $restore();
            $empty['reason'] = '진입 클래스 로드 실패: '.$e->getMessage();

            return $empty;
        }

        if ($instance === null) {
            $restore();
            $empty['reason'] = '진입 클래스 인스턴스화 실패: '.$record['entryClass'];

            return $empty;
        }

        $values = [];
        $errors = [];

        if ($this->pathInjectionError !== null) {
            $errors['__path_injection'] = $this->pathInjectionError;
        }

        try {
            foreach (array_keys(self::GETTERS) as $getter) {
                if (! method_exists($instance, $getter)) {
                    continue;
                }

                try {
                    $values[$getter] = $instance->{$getter}();
                } catch (Throwable $e) {
                    $errors[$getter] = $e->getMessage();
                }
            }
        } finally {
            $restore();
        }

        return [
            'available' => true,
            'reason' => null,
            'values' => $values,
            'errors' => $errors,
            'endpoints' => $this->countEndpoints($values['getRoutes'] ?? []),
        ];
    }

    /**
     * 선언된 라우트 파일에 등록된 엔드포인트 수를 셉니다.
     *
     * 집계 배지가 말하는 "라우트 수" 는 **주소(엔드포인트) 개수**입니다 — 라우트 파일 개수가
     * 아닙니다. 확장 대부분이 파일 1~2개에 수십 개의 주소를 담으므로 파일 수는 규모를 전혀
     * 알려주지 않습니다. 이 값은 `docs/api/README.md` 목차의 엔드포인트 수와 같은 것을 세므로
     * 두 표가 서로 다른 숫자를 말하지 않습니다.
     *
     * `Route::match(['GET','POST'], ...)` 는 한 번 등록되지만 주소는 메서드 수만큼이므로
     * 배열 길이로 셉니다 (API 문서 생성기와 같은 기준).
     *
     * @param  mixed  $routes  `getRoutes()` 반환값 (종류 => 파일 경로)
     * @return int 엔드포인트 수 (셀 수 없으면 0)
     */
    private function countEndpoints(mixed $routes): int
    {
        if (! is_array($routes)) {
            return 0;
        }

        $verbs = 'get|post|put|patch|delete|options|any|dualSuffix|dualSuffixSegment|dualAsset';
        $total = 0;

        foreach ($routes as $path) {
            if (! is_string($path) || ! is_file($path)) {
                continue;
            }

            $source = (string) file_get_contents($path);

            $total += preg_match_all('/Route::(?:'.$verbs.')\s*\(/', $source);

            // match 는 메서드 배열의 길이만큼 주소를 만든다.
            if (preg_match_all('/Route::match\s*\(\s*\[([^\]]*)\]/', $source, $m) > 0) {
                foreach ($m[1] as $methods) {
                    $total += max(1, preg_match_all('/[\'"]/', $methods) / 2);
                }
            }
        }

        return (int) $total;
    }

    /**
     * 진입 클래스를 인스턴스화합니다.
     *
     * 같은 FQCN 이 이미 로드되어 있으면(활성 디렉토리 확장이 부팅된 경우) 클래스명을 바꿔
     * eval 로 다시 로드합니다. 그렇지 않으면 `_bundled` 파일을 직접 include 합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return object|null 확장 인스턴스 (실패 시 null)
     */
    /**
     * 직전 인스턴스화에서 발생한 경로 주입 실패 사유 (없으면 null).
     */
    private ?string $pathInjectionError = null;

    private function instantiate(array $record): ?object
    {
        $fqcn = (string) $record['entryClass'];
        $entryFile = (string) $record['entryFile'];

        if (! class_exists($fqcn, false)) {
            require_once $entryFile;

            return class_exists($fqcn, false) ? new $fqcn : null;
        }

        return $this->evalFreshEntry($record);
    }

    /**
     * 이미 로드된 FQCN 을 피해 `_bundled` 진입 클래스를 새 이름으로 다시 로드합니다.
     *
     * PHP 는 동일 프로세스에서 클래스를 재정의할 수 없으므로 클래스명만 치환합니다.
     * namespace 는 유지하므로 use/extends/implements 가 그대로 동작합니다.
     * eval 로 만든 클래스는 `ReflectionClass::getFileName()` 이 비정상이라
     * 경로 프로퍼티를 리플렉션으로 직접 주입합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return object|null 확장 인스턴스 (실패 시 null)
     */
    private function evalFreshEntry(array $record): ?object
    {
        $content = @file_get_contents((string) $record['entryFile']);
        if ($content === false) {
            return null;
        }

        $short = (string) $record['entryClassShort'];
        $uid = '_extdoc_'.bin2hex(random_bytes(6));

        $renamed = preg_replace('/\bclass\s+'.preg_quote($short, '/').'\b/', 'class '.$short.$uid, $content, 1);
        if (! is_string($renamed)) {
            return null;
        }

        $renamed = preg_replace('/^<\?php\s*/', '', $renamed);
        if (! is_string($renamed)) {
            return null;
        }

        eval($renamed);

        $freshClass = $record['namespace'].'\\'.$short.$uid;
        if (! class_exists($freshClass, false)) {
            return null;
        }

        $instance = new $freshClass;
        $this->pathInjectionError = $this->injectExtensionPath($instance, (string) $record['path']);

        return $instance;
    }

    /**
     * eval 로 로드한 인스턴스에 확장 디렉토리 경로를 주입합니다.
     *
     * @param  object  $instance  확장 인스턴스
     * @param  string  $path  확장 디렉토리 절대 경로
     */
    private function injectExtensionPath(object $instance, string $path): ?string
    {
        $targets = [
            AbstractModule::class => 'modulePath',
            AbstractPlugin::class => 'pluginPath',
        ];

        foreach ($targets as $class => $property) {
            if (! $instance instanceof $class) {
                continue;
            }

            try {
                $ref = new ReflectionClass($class);
                if (! $ref->hasProperty($property)) {
                    continue;
                }

                $prop = $ref->getProperty($property);
                $prop->setAccessible(true);
                $prop->setValue($instance, $path);
            } catch (Throwable $e) {
                // 경로 주입 실패는 예외를 던지지 않는다. 그런데 경로 기반 getter(`getRoutes`
                // `getMigrations` 등)는 그 상태에서 **예외 없이 빈 값**을 돌려주므로 getter 별
                // try/catch 에도 걸리지 않는다 — "없음" 으로 굳는 침묵 경로다. 사유를 올린다.
                return $property.' 주입 실패: '.$e->getMessage();
            }
        }

        return null;
    }

    /**
     * `_bundled` composer.json 의 PSR-4 매핑을 임시 오토로더로 등록합니다.
     *
     * 아직 로드되지 않은 확장 내부 클래스(Listener·Model 등)가 활성 디렉토리가 아니라
     * `_bundled` 소스에서 해석되도록 합니다. 수집이 끝나면 반드시 해제해야 하므로
     * 해제 클로저를 돌려줍니다 (프로세스 잔류 시 이후 코드가 `_bundled` 를 보게 됨).
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return \Closure 해제 클로저
     */
    private function registerBundledAutoloader(array $record): \Closure
    {
        $composerPath = $record['path'].DIRECTORY_SEPARATOR.'composer.json';
        $psr4 = [];

        if (is_file($composerPath)) {
            $composer = json_decode((string) file_get_contents($composerPath), true);
            $declared = $composer['autoload']['psr-4'] ?? null;

            if (is_array($declared)) {
                foreach ($declared as $prefix => $dir) {
                    $dirs = is_array($dir) ? $dir : [$dir];
                    foreach ($dirs as $one) {
                        $psr4[(string) $prefix][] = rtrim($record['path'].DIRECTORY_SEPARATOR.trim((string) $one, '/\\'), '/\\');
                    }
                }
            }
        }

        if ($psr4 === []) {
            return static function (): void {};
        }

        $loader = static function (string $class) use ($psr4): void {
            foreach ($psr4 as $prefix => $dirs) {
                if (! str_starts_with($class, $prefix)) {
                    continue;
                }

                $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))).'.php';

                foreach ($dirs as $dir) {
                    $file = $dir.DIRECTORY_SEPARATOR.$relative;
                    if (is_file($file)) {
                        require_once $file;

                        return;
                    }
                }
            }
        };

        spl_autoload_register($loader, true, true);

        return static function () use ($loader): void {
            spl_autoload_unregister($loader);
        };
    }
}
