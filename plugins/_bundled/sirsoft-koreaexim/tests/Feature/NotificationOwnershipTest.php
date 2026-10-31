<?php

namespace Plugins\Sirsoft\Koreaexim\Tests\Feature;

use App\Extension\Helpers\NotificationSyncHelper;
use App\Extension\HookListenerRegistrar;
use Plugins\Sirsoft\Koreaexim\Listeners\UpdateFailedNotificationDataListener;
use Plugins\Sirsoft\Koreaexim\Plugin;
use Plugins\Sirsoft\Koreaexim\Tests\PluginTestCase;

class NotificationOwnershipTest extends PluginTestCase
{
    /**
     * 알림 권한과 확장 소유권 조합별 조회 및 변경 경계를 검증합니다.
     *
     * @effects notification_owner_filter, notification_override_preserved
     *
     * @return void 반환값 없음
     */
    public function test_notification_ownership_matrix(): void
    {
        HookListenerRegistrar::register(UpdateFailedNotificationDataListener::class);
        $this->actingAs($this->createAdminUser(['core.settings.read', 'core.settings.update']));
        $helper = app(NotificationSyncHelper::class);
        $metadata = (new Plugin)->getNotificationDefinitions()[0];
        $definition = $helper->syncDefinition($metadata + ['extension_type' => 'plugin', 'extension_identifier' => 'sirsoft-koreaexim']);
        foreach ($metadata['templates'] as $data) {
            $helper->syncTemplate($definition->id, $data);
        }
        $template = $definition->templates()->where('channel', 'mail')->firstOrFail();
        $templateUrl = '/api/admin/notification-templates/'.$template->id;
        $resetUrl = '/api/admin/notification-definitions/'.$definition->id.'/reset';
        $cases = require dirname(__DIR__).'/fixtures/OwnershipCases.php';
        foreach ($cases as [$state,$screen,$action,$filter]) {
            $this->postJson($resetUrl)->assertOk();
            if (! $template->fresh()->is_active) {
                $this->patchJson($templateUrl.'/toggle-active')->assertOk();
            }
            if ($state === 'user_overridden') {
                $this->putJson($templateUrl, ['subject' => ['ko' => '운영자 수정', 'en' => 'Operator subject'], 'body' => ['ko' => '운영자 본문', 'en' => 'Operator body']])->assertOk();
            } elseif ($state === 'toggled_off') {
                $this->patchJson($templateUrl.'/toggle-active')->assertOk();
            }
            $before = $template->fresh();
            $helper->syncTemplate($definition->id, $metadata['templates'][0]);
            $this->assertSame($before->subject, $template->fresh()->subject);
            $this->assertSame($before->is_active, $template->fresh()->is_active);
            $query = ['template_channel' => 'mail', 'per_page' => 100];
            if ($screen === 'plugin_tab' || $filter === 'other') {
                $query += ['extension_type' => 'plugin', 'extension_identifier' => $filter === 'own' ? 'sirsoft-koreaexim' : 'fixture-other'];
            }
            $rows = $this->getJson('/api/admin/notification-definitions?'.http_build_query($query))->assertOk()->json('data.data');
            $owned = collect($rows)->firstWhere('id', $definition->id);
            if ($filter === 'other') {
                $this->assertNull($owned);

                continue;
            }
            $this->assertNotNull($owned);
            if ($screen === 'plugin_tab') {
                $this->assertCount(1, $rows);
            }
            if ($action === 'toggle') {
                $this->patchJson($templateUrl.'/toggle-active')->assertOk();
                $this->assertSame(! $before->is_active, $template->fresh()->is_active);
            } elseif ($action === 'edit_template') {
                $this->putJson($templateUrl, ['subject' => ['ko' => '공통 수정', 'en' => 'Shared subject'], 'body' => ['ko' => '공통 본문', 'en' => 'Shared body']])->assertOk();
                $this->assertSame('공통 수정', $template->fresh()->subject['ko']);
            } else {
                $this->postJson($resetUrl)->assertOk();
                $this->assertSame($metadata['templates'][0]['subject']['ko'], $template->fresh()->subject['ko']);
            }
        }
    }

    /**
     * 코어 알림 탭의 실제 조회 결과에서 파생해 문서 서술이 사실과 일치하는지 검증합니다.
     *
     * @effects notification_owner_filter
     *
     * @return void 반환값 없음
     */
    public function test_extension_points_doc_matches_core_tab_visibility(): void
    {
        $this->actingAs($this->createAdminUser(['core.settings.read']));
        $helper = app(NotificationSyncHelper::class);
        $metadata = (new Plugin)->getNotificationDefinitions()[0];
        $definition = $helper->syncDefinition($metadata + ['extension_type' => 'plugin', 'extension_identifier' => 'sirsoft-koreaexim']);
        // 코어 환경설정 알림 탭과 같은 호출 — 확장 파라미터 없이 조회한다.
        $rows = $this->getJson('/api/admin/notification-definitions?per_page=100')->assertOk()->json('data.data');
        $visibleInCoreTab = collect($rows)->firstWhere('id', $definition->id) !== null;

        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/extension-points.md');
        $intent = (string) preg_replace('/^.*<!-- @intent START -->/s', '', $doc);
        $intent = (string) preg_replace('/<!-- @intent END -->.*$/s', '', $intent);

        $this->assertTrue($visibleInCoreTab, '코어 알림 탭 조회에 플러그인 정의가 포함되어야 합니다.');
        // 위 실측이 참이므로 문서는 「코어 정의만 표시」로 적을 수 없다.
        $this->assertStringNotContainsString('코어 소유 정의만 표시', $intent);
        $this->assertStringContainsString('이 정의도 함께 표시', $intent);
    }
}
