<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 확장 lifecycle 메일 템플릿 관리 리스너
 *
 * 모듈 설치/삭제/활성화/비활성화 시 메일 템플릿 캐시를 무효화하고,
 * 설치 시 시더가 존재하면 자동 실행합니다.
 */
class MailTemplateExtensionListener implements HookListenerInterface
{
    /**
     * 모듈별 메일 템플릿 시더 매핑
     *
     * @var array<string, class-string>
     */
    private const MODULE_SEEDERS = [
        'sirsoft-board' => \Modules\Sirsoft\Board\Database\Seeders\BoardMailTemplateSeeder::class,
        'sirsoft-ecommerce' => \Modules\Sirsoft\Ecommerce\Database\Seeders\EcommerceMailTemplateSeeder::class,
    ];

    /**
     * 모듈별 메일 템플릿 테이블명 매핑
     *
     * @var array<string, string>
     */
    private const MODULE_TABLES = [
        'sirsoft-board' => 'board_mail_templates',
        'sirsoft-ecommerce' => 'ecommerce_mail_templates',
    ];

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.modules.after_install' => ['method' => 'handleAfterInstall', 'priority' => 20],
            'core.modules.after_uninstall' => ['method' => 'handleAfterUninstall', 'priority' => 20],
            'core.modules.after_activate' => ['method' => 'handleAfterActivate', 'priority' => 20],
            'core.modules.after_deactivate' => ['method' => 'handleAfterDeactivate', 'priority' => 20],
        ];
    }

    /**
     * 훅 이벤트 처리 (기본 핸들러)
     *
     * @param mixed ...$args 훅에서 전달된 인수들
     * @return void
     */
    public function handle(...$args): void
    {
        // 기본 핸들러는 사용하지 않음 (개별 메서드로 라우팅됨)
    }

    /**
     * 모듈 설치 후 메일 템플릿 시더를 실행합니다.
     *
     * @param string $moduleName 모듈 식별자
     * @param array|null $moduleInfo 모듈 정보
     * @return void
     */
    public function handleAfterInstall(string $moduleName, ?array $moduleInfo = null): void
    {
        if (! isset(self::MODULE_SEEDERS[$moduleName])) {
            return;
        }

        $seederClass = self::MODULE_SEEDERS[$moduleName];
        $tableName = self::MODULE_TABLES[$moduleName] ?? null;

        // 시더 클래스 존재 여부 확인
        if (! class_exists($seederClass)) {
            Log::warning('메일 템플릿 시더 클래스 미발견', [
                'module' => $moduleName,
                'seeder' => $seederClass,
            ]);

            return;
        }

        // 테이블 존재 여부 확인
        if ($tableName && ! Schema::hasTable($tableName)) {
            Log::info('메일 템플릿 테이블 미존재 — 시더 스킵', [
                'module' => $moduleName,
                'table' => $tableName,
            ]);

            return;
        }

        try {
            $seeder = app($seederClass);
            $seeder->run();

            Log::info('모듈 설치 후 메일 템플릿 시더 실행 완료', [
                'module' => $moduleName,
            ]);
        } catch (\Throwable $e) {
            Log::error('모듈 설치 후 메일 템플릿 시더 실행 실패', [
                'module' => $moduleName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 모듈 삭제 후 메일 템플릿 캐시를 무효화합니다.
     *
     * @param string $moduleName 모듈 식별자
     * @param array|null $moduleInfo 모듈 정보
     * @param bool $deleteData 데이터 삭제 여부
     * @return void
     */
    public function handleAfterUninstall(string $moduleName, ?array $moduleInfo = null, bool $deleteData = false): void
    {
        $this->invalidateModuleMailTemplateCache($moduleName);

        Log::info('모듈 삭제 후 메일 템플릿 캐시 무효화 완료', [
            'module' => $moduleName,
            'deleteData' => $deleteData,
        ]);
    }

    /**
     * 모듈 활성화 후 메일 템플릿 캐시를 무효화합니다.
     *
     * @param string $moduleName 모듈 식별자
     * @param array|null $moduleInfo 모듈 정보
     * @return void
     */
    public function handleAfterActivate(string $moduleName, ?array $moduleInfo = null): void
    {
        $this->invalidateModuleMailTemplateCache($moduleName);
    }

    /**
     * 모듈 비활성화 후 메일 템플릿 캐시를 무효화합니다.
     *
     * @param string $moduleName 모듈 식별자
     * @param array|null $moduleInfo 모듈 정보
     * @return void
     */
    public function handleAfterDeactivate(string $moduleName, ?array $moduleInfo = null): void
    {
        $this->invalidateModuleMailTemplateCache($moduleName);
    }

    /**
     * 모듈의 메일 템플릿 캐시를 무효화합니다.
     *
     * @param string $moduleName 모듈 식별자
     * @return void
     */
    private function invalidateModuleMailTemplateCache(string $moduleName): void
    {
        $cachePrefix = "mail_template:{$moduleName}:";

        try {
            // 패턴 기반 캐시 삭제 (개별 타입별 캐시 키)
            if (method_exists(Cache::getStore(), 'flush')) {
                // 태그 기반 캐시가 아닌 경우, 알려진 타입의 캐시만 삭제
                $types = $this->getKnownTemplateTypes($moduleName);

                foreach ($types as $type) {
                    Cache::forget($cachePrefix . $type);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('메일 템플릿 캐시 무효화 실패', [
                'module' => $moduleName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 모듈의 알려진 메일 템플릿 타입 목록을 반환합니다.
     *
     * @param string $moduleName 모듈 식별자
     * @return array<string> 템플릿 타입 목록
     */
    private function getKnownTemplateTypes(string $moduleName): array
    {
        return match ($moduleName) {
            'sirsoft-board' => ['new_comment', 'reply_comment', 'post_reply', 'post_action', 'new_post_admin'],
            'sirsoft-ecommerce' => ['order_confirmed', 'order_shipped', 'order_completed', 'order_cancelled', 'new_order_admin'],
            default => [],
        };
    }
}
