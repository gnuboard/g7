<?php

namespace Modules\Sirsoft\Board\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use App\Models\Permission;

/**
 * v0.6.0 업그레이드 스텝
 *
 * - 게시판 동적 권한에 스코프 메타데이터(resource_route_key, owner_key) 백필
 */
class Upgrade_0_6_0 implements UpgradeStepInterface
{
    /**
     * 스코프 메타데이터 매핑 (권한 키 패턴 → resource_route_key, owner_key)
     *
     * @var array<string, array{resource_route_key: string, owner_key: string}>
     */
    private const SCOPE_METADATA = [
        'posts.read' => ['resource_route_key' => 'post', 'owner_key' => 'user_id'],
        'posts.read-secret' => ['resource_route_key' => 'post', 'owner_key' => 'user_id'],
        'comments.read' => ['resource_route_key' => 'comment', 'owner_key' => 'user_id'],
        'attachments.upload' => ['resource_route_key' => 'attachment', 'owner_key' => 'created_by'],
        'attachments.download' => ['resource_route_key' => 'attachment', 'owner_key' => 'created_by'],
        'manager' => ['resource_route_key' => 'board', 'owner_key' => 'created_by'],
        'admin.posts.read' => ['resource_route_key' => 'post', 'owner_key' => 'user_id'],
        'admin.posts.read-secret' => ['resource_route_key' => 'post', 'owner_key' => 'user_id'],
        'admin.comments.read' => ['resource_route_key' => 'comment', 'owner_key' => 'user_id'],
        'admin.attachments.upload' => ['resource_route_key' => 'attachment', 'owner_key' => 'created_by'],
        'admin.attachments.download' => ['resource_route_key' => 'attachment', 'owner_key' => 'created_by'],
        'admin.manage' => ['resource_route_key' => 'board', 'owner_key' => 'created_by'],
    ];

    /**
     * 업그레이드를 실행합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        $this->backfillPermissionScopeMetadata($context);
    }

    /**
     * 기존 게시판 동적 권한에 스코프 메타데이터를 백필합니다.
     *
     * 패턴 매칭: sirsoft-board.{slug}.{action} 형식의 identifier를 찾아
     * resource_route_key/owner_key를 일괄 업데이트합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    private function backfillPermissionScopeMetadata(UpgradeContext $context): void
    {
        $updated = 0;

        foreach (self::SCOPE_METADATA as $actionSuffix => $metadata) {
            // sirsoft-board.%.{action} 패턴으로 검색
            // admin.* 권한: sirsoft-board.{slug}.admin.posts.read
            // user 권한: sirsoft-board.{slug}.posts.read
            $pattern = "sirsoft-board.%.{$actionSuffix}";

            $count = Permission::where('identifier', 'LIKE', $pattern)
                ->where('extension_identifier', 'sirsoft-board')
                ->whereNull('resource_route_key')
                ->update([
                    'resource_route_key' => $metadata['resource_route_key'],
                    'owner_key' => $metadata['owner_key'],
                ]);

            $updated += $count;
        }

        $context->logger->info("[v0.6.0] 게시판 동적 권한 스코프 메타데이터 백필 완료: {$updated}건 업데이트");
    }
}
