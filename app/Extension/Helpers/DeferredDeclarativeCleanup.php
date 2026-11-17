<?php

namespace App\Extension\Helpers;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Contracts\Repositories\ExtensionDeferredCleanupRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\NotificationDefinitionRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Enums\DeferredCleanupKind;
use App\Enums\ExtensionOwnerType;
use App\Exceptions\DeferredCleanupHelperNotAllowedException;
use App\Exceptions\DeferredCleanupTargetNotAllowedException;
use App\Exceptions\ExtensionOperationLockException;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Support\LifecycleWarnings;
use App\Models\ExtensionDeferredCleanup;
use App\Services\CoreUpdateService;
use App\Services\Extension\ExtensionFileSyncService;
use App\Support\CoreVersionDiskReader;
use App\Support\RuntimeRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * 검수 서버(stage)에서 선언 정리를 지우지 않고 「정리 필요」로 대장에 적고, 운영 서버에서 지울 대상을 다시 정해 실행합니다.
 *
 * 선언 정리 헬퍼(메뉴·권한·역할·알림·본인확인 정책·시드 엔티티·스케줄)의 삭제 메서드 첫 줄이 {@see shouldDefer()} 로 갈라
 * {@see defer()} 로 적는다. 회원 항목 정리·역할 권한 회수처럼 헬퍼 안에서 일부만 지우는 곳은 {@see deferOwner()} 로 적는다.
 *
 * 대장에는 지울 대상 목록(검수 서버 시점의 「남길 목록」)을 적지 않는다. 운영 서버의 `extension:apply-deferred-cleanup` 이
 * {@see apply()} 로 실행하는 순간의 그 서버 확장 파일 선언과 공유 DB 로 다시 정한다 — 설치·업데이트가 쓰는 선언 동기화
 * (`syncDeclarativeArtifacts`, 코어는 `reloadCoreConfigAndResync`)와 시더를 그대로 부른다. 그래서
 * - 검수 서버에 여러 번 배포해도 마지막 판의 선언 기준으로만 지운다.
 * - 배포 뒤 운영 서버에서 생긴 게시판(동적 권한·메뉴·역할)이나 주문이 쓰기 시작한 시드 행을 지우지 않는다.
 * - 이 서버의 확장 파일 판이 공유 DB 판과 다르면(운영 서버가 아직 새 파일을 받지 않음, 반려된 판) 기다린다.
 *
 * @since 7.2.0
 */
final class DeferredDeclarativeCleanup
{
    /** 기다림 사유 — 이 서버에 그 확장 파일이 없음(검수 서버에만 설치 등) */
    public const WAITING_FILES_MISSING = 'files_missing';

    /** 기다림 사유 — 이 서버 확장 파일 판이 공유 DB 판과 다름(아직 새 파일을 받지 않음·반려된 판) */
    public const WAITING_VERSION_DIFFERS = 'version_differs';

    /** 기다림 사유 — 판 번호는 같지만 이 서버 코드 파일 내용이 검수 서버가 적을 때와 다름(판 번호를 올리지 않은 수정) */
    public const WAITING_CODE_DIFFERS = 'code_differs';

    /** 기다림 사유 — 이 서버 코어가 정리를 적을 때의 코어 판보다 낮음 */
    public const WAITING_CORE_VERSION = 'core_version';

    /** 소유 읽기 방식 — (종류, 식별자, …) 꼴의 앞 두 인자 */
    private const OWNER_LEADING_ARGS = 'leading_args';

    /** 소유 읽기 방식 — GenericEntitySyncHelper::cleanupStale 의 범위 조건·소유 식별자 인자 */
    private const OWNER_ENTITY_SCOPE = 'entity_scope';

    /** 소유 읽기 방식 — 첫 인자인 알림 정의 id 의 소유 */
    private const OWNER_NOTIFICATION_DEFINITION = 'notification_definition';

    /**
     * 대장으로 미룰 수 있는 정리 메서드와 소유 읽기 방식.
     *
     * 새 선언 정리 메서드가 {@see defer()} 를 쓰려면 여기에 먼저 올려야 한다 — 목록에 없으면 미루지 않고 예외를 던진다.
     *
     * @var array<class-string, array<string, string>>
     */
    private const ALLOWED = [
        ExtensionMenuSyncHelper::class => [
            'cleanupStaleMenus' => self::OWNER_LEADING_ARGS,
        ],
        ExtensionRoleSyncHelper::class => [
            'cleanupStalePermissions' => self::OWNER_LEADING_ARGS,
            'cleanupStaleRoles' => self::OWNER_LEADING_ARGS,
        ],
        ExtensionScheduleSyncHelper::class => [
            'cleanupSchedulesForExtension' => self::OWNER_LEADING_ARGS,
            'removeStale' => self::OWNER_LEADING_ARGS,
        ],
        GenericEntitySyncHelper::class => [
            'cleanupStale' => self::OWNER_ENTITY_SCOPE,
        ],
        IdentityPolicySyncHelper::class => [
            'cleanupStalePolicies' => self::OWNER_LEADING_ARGS,
        ],
        NotificationSyncHelper::class => [
            'cleanupStaleDefinitions' => self::OWNER_LEADING_ARGS,
            'cleanupStaleTemplates' => self::OWNER_NOTIFICATION_DEFINITION,
        ],
    ];

    /**
     * 대장 실행 중인지 — 실행 중에는 미루지 않고 실제로 지운다.
     */
    private static bool $applying = false;

    /**
     * 지금 정리를 미뤄야 하는지.
     *
     * @return bool stage 이고 대장 실행 중이 아니면 true
     */
    public static function shouldDefer(): bool
    {
        return ! self::$applying && RuntimeRole::isStage();
    }

    /**
     * 정리 메서드 호출을 「정리 필요」로 대장에 적고 0(지운 수)을 돌려줍니다.
     *
     * 시드 엔티티 정리(GenericEntitySyncHelper)는 부른 시더를 함께 적는다 — 운영 서버가 그 시더를 다시 돌려 사용 중 판정을 다시 한다.
     *
     * @param  object  $helper  헬퍼 인스턴스
     * @param  string  $method  메서드 이름
     * @param  array<int, mixed>  $args  인자
     * @return int 늘 0
     *
     * @throws DeferredCleanupHelperNotAllowedException 허용 목록에 없는 정리 메서드일 때
     */
    public static function defer(object $helper, string $method, array $args): int
    {
        $class = self::allowedClassOf($helper, $method);
        [$scope, $identifier] = self::owner(self::ALLOWED[$class][$method], $args);

        $seeder = null;
        if ($class === GenericEntitySyncHelper::class) {
            $seeder = self::callingSeeder($scope, $identifier);
            if ($seeder === null) {
                // 선언 시더 밖(업그레이드 스텝 등)의 시드 정리는 운영 서버가 다시 계산할 근거가 없다 — 선언 동기화로만 적고 알린다
                Log::warning('시드 정리가 확장이 선언한 시더 밖에서 불려 운영 서버 실행에서 다시 계산되지 않습니다 (검수 서버)', ['scope' => $scope, 'identifier' => $identifier, 'model' => $args[0] ?? null]);
            }
        }

        if ($seeder !== null) {
            self::record($scope, $identifier, DeferredCleanupKind::Seeder, $seeder);
        } else {
            self::record($scope, $identifier, DeferredCleanupKind::Declarations);
        }

        Log::info('선언 정리를 운영 서버 실행으로 미룸 (검수 서버)', ['helper' => $class.'::'.$method, 'scope' => $scope, 'identifier' => $identifier, 'seeder' => $seeder]);

        return 0;
    }

    /**
     * 헬퍼 안에서 일부만 지우는 정리(회원 항목·역할 권한 회수)를 소유 확장의 「정리 필요」로 적습니다.
     *
     * @param  mixed  $type  소유 종류 (ExtensionOwnerType 또는 문자열)
     * @param  mixed  $identifier  소유 식별자
     * @return void 반환값 없음
     */
    public static function deferOwner(mixed $type, mixed $identifier): void
    {
        [$scope, $owner] = self::normalizeOwner($type, $identifier);
        self::record($scope, $owner, DeferredCleanupKind::Declarations);
        Log::info('선언 정리를 운영 서버 실행으로 미룸 (검수 서버)', ['scope' => $scope, 'identifier' => $owner]);
    }

    /**
     * 대장 한 건을 실행합니다 — 지울 대상은 지금 이 서버의 선언과 공유 DB 로 다시 정한다.
     *
     * 그 확장(코어)의 작업 잠금을 잡고 실행한다 — 같은 확장의 업데이트·설치와 섞이지 않게.
     *
     * @param  ExtensionDeferredCleanup  $row  대장 행
     * @return string|null 실행했으면 null, 기다리면 사유 코드(self::WAITING_*) — 행을 남긴다
     *
     * @throws DeferredCleanupTargetNotAllowedException 범위·종류·시더가 실행할 수 없는 값일 때 (실행하지 않는다)
     * @throws ExtensionOperationLockException 그 확장(코어) 작업이 진행 중일 때
     * @throws \RuntimeException 코어 재동기화가 단계 실패를 남겼거나 시더가 실패했을 때
     */
    public static function apply(ExtensionDeferredCleanup $row): ?string
    {
        $kind = $row->kindOrNull();
        if ($kind === null) {
            throw new DeferredCleanupTargetNotAllowedException(self::describe($row));
        }

        self::$applying = true;
        try {
            return match ((string) $row->scope) {
                'core' => self::applyCore($row, $kind),
                'module', 'plugin' => self::applyExtension($row, $kind),
                default => throw new DeferredCleanupTargetNotAllowedException(self::describe($row)),
            };
        } finally {
            self::$applying = false;
        }
    }

    /**
     * 코어 선언 정리를 실행합니다 — 이 서버의 코드 판과 설정 판(APP_VERSION)이 모두 적을 때의 판 이상일 때만.
     *
     * 검수 서버의 `core:update` 는 부모 프로세스가 옛 설정 판인 채로 재동기화하므로, 적을 때는 디스크의 새 코드 판을 쓴다.
     * 운영 서버가 아직 옛 코어면 옛 선언으로 재동기화해 새 코어 항목을 지우게 되므로 기다린다.
     *
     * @param  ExtensionDeferredCleanup  $row  대장 행
     * @param  DeferredCleanupKind  $kind  종류
     * @return string|null 실행했으면 null, 기다리면 사유 코드
     *
     * @throws DeferredCleanupTargetNotAllowedException 선언 정리가 아닌 코어 행일 때
     * @throws \RuntimeException 재동기화 단계가 실패를 남겼을 때
     */
    private static function applyCore(ExtensionDeferredCleanup $row, DeferredCleanupKind $kind): ?string
    {
        if ($kind !== DeferredCleanupKind::Declarations) {
            throw new DeferredCleanupTargetNotAllowedException(self::describe($row));
        }

        $recorded = (string) $row->recorded_version;
        if ($recorded !== '') {
            $code = (string) (CoreVersionDiskReader::shippedVersion() ?? config('app.version'));
            if (version_compare($code, $recorded, '<') || version_compare((string) config('app.version'), $recorded, '<')) {
                return self::WAITING_CORE_VERSION;
            }
        }
        if ($row->code_fingerprint !== null && $row->code_fingerprint !== self::fingerprint('core', '')) {
            return self::WAITING_CODE_DIFFERS;
        }

        $lock = ExtensionOperationLock::acquireCore();
        try {
            // 재동기화는 단계마다 예외를 삼키고 경고로 남긴다 — 경고가 늘었으면 실패로 보고 행을 남긴다
            $before = count(LifecycleWarnings::current()->all());
            app(CoreUpdateService::class)->reloadCoreConfigAndResync();
            $after = LifecycleWarnings::current()->all();
            if (count($after) > $before) {
                $last = end($after);
                throw new \RuntimeException((string) __('exceptions.deferred_cleanup_core_resync_failed', ['message' => (string) ($last['message'] ?? '')]));
            }
        } finally {
            $lock->release();
        }

        return null;
    }

    /**
     * 확장 선언 정리를 실행합니다.
     *
     * - 공유 DB 에 설치돼 있지 않으면(검수 서버의 설치 실패 되돌리기 등) 그 확장 소유 선언 항목을 모두 지운다.
     * - 이 서버에 그 확장 파일이 없거나 파일 판이 공유 DB 판과 다르면 기다린다.
     * - 그 밖에는 설치·업데이트와 같은 선언 동기화(또는 적힌 시더)를 이 서버 선언으로 실행한다.
     *
     * @param  ExtensionDeferredCleanup  $row  대장 행
     * @param  DeferredCleanupKind  $kind  종류
     * @return string|null 실행했으면 null, 기다리면 사유 코드
     *
     * @throws DeferredCleanupTargetNotAllowedException 시더가 그 확장이 선언한 시더가 아닐 때
     */
    private static function applyExtension(ExtensionDeferredCleanup $row, DeferredCleanupKind $kind): ?string
    {
        $isModule = $row->scope === 'module';
        $identifier = (string) $row->identifier;
        $record = $isModule
            ? app(ModuleRepositoryInterface::class)->findByIdentifier($identifier)
            : app(PluginRepositoryInterface::class)->findByIdentifier($identifier);

        $loaded = null;
        if ($record !== null) {
            $loaded = $isModule
                ? app(ModuleManager::class)->getModule($identifier)
                : app(PluginManager::class)->getPlugin($identifier);
            if ($loaded === null) {
                return self::WAITING_FILES_MISSING;
            }
            if ((string) $loaded->getVersion() !== (string) $record->version) {
                return self::WAITING_VERSION_DIFFERS;
            }
            if ($row->code_fingerprint !== null && $row->code_fingerprint !== self::fingerprint((string) $row->scope, $identifier)) {
                return self::WAITING_CODE_DIFFERS;
            }
        }

        $lock = ExtensionOperationLock::acquire((string) $row->scope, $identifier, 'deferred_cleanup');
        try {
            if ($loaded === null) {
                if ($kind === DeferredCleanupKind::Declarations) {
                    self::purgeOwned($isModule ? ExtensionOwnerType::Module : ExtensionOwnerType::Plugin, $identifier);
                }
            } elseif ($kind === DeferredCleanupKind::Seeder) {
                self::runDeclaredSeeder($loaded, $row);
            } else {
                $isModule
                    ? app(ModuleManager::class)->syncDeclarativeArtifacts($loaded)
                    : app(PluginManager::class)->syncDeclarativeArtifacts($loaded);
            }
        } finally {
            $lock->release();
        }

        return null;
    }

    /**
     * 그 확장이 선언한 시더일 때만 다시 실행합니다 — 대장의 시더 클래스는 DB 에서 읽는 값이다.
     *
     * @param  ModuleInterface|PluginInterface  $extension  이 서버에 로드된 확장
     * @param  ExtensionDeferredCleanup  $row  대장 행
     * @return void 반환값 없음
     *
     * @throws DeferredCleanupTargetNotAllowedException 선언하지 않은 시더일 때
     * @throws \RuntimeException 시더가 실패했을 때
     */
    private static function runDeclaredSeeder(ModuleInterface|PluginInterface $extension, ExtensionDeferredCleanup $row): void
    {
        $seeder = (string) $row->target;
        if (! in_array($seeder, $extension->getSeeders(), true) || ! is_subclass_of($seeder, Seeder::class)) {
            throw new DeferredCleanupTargetNotAllowedException(self::describe($row));
        }

        $exit = Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
        if ($exit !== 0) {
            $output = trim(Artisan::output());
            throw new \RuntimeException($output !== '' ? $output : (string) __('exceptions.deferred_cleanup_seeder_failed', ['seeder' => $seeder, 'code' => $exit]));
        }
    }

    /**
     * 공유 DB 에 없는 확장이 소유한 선언 항목을 모두 지웁니다 — 제거와 같은 규칙(지우기 직전 기록·자식 명시 삭제).
     *
     * @param  ExtensionOwnerType  $type  소유 종류
     * @param  string  $identifier  확장 식별자
     * @return void 반환값 없음
     */
    private static function purgeOwned(ExtensionOwnerType $type, string $identifier): void
    {
        $roles = app(ExtensionRoleSyncHelper::class);
        $roles->cleanupStalePermissions($type, $identifier, []);
        $roles->cleanupStaleRoles($type, $identifier, []);
        app(ExtensionMenuSyncHelper::class)->cleanupStaleMenus($type, $identifier, []);
        app(IdentityPolicySyncHelper::class)->cleanupStalePolicies($type->value, $identifier, []);
        app(NotificationSyncHelper::class)->cleanupStaleDefinitions($type->value, $identifier, []);
        app(ExtensionScheduleSyncHelper::class)->cleanupSchedulesForExtension($type, $identifier);
        app(FieldDefinitionSyncHelper::class)->removeSource($type->value, $identifier);
    }

    /**
     * 정리 필요를 대장에 적습니다 — 코어는 디스크의 코드 판을 함께 적는다(검수 서버 `core:update` 부모의 설정 판은 아직 옛 판).
     *
     * @param  string  $scope  범위
     * @param  string  $identifier  식별자
     * @param  DeferredCleanupKind  $kind  종류
     * @param  string  $target  시더 클래스 (그 밖은 빈 문자열)
     * @return void 반환값 없음
     */
    private static function record(string $scope, string $identifier, DeferredCleanupKind $kind, string $target = ''): void
    {
        app(ExtensionDeferredCleanupRepositoryInterface::class)->record(
            $scope,
            $identifier,
            $kind,
            $target,
            $scope === 'core' ? (string) (CoreVersionDiskReader::shippedVersion() ?? config('app.version')) : null,
            self::fingerprint($scope, $identifier),
        );
    }

    /**
     * 이 서버 코드의 내용 지문을 구합니다 — 판 번호가 같아도 파일이 다르면 다른 값이다.
     *
     * 확장은 활성 폴더(파일 맞추기 명령과 같은 정의 — vendor·node_modules·.git·custom 제외), 코어는 선언 파일 `config/core.php`.
     *
     * @param  string  $scope  범위 (module|plugin|core)
     * @param  string  $identifier  확장 식별자
     * @return string|null sha256 (구할 수 없으면 null)
     */
    private static function fingerprint(string $scope, string $identifier): ?string
    {
        if ($scope === 'core') {
            $path = config_path('core.php');

            return is_file($path) ? (hash_file('sha256', $path) ?: null) : null;
        }
        if (! in_array($scope, ['module', 'plugin'], true) || $identifier === '') {
            return null;
        }

        $sync = app(ExtensionFileSyncService::class);

        return $sync->contentHash($sync->activePath($scope, $identifier));
    }

    /**
     * 시드 엔티티 정리를 부른 시더를 호출 스택에서 찾습니다 — 소유 확장이 선언한 시더 가운데 가장 바깥 것.
     *
     * 선언 시더가 다른 시더를 불러 그 안에서 정리하면 가장 안쪽은 선언 밖 시더다. 운영 서버는 선언 시더만 다시 돌리므로
     * 선언 목록(`getSeeders()`)에 있는 가장 바깥 프레임을 고른다.
     *
     * @param  string  $scope  소유 범위
     * @param  string  $identifier  소유 식별자
     * @return class-string<Seeder>|null 시더 클래스 (선언 시더 밖이면 null)
     */
    private static function callingSeeder(string $scope, string $identifier): ?string
    {
        if (! in_array($scope, ['module', 'plugin'], true)) {
            return null;
        }

        $extension = $scope === 'module'
            ? app(ModuleManager::class)->getModule($identifier)
            : app(PluginManager::class)->getPlugin($identifier);
        $declared = $extension !== null ? $extension->getSeeders() : [];

        $found = null;
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 60) as $frame) {
            $object = $frame['object'] ?? null;
            if ($object instanceof Seeder && in_array($object::class, $declared, true)) {
                $found = $object::class; // 안쪽 → 바깥 순서라 마지막에 남는 것이 가장 바깥
            }
        }

        return $found;
    }

    /**
     * 대장 행을 사람이 읽는 한 줄로 바꿉니다(예외 메시지용).
     *
     * @param  ExtensionDeferredCleanup  $row  대장 행
     * @return string 범위:식별자 종류 대상
     */
    private static function describe(ExtensionDeferredCleanup $row): string
    {
        return trim("{$row->scope}:{$row->identifier} {$row->kindValue()} {$row->target}");
    }

    /**
     * 헬퍼가 속한 허용 목록의 클래스를 돌려줍니다.
     *
     * @param  object  $helper  헬퍼 인스턴스
     * @param  string  $method  메서드 이름
     * @return class-string 허용 목록의 클래스
     *
     * @throws DeferredCleanupHelperNotAllowedException 허용 목록에 없을 때
     */
    private static function allowedClassOf(object $helper, string $method): string
    {
        foreach (self::ALLOWED as $class => $methods) {
            if ($helper instanceof $class && isset($methods[$method])) {
                return $class;
            }
        }

        throw new DeferredCleanupHelperNotAllowedException($helper::class.'::'.$method);
    }

    /**
     * 메서드마다 정해 둔 인자 자리에서 소유 범위·식별자를 읽습니다 — 읽을 수 없으면 core.
     *
     * @param  string  $resolver  소유 읽기 방식
     * @param  array<int, mixed>  $args  인자
     * @return array{0: string, 1: string} [범위, 식별자]
     */
    private static function owner(string $resolver, array $args): array
    {
        return match ($resolver) {
            self::OWNER_LEADING_ARGS => self::normalizeOwner($args[0] ?? null, $args[1] ?? null),
            self::OWNER_ENTITY_SCOPE => self::entityOwner($args),
            self::OWNER_NOTIFICATION_DEFINITION => self::notificationDefinitionOwner($args[0] ?? null),
            default => ['core', ''],
        };
    }

    /**
     * GenericEntitySyncHelper::cleanupStale(모델, 범위 조건, 키 컬럼, 키 목록, 엔티티 이름, 소유 식별자) 의 소유를 읽습니다.
     *
     * 식별자는 소유 식별자 인자 또는 범위 조건의 `extension_identifier`, 종류는 범위 조건의 `extension_type` 에서 읽는다.
     * 종류가 없으면 설치된 모듈·플러그인 기록으로 정하고, 어느 쪽에도 없으면 core 로 둔다.
     *
     * @param  array<int, mixed>  $args  인자
     * @return array{0: string, 1: string} [범위, 식별자]
     */
    private static function entityOwner(array $args): array
    {
        $scopeFilter = is_array($args[1] ?? null) ? $args[1] : [];
        $identifier = $args[5] ?? $scopeFilter['extension_identifier'] ?? null;
        if (! is_string($identifier) || $identifier === '') {
            return ['core', ''];
        }

        $type = $scopeFilter['extension_type'] ?? null;
        if ($type !== null) {
            return self::normalizeOwner($type, $identifier);
        }

        if (app(ModuleRepositoryInterface::class)->findByIdentifier($identifier) !== null) {
            return ['module', $identifier];
        }
        if (app(PluginRepositoryInterface::class)->findByIdentifier($identifier) !== null) {
            return ['plugin', $identifier];
        }

        return ['core', ''];
    }

    /**
     * 알림 정의 id 의 소유를 읽습니다.
     *
     * @param  mixed  $definitionId  알림 정의 id
     * @return array{0: string, 1: string} [범위, 식별자]
     */
    private static function notificationDefinitionOwner(mixed $definitionId): array
    {
        if (! is_int($definitionId)) {
            return ['core', ''];
        }

        $definition = app(NotificationDefinitionRepositoryInterface::class)->findById($definitionId);

        return $definition === null
            ? ['core', '']
            : self::normalizeOwner($definition->extension_type, $definition->extension_identifier);
    }

    /**
     * 종류·식별자 값을 대장의 [범위, 식별자] 로 맞춥니다 — 모듈·플러그인이 아니면 core.
     *
     * 템플릿은 선언 정리를 두지 않는다 — 템플릿 소유로 읽히는 값도 core 로 둬 코어 재동기화가 맡는다.
     *
     * @param  mixed  $type  종류 (ExtensionOwnerType 또는 문자열)
     * @param  mixed  $identifier  식별자
     * @return array{0: string, 1: string} [범위, 식별자]
     */
    private static function normalizeOwner(mixed $type, mixed $identifier): array
    {
        $type = $type instanceof \BackedEnum ? (string) $type->value : $type;
        if (is_string($type) && in_array($type, ['module', 'plugin'], true) && is_string($identifier) && $identifier !== '') {
            return [$type, $identifier];
        }

        return ['core', ''];
    }
}
