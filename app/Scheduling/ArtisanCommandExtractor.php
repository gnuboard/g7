<?php

namespace App\Scheduling;

use App\Scheduling\Exceptions\ScheduleCommandExtractionException;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Scheduling\Event;
use Symfony\Component\Console\Input\StringInput;

/**
 * 등록된 shell 형태 스케줄 이벤트에서 Artisan 커맨드를 되뽑는다 (A-5 ㉢).
 *
 * `Schedule::command('x', [...])` 는 실행 문자열을
 * `"{php 바이너리} {artisan 바이너리} x --opt=값"` 으로 조립해 `Event::$command` 에 둔다.
 * CLI 가 없는 환경에서는 그 문자열을 스폰할 수 없으므로, 접두사를 걷어 낸 나머지를
 * 커맨드명 + 파라미터로 되돌려 `Artisan::call()` 경로로 실행한다.
 *
 * 이 되뽑기는 **프레임워크 내부 표현에 의존한다.** 그래서 세 가지를 지킨다:
 *
 * 1. 접두사는 리터럴이 아니라 프레임워크가 쓰는 것과 **같은 생성자**
 *    (`ConsoleApplication::formatCommandString`)에서 도출한다.
 * 2. 나머지 토큰화는 Symfony 의 `StringInput` 에게 맡긴다 — 인용부호 규칙을
 *    직접 다시 구현하면 `escapeArgument` 와 조용히 갈라진다.
 * 3. 되뽑기에 실패하면 예외로 드러낸다. 조용히 건너뛰면 그 예약은 오류 없이 사라진다.
 *
 * `Schedule::exec('rsync …')` 처럼 애초에 Artisan 이 아닌 이벤트는 되뽑기 대상이
 * 아니며(이 클래스가 예외를 던진다), CLI 없이 실행할 방법도 없다 — 러너는 그 사실을
 * 실패로 기록해 운영자에게 보인다.
 *
 * @since 7.1.0
 */
class ArtisanCommandExtractor
{
    /**
     * 이벤트에서 Artisan 커맨드를 되뽑습니다.
     *
     * @param  Event  $event  등록된 스케줄 이벤트 (shell 형태)
     * @return ArtisanCommandExtraction 커맨드명 + 파라미터
     *
     * @throws ScheduleCommandExtractionException 되뽑기 실패 시
     */
    public function extract(Event $event): ArtisanCommandExtraction
    {
        return $this->extractFromCommandString((string) $event->command);
    }

    /**
     * 조립된 실행 문자열에서 Artisan 커맨드를 되뽑습니다.
     *
     * @param  string  $rawCommand  `Event::$command` 원문
     * @return ArtisanCommandExtraction 커맨드명 + 파라미터
     *
     * @throws ScheduleCommandExtractionException 되뽑기 실패 시
     */
    public function extractFromCommandString(string $rawCommand): ArtisanCommandExtraction
    {
        $line = $this->stripArtisanPrefix($rawCommand);

        if ($line === null) {
            throw new ScheduleCommandExtractionException(
                $rawCommand,
                __('exceptions.schedule_extraction.not_artisan'),
            );
        }

        $input = new StringInput($line);
        $name = $input->getFirstArgument();

        if (! is_string($name) || $name === '') {
            throw new ScheduleCommandExtractionException(
                $rawCommand,
                __('exceptions.schedule_extraction.command_name_missing'),
            );
        }

        return new ArtisanCommandExtraction(
            $name,
            $this->mapTokens($input->getRawTokens(strip: true)),
        );
    }

    /**
     * php·artisan 바이너리 접두사를 걷어 냅니다.
     *
     * 두 형태를 본다 — 조립 그대로(`ConsoleApplication::formatCommandString`)와
     * 프레임워크가 표시용으로 정규화한 형태(`Event::normalizeCommand`). 후자는
     * `schedule:list` 가 쓰는 것과 같은 변환이라, 이미 정규화된 문자열이 들어와도
     * 같은 결과를 낸다.
     *
     * @param  string  $rawCommand  원본 커맨드 문자열
     * @return string|null 접두사를 걷어 낸 Artisan 커맨드 줄, 접두사가 없으면 null
     */
    private function stripArtisanPrefix(string $rawCommand): ?string
    {
        // formatCommandString('') 은 "{php} {artisan} " 을 돌려준다 — 접두사의 SSoT.
        $prefixes = [ConsoleApplication::formatCommandString('')];

        $normalizedPrefix = Event::normalizeCommand($prefixes[0]);
        if ($normalizedPrefix !== $prefixes[0]) {
            $prefixes[] = $normalizedPrefix;
        }

        $candidates = [$rawCommand, Event::normalizeCommand($rawCommand)];

        foreach ($candidates as $candidate) {
            foreach ($prefixes as $prefix) {
                if ($prefix !== '' && str_starts_with($candidate, $prefix)) {
                    $remainder = trim(substr($candidate, strlen($prefix)));

                    if ($remainder !== '') {
                        return $remainder;
                    }
                }
            }
        }

        return null;
    }

    /**
     * 토큰 목록을 `Artisan::call` 규약 파라미터 배열로 옮깁니다.
     *
     * - `--opt=값` → `['--opt' => '값']` (같은 키가 반복되면 배열로 모은다)
     * - `--flag` · `-f` → `['--flag' => true]`
     * - 그 외 → 위치 인자(숫자 키). 러너가 커맨드 정의의 인자명으로 매핑한다.
     *
     * @param  list<string>  $tokens  커맨드명 뒤의 토큰 목록
     * @return array<string|int, mixed> 파라미터 배열
     */
    private function mapTokens(array $tokens): array
    {
        $parameters = [];

        foreach ($tokens as $token) {
            if (! str_starts_with($token, '-')) {
                $parameters[] = $token;

                continue;
            }

            [$key, $value] = str_contains($token, '=')
                ? explode('=', $token, 2)
                : [$token, true];

            if (! array_key_exists($key, $parameters)) {
                $parameters[$key] = $value;

                continue;
            }

            // 같은 옵션이 두 번 이상 왔다 — 배열 옵션(`--key=a --key=b`)이다.
            $parameters[$key] = array_merge((array) $parameters[$key], [$value]);
        }

        return $parameters;
    }
}
