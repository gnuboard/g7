<?php

namespace Plugins\Sirsoft\Sso\DTO;

/**
 * 서버 쪽 연결 시험 결과 (관리자 진단 카드).
 *
 * @since 1.0.0
 */
final class ConnectionTestResult
{
    /**
     * @param  bool  $ok  모든 항목 통과 여부
     * @param  array<int, array{key: string, ok: bool, message_key: string, detail: string|null}>  $checks  항목별 결과
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $checks,
    ) {}

    /**
     * 항목 목록으로 결과를 만듭니다 — 하나라도 실패면 전체 실패.
     *
     * @param  array<int, array{key: string, ok: bool, message_key: string, detail: string|null}>  $checks  항목별 결과
     * @return self 결과
     */
    public static function fromChecks(array $checks): self
    {
        $ok = $checks !== [];
        foreach ($checks as $check) {
            $ok = $ok && (bool) $check['ok'];
        }

        return new self($ok, array_values($checks));
    }

    /**
     * 배열로 바꿉니다 (API 응답).
     *
     * @return array{ok: bool, checks: array<int, array<string, mixed>>} 응답 모양
     */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'checks' => $this->checks];
    }
}
