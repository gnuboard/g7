<?php

namespace Plugins\Sirsoft\Gdpr\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\Consent\ConsentManager;
use App\Extension\Consent\ConsentSubject;
use Plugins\Sirsoft\Gdpr\Consent\GdprConsentProvider;
use Plugins\Sirsoft\Gdpr\Enums\ConsentAction;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprUserConsentHistoryRepositoryInterface;

/**
 * 이 플러그인의 동의 변경을 코어 범용 액션 `core.consent.changed` 로 중계합니다 (#639, 결정 D11)
 *
 * 코어는 이 플러그인의 훅 이름을 모릅니다. `sirsoft-gdpr.consent.granted`·`revoked` 를 받아 쿠키 동의 키
 * (`cookie_{카테고리}`)만 카테고리 이름으로 바꿔 모으고, 요청이 끝날 때 주체마다 한 번만 알립니다 — 배너의
 * 「모두 거부」 한 번이 항목 수만큼 알림이 되면 철회 진단 카운터가 부풀기 때문입니다. 쿠키가 아닌 동의(약관 등)는
 * 무시합니다.
 *
 * 주체는 훅 인자(회원 ID·게스트 식별자)에서 구합니다. 이 제공자가 코어가 고른 제공자가 아니면 코어가 알림을 버립니다.
 *
 * 알리는 것은 직전 결정과 **달라진** 항목뿐입니다. 동의 서비스는 게스트(그리고 저장된 행이 없는 회원)에게 이전
 * 값과 상관없이 저장마다 부여·철회 훅을 내므로, 그대로 옮기면 한 번도 동의하지 않은 방문자의 첫 「모두 거부」가
 * 철회로 세어집니다. 직전 결정은 훅 직전에 남는 동의 이력의 바로 앞 행에서 읽고, 없으면 「동의 안 함」으로 봅니다.
 */
class GdprConsentChangeRelayListener implements HookListenerInterface
{
    /**
     * 요청 안에서 모은 변경을 담는 컨테이너 키.
     *
     * 훅 등록기는 훅을 부를 때마다 `app($listenerClass)` 로 리스너를 새로 만든다 — 모음 버퍼를 인스턴스 속성에 두면
     * 변경 한 건마다 버퍼가 비어 「한 번만 알린다」가 깨진다. 그래서 요청 단위로 살아 있는 컨테이너 객체에 둔다.
     */
    private const BUFFER_KEY = 'sirsoft-gdpr.consent-relay.buffer';

    /**
     * @param  GdprUserConsentHistoryRepositoryInterface  $history  동의 이력 저장소 (직전 결정 조회)
     */
    public function __construct(private readonly GdprUserConsentHistoryRepositoryInterface $history) {}

    /**
     * 구독할 훅 목록.
     *
     * @return array<string, array{method?: string, priority?: int, type?: string, sync?: bool}> 훅 정의
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // sync => true : 요청 종료 시점에 모아 한 번만 알린다 — 큐로 넘기면 요청 단위 묶음이 깨진다.
            'sirsoft-gdpr.consent.granted' => ['method' => 'onGranted', 'priority' => 20, 'sync' => true],
            'sirsoft-gdpr.consent.revoked' => ['method' => 'onRevoked', 'priority' => 20, 'sync' => true],
        ];
    }

    /**
     * 인터페이스 표준 진입점 — 실제 진입점은 onGranted()·onRevoked() 다.
     *
     * @param  mixed  ...$args  사용 안 함
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // no-op
    }

    /**
     * 동의 부여를 모읍니다.
     *
     * @param  mixed  ...$args  훅 인자 (동의 모델|null, 출처, 동의 키, 회원 ID, 게스트 식별자)
     * @return void 반환값 없음
     */
    public function onGranted(...$args): void
    {
        $this->collect(true, $args);
    }

    /**
     * 동의 철회를 모읍니다.
     *
     * @param  mixed  ...$args  훅 인자 (동의 모델|null, 출처, 동의 키, 회원 ID, 게스트 식별자)
     * @return void 반환값 없음
     */
    public function onRevoked(...$args): void
    {
        $this->collect(false, $args);
    }

    /**
     * 모은 변경을 코어에 알리고 비웁니다 (요청 종료 시 자동 호출, 테스트에서는 직접 호출).
     *
     * @return void 반환값 없음
     */
    public function flush(): void
    {
        $buffer = $this->buffer();
        $pending = $buffer['pending'];
        $buffer['pending'] = [];
        $buffer['scheduled'] = false;

        foreach ($pending as $entry) {
            app(ConsentManager::class)->reportChange(GdprConsentProvider::PROVIDER_ID, $entry['subject'], $entry['changes']);
        }
    }

    /**
     * 변경 한 건을 주체별로 모읍니다.
     *
     * @param  bool  $value  새 동의 여부
     * @param  array<int, mixed>  $args  훅 인자
     * @return void 반환값 없음
     */
    private function collect(bool $value, array $args): void
    {
        $consentKey = $args[2] ?? null;
        if (! is_string($consentKey) || ! str_starts_with($consentKey, 'cookie_')) {
            return;
        }

        $userId = is_numeric($args[3] ?? null) ? (int) $args[3] : null;
        $sessionId = is_string($args[4] ?? null) && $args[4] !== '' ? $args[4] : null;
        if ($userId === null && $sessionId === null) {
            return;
        }

        if ($this->previousDecision($consentKey, $userId, $sessionId, $value) === $value) {
            return;
        }

        $bucket = $userId !== null ? 'u:'.$userId : 'g:'.$sessionId;
        $buffer = $this->buffer();
        $pending = $buffer['pending'];
        $pending[$bucket] ??= [
            'subject' => $userId !== null ? ConsentSubject::member($userId) : ConsentSubject::guest($sessionId),
            'changes' => [],
        ];
        $pending[$bucket]['changes'][substr($consentKey, strlen('cookie_'))] = $value;
        $buffer['pending'] = $pending;

        if (! $buffer['scheduled']) {
            $buffer['scheduled'] = true;
            app()->terminating(fn () => $this->flush());
        }
    }

    /**
     * 이번 저장 직전의 결정을 동의 이력에서 읽습니다.
     *
     * 동의 서비스는 이력을 남긴 뒤 훅을 내므로, 이 키의 최신 행이 이번 결정과 같으면 그 행이 방금 남긴 것이고 그
     * 다음 행이 직전 결정입니다. 이력을 남기지 않는 항목(필수)은 최신 행이 곧 직전 결정입니다. 행이 없으면 한 번도
     * 동의하지 않은 것이므로 false 입니다.
     *
     * @param  string  $consentKey  동의 키
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $sessionId  게스트 식별자
     * @param  bool  $value  이번 결정
     * @return bool|null 직전 동의 여부 (이력을 읽지 못하면 null — 달라진 것으로 보고 알린다)
     */
    private function previousDecision(string $consentKey, ?int $userId, ?string $sessionId, bool $value): ?bool
    {
        try {
            $rows = ($userId !== null ? $this->history->getByUserId($userId) : $this->history->getBySessionId((string) $sessionId))
                ->filter(fn ($row) => (string) $row->consent_key === $consentKey)
                ->values();
        } catch (\Throwable) {
            return null;
        }

        $granted = fn ($row) => (string) $row->action === ConsentAction::Granted->value;

        if ($rows->isNotEmpty() && $granted($rows[0]) === $value) {
            $rows = $rows->slice(1)->values();
        }

        return $rows->isNotEmpty() ? $granted($rows[0]) : false;
    }

    /**
     * 요청 단위 모음 버퍼를 돌려줍니다 (없으면 만듭니다).
     *
     * @return \ArrayObject<string, mixed> `pending`(주체별 변경)·`scheduled`(요청 종료 flush 예약 여부)
     */
    private function buffer(): \ArrayObject
    {
        if (! app()->bound(self::BUFFER_KEY)) {
            app()->instance(self::BUFFER_KEY, new \ArrayObject(['pending' => [], 'scheduled' => false]));
        }

        return app(self::BUFFER_KEY);
    }
}
