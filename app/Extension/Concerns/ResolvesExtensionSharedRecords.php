<?php

namespace App\Extension\Concerns;

use App\Contracts\Repositories\IdentityMessageDefinitionRepositoryInterface;
use App\Contracts\Repositories\IdentityPolicyRepositoryInterface;
use App\Contracts\Repositories\MenuRepositoryInterface;
use App\Contracts\Repositories\NotificationDefinitionRepositoryInterface;
use App\Contracts\Repositories\PermissionRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use Illuminate\Support\Facades\Log;

/**
 * 코어 공유 테이블에 적재된 확장(모듈/플러그인) 영역의 데이터 레코드 조회 trait.
 *
 * `ModuleManager::getModuleUninstallInfo()` / `PluginManager::getPluginUninstallInfo()` 가
 * 공통으로 사용. uninstall(deleteData=true) 시 cleanup 되는 영역만 표시 — 사용자가
 * 모달에서 "삭제될 데이터" 를 정확히 파악하도록 함.
 *
 * 감사 성격 데이터(`identity_verification_logs`, `notification_logs` 등) 는 보존되므로
 * 본 목록에 포함하지 않음 — uninstall cleanup 동작과 정합.
 *
 * 새 공유 테이블이 추가되면 sharedRecordResolvers() 메서드에 한 항목만 추가.
 *
 * 모든 데이터 접근은 RepositoryInterface 를 경유 (Service-Repository 패턴 준수).
 *
 * @since 7.0.0-beta.4
 */
trait ResolvesExtensionSharedRecords
{
    /**
     * 코어 공유 테이블 레지스트리.
     *
     * 각 entry 는 `[label_key, resolver]` 2-튜플 — resolver 는 (extensionType, identifier) 받아
     * 해당 확장이 차지한 row 개수를 반환하는 callable. label_key 는 모달 lang 키 매칭용.
     *
     * 신규 공유 테이블 추가 시 본 메서드에만 새 항목 추가.
     *
     * @return array<int, array{0: string, 1: callable(string, string): int}>
     */
    protected function sharedRecordResolvers(): array
    {
        return [
            ['permissions', fn (string $type, string $id): int => app(PermissionRepositoryInterface::class)
                ->getByExtension(ExtensionOwnerType::from($type), $id)
                ->count(),
            ],
            ['menus', fn (string $type, string $id): int => app(MenuRepositoryInterface::class)
                ->getMenusByExtension(ExtensionOwnerType::from($type), $id)
                ->count(),
            ],
            ['notification_definitions', fn (string $type, string $id): int => app(NotificationDefinitionRepositoryInterface::class)
                ->getByExtension($type, $id)
                ->count(),
            ],
            ['identity_policies', fn (string $type, string $id): int => app(IdentityPolicyRepositoryInterface::class)
                ->countBySource($type, $id),
            ],
            ['identity_message_definitions', fn (string $type, string $id): int => app(IdentityMessageDefinitionRepositoryInterface::class)
                ->getByExtension($type, $id)
                ->count(),
            ],
        ];
    }

    /**
     * 코어 공유 테이블에 적재된 확장 영역 레코드 정보를 조회합니다.
     *
     * 0건인 항목은 결과에서 제외 (모달에서 빈 항목 노이즈 회피).
     * Repository/Schema 예외는 경고 로그만 남기고 skip — uninstall 모달이 부분적으로라도
     * 동작하도록 보장 (예: 마이그레이션 미실행 환경).
     *
     * @param  string  $extensionType  'module' 또는 'plugin'
     * @param  string  $extensionIdentifier  확장 식별자
     * @return array<int, array{table: string, label_key: string, count: int}>
     */
    protected function resolveExtensionSharedRecords(string $extensionType, string $extensionIdentifier): array
    {
        $records = [];

        foreach ($this->sharedRecordResolvers() as [$labelKey, $resolver]) {
            try {
                $count = (int) $resolver($extensionType, $extensionIdentifier);
            } catch (\Throwable $e) {
                Log::warning('확장 공유 레코드 조회 실패', [
                    'label' => $labelKey,
                    'extension' => "{$extensionType}/{$extensionIdentifier}",
                    'error' => $e->getMessage(),
                ]);

                continue;
            }
            if ($count === 0) {
                continue;
            }
            $records[] = [
                'table' => $labelKey,
                'label_key' => $labelKey,
                'count' => $count,
            ];
        }

        return $records;
    }
}
