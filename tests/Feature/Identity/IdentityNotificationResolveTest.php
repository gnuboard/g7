<?php

namespace Tests\Feature\Identity;

use App\Models\NotificationDefinition;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Notifications\Identity\IdentityNotificationType;
use App\Services\IdentityMessageDispatcher;
use App\Services\NotificationDefinitionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * 본인인증 알림 해석 계약 테스트 (#699 — 옛 IdentityMessageResolverTest 이식)
 *
 * 옛 Resolver 의 다섯 계약(목적 정의 선택·목적 비활성 시 기본 폴백·전부 비활성 시 실패·템플릿 비활성 시
 * 실패·정책 정의 우선)이 알림 type 후보 해석에서 그대로 성립하는지, 실제로 기록된 발송 로그의
 * notification_type 으로 확인한다.
 */
class IdentityNotificationResolveTest extends TestCase
{
    use CapturesSentMail;
    use DatabaseTransactions;

    /**
     * 본인인증 알림 정의를 시딩하고 메일 채널을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->prepareIdentityNotifications();
    }

    /**
     * 정책이 없으면 목적 정의로 해석된다.
     *
     * @scenario surface=resolution
     *
     * @effects purpose_definition_used
     *
     * @return void 반환값 없음
     */
    public function test_resolves_to_purpose_definition_when_no_policy(): void
    {
        $this->assertSame('identity.purpose.signup', $this->resolvedType('signup', null));
    }

    /**
     * 목적 정의가 비활성이면 기본 정의로 내려간다.
     *
     * @scenario surface=resolution
     *
     * @effects inactive_candidate_falls_through
     *
     * @return void 반환값 없음
     */
    public function test_falls_back_to_default_when_purpose_inactive(): void
    {
        NotificationDefinition::where('type', 'identity.purpose.signup')->update(['is_active' => false]);
        $this->flush();

        $this->assertSame('identity.default', $this->resolvedType('signup', null));
    }

    /**
     * 본인인증 정의가 하나도 활성이 아니면 해석에 실패한다(발송 0).
     *
     * @scenario surface=resolution
     *
     * @effects unresolved_returns_false
     *
     * @return void 반환값 없음
     */
    public function test_returns_null_when_no_definition_active(): void
    {
        NotificationDefinition::where('category', IdentityNotificationType::CATEGORY)->update(['is_active' => false]);
        $this->flush();

        $this->assertNull($this->resolvedType('signup', null));
    }

    /**
     * 템플릿이 모두 비활성이면 해석에 실패한다.
     *
     * @scenario surface=resolution
     *
     * @effects unresolved_returns_false
     *
     * @return void 반환값 없음
     */
    public function test_returns_null_when_template_inactive(): void
    {
        NotificationTemplate::query()
            ->whereHas('definition', fn ($q) => $q->where('category', IdentityNotificationType::CATEGORY))
            ->update(['is_active' => false]);
        $this->flush();

        $this->assertNull($this->resolvedType('signup', null));
    }

    /**
     * 정책 정의가 목적 정의보다 우선한다.
     *
     * @scenario surface=resolution
     *
     * @effects policy_definition_preferred
     *
     * @return void 반환값 없음
     */
    public function test_policy_definition_overrides_purpose(): void
    {
        $definition = NotificationDefinition::create([
            'type' => IdentityNotificationType::forPolicy('core.account.withdraw'),
            'hook_prefix' => 'core.identity',
            'extension_type' => 'core',
            'extension_identifier' => 'admin',
            'category' => IdentityNotificationType::CATEGORY,
            'name' => ['ko' => '탈퇴 확인', 'en' => 'Withdraw'],
            'variables' => IdentityNotificationType::commonVariables(),
            'channels' => ['mail'],
            'hooks' => [],
            'is_active' => true,
            'is_default' => false,
        ]);
        NotificationTemplate::create([
            'definition_id' => $definition->id,
            'channel' => 'mail',
            'subject' => ['ko' => '탈퇴 확인 {code}', 'en' => 'Withdraw {code}'],
            'body' => ['ko' => '<p>{code}</p>', 'en' => '<p>{code}</p>'],
            'is_active' => true,
            'is_default' => false,
        ]);
        $this->flush();

        $this->assertSame('identity.policy.core.account.withdraw', $this->resolvedType('sensitive_action', 'core.account.withdraw'));
    }

    /**
     * 디스패처로 보내고, 채택된 정의의 type 을 발송 로그에서 읽습니다.
     *
     * @param  string  $purpose  인증 목적
     * @param  string|null  $policyKey  정책 키
     * @return string|null 발송된 type (해석 실패면 null)
     */
    private function resolvedType(string $purpose, ?string $policyKey): ?string
    {
        $before = (int) NotificationLog::max('id');

        $sent = app(IdentityMessageDispatcher::class)->dispatch(
            providerId: 'g7:core.mail',
            purpose: $purpose,
            policyKey: $policyKey,
            renderHint: 'text_code',
            channel: 'mail',
            target: 'resolve@example.test',
            data: ['code' => '111111', 'expire_minutes' => 10],
        );

        $log = NotificationLog::where('id', '>', $before)->where('channel', 'mail')->first();

        if (! $sent) {
            $this->assertNull($log, '해석에 실패하면 발송 로그도 남지 않아야 한다.');

            return null;
        }

        return $log?->notification_type;
    }

    /**
     * 알림 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    private function flush(): void
    {
        app(NotificationDefinitionService::class)->invalidateAllCache();
    }
}
