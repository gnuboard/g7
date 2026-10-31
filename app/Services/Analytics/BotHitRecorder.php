<?php

namespace App\Services\Analytics;

use App\Analytics\ActionNameHasher;
use App\Analytics\AnalyticsClock;
use App\Analytics\IpAddress;
use App\Contracts\Repositories\Analytics\BotHitRepositoryInterface;
use Illuminate\Support\Facades\Log;

/**
 * 봇 요청 기록
 *
 * 봇 방문은 사람 방문과 섞으면 통계가 망가지므로 원장을 따로 둡니다. 어떤 봇이 언제
 * 무엇을 가져갔는지와, 그때 SEO 캐시가 쓰였는지를 함께 남깁니다 — 캐시가 듣지 않으면
 * 크롤러 하나가 서버를 계속 갈아 대므로 운영자가 볼 수 있어야 합니다.
 *
 * 응답을 보낸 뒤에 기록하므로 큐를 타지 않습니다. 기록 실패가 응답에 영향을 주지
 * 않으니 예외는 삼키고 로그로 남깁니다.
 */
class BotHitRecorder
{
    /**
     * 봇 요청 기록기를 생성합니다.
     *
     * @param  BotHitRepositoryInterface  $botHits  봇 요청 저장소
     */
    public function __construct(private readonly BotHitRepositoryInterface $botHits) {}

    /**
     * 봇 요청을 기록합니다.
     *
     * @param  string  $botName  봇 이름
     * @param  string|null  $userAgent  User-Agent 문자열
     * @param  string  $url  요청 URL
     * @param  string|null  $ip  요청 IP
     * @param  string  $cacheStatus  SEO 캐시 상태 (hit, miss, bypass, none)
     * @param  int  $maskOctets  IP 마스킹 옥텟 수 (사람 방문과 같은 규칙을 적용한다)
     * @return void
     */
    public function record(
        string $botName,
        ?string $userAgent,
        string $url,
        ?string $ip,
        string $cacheStatus,
        int $maskOctets = 0,
    ): void {
        try {
            $this->botHits->create([
                'bot_name' => mb_substr($botName, 0, 100),
                'user_agent' => mb_substr($userAgent ?? '', 0, 500),
                'url' => mb_substr($url, 0, 1024),
                'url_hash' => ActionNameHasher::urlHash($url),
                'ip' => $this->maskedIp($ip, $maskOctets),
                'cache_status' => $cacheStatus,
                'occurred_at' => AnalyticsClock::now(),
                'created_at' => AnalyticsClock::now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('방문자 통계 봇 요청 기록 실패', [
                'bot' => $botName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 저장할 IP 에 마스킹을 적용합니다.
     *
     * @param  string|null  $ip  원본 IP
     * @param  int  $maskOctets  마스킹 옥텟 수
     * @return string|null 마스킹된 IP
     */
    private function maskedIp(?string $ip, int $maskOctets): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return $maskOctets > 0 ? IpAddress::mask($ip, $maskOctets) : $ip;
    }
}
