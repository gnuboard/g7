<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\AnalyticsConfig;
use App\Analytics\IpAddress;
use App\Analytics\TrackingRequest;
use App\Contracts\Repositories\Analytics\CollectFailureRepositoryInterface;
use App\Enums\Analytics\CollectFailureReason;
use Illuminate\Support\Facades\Log;

/**
 * 적재 실패 기록
 *
 * 수집은 사이트 동작을 막지 않으려고 예외를 전부 삼키고 응답을 그대로 돌려줍니다.
 * 그래서 실패가 아무 흔적도 남기지 않으면 운영자는 방문이 왜 비는지 알 길이
 * 없습니다 — 이 서비스가 그 유일한 단서를 남깁니다.
 *
 * 기록 자체도 실패할 수 있으므로(표가 아직 없는 설치 중 상태 등) 여기서도 예외를
 * 밖으로 던지지 않습니다. 그 경우 애플리케이션 로그로 물러섭니다.
 */
class CollectFailureLogger
{
    /**
     * 페이로드 발췌에서 빼는 필드
     *
     * 실패를 진단하려면 어떤 요청이었는지 알아야 하지만, 실패 로그가 개인정보의
     * 새는 구멍이 되면 안 됩니다. 사람을 가리키는 값은 담지 않습니다.
     *
     * @var array<int, string>
     */
    private const REDACTED_KEYS = ['ip', 'user_id', 'session_id', 'cookies', 'headers', 'user_agent', 'ch'];

    /**
     * 실패 기록기를 생성합니다.
     *
     * @param  CollectFailureRepositoryInterface  $failures  실패 로그 저장소
     * @param  PrivacyService  $privacy  개인정보 판정 (익명 모드면 주소를 남기지 않는다)
     */
    public function __construct(
        private readonly CollectFailureRepositoryInterface $failures,
        private readonly PrivacyService $privacy,
    ) {}

    /**
     * 수집 요청의 실패를 기록합니다.
     *
     * @param  CollectFailureReason  $reason  실패 사유
     * @param  string  $message  실패 메시지
     * @param  TrackingRequest|null  $request  수집 요청
     * @param  int  $maskOctets  IP 마스킹 옥텟 수 (원장과 같은 규칙을 적용한다)
     * @return void
     */
    public function log(
        CollectFailureReason $reason,
        string $message,
        ?TrackingRequest $request = null,
        int $maskOctets = 0,
    ): void {
        try {
            $this->failures->create([
                'reason' => $reason->value,
                'message' => mb_substr($message, 0, 500),
                'url' => $request !== null ? mb_substr($request->url, 0, 1024) : null,
                'ip' => $this->storedIp($request, $maskOctets),
                'user_agent' => $request?->userAgent !== null ? mb_substr($request->userAgent, 0, 500) : null,
                'payload_excerpt' => $request !== null ? $this->excerpt($request) : null,
                'occurred_at' => AnalyticsClock::now(),
                'created_at' => AnalyticsClock::now(),
            ]);
        } catch (\Throwable $e) {
            // 실패를 적는 것마저 실패하면 애플리케이션 로그로 물러선다.
            Log::warning('방문자 통계 실패 로그 기록 실패', [
                'reason' => $reason->value,
                'message' => $message,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 요청에서 진단에 필요한 부분만 뽑습니다.
     *
     * @param  TrackingRequest  $request  수집 요청
     * @return array<string, mixed> 발췌 (개인정보 필드 제거)
     */
    private function excerpt(TrackingRequest $request): array
    {
        $excerpt = $request->toArray();

        foreach (self::REDACTED_KEYS as $key) {
            unset($excerpt[$key]);
        }

        return $excerpt;
    }

    /**
     * 저장할 IP 를 원장과 같은 규칙으로 정합니다.
     *
     * 익명 모드(운영자가 고른 익명, 또는 동의 출처가 없어 내려간 익명)는 원장에 주소를 두지
     * 않으므로 실패 로그에도 두지 않습니다 — 호출부가 넘기는 것은 마스킹 옥텟뿐이라 기록기가
     * 직접 판정합니다. 판정 자체가 실패하면 남기지 않는 쪽으로 물러섭니다.
     *
     * 판정은 원장과 같은 방문자별 규칙이다 — 동의 관리 플러그인이 켜진 IP 저장 사이트에서는
     * 동의한 방문자만 IP 를 남긴다(`PrivacyService::isAnonymousFor`).
     *
     * @param  TrackingRequest|null  $request  수집 요청 (원본 IP 를 담는다)
     * @param  int  $maskOctets  마스킹 옥텟 수
     * @return string|null 저장할 IP (익명이면 null)
     */
    private function storedIp(?TrackingRequest $request, int $maskOctets): ?string
    {
        $ip = $request?->ip;

        if ($request === null || $ip === null || $ip === '') {
            return null;
        }

        try {
            if ($this->privacy->isAnonymousFor($request, AnalyticsConfig::fromSettings())) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return $maskOctets > 0 ? IpAddress::mask($ip, $maskOctets) : $ip;
    }
}
