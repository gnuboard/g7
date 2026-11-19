<?php

namespace App\Scheduling;

/**
 * 되뽑기 결과 — 등록된 스케줄 이벤트에서 복원한 Artisan 커맨드 한 건.
 *
 * 러너는 이 형태를 그대로 `CommandRunner::run($command, $parameters)` 에 넘긴다.
 * 파라미터 표기는 두 러너가 공유하는 규약(`[0 => 'value', '--opt' => …]`)이다 —
 * 위치 인자는 숫자 키, 옵션은 `--` 접두 키.
 *
 * @since 7.1.0
 */
final class ArtisanCommandExtraction
{
    /**
     * @param  string  $command  Artisan 커맨드명 (예: `sanctum:prune-expired`)
     * @param  array<string|int, mixed>  $parameters  커맨드 파라미터
     */
    public function __construct(
        public readonly string $command,
        public readonly array $parameters = [],
    ) {}

    /**
     * 사람이 읽는 커맨드 표기 (로그·이력 표시용).
     *
     * @return string `커맨드명 --옵션=값` 형태
     */
    public function display(): string
    {
        $parts = [$this->command];

        foreach ($this->parameters as $key => $value) {
            if (is_int($key)) {
                $parts[] = (string) $value;

                continue;
            }

            if ($value === true) {
                $parts[] = $key;

                continue;
            }

            foreach ((array) $value as $item) {
                $parts[] = $key.'='.$item;
            }
        }

        return implode(' ', $parts);
    }
}
