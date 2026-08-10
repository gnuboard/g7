<?php

namespace Tests\Feature\Seo;

use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Listeners\NotificationHookListener;
use App\Listeners\SeoNotificationDataListener;
use App\Models\User;
use App\Notifications\GenericNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * 사이트맵 재생성 알림 통합 테스트.
 *
 * 관리자 수동 재생성(triggered_by 존재) 완료/실패 시 실행한 관리자에게만 알림이 발송되고,
 * 스케줄러/증분/봇 재생성(triggered_by null)은 발송되지 않는지 end-to-end 로 검증합니다.
 */
class SitemapRegenerateNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 알림 정의(sitemap_regenerated / _failed) + 동적 훅 구독 + extract_data 필터 등록
        $this->artisan('db:seed', ['--class' => 'NotificationDefinitionSeeder']);
        app(NotificationHookListener::class)->registerDynamicHooks();
        HookListenerRegistrar::register(SeoNotificationDataListener::class, 'core');
    }

    private function completionResult(): array
    {
        return [
            'success' => true,
            'status' => 'updated',
            'data' => ['url_count' => 720, 'child_count' => 1],
        ];
    }

    public function test_manual_completion_notifies_only_triggering_admin(): void
    {
        $admin = User::factory()->create();
        $other = User::factory()->create();
        Notification::fake();

        HookManager::doAction('core.seo.sitemap.after_regenerate', $this->completionResult(), $admin->id);

        Notification::assertSentTo($admin, GenericNotification::class);
        Notification::assertNotSentTo($other, GenericNotification::class);
    }

    public function test_non_manual_completion_notifies_nobody(): void
    {
        User::factory()->create();
        Notification::fake();

        // triggered_by null = 스케줄러/증분/봇 → skip
        HookManager::doAction('core.seo.sitemap.after_regenerate', $this->completionResult(), null);

        Notification::assertNothingSent();
    }

    public function test_manual_final_failure_notifies_triggering_admin(): void
    {
        $admin = User::factory()->create();
        Notification::fake();

        // 최종 실패(재시도 소진) 훅 = Job::failed() 가 발화
        HookManager::doAction('core.seo.sitemap.regenerate_failed_final', [
            'success' => false,
            'status' => 'failed',
            'message' => '디스크 용량 부족',
        ], $admin->id);

        Notification::assertSentTo($admin, GenericNotification::class);
    }

    public function test_per_attempt_failure_hook_does_not_notify(): void
    {
        $admin = User::factory()->create();
        Notification::fake();

        // 매 시도마다 발화하는 per-attempt 훅은 알림을 유발하지 않는다(최종 실패 훅만 구독).
        // → 재시도 중 실패가 반복돼도, 결국 성공해도 실패 알림이 중복/오발송되지 않음.
        HookManager::doAction('core.seo.sitemap.after_regenerate_failed', [
            'success' => false,
            'status' => 'failed',
            'message' => '일시적 오류',
        ], $admin->id);

        Notification::assertNothingSent();
    }

    public function test_job_failed_notifies_triggering_admin_via_final_hook(): void
    {
        $admin = User::factory()->create();
        Notification::fake();

        // Job::failed() (재시도 소진) 가 최종 실패 훅을 발화 → 유발 관리자에게 알림
        (new \App\Jobs\GenerateSitemapJob(\App\Enums\SitemapGenerationMode::Full, $admin->id))
            ->failed(new \RuntimeException('boom'));

        Notification::assertSentTo($admin, GenericNotification::class);
    }

    public function test_job_failed_without_trigger_user_notifies_nobody(): void
    {
        User::factory()->create();
        Notification::fake();

        // 스케줄러/증분 잡(triggeredBy null)의 최종 실패는 알림 없음
        (new \App\Jobs\GenerateSitemapJob(\App\Enums\SitemapGenerationMode::Auto, null))
            ->failed(new \RuntimeException('boom'));

        Notification::assertNothingSent();
    }

    public function test_default_active_channel_is_database_only(): void
    {
        $definition = \App\Models\NotificationDefinition::where('type', 'sitemap_regenerated')->first();

        $this->assertNotNull($definition);
        $this->assertSame(['database'], $definition->channels);

        // mail 템플릿은 존재하되 비활성, database 템플릿은 활성
        $mail = $definition->templates()->where('channel', 'mail')->first();
        $database = $definition->templates()->where('channel', 'database')->first();
        $this->assertNotNull($mail, 'mail 템플릿 행은 존재해야 한다(운영자가 필요 시 활성화)');
        $this->assertFalse((bool) $mail->is_active, 'mail 템플릿은 기본 비활성');
        $this->assertTrue((bool) $database->is_active, 'database 템플릿은 기본 활성');
    }
}
