<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\NotificationDeliveryClaim;
use App\Exceptions\RetryHookListenerLaterException;
use App\Extension\HookContextCapture;
use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Models\NotificationDefinition;
use App\Models\User;
use App\Notifications\DeliveryReport;
use App\Notifications\GenericNotification;
use App\Notifications\GuestNotifiable;
use App\Services\NotificationDefinitionService;
use App\Services\NotificationDeliveryGuard;
use App\Services\NotificationLogService;
use App\Services\NotificationRecipientResolver;
use App\Services\NotificationWithdrawnRecipientPolicy;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 알림 훅 리스너
 *
 * 코어 훅 이벤트 발생 시 알림 정의를 조회하여 GenericNotification을 발송합니다.
 */
class NotificationHookListener implements HookListenerInterface
{
    /**
     * 발송 잡의 큐 옵션 (#139).
     *
     * 다른 사본이 보내는 중인 수신자를 기다리느라 잡을 미루는 release 도 시도 1회를 쓰므로 3 → 5 로 늘린다.
     *
     * @var array<string, mixed>
     */
    public const QUEUE_OPTIONS = ['tries' => 5];

    /**
     * 필요한 서비스를 주입합니다.
     *
     * @param  NotificationDefinitionService  $definitionService  활성 알림 정의 조회 서비스
     */
    public function __construct(
        private readonly NotificationDefinitionService $definitionService,
    ) {}

    /**
     * 구독할 훅 목록을 반환합니다.
     *
     * DB 기반 동적 구독이므로 정적 메서드에서는 빈 배열을 반환하고,
     * boot 시점에 registerDynamicHooks()로 동적 구독합니다.
     *
     * @return array<string, array<string, mixed>> 빈 배열 (동적 등록)
     */
    public static function getSubscribedHooks(): array
    {
        return [];
    }

    /**
     * 기본 핸들러 (미사용 — 동적 핸들러에서 처리).
     *
     * @param  mixed  ...$args
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * notification_definitions에서 정의된 훅을 동적으로 구독합니다.
     *
     * ServiceProvider boot() 시점에 호출됩니다.
     *
     * @return void 반환값 없음
     */
    public function registerDynamicHooks(): void
    {
        // 설치 완료 상태에서는 Schema introspection 을 건너뜀 (매 요청 information_schema 쿼리 제거).
        // 인스톨러 이전/마이그레이션 전 환경에서는 기존 hasTable 폴백으로 안전하게 스킵.
        if (! config('app.installer_completed') && ! Schema::hasTable('notification_definitions')) {
            return;
        }

        try {
            $definitions = $this->definitionService->getAllActive();
        } catch (\Throwable $e) {
            Log::warning('NotificationHookListener: 알림 정의 로드 실패', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($definitions as $definition) {
            $hooks = $definition->hooks ?? [];
            foreach ($hooks as $hook) {
                // 발송을 큐로 디스패치한다 — 다른 코어/모듈 리스너(getSubscribedHooks → HookListenerRegistrar)
                // 가 큐로 가는 것과 동일한 정책. 직접 발송하면 요청 스레드에서 동기 실행되어, 수신자가
                // 많을 때(예: admin role 전체) 호출 요청(주문 생성 등)이 전체 발송 완료까지 막힌다.
                // 동적 구독은 정적 매핑으로 표현할 수 없으므로 Registrar 의 동적 진입점에 위임한다 —
                // 큐 래핑 로직(DispatchHookListenerJob/직렬화/컨텍스트 캡처)의 소유권을 Registrar 에
                // 유지하여 정적/동적 등록을 일관되게 만든다. 큐 드라이버가 sync 면 즉시 실행(하위호환).
                // $definition 을 boundArgs 로 고정 전달 → 워커가 dispatchForDefinition($definition, ...$args) 호출.
                HookListenerRegistrar::registerDynamicAction(
                    hookName: $hook,
                    listenerClass: self::class,
                    method: 'dispatchForDefinition',
                    boundArgs: [$definition],
                    priority: 30,
                    queueOptions: self::QUEUE_OPTIONS,
                );
            }
        }
    }

    /**
     * 큐 워커에서 호출되는 발송 진입점.
     *
     * registerDynamicHooks 가 등록한 큐 작업이 (NotificationDefinition $definition, ...$hookArgs)
     * 형태로 복원해 호출한다. 원래 훅 인자($hookArgs)를 배열로 모아 dispatch() 에 위임한다.
     *
     * @param  NotificationDefinition  $definition  알림 정의 (큐 직렬화 후 PK 로 복원됨)
     * @param  mixed  ...$hookArgs  원래 훅 발화 시 전달된 인자들
     * @return void 반환값 없음
     */
    public function dispatchForDefinition(NotificationDefinition $definition, ...$hookArgs): void
    {
        $this->dispatch($definition, $hookArgs);
    }

    /**
     * 훅 발화 시 알림을 발송합니다.
     *
     * 수신자 결정 우선순위:
     * 1. definition.recipients 설정 → NotificationRecipientResolver 사용
     * 2. extract_data 필터의 notifiables 배열
     * 3. extract_data 필터의 단일 notifiable (레거시 호환)
     *
     * @param  NotificationDefinition  $definition  알림 정의
     * @param  array  $args  훅 파라미터
     * @return void 반환값 없음
     */
    private function dispatch(NotificationDefinition $definition, array $args): void
    {
        $extracted = HookManager::applyFilters(
            "{$definition->hook_prefix}.notification.extract_data",
            ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []],
            $definition->type,
            $args
        );

        $data = $extracted['data'] ?? [];
        $context = $extracted['context'] ?? [];

        // Listener 가 명시적으로 skip 을 요청한 경우 발송 중단
        // (e.g. 모듈 환경설정의 notify_* 플래그가 OFF 일 때, 템플릿 recipients 기반 해석이
        //  Listener 의 정책 gate 를 우회하는 문제 해결 — 2026-04-24 sirsoft-board report_policy)
        if (! empty($context['skip'])) {
            return;
        }

        // 활성 템플릿을 순회하며 채널별 독립 발송
        $templates = $definition->templates()->where('is_active', true)->get();
        if ($templates->isEmpty()) {
            return;
        }

        $resolver = app(NotificationRecipientResolver::class);
        // 재시도·재예약된 사본도 같은 값 — 수신자별 중복 방지 키의 첫 성분 (#139)
        $eventId = HookContextCapture::eventId() ?? (string) Str::uuid();
        $chunkSize = max(1, (int) config('notification.fanout.chunk_size', 100));

        foreach ($templates as $template) {
            // 발행자가 채널을 제한한 알림은 해당 채널의 템플릿만 사용합니다.
            // 키 부재는 기존 전체 채널 동작, 빈 배열은 발송하지 않음을 뜻합니다.
            if (isset($context['channels']) && ! in_array($template->channel, $context['channels'], true)) {
                continue;
            }

            // 채널별 수신자 결정: template.recipients 사용
            $recipientRules = $template->recipients ?? [];
            $refs = null;
            $notifiables = null;

            if (! empty($recipientRules)) {
                // 회원은 id 만 뽑는다 — 전 회원 역할이어도 모델·권한을 한꺼번에 싣지 않는다 (#139)
                $refs = $resolver->resolveRefs($recipientRules, $context);
                if ($refs === null) {
                    $notifiables = $resolver->resolve($recipientRules, $context);
                }
            }
            // 레거시 fallback: extract_data의 notifiables
            elseif (! empty($extracted['notifiables'])) {
                $notifiables = collect($extracted['notifiables']);
            }
            // 레거시 fallback: 단일 notifiable
            elseif ($extracted['notifiable']) {
                $notifiables = collect([$extracted['notifiable']]);
            } else {
                continue;
            }

            // 레거시 객체도 참조로 바꿀 수 있으면 같은 분할 경로를 탄다. 못 바꾸면(User·게스트가 아닌 객체) 종전 인라인.
            if ($refs === null && $notifiables !== null) {
                $refs = $resolver->toRecipientRefs($notifiables);
            }

            if (($refs ?? $notifiables->all()) === []) {
                // 수신자 0명은 "조용히" 사라지면 안 된다 — 운영자가 발송 이력 화면에서
                // 왜 안 갔는지를 볼 수 있게 skipped 로 남긴다.
                $this->logNoRecipient($definition, $template->channel, $recipientRules, $context);

                continue;
            }

            if ($refs !== null && count($refs) > $chunkSize) {
                // 묶음마다 자식 잡 — 잡 하나가 retry_after 를 넘겨 다시 예약돼도 다시 도는 범위가 묶음 하나로 줄고,
                // 각 수신자는 중복 방지 키로 한 번만 간다. 자식 잡에는 참조 배열만 싣는다(게스트 객체는 직렬화되지 않는다).
                foreach (array_chunk($refs, $chunkSize) as $chunk) {
                    HookListenerRegistrar::dispatchQueued(
                        self::class,
                        'dispatchChunk',
                        [$definition, (int) $template->id, $eventId, $chunk, $data, $context, $args],
                        self::QUEUE_OPTIONS,
                        "notification.fanout.{$definition->type}",
                    );
                }

                continue;
            }

            $this->sendToRecipients(
                $definition,
                $template->channel,
                $refs !== null ? $this->rehydrateRecipients($refs) : $notifiables->all(),
                $data,
                $context,
                $args,
                $eventId,
            );
        }
    }

    /**
     * 알림 대량 발송의 자식 잡 진입점 — 수신자 묶음 하나를 보냅니다 (#139).
     *
     * 부모 잡(dispatch)이 수신자 참조를 묶음으로 나눠 `HookListenerRegistrar::dispatchQueued()` 로 띄운다.
     * 이벤트 ID 는 부모의 값이라 같은 묶음이 두 번 전달되거나 다시 예약돼도 수신자마다 한 번만 간다.
     *
     * @param  NotificationDefinition  $definition  알림 정의 (큐 직렬화 후 PK 로 복원됨)
     * @param  int  $templateId  보낼 템플릿 ID
     * @param  string  $eventId  부모 잡의 이벤트 ID
     * @param  array<int, array<string, mixed>>  $refs  이 묶음의 수신자 참조
     * @param  array  $data  extract_data 의 알림 데이터
     * @param  array  $context  extract_data 의 컨텍스트 (큐 직렬화 — 모델이 아닌 객체는 null)
     * @param  array  $hookArgs  원래 훅 인자 (큐 직렬화 — 모델이 아닌 객체는 null)
     * @return void 반환값 없음
     */
    public function dispatchChunk(
        NotificationDefinition $definition,
        int $templateId,
        string $eventId,
        array $refs,
        array $data,
        array $context,
        array $hookArgs,
    ): void {
        // 그 사이 템플릿이 꺼졌거나 지워졌으면 보내지 않는다 — 부모가 띄울 때의 판정을 믿지 않는다.
        $template = $definition->templates()->where('id', $templateId)->where('is_active', true)->first();
        if ($template === null) {
            return;
        }

        $this->sendToRecipients(
            $definition,
            $template->channel,
            $this->rehydrateRecipients($refs),
            $data,
            $context,
            $hookArgs,
            $eventId,
        );
    }

    /**
     * 수신자 참조를 발송할 수 있는 객체로 다시 만듭니다 (#139).
     *
     * 회원은 id 순으로 한 번에 싣고(역할·권한은 좁혀서), 그 사이 사라진 회원은 info 로그만 남기고 건너뛴다.
     * 비회원은 표준 guest_recipient 배열에서 다시 만든다.
     *
     * @param  array<int, array<string, mixed>>  $refs  수신자 참조
     * @return array<int, object> 수신자 객체 (참조 순서 유지)
     */
    private function rehydrateRecipients(array $refs): array
    {
        $userIds = [];
        foreach ($refs as $ref) {
            if (($ref['type'] ?? null) === 'user' && isset($ref['id'])) {
                $userIds[] = (int) $ref['id'];
            }
        }

        $users = $userIds === []
            ? []
            : app(UserRepositoryInterface::class)->findNotifiablesByIds($userIds)->keyBy('id')->all();

        $recipients = [];
        foreach ($refs as $ref) {
            if (($ref['type'] ?? null) === 'user') {
                $user = $users[(int) ($ref['id'] ?? 0)] ?? null;
                if ($user === null) {
                    Log::info('NotificationHookListener: 발송 직전에 사라진 회원을 건너뜁니다', ['user_id' => $ref['id'] ?? null]);

                    continue;
                }

                $recipients[] = $user;
            } elseif (($ref['type'] ?? null) === 'guest' && is_array($ref['guest'] ?? null)) {
                $guest = GuestNotifiable::fromContext($ref['guest']);
                if ($guest !== null) {
                    $recipients[] = $guest;
                }
            }
        }

        return $recipients;
    }

    /**
     * 수신자들에게 한 채널로 보냅니다 — 수신자마다 중복 방지 선점을 거칩니다 (#139).
     *
     * 수신자 키를 만들 수 있으면 보내기 전에 선점하고(NotificationDeliveryGuard), 이미 끝난 수신자는 건너뛰고,
     * 다른 사본이 보내는 중이면 보류한다. 발송 결과는 채널별 결과 보고서로 판정한다 — 디스패처가 채널 예외를
     * 삼키므로 notify() 가 끝났다고 보낸 것이 아니다. 실패면 선점을 풀어 재시도가 다시 보내게 한다.
     * 보류한 수신자는 루프 끝에 한 번 더 보고, 그래도 다른 사본이 처리 중이면 잡을 리스 시간 뒤로 미룬다.
     *
     * @param  NotificationDefinition  $definition  알림 정의
     * @param  string  $channel  채널
     * @param  iterable<int, object>  $recipients  수신자 (User·GuestNotifiable·그 밖의 notifiable)
     * @param  array  $data  알림 데이터
     * @param  array  $context  extract_data 컨텍스트
     * @param  array  $args  원래 훅 인자
     * @param  string  $eventId  이벤트 ID
     * @return void 반환값 없음
     *
     * @throws RetryHookListenerLaterException 다른 사본이 아직 보내는 수신자가 남았을 때
     */
    private function sendToRecipients(
        NotificationDefinition $definition,
        string $channel,
        iterable $recipients,
        array $data,
        array $context,
        array $args,
        string $eventId,
    ): void {
        $guard = app(NotificationDeliveryGuard::class);
        $deferred = [];

        foreach ($recipients as $notifiable) {
            if ($this->sendToRecipient($guard, $definition, $channel, $notifiable, $data, $context, $args, $eventId) === NotificationDeliveryClaim::InFlight) {
                $deferred[] = $notifiable;
            }
        }

        if ($deferred === []) {
            return;
        }

        // 다른 사본이 끝냈으면 Done, 죽어서 리스가 지났으면 넘겨받아 보낸다(누락 방지).
        $stillInFlight = 0;
        foreach ($deferred as $notifiable) {
            if ($this->sendToRecipient($guard, $definition, $channel, $notifiable, $data, $context, $args, $eventId) === NotificationDeliveryClaim::InFlight) {
                $stillInFlight++;
            }
        }

        if ($stillInFlight > 0) {
            throw new RetryHookListenerLaterException(
                $guard->leaseSeconds() + 5,
                "다른 사본이 보내는 중인 수신자 {$stillInFlight}명이 남아 잡을 미룹니다 ({$definition->type}/{$channel})",
            );
        }
    }

    /**
     * 수신자 한 명에게 보냅니다.
     *
     * @param  NotificationDeliveryGuard  $guard  중복 방지
     * @param  NotificationDefinition  $definition  알림 정의
     * @param  string  $channel  채널
     * @param  object  $notifiable  수신자
     * @param  array  $data  알림 데이터
     * @param  array  $context  extract_data 컨텍스트
     * @param  array  $args  원래 훅 인자
     * @param  string  $eventId  이벤트 ID
     * @return NotificationDeliveryClaim|null 선점 결과 (중복 방지 대상이 아니면 null)
     */
    private function sendToRecipient(
        NotificationDeliveryGuard $guard,
        NotificationDefinition $definition,
        string $channel,
        object $notifiable,
        array $data,
        array $context,
        array $args,
        string $eventId,
    ): ?NotificationDeliveryClaim {
        // 키를 못 만드는 수신자(모델이 아닌 임시 객체)는 종전처럼 중복 방지 없이 보낸다.
        $recipientKey = $guard->recipientKey($notifiable);
        $claim = null;

        if ($recipientKey !== null) {
            $claim = $guard->claim($eventId, $definition->type, $channel, $recipientKey);
            if ($claim !== NotificationDeliveryClaim::Acquired) {
                return $claim;
            }
        }

        // 탈퇴 회원에게 바깥 채널로 보내지 않는다 — 조용히 거르지는 않는다. 재실행돼도 기록은 한 번이다.
        if ($this->shouldSkipWithdrawnRecipient($notifiable, $channel)) {
            $this->logWithdrawnRecipient($definition, $channel, $notifiable);
            if ($recipientKey !== null) {
                $guard->markSkipped($eventId, $definition->type, $channel, $recipientKey);
            }

            return $claim;
        }

        try {
            $notifiableData = $this->personalize($data, $notifiable, $definition, $context, $args);

            $report = new DeliveryReport;
            $notification = (new GenericNotification(
                type: $definition->type,
                hookPrefix: $definition->hook_prefix,
                data: $notifiableData,
                extensionType: $definition->extension_type,
                extensionIdentifier: $definition->extension_identifier,
                channel: $channel,
            ))->withDeliveryReport($report);

            $notifiable->notify($notification);

            if ($recipientKey !== null) {
                match ($report->statusFor($channel)) {
                    // 보내지 못했다 — 선점을 풀어 재시도가 다시 보내게 한다(누락 방지 우선).
                    DeliveryReport::FAILED => $guard->release($eventId, $definition->type, $channel, $recipientKey),
                    DeliveryReport::SKIPPED => $guard->markSkipped($eventId, $definition->type, $channel, $recipientKey),
                    default => $guard->markSent($eventId, $definition->type, $channel, $recipientKey),
                };
            }

            // 실제로 나간 값(수신자별 치환 결과)을 넘긴다 — 원본 $data 를 넘기면
            // 로그 연결 리스너가 수신자가 받은 것과 다른 값을 본다.
            HookManager::doAction('core.notification.after_send', $notifiable, $definition, $notifiableData);
        } catch (\Throwable $e) {
            if ($recipientKey !== null) {
                $guard->release($eventId, $definition->type, $channel, $recipientKey);
            }

            Log::error('NotificationHookListener: 알림 발송 실패', [
                'type' => $definition->type,
                'channel' => $channel,
                'notifiable' => $notifiable->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        return $claim;
    }

    /**
     * 수신자별로 알림 데이터를 개인화합니다.
     *
     * 코어는 `{recipient_name}` 치환 하나만 소유하고, 그 밖의 수신자별 값
     * (예: 수신자가 운영자면 관리자 화면 링크)은 확장이
     * `{hook_prefix}.notification.personalize_data` 필터로 채웁니다.
     *
     * **구독자가 없으면 비용이 0 입니다** — `applyFilters` 가 즉시 원본을 돌려줍니다.
     *
     * > ⚠️ 구독자 계약: 이 필터는 **큐 워커에서** 돌 수 있으므로 `$context` 의 스칼라만
     * > 읽어야 합니다. `$args` 의 모델을 다시 읽으면 과거 사건의 알림에 "지금" 상태가 실립니다.
     *
     * @param  array  $data  원본 알림 데이터
     * @param  object  $notifiable  수신자 (User 또는 GuestNotifiable)
     * @param  NotificationDefinition  $definition  알림 정의
     * @param  array  $context  extract_data 가 실은 컨텍스트
     * @param  array  $args  원래 훅 인자
     * @return array 수신자별로 치환된 알림 데이터
     */
    private function personalize(
        array $data,
        object $notifiable,
        NotificationDefinition $definition,
        array $context,
        array $args,
    ): array {
        if (isset($data['name']) && $data['name'] === '{recipient_name}') {
            $data['name'] = $notifiable->name ?? '';
        }

        $personalized = HookManager::applyFilters(
            "{$definition->hook_prefix}.notification.personalize_data",
            $data,
            $notifiable,
            $definition->type,
            $context,
            $args
        );

        return is_array($personalized) ? $personalized : $data;
    }

    /**
     * 탈퇴 회원에게 **바깥 채널**로 보내지 않아야 하는지 판정합니다.
     *
     * 판정은 {@see NotificationWithdrawnRecipientPolicy::blocksChannel()} 한 곳이 소유합니다 —
     * 수신자 해석(삭제 회원의 게스트 폴백 금지)과 같은 정책 객체를 써서 두 경로가 갈라지지
     * 않게 합니다.
     *
     * 탈퇴 처리는 이름·이메일·닉네임을 익명화합니다(`…@example.test_deleted_20260921_1733`).
     * 그 주소는 더 이상 존재하지 않는 우편함이므로, 그대로 보내면 운영에서는 매번 바운스가
     * 나고 발송 평판이 깎입니다(#599 세션A 실측 2026-09-22 — 탈퇴 회원의 `refund_approved`
     * 4행이 익명화된 주소로 생성됨. 그 환경은 SMTP 가 없어 가려졌을 뿐입니다).
     *
     * `database`(알림함)는 막지 않습니다 — 바깥으로 나가지 않고, 관리자가 탈퇴 회원의
     * 이력을 화면에서 보는 근거가 됩니다.
     *
     * 회원이 아닌 수신자(비회원 주문 알림의 임시 notifiable 등)는 탈퇴 개념이 없으므로
     * 판정 대상이 아닙니다.
     *
     * @param  mixed  $notifiable  수신자
     * @param  string  $channel  채널
     * @return bool 발송을 건너뛰면 true
     */
    private function shouldSkipWithdrawnRecipient(mixed $notifiable, string $channel): bool
    {
        if (! is_object($notifiable)) {
            return false;
        }

        return app(NotificationWithdrawnRecipientPolicy::class)->blocksChannel($notifiable, $channel);
    }

    /**
     * 탈퇴 회원 제외를 발송 이력에 skipped 로 기록합니다.
     *
     * 수신자 0명과 같은 규율입니다 — **조용한 것은 의도가 아닙니다.** 운영자가 발송 이력에서
     * "왜 안 갔지" 를 답할 수 있어야 합니다.
     *
     * @param  NotificationDefinition  $definition  알림 정의
     * @param  string  $channel  채널
     * @param  User  $user  탈퇴 회원
     * @return void 반환값 없음
     */
    private function logWithdrawnRecipient(
        NotificationDefinition $definition,
        string $channel,
        User $user,
    ): void {
        try {
            app(NotificationLogService::class)->logSkipped([
                'channel' => $channel,
                'notification_type' => $definition->type,
                'extension_type' => $definition->extension_type,
                'extension_identifier' => $definition->extension_identifier,
                'recipient_user_id' => $user->id,
                'recipient_identifier' => (string) ($user->email ?? ''),
                'error_message' => __('notification.skipped.withdrawn_recipient'),
                'source' => 'notification',
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotificationHookListener: 탈퇴 회원 제외 로그 기록 실패', [
                'type' => $definition->type,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 수신자 0명을 발송 이력에 skipped 로 기록합니다.
     *
     * 빈 이메일·유발자 제외로 수신자가 사라지는 것은 의도된 의미일 수 있지만,
     * **조용한 것은 의도가 아닙니다.** 운영자가 "왜 안 왔지" 를 이력에서 답할 수 있게 합니다.
     *
     * @param  NotificationDefinition  $definition  알림 정의
     * @param  string  $channel  채널
     * @param  array  $recipientRules  적용된 수신자 규칙 (요약만 남긴다)
     * @param  array  $context  extract_data 가 실은 컨텍스트 (사유 판정용)
     * @return void
     */
    private function logNoRecipient(
        NotificationDefinition $definition,
        string $channel,
        array $recipientRules,
        array $context,
    ): void {
        try {
            app(NotificationLogService::class)->logSkipped([
                'channel' => $channel,
                'notification_type' => $definition->type,
                'extension_type' => $definition->extension_type,
                'extension_identifier' => $definition->extension_identifier,
                'recipient_identifier' => '',
                'error_message' => __(
                    'notification.skipped.'.$this->resolveNoRecipientReason($recipientRules, $context),
                    [
                        'rules' => $this->summarizeRecipientRules($recipientRules),
                        'rule_names' => $this->describeRecipientRules($definition, $recipientRules),
                    ]
                ),
                'source' => 'notification',
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotificationHookListener: 수신자 0명 로그 기록 실패', [
                'type' => $definition->type,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 수신자 0명의 사유 키를 고릅니다.
     *
     * 비회원 수신자 경로에서 이메일이 비어 있는 것(`guest_email_empty`), 지목된 회원의
     * 계정이 삭제된 것(`member_account_missing`), 규칙이 아무도 뽑지 못한 것
     * (`no_recipient_resolved`)은 운영자가 취할 조치가 다릅니다 — 첫째는 주문의 연락처를
     * 보고, 둘째는 조치할 것이 없으며(삭제 회원은 다른 주소로 대신 보내지 않는다), 셋째는
     * 템플릿의 수신자 규칙을 봐야 합니다.
     *
     * @param  array  $recipientRules  수신자 규칙 배열
     * @param  array  $context  컨텍스트
     * @return string 사유 키 (`notification.skipped.*` 하위)
     */
    private function resolveNoRecipientReason(array $recipientRules, array $context): string
    {
        $hasTriggerUserRule = collect($recipientRules)
            ->contains(fn ($rule) => ($rule['type'] ?? null) === 'trigger_user');

        if (! $hasTriggerUserRule) {
            return 'no_recipient_resolved';
        }

        $policy = app(NotificationWithdrawnRecipientPolicy::class);

        if ($policy->isTriggerMemberMissing($context)) {
            return 'member_account_missing';
        }

        if ($policy->namesMember($context)) {
            return 'no_recipient_resolved';
        }

        $guest = $context['guest_recipient'] ?? null;
        if (is_array($guest) && trim((string) ($guest['email'] ?? '')) === '') {
            return 'guest_email_empty';
        }

        return 'no_recipient_resolved';
    }

    /**
     * 수신자 규칙을 한 줄 요약으로 만듭니다 (개인정보는 싣지 않는다).
     *
     * @param  array  $recipientRules  수신자 규칙 배열
     * @return string 예: `role:admin, trigger_user`
     */
    private function summarizeRecipientRules(array $recipientRules): string
    {
        if (empty($recipientRules)) {
            return 'legacy_notifiables';
        }

        $summary = [];
        foreach ($recipientRules as $rule) {
            $type = (string) ($rule['type'] ?? 'unknown');
            $value = $rule['value'] ?? null;

            // specific_users 의 UUID 목록은 개인 식별자이므로 개수만 남긴다.
            if ($type === 'specific_users') {
                $summary[] = $type.':'.(is_array($value) ? count($value) : 0);

                continue;
            }

            if ($type === 'related_user') {
                $relation = (string) ($rule['relation'] ?? '');
                $value = $relation === '' ? null : $relation;
            }

            $line = is_array($value)
                ? $type.':'.implode('|', array_map('strval', $value))
                : ($value === null || $value === '' ? $type : $type.':'.$value);

            $summary[] = empty($rule['exclude_trigger_user']) ? $line : $line.'+exclude_trigger_user';
        }

        return implode(', ', $summary);
    }

    /**
     * 수신자 규칙을 운영자가 읽을 말로 적습니다 (개인정보는 싣지 않는다).
     *
     * 규칙 코드(`related_user`)만으로는 어떤 관계인지·이벤트 유발자를 뺐는지 알 수 없어, 자기 글에
     * 자기가 댓글을 달아 0명이 된 경우처럼 원인이 「유발자 제외」 인 건너뜀을 읽지 못한다.
     *
     * @param  NotificationDefinition  $definition  알림 정의 (관계 이름 출처)
     * @param  array  $recipientRules  수신자 규칙 배열
     * @return string 예: `관련 사용자(게시글 작성자) — 이벤트 유발자 제외`
     */
    private function describeRecipientRules(NotificationDefinition $definition, array $recipientRules): string
    {
        if (empty($recipientRules)) {
            return __('notification.recipient_rules.legacy');
        }

        $names = [];
        foreach ($recipientRules as $rule) {
            $type = (string) ($rule['type'] ?? '');
            $value = $rule['value'] ?? null;

            $name = match ($type) {
                'trigger_user' => __('notification.recipient_rules.trigger_user'),
                'related_user' => __('notification.recipient_rules.related_user', [
                    'relation' => $definition->relationLabel((string) ($rule['relation'] ?? '')),
                ]),
                'role' => __('notification.recipient_rules.role', [
                    'roles' => $this->describeRoles(array_map('strval', is_array($value) ? $value : [$value])),
                ]),
                'specific_users' => __('notification.recipient_rules.specific_users', [
                    'count' => is_array($value) ? count($value) : 0,
                ]),
                default => $type,
            };

            $names[] = empty($rule['exclude_trigger_user'])
                ? $name
                : __('notification.recipient_rules.exclude_trigger_user', ['rule' => $name]);
        }

        return implode(' / ', $names);
    }

    /**
     * 역할 식별자를 운영자가 아는 역할 이름으로 바꿉니다 — 지워진 역할은 식별자를 그대로 둡니다.
     *
     * 식별자(`admin`)는 규칙 코드에 그대로 남으므로 사람용 이름에는 역할 관리 화면과 같은 이름을 쓴다.
     *
     * @param  array<int, string>  $identifiers  역할 식별자 목록
     * @return string 쉼표로 이은 역할 이름
     */
    private function describeRoles(array $identifiers): string
    {
        $identifiers = array_values(array_filter($identifiers, fn (string $id) => $id !== ''));
        if ($identifiers === []) {
            return '';
        }

        try {
            $names = app(RoleRepositoryInterface::class)->getByIdentifiers($identifiers)
                ->mapWithKeys(fn ($role) => [$role->identifier => $role->getLocalizedName()])
                ->all();
        } catch (\Throwable) {
            // 사유 문장은 부가 정보다 — 이름 조회가 실패해도 건너뜀 기록 자체는 남겨야 한다.
            $names = [];
        }

        return implode(', ', array_map(
            fn (string $id) => ($names[$id] ?? '') !== '' ? $names[$id] : $id,
            $identifiers,
        ));
    }
}
