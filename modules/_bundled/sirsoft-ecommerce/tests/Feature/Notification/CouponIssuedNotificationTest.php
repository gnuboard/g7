<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Notification;

use App\Extension\HookManager;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Notifications\GenericNotification;
use App\Services\NotificationDefinitionService;
use Illuminate\Support\Facades\Notification;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueSource;
use Modules\Sirsoft\Ecommerce\Listeners\EcommerceNotificationDataListener;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Services\CouponIssueNotifier;
use Modules\Sirsoft\Ecommerce\Services\CouponNotificationDefinitionService;
use Modules\Sirsoft\Ecommerce\Services\CouponService;
use Modules\Sirsoft\Ecommerce\Support\MarketingConsentGate;
use Modules\Sirsoft\Ecommerce\Tests\Feature\Coupon\AutoIssue\Concerns\CreatesAutoIssueCoupons;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Tests\Helpers\MocksChannelReadiness;

/**
 * 쿠폰별 발급 알림
 */
class CouponIssuedNotificationTest extends ModuleTestCase
{
    use CreatesAutoIssueCoupons;
    use MocksChannelReadiness;

    /**
     * 정의 서비스를 반환합니다.
     *
     * @return CouponNotificationDefinitionService 서비스
     */
    private function definitions(): CouponNotificationDefinitionService
    {
        return app(CouponNotificationDefinitionService::class);
    }

    /**
     * 쿠폰 발급 알림 데이터를 뽑습니다.
     *
     * @param  Coupon  $coupon  쿠폰
     * @param  CouponIssue  $issue  발급 건
     * @return array 추출 결과
     */
    private function extract(Coupon $coupon, CouponIssue $issue): array
    {
        return app(EcommerceNotificationDataListener::class)->extractData(
            ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []],
            $coupon->notificationType(),
            [$issue->user, $issue->fresh(['coupon']), CouponIssueSource::SIGNUP->value],
        );
    }

    /**
     * 발급 건을 만듭니다.
     *
     * @param  Coupon  $coupon  쿠폰
     * @return CouponIssue 발급 건
     */
    private function issue(Coupon $coupon): CouponIssue
    {
        $user = $this->createUser();

        return CouponIssue::query()->create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'coupon_code' => 'AU-'.uniqid(),
            'status' => 'available',
            'issued_at' => now(),
            'expired_at' => now()->addDays(30),
            'issue_source' => CouponIssueSource::SIGNUP->value,
        ]);
    }

    /**
     * 동의 조회 필터를 걸고 콜백을 실행합니다.
     *
     * @param  array<string, bool>  $consents  동의 키 => 동의 여부
     * @param  callable  $callback  실행할 코드
     * @return mixed 콜백 결과
     */
    private function withConsents(array $consents, callable $callback): mixed
    {
        $filter = fn ($value, $userId, $key) => $consents[$key] ?? null;
        HookManager::addFilter(MarketingConsentGate::FILTER, $filter, 5);

        try {
            return $callback();
        } finally {
            HookManager::removeFilter(MarketingConsentGate::FILTER, $filter);
        }
    }

    /**
     * 알림을 켠 쿠폰을 저장하면 정의와 템플릿이 생긴다 — 사이트 알림은 켜지고 메일은 꺼진다. 알림을 켠 적 없는 쿠폰은 정의가 없다.
     *
     * @scenario case=sync_create
     *
     * @effects coupon_notification_definition_created
     *
     * @return void 반환값 없음
     */
    public function test_enabling_creates_definition_with_mail_off_and_database_on(): void
    {
        $silent = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP, ['notify_enabled' => false]);
        $this->assertNull($this->definitions()->sync($silent));
        $this->assertFalse(NotificationDefinition::query()->where('type', $silent->notificationType())->exists());

        $coupon = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP, ['notify_enabled' => true]);
        $definition = $this->definitions()->sync($coupon);

        $this->assertNotNull($definition);
        $this->assertTrue((bool) $definition->is_active);
        $this->assertSame([$coupon->notificationHook()], $definition->hooks);
        $templates = NotificationTemplate::query()->where('definition_id', $definition->id)->get()->keyBy('channel');
        $this->assertFalse((bool) $templates['mail']->is_active);
        $this->assertTrue((bool) $templates['database']->is_active);
    }

    /**
     * 쿠폰명이 바뀌면 정의 이름만 갱신되고, 운영자가 고친 템플릿 문구는 남는다. 끄면 비활성, 다시 켜면 같은 문구로 돌아온다.
     *
     * @scenario case=sync_update_toggle
     *
     * @effects coupon_notification_definition_preserves_overrides
     *
     * @return void 반환값 없음
     */
    public function test_rename_preserves_overrides_and_toggle_keeps_text(): void
    {
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP, ['notify_enabled' => true]);
        $definition = $this->definitions()->sync($coupon);
        $mail = NotificationTemplate::query()->where('definition_id', $definition->id)->where('channel', 'mail')->first();
        $mail->update(['subject' => ['ko' => '운영자 제목', 'en' => 'Operator subject']]);

        $coupon->update(['name' => ['ko' => '새 이름 쿠폰', 'en' => 'Renamed']]);
        $definition = $this->definitions()->sync($coupon->fresh());
        $this->assertStringContainsString('새 이름 쿠폰', (string) ($definition->name['ko'] ?? ''));
        $this->assertSame('운영자 제목', $mail->fresh()->subject['ko']);

        $coupon->update(['notify_enabled' => false]);
        $this->assertFalse((bool) $this->definitions()->sync($coupon->fresh())->is_active);
        $this->assertSame('운영자 제목', $mail->fresh()->subject['ko'], '끄더라도 문구는 남는다');

        $coupon->update(['notify_enabled' => true]);
        $this->assertTrue((bool) $this->definitions()->sync($coupon->fresh())->is_active);
        $this->assertSame('운영자 제목', $mail->fresh()->subject['ko']);
    }

    /**
     * 쿠폰을 지우면 정의와 템플릿이 지워지고, 코어 표준 삭제 훅이 발화한다(채널 플러그인 정리용).
     *
     * @scenario case=remove
     *
     * @effects coupon_notification_definition_removed
     *
     * @return void 반환값 없음
     */
    public function test_delete_removes_definition_templates_and_fires_hook(): void
    {
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP, ['notify_enabled' => true]);
        $definition = $this->definitions()->sync($coupon);
        $fired = [];
        $listener = function ($deleted) use (&$fired) {
            $fired[] = $deleted->type;
        };
        HookManager::addAction('core.notification_definition.after_delete', $listener, 5);

        try {
            $this->actingAs($this->createAdminUser(['sirsoft-ecommerce.promotion-coupon.delete']));
            app(CouponService::class)->deleteCoupon($coupon->id);
        } finally {
            HookManager::removeAction('core.notification_definition.after_delete', $listener);
        }

        $this->assertFalse(NotificationDefinition::query()->whereKey($definition->id)->exists());
        $this->assertSame(0, NotificationTemplate::query()->where('definition_id', $definition->id)->count());
        $this->assertSame([$coupon->notificationType()], $fired);
    }

    /**
     * 모듈 선언에 쿠폰 정의가 포함돼, 확장 업데이트의 「선언에 없는 정의 정리」 가 지우지 않는다. 끈 쿠폰도 정의가 있으면 선언된다.
     *
     * @scenario case=module_declaration
     *
     * @effects coupon_notification_definition_survives_update
     *
     * @return void 반환값 없음
     */
    public function test_declared_definitions_include_existing_coupon_definitions(): void
    {
        $on = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP, ['notify_enabled' => true]);
        $off = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP, ['notify_enabled' => true]);
        $this->definitions()->sync($on);
        $this->definitions()->sync($off);
        $off->update(['notify_enabled' => false]);
        $this->definitions()->sync($off->fresh());

        $types = array_column($this->definitions()->declaredDefinitions(), 'type');

        $this->assertContains($on->notificationType(), $types);
        $this->assertContains($off->notificationType(), $types);
    }

    /**
     * 발급 알림 훅은 쿠폰마다 따로다 — 회원이 받은 다운로드 쿠폰과 알림을 끈 쿠폰은 발화하지 않는다.
     *
     * @scenario case=per_coupon_hook
     *
     * @effects coupon_notification_hook_per_coupon
     *
     * @return void 반환값 없음
     */
    public function test_notifier_fires_only_the_coupon_hook(): void
    {
        $a = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP, ['notify_enabled' => true]);
        $b = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP, ['notify_enabled' => true]);
        $off = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP, ['notify_enabled' => false]);
        $calls = ['a' => 0, 'b' => 0];
        $la = function () use (&$calls) {
            $calls['a']++;
        };
        $lb = function () use (&$calls) {
            $calls['b']++;
        };
        HookManager::addAction($a->notificationHook(), $la, 5);
        HookManager::addAction($b->notificationHook(), $lb, 5);

        try {
            $notifier = app(CouponIssueNotifier::class);
            $this->assertSame(1, $notifier->notify($this->issue($a), CouponIssueSource::SIGNUP));
            $this->assertSame(0, $notifier->notify($this->issue($a), CouponIssueSource::DOWNLOAD));
            $this->assertSame(0, $notifier->notify($this->issue($off), CouponIssueSource::DIRECT));
        } finally {
            HookManager::removeAction($a->notificationHook(), $la);
            HookManager::removeAction($b->notificationHook(), $lb);
        }

        $this->assertSame(['a' => 1, 'b' => 0], $calls);
    }

    /**
     * 광고성 쿠폰은 미동의 회원에게 사이트 알림만, 동의하면 메일도(메일은 이메일 수신 동의까지), 마케팅 플러그인이 없으면 미동의로 본다.
     * 「(광고) 」 표시는 광고성일 때만 붙는다. 광고성이 아니면 채널을 제한하지 않는다.
     *
     * @scenario case=marketing_gate
     *
     * @effects coupon_notification_marketing_consent
     *
     * @return void 반환값 없음
     */
    public function test_marketing_only_coupon_respects_consent(): void
    {
        $ad = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP, ['notify_enabled' => true, 'notify_marketing_only' => true]);
        $plain = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP, ['notify_enabled' => true, 'notify_marketing_only' => false]);
        $adIssue = $this->issue($ad);

        // 마케팅 플러그인 응답 없음(null) = 미동의
        $none = $this->extract($ad, $adIssue);
        $this->assertSame(['database'], $none['context']['channels']);
        $this->assertNotSame('', $none['data']['ad_prefix']);

        $marketingOnly = $this->withConsents(['marketing_consent' => true], fn () => $this->extract($ad, $adIssue));
        $this->assertNotContains('mail', $marketingOnly['context']['channels'], '메일은 이메일 수신 동의도 있어야 한다');
        $this->assertContains('sms', $marketingOnly['context']['channels']);

        $both = $this->withConsents(['marketing_consent' => true, 'email_subscription' => true], fn () => $this->extract($ad, $adIssue));
        $this->assertContains('mail', $both['context']['channels']);

        $plainResult = $this->extract($plain, $this->issue($plain));
        $this->assertArrayNotHasKey('channels', $plainResult['context']);
        $this->assertSame('', $plainResult['data']['ad_prefix']);
    }

    /**
     * 직접발급은 그 자리에서 발급 알림 훅을 발화한다.
     *
     * @scenario case=direct_issue
     *
     * @effects coupon_notification_hook_per_coupon
     *
     * @return void 반환값 없음
     */
    public function test_direct_issue_fires_notification_hook(): void
    {
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::MANUAL, [
            'issue_method' => \Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod::DIRECT,
            'notify_enabled' => true,
        ]);
        $user = $this->createUser();
        $sources = [];
        $listener = function ($u, $issue, $source) use (&$sources) {
            $sources[] = $source;
        };
        HookManager::addAction($coupon->notificationHook(), $listener, 5);

        try {
            $this->actingAs($this->createAdminUser(['sirsoft-ecommerce.promotion-coupon.update']));
            app(CouponService::class)->issueDirectly($coupon->id, [$user->id]);
        } finally {
            HookManager::removeAction($coupon->notificationHook(), $listener);
        }

        $this->assertSame(['direct'], $sources);
    }

    /**
     * 커머스 알림 설정 목록(코어 정의 목록)에는 쿠폰별 정의가 빠지고, include_hidden 이면 나온다.
     *
     * @scenario case=hidden_in_list
     *
     * @effects coupon_notification_hidden_from_list
     *
     * @return void 반환값 없음
     */
    public function test_coupon_types_are_hidden_from_definition_list(): void
    {
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP, ['notify_enabled' => true]);
        $this->definitions()->sync($coupon);
        $service = app(NotificationDefinitionService::class);

        $visible = collect($service->getDefinitions(['extension_identifier' => 'sirsoft-ecommerce'], 500)->items())->pluck('type');
        $all = collect($service->getDefinitions(['extension_identifier' => 'sirsoft-ecommerce', 'include_hidden' => true], 500)->items())->pluck('type');

        $this->assertNotContains($coupon->notificationType(), $visible);
        $this->assertContains($coupon->notificationType(), $all);
    }

    /**
     * 정의 목록이 캐시돼 있어도 알림을 켜서 저장하면 캐시가 비워지고, 같은 요청 안에서 그 쿠폰의 발급 훅이 등록돼 이어지는 발급이 바로 알림을 보낸다.
     *
     * @scenario case=cache_invalidated_same_request
     *
     * @effects coupon_notification_definition_created
     *
     * @return void 반환값 없음
     */
    public function test_sync_invalidates_cache_and_registers_hook_in_same_request(): void
    {
        $this->resetRequestRegistrations();
        Notification::fake();
        $definitionService = app(NotificationDefinitionService::class);
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP, ['notify_enabled' => true]);

        // 켜기 전의 정의 목록을 캐시에 올려 둔다
        $this->assertNotContains($coupon->notificationType(), $definitionService->getAllActive()->pluck('type'));

        $this->definitions()->sync($coupon);

        $this->assertContains($coupon->notificationType(), $definitionService->getAllActive()->pluck('type'), '캐시가 비워져 새 정의가 보인다');
        $this->assertArrayHasKey($coupon->notificationHook(), HookManager::getHooks(), '같은 요청에서 발급 훅이 등록된다');

        $issue = $this->issue($coupon);
        app(CouponIssueNotifier::class)->notify($issue, CouponIssueSource::SIGNUP);

        Notification::assertSentTo($issue->user, GenericNotification::class);
    }

    /**
     * 템플릿을 끈 채널로는 보내지 않는다 — 기본(메일 꺼짐)이면 사이트 알림만, 메일 템플릿을 켜면 메일도 함께 보낸다.
     *
     * @scenario case=inactive_channel_not_sent
     *
     * @effects coupon_notification_hook_per_coupon
     *
     * @return void 반환값 없음
     */
    public function test_inactive_template_channel_is_not_sent(): void
    {
        $this->resetRequestRegistrations();
        // 메일 발송 준비(설정)는 이 시험의 축이 아니다 — 준비됐다고 두고 템플릿 켜짐만 가른다
        $this->mockAllChannelsReady();
        Notification::fake();
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP, ['notify_enabled' => true]);
        $definition = $this->definitions()->sync($coupon);
        $notifier = app(CouponIssueNotifier::class);

        $first = $this->issue($coupon);
        $notifier->notify($first, CouponIssueSource::SIGNUP);
        // 채널마다 따로 보낸다 — 사이트 알림은 가고, 꺼진 메일은 가지 않는다
        Notification::assertSentTo($first->user, GenericNotification::class, fn ($n, array $channels) => in_array('database', $channels, true));
        Notification::assertNotSentTo($first->user, GenericNotification::class, fn ($n, array $channels) => in_array('mail', $channels, true));

        NotificationTemplate::query()->where('definition_id', $definition->id)->where('channel', 'mail')->update(['is_active' => true]);
        app(NotificationDefinitionService::class)->invalidateAllCache();

        $second = $this->issue($coupon);
        $notifier->notify($second, CouponIssueSource::SIGNUP);
        Notification::assertSentTo($second->user, GenericNotification::class, fn ($n, array $channels) => in_array('mail', $channels, true));
        Notification::assertSentTo($second->user, GenericNotification::class, fn ($n, array $channels) => in_array('database', $channels, true));
    }

    /**
     * 「이 요청에서 이미 등록함」 기록을 비웁니다 — 테스트마다 DB 가 새로 시작해 쿠폰 ID 가 겹칠 수 있어서다.
     *
     * @return void 반환값 없음
     */
    private function resetRequestRegistrations(): void
    {
        $property = new \ReflectionProperty(CouponNotificationDefinitionService::class, 'registeredInRequest');
        $property->setValue(null, []);
    }
}
