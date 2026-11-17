<?php

namespace App\Services\Extension;

use App\Enums\ExtensionOwnerType;
use App\Extension\Helpers\DeclaredExtensionKeys;
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use App\Extension\Helpers\ExtensionRoleSyncHelper;
use App\Extension\Helpers\ExtensionScheduleSyncHelper;
use App\Extension\Helpers\IdentityPolicySyncHelper;
use App\Extension\Helpers\NotificationSyncHelper;
use App\Extension\HookManager;
use App\Extension\Update\PreviewEntryLoader;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 확장 업데이트가 지울 선언 항목(알림 정의·알림 템플릿·관리자 메뉴·권한·역할·본인확인 정책·스케줄)을 미리 계산합니다.
 *
 * 새 판의 선언 키는 {@see DeclaredExtensionKeys} 가 뽑고(업데이트의 정리가 쓰는 함수와 같다), 지워질 행 판정은 각 헬퍼의
 * `planCleanup()`(실제 `cleanup*()` 와 같은 조회식)이 한다. 쓰기는 하지 않는다. 업데이트 미리보기(API·CLI)가 부른다.
 * 템플릿은 선언형 산출물이 없어 늘 빈 목록이다.
 *
 * 확장 시더의 범용 엔티티 정리(`GenericEntitySyncHelper::cleanupStale()` — 이커머스 클레임 사유·배송유형·택배사)는 여기서
 * 계산하지 않는다 — 업데이트는 확장 시더를 돌리지 않는다(설치·언어팩 켜기/끄기 때 돈다). 업데이트가 지우지 않는 행을 「이
 * 업데이트로 지워질 항목」에 올리면 거짓 안내가 된다.
 */
class ExtensionDeclarativeCleanupPlanner
{
    /**
     * @param  PreviewEntryLoader  $loader  새 판 진입 클래스 로더
     */
    public function __construct(private PreviewEntryLoader $loader) {}

    /**
     * 삭제 예정 목록을 만듭니다.
     *
     * @param  string  $type  확장 종류 (`module`·`plugin`·`template`)
     * @param  string  $identifier  확장 식별자
     * @param  string  $root  새 판 디렉토리
     * @return list<array{type: string, key: string, has_operator_overrides: bool}> 삭제 예정 목록
     */
    public function plan(string $type, string $identifier, string $root): array
    {
        if (! in_array($type, ['module', 'plugin'], true)) {
            return [];
        }

        $extension = $this->loader->load($type, $identifier, $root);
        if ($extension === null) {
            return [];
        }

        $owner = $type === 'module' ? ExtensionOwnerType::Module : ExtensionOwnerType::Plugin;
        $plan = [];

        try {
            if (Schema::hasTable('notification_definitions') && ($types = DeclaredExtensionKeys::notificationTypes($extension)) !== null) {
                $notifications = app(NotificationSyncHelper::class);
                $plan = array_merge($plan, $notifications->planCleanup($type, $identifier, $types));
                // 남는 정의에서 선언이 빠진 발송 수단의 템플릿 — 동기화가 정의마다 cleanupStaleTemplates() 로 지운다.
                $plan = array_merge($plan, $notifications->planTemplateCleanup($type, $identifier, $extension->getNotificationDefinitions()));
            }

            // 메뉴 정리는 모듈 업데이트가 한다(플러그인은 자기 활성화 코드가 맡는다).
            if ($type === 'module' && ($slugs = DeclaredExtensionKeys::menus($extension)) !== null) {
                $plan = array_merge($plan, app(ExtensionMenuSyncHelper::class)->planCleanup($owner, $identifier, $slugs));
            }

            $plan = array_merge($plan, app(ExtensionRoleSyncHelper::class)->planCleanup(
                $owner,
                $identifier,
                DeclaredExtensionKeys::permissions($extension),
                DeclaredExtensionKeys::roles($extension),
            ));

            if (Schema::hasTable('identity_policies') && ($keys = DeclaredExtensionKeys::identityPolicyKeys($extension)) !== null) {
                $plan = array_merge($plan, app(IdentityPolicySyncHelper::class)->planCleanup($type, $identifier, $keys));
            }

            // 스케줄 — 동기화(syncModuleSchedules·syncPluginSchedules)와 같은 순서로 선언을 만들고, 선언이 비면 정리를 건너뛴다(데이터 손실 방어).
            if (Schema::hasTable('schedules')) {
                $schedules = app(ExtensionScheduleSyncHelper::class);
                $definitions = $schedules->normalizeLegacySchedules($extension->getSchedules(), $owner, $identifier);
                $definitions = HookManager::applyFilters("seed.{$identifier}.schedules.translations", $definitions);
                if (is_array($definitions) && $definitions !== []) {
                    $plan = array_merge($plan, $schedules->planCleanup($owner, $identifier, $definitions));
                }
            }
        } catch (\Throwable $e) {
            Log::warning('업데이트 미리보기: 선언 항목 삭제 예정을 계산하지 못했습니다', ['type' => $type, 'identifier' => $identifier, 'error' => $e->getMessage()]);
        }

        return array_values($plan);
    }
}
