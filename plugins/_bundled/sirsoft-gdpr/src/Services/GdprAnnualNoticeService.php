<?php

namespace Plugins\Sirsoft\Gdpr\Services;

use App\Contracts\Notifications\ChannelReadinessCheckerInterface;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\User;
use App\Notifications\BaseNotification;
use App\Services\NotificationChannelService;
use App\Services\NotificationTemplateService;
use App\Services\PluginSettingsService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Gdpr\Enums\PrivacyNoticeStatus;
use Plugins\Sirsoft\Gdpr\Plugin;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprPrivacyNoticeRepositoryInterface;

/** 연간 통지 접수·결과 기록을 담당합니다. 접수 성공은 발송 완료가 아닙니다. */
class GdprAnnualNoticeService
{
    /**
     * 필요한 서비스를 주입합니다.
     *
     * @param  GdprPrivacyNoticeRepositoryInterface  $notices  통지 서비스 또는 이력 저장소
     * @param  PluginSettingsService  $settings  플러그인 설정 서비스
     */
    public function __construct(
        private readonly GdprPrivacyNoticeRepositoryInterface $notices,
        private readonly PluginSettingsService $settings,
    ) {}

    /**
     * 기본 비활성 게이트와 실행당 회원 수 상한을 적용합니다.
     *
     * @return int 처리 결과
     */
    public function run(): int
    {
        if (! $this->settings->get('sirsoft-gdpr', 'annual_notice.enabled', false)) {
            return 0;
        }

        $limit = max(Plugin::ANNUAL_NOTICE_MIN, min(Plugin::ANNUAL_NOTICE_MAX, (int) $this->settings->get('sirsoft-gdpr', 'annual_notice.batch_size', 500)));
        $count = 0;
        foreach ($this->notices->candidates() as $candidate) {
            if ($this->enqueue($candidate->id) !== null && ++$count >= $limit) {
                break;
            }
        }

        return $count;
    }

    /**
     * 자신의 시험 발송은 가입일·정기 실행 토글과 독립이며 정기 발송일을 바꾸지 않습니다.
     *
     * @param  User  $user  수신 회원
     * @return ?string 처리 결과
     */
    public function sendTest(User $user): ?string
    {
        return $this->enqueue($user->id, true);
    }

    /**
     * 동시 실행은 회원 행 잠금으로 직렬화하고 커밋 후에만 큐 훅을 발행합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  bool  $test  시험 발송 여부
     * @return ?string 처리 결과
     */
    private function enqueue(int $userId, bool $test = false): ?string
    {
        $request = DB::transaction(function () use ($userId, $test) {
            $user = $this->notices->lockUser($userId);
            if (! $user || $user->status !== UserStatus::Active->value) {
                return null;
            }
            if (! $test && $user->created_at->gt(now()->subYearNoOverflow())) {
                return null;
            }

            $channels = array_values(array_intersect(['mail', 'database'], (array) $this->settings->get('sirsoft-gdpr', 'annual_notice.channels', ['mail', 'database'])));
            $channels = $test ? $channels : $this->notices->dueChannels($userId, $channels);
            if ($channels === []) {
                return null;
            }

            $attempt = (string) Str::uuid();
            $locale = BaseNotification::resolveNotifiableLocale($user);
            $sections = HookManager::applyFilters('core.privacy.notice_sections', [], $locale);
            $snapshot = [
                'locale' => $locale,
                'sections' => $sections,
                'introduction' => $this->settings->get('sirsoft-gdpr', 'annual_notice.introduction.'.$locale, ''),
                'privacy_policy_url' => url('/page/'.$this->settings->get('sirsoft-gdpr', 'privacy_policy_slug', 'privacy')),
            ];
            $this->notices->append([
                'user_id' => $userId, 'attempt_id' => $attempt, 'status' => PrivacyNoticeStatus::Queued,
                'channels' => $channels, 'is_test' => $test, 'snapshot' => $snapshot,
            ]);

            return [$user, $attempt];
        });

        if ($request === null) {
            return null;
        }
        [$user, $attempt] = $request;
        HookManager::doAction('sirsoft-gdpr.privacy.annual_notice_due', $user, $attempt);

        return $attempt;
    }

    /**
     * 큐 처리에 사용할 내용과 발송 대상 채널을 복원합니다.
     *
     * @param  array  $extracted  기존 추출 데이터
     * @param  string  $type  알림 유형
     * @param  array  $args  훅 인자
     * @return array 처리 결과
     */
    public function extract(array $extracted, string $type, array $args): array
    {
        if ($type !== 'annual_privacy_notice') {
            return $extracted;
        }
        $user = $args[0] ?? null;
        $attempt = (string) ($args[1] ?? '');
        $log = $this->notices->findAttempt($attempt);
        $pending = $this->notices->pending($attempt);
        if (! $user instanceof User || ! $log || $log->user_id !== $user->id || $user->status !== UserStatus::Active->value || $pending === []) {
            return ['context' => ['skip' => true]];
        }
        // 발송 전에 템플릿·채널 상태를 확인해 건너뛴 접수도 재시도 가능한 결과로 닫습니다.
        $templates = app(NotificationTemplateService::class);
        $channels = app(NotificationChannelService::class);
        $readiness = app(ChannelReadinessCheckerInterface::class);
        foreach ($pending as $channel) {
            if (! $templates->resolve($type, $channel)
                || ! $channels->isChannelAvailable($channel)
                || ! $channels->isChannelEnabledForExtension('plugin', 'sirsoft-gdpr', $channel)
                || ! $readiness->isReady($channel)) {
                $this->recordResult($channel, [
                    'extension_identifier' => 'sirsoft-gdpr', 'notification_type' => $type,
                    'data' => ['privacy_notice_attempt' => $attempt],
                ], PrivacyNoticeStatus::Failed);
            }
        }
        $pending = $this->notices->pending($attempt);
        if ($pending === []) {
            return ['context' => ['skip' => true]];
        }
        $snapshot = $log->snapshot;
        $locale = $snapshot['locale'];
        $sections = '';
        foreach ($snapshot['sections'] as $section) {
            $sections .= '<h3>'.e($section['title'] ?? '').'</h3><p>'.nl2br(e($section['body'] ?? '')).'</p>';
        }

        return [
            'notifiable' => $user,
            'context' => ['trigger_user_id' => $user->id, 'trigger_user' => $user, 'channels' => $pending],
            'data' => [
                'recipient_name' => $user->name,
                'app_name' => config('app.name'),
                'introduction' => e($snapshot['introduction']),
                'collection_summary' => __('sirsoft-gdpr::messages.annual_notice.collection_summary', [], $locale),
                'purpose' => __('sirsoft-gdpr::messages.annual_notice.purpose', [], $locale),
                'retention' => __('sirsoft-gdpr::messages.annual_notice.retention', [], $locale),
                'privacy_policy_url' => $snapshot['privacy_policy_url'],
                'notice_sections' => $sections,
                'privacy_notice_attempt' => $attempt,
            ],
        ];
    }

    /**
     * 실제 채널 결과만 INSERT하며 재전달된 결과는 중복 기록하지 않습니다.
     *
     * @param  string  $channel  결과 채널
     * @param  array  $context  발송 컨텍스트
     * @param  PrivacyNoticeStatus  $status  결과 상태
     * @return void 반환값 없음
     */
    public function recordResult(string $channel, array $context, PrivacyNoticeStatus $status): void
    {
        if (($context['extension_identifier'] ?? '') !== 'sirsoft-gdpr' || ($context['notification_type'] ?? '') !== 'annual_privacy_notice') {
            return;
        }
        $attempt = $context['data']['privacy_notice_attempt'] ?? '';
        $log = $this->notices->findAttempt($attempt);
        if (! $log || $log->user_id === null) {
            return;
        }
        DB::transaction(function () use ($log, $attempt, $channel, $status) {
            $user = $this->notices->lockUser($log->user_id);
            if (! $user || ! in_array($channel, $this->notices->pending($attempt), true)) {
                return;
            }
            $this->notices->append([
                'user_id' => $user->id, 'attempt_id' => $attempt, 'status' => $status,
                'channels' => [$channel], 'is_test' => $log->is_test,
                'sent_at' => $status === PrivacyNoticeStatus::Sent ? now() : null, 'snapshot' => $log->snapshot,
            ]);
        });
    }

    /**
     * 관리자용 발송 이벤트 목록을 반환합니다.
     *
     * @param  int  $perPage  페이지당 행 수
     * @return LengthAwarePaginator 처리 결과
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return $this->notices->paginate($perPage);
    }

    /**
     * 회원 완전 삭제 시 이력 행을 남기고 회원 연결만 끊습니다.
     *
     * @param  int  $userId  회원 ID
     * @return void 반환값 없음
     */
    public function anonymize(int $userId): void
    {
        $this->notices->anonymize($userId);
    }
}
