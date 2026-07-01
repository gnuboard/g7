<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Concerns;

use Carbon\Carbon;

/**
 * Signature 요청의 timestamp 신선도(freshness) 검증.
 *
 * KG 이니시스 결제 signature 는 timestamp 를 hash 입력에 포함하지만 PG 측에서
 * timestamp 의 시간상 유효성을 검증하지 않는다. 따라서 공격자가 과거에 캡처한
 * 정상 signature 를 stale timestamp 와 함께 재사용할 위험이 있다 (replay).
 *
 * 본 트레이트는 ±300초(5분) 윈도우로 timestamp 의 freshness 를 검증하여
 * stale signature 의 재사용을 차단한다.
 *
 * 지원 timestamp 포맷:
 *  - yyyyMMddHHmmss (14자리 숫자) — CBT 표준
 *  - epoch milliseconds (13자리 숫자) — PC/모바일 표준 (Math.floor(Date.now()))
 */
trait ValidatesTimestampFreshness
{
    /** 허용 시간 윈도우 (초) — 클라이언트/서버 시간차 흡수 + 정상 사용자 지연 흡수 */
    private const FRESHNESS_WINDOW_SECONDS = 300;

    /**
     * 14자리 yyyyMMddHHmmss 파싱 시 시도할 timezone 후보 목록.
     *
     * 클라이언트의 환경에 따라 timestamp 가 어느 timezone 인지 결정적으로 알 수
     * 없다 (JS Date.getHours() 는 브라우저 로컬, gnuboard5 PHP date('YmdHis')
     * 는 서버 로컬, 일부 SDK 는 UTC). 따라서 후보 목록을 순회하며 현재 시각과의
     * 차이가 ±300s 윈도우에 들어오는 해석을 발견하면 fresh 로 판정.
     *
     *  - Asia/Seoul: gnuboard5 + 한국 머천트 표준 (대다수 케이스)
     *  - UTC:       Laravel app.timezone=UTC 환경에서 PHP Carbon::now() 출력 또는
     *               UTC 기반 클라이언트
     */
    private const PARSE_TIMEZONES = ['Asia/Seoul', 'UTC'];

    /**
     * timestamp 가 현재 시각 ±300초 윈도우 내에 있는지 검증.
     *
     * 14자리 yyyyMMddHHmmss 또는 13자리 epoch ms 모두 허용. 그 외 포맷은 거부.
     *
     * @param  string  $timestamp  검증할 timestamp (PG signature 요청 페이로드의 값)
     * @return bool 신선하면 true, stale 또는 파싱 실패면 false
     */
    protected function isTimestampFresh(string $timestamp): bool
    {
        if (! preg_match('/^\d{13,14}$/', $timestamp)) {
            return false;
        }

        // 13자리: epoch ms — timezone 무관
        if (strlen($timestamp) === 13) {
            $parsed = (int) floor((int) $timestamp / 1000);
            return abs(time() - $parsed) <= self::FRESHNESS_WINDOW_SECONDS;
        }

        // 14자리: 후보 timezone 순회 — 하나라도 윈도우 내면 fresh.
        $now = time();
        foreach (self::PARSE_TIMEZONES as $tz) {
            $parsed = $this->parseYmdHisAsTimezone($timestamp, $tz);
            if ($parsed === null) {
                continue;
            }
            if (abs($now - $parsed) <= self::FRESHNESS_WINDOW_SECONDS) {
                return true;
            }
        }

        return false;
    }

    /**
     * 14자리 yyyyMMddHHmmss 를 지정 timezone 으로 파싱.
     *
     * @return int|null Unix epoch, 파싱 실패 시 null
     */
    private function parseYmdHisAsTimezone(string $timestamp, string $timezone): ?int
    {
        try {
            return Carbon::createFromFormat('YmdHis', $timestamp, $timezone)->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

}
