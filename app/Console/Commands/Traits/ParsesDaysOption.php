<?php

namespace App\Console\Commands\Traits;

/**
 * 정리·통계 커맨드의 「일수」 옵션(`--days` 등)을 정수로 검증하는 트레이트.
 *
 * `(int) 'abc'` 는 0, `(int) '-5'` 는 -5 라 오타가 보관 하한(1일)으로 깎여 기본값보다 더 많이 지우는
 * 쪽으로 해석됐다(7.1.1 출시 전). 0 이상의 정수가 아니면 오류를 내고 아무것도 하지 않는다.
 * `0` 은 받는다 — 보관 하한(1일)은 도메인 계층이 소유하고(명시한 0 도 그 하한으로 막힌다), 첨부·임시 산출물 정리는
 * 0 을 「정리 안 함」·「보존일 0」으로 이미 쓴다.
 */
trait ParsesDaysOption
{
    /**
     * 일수 옵션 값을 읽습니다.
     *
     * @param  string  $name  옵션 이름 (기본 `days`)
     * @param  int  $min  허용 하한 (기본 0)
     * @return int|null|false 정수 값, 미지정·빈 값이면 null, 잘못된 값이면 false (오류는 이미 출력함)
     */
    protected function parseDaysOption(string $name = 'days', int $min = 0): int|null|false
    {
        $raw = $this->option($name);

        if ($raw === null || $raw === '') {
            return null;
        }

        $text = trim((string) $raw);

        if (preg_match('/^\d+$/', $text) !== 1 || (int) $text < $min) {
            $this->error(__('common.console.invalid_days_option', [
                'option' => $name,
                'min' => $min,
                'value' => (string) $raw,
            ]));

            return false;
        }

        return (int) $text;
    }

    /**
     * 보관 일수를 출력용으로 맞춥니다 — 도메인 하한보다 작게 준 값은 실제로 적용된 하한으로 보이고 그 사실을 덧붙입니다.
     *
     * 명시한 `--days=0` 은 정리 서비스가 하한(1일)으로 막는데 완료 문구는 「(보관 0일)」로 찍혀 전부 지운 것처럼 읽혔다
     * (7.1.1 출시 전).
     *
     * @param  int  $days  요청한 보관 일수
     * @param  int  $floor  도메인 하한 (기본 1)
     * @return array{0: int, 1: string} [출력할 일수, 덧붙일 안내 (하한을 적용하지 않았으면 빈 문자열)]
     */
    protected function retentionForDisplay(int $days, int $floor = 1): array
    {
        if ($days >= $floor) {
            return [$days, ''];
        }

        return [$floor, ' '.__('common.console.retention_floor_applied', ['requested' => $days, 'floor' => $floor])];
    }
}
