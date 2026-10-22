<?php

namespace Plugins\Sirsoft\SocialLogin\Services;

use App\Support\TrustedProxyDiagnostic;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Queue;
use Plugins\Sirsoft\SocialLogin\Enums\WebhookStatus;
use Plugins\Sirsoft\SocialLogin\Jobs\ProcessWebhookEventJob;
use Plugins\Sirsoft\SocialLogin\Mock\MockGate;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginProvider;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\AttemptRepositoryInterface;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\ProviderRepositoryInterface;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\SocialAccountRepositoryInterface;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\WebhookEventRepositoryInterface;

/**
 * 진단 화면 요약 — 소셜 로그인이 조용히 실패하는 지점을 한 화면에 모읍니다.
 *
 * 제공자(설정·활성·마지막 성공/실패·실패 코드·웹훅 마지막 수신·자격증명 복호화 실패), 큐(대기 잡·이 플러그인 실패 잡 —
 * 워커가 멈추면 웹훅이 200 을 받고도 연결이 남는다), 메일(발송 수단·최근 인증번호 발송 실패), 모의 제공자(켜져 있으면 만료
 * 시각), 회원(연결 회원·그중 비밀번호 없는 회원). 시도·웹훅 목록은 따로 페이지로 받는다.
 *
 * @since 1.0.0
 */
class DiagnosticsService
{
    /** 최근 집계 기간(일) */
    private const RECENT_DAYS = 7;

    /** 실제로 메일을 보내지 않는 메일러 */
    private const NON_DELIVERING_MAILERS = ['log', 'array'];

    /**
     * @param  ProviderRepositoryInterface  $providers  제공자 저장소
     * @param  SocialAccountRepositoryInterface  $accounts  연결 저장소
     * @param  AttemptRepositoryInterface  $attempts  시도 저장소
     * @param  WebhookEventRepositoryInterface  $webhooks  웹훅 저장소
     * @param  ProviderAdminService  $admin  제공자 관리 (복호화 진단)
     * @param  MockGate  $mock  모의 게이트
     */
    public function __construct(
        private readonly ProviderRepositoryInterface $providers,
        private readonly SocialAccountRepositoryInterface $accounts,
        private readonly AttemptRepositoryInterface $attempts,
        private readonly WebhookEventRepositoryInterface $webhooks,
        private readonly ProviderAdminService $admin,
        private readonly MockGate $mock,
    ) {}

    /**
     * 진단 요약을 돌려줍니다.
     *
     * @return array<string, mixed> providers·queue·mail·mock·members·webhooks
     */
    public function summary(): array
    {
        $since = now()->subDays(self::RECENT_DAYS);
        $lastWebhook = $this->webhooks->lastReceivedByProvider();

        $providers = $this->providers->all()->map(fn (SocialLoginProvider $row) => [
            'key' => $row->key,
            'label' => $row->localizedLabel(),
            'driver' => $row->driver,
            'type' => $row->type,
            'enabled' => (bool) $row->enabled,
            'configured' => $row->client_id !== null && $row->getRawOriginal('client_secret') !== null,
            'credentials_unreadable' => $this->admin->credentialsUnreadable($row),
            'last_success_at' => $row->last_success_at?->toIso8601String(),
            'last_error_at' => $row->last_error_at?->toIso8601String(),
            'last_error_code' => $row->last_error_code,
            'last_webhook_at' => $lastWebhook[$row->key] ?? null,
        ])->values()->all();

        $webhookCounts = $this->webhooks->statusCountsSince($since);
        $mailer = (string) config('mail.default');

        return [
            'providers' => $providers,
            'queue' => [
                'connection' => (string) config('queue.default'),
                'pending' => $this->pendingJobs(),
                'failed_jobs' => $this->webhooks->failedQueueJobCount(ProcessWebhookEventJob::class),
            ],
            'mail' => [
                'mailer' => $mailer,
                'delivering' => ! in_array($mailer, self::NON_DELIVERING_MAILERS, true),
                'recent_code_failures' => $this->attempts->countErrorSince('mail_failed', $since),
            ],
            'mock' => [
                'enabled' => $this->mock->enabled(),
                'expires_at' => ($until = $this->mock->until()) !== null ? date(DATE_ATOM, $until) : null,
            ],
            'members' => $this->accounts->linkedUserCounts(),
            'webhooks' => [
                'days' => self::RECENT_DAYS,
                'received' => array_sum($webhookCounts),
                'ignored' => (int) ($webhookCounts[WebhookStatus::Ignored->value] ?? 0),
                'failed' => (int) ($webhookCounts[WebhookStatus::Failed->value] ?? 0),
                'trusted_proxy_configured' => TrustedProxyDiagnostic::isConfigured(),
            ],
        ];
    }

    /**
     * 시도 기록 페이지를 응답 모양으로 돌려줍니다 (최신순).
     *
     * @param  array<string, mixed>  $filters  provider·outcome·intent·error_code·per_page
     * @return array{data: array<int, array<string, mixed>>, pagination: array<string, int|null>} 행과 페이지 정보
     */
    public function attempts(array $filters): array
    {
        $page = $this->attempts->paginate($filters, (int) ($filters['per_page'] ?? 20));

        return [
            'data' => collect($page->items())->map(fn ($row) => [
                'id' => $row->id,
                'provider_key' => $row->provider_key,
                'user_id' => $row->user_id,
                'intent' => $row->intent,
                'outcome' => $row->outcome,
                'error_code' => $row->error_code,
                'error_message' => $row->error_message,
                'ip' => $row->ip,
                'created_at' => $row->created_at?->toIso8601String(),
            ])->values()->all(),
            'pagination' => $this->pagination($page),
        ];
    }

    /**
     * 웹훅 수신 이력 페이지를 응답 모양으로 돌려줍니다 (최신순, 본문은 서명·암호문을 뺀 요약, 제공자 사용자 ID 는 앞 6자).
     *
     * @param  array<string, mixed>  $filters  provider·status·event_type·per_page
     * @return array{data: array<int, array<string, mixed>>, pagination: array<string, int|null>} 행과 페이지 정보
     */
    public function webhookEvents(array $filters): array
    {
        $page = $this->webhooks->paginate($filters, (int) ($filters['per_page'] ?? 20));

        return [
            'data' => collect($page->items())->map(fn ($row) => [
                'id' => $row->id,
                'provider_key' => $row->provider_key,
                'event_type' => $row->event_type,
                'provider_user_id_prefix' => $row->provider_user_id !== null ? mb_substr($row->provider_user_id, 0, 6) : null,
                'payload' => $row->payload,
                'status' => $row->status,
                'attempts' => $row->attempts,
                'error' => $row->error,
                'received_at' => $row->received_at?->toIso8601String(),
                'processed_at' => $row->processed_at?->toIso8601String(),
            ])->values()->all(),
            'pagination' => $this->pagination($page),
        ];
    }

    /**
     * 표준 페이지 정보를 만듭니다 (`docs/backend/response-helper.md` 「페이지네이션 처리」).
     *
     * @param  LengthAwarePaginator  $page  페이지
     * @return array<string, int|null> current_page·last_page·per_page·total·from·to
     */
    private function pagination(LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'from' => $page->firstItem(),
            'to' => $page->lastItem(),
        ];
    }

    /**
     * 기본 큐의 대기 잡 수 (동기 큐면 0, 셀 수 없으면 null).
     *
     * @return int|null 대기 잡 수
     */
    private function pendingJobs(): ?int
    {
        try {
            return Queue::size('default');
        } catch (\Throwable) {
            return null;
        }
    }
}
