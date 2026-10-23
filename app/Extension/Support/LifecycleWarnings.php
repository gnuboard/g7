<?php

namespace App\Extension\Support;

/**
 * 확장 생명주기 작업의 부분 실패 경고 수집기.
 *
 * 여러 파일·항목을 처리하는 작업(레이아웃 등록, 언어팩 시더 재실행, 정리 단계 등)은
 * 항목 하나가 실패해도 작업을 계속하고 성공으로 끝낸다. 그 실패를 로그에만 남기면
 * 운영자가 모르므로, 같은 요청(또는 명령) 안에서 이 수집기에 모아 결과·CLI 출력·관리자
 * 결과 창으로 돌려준다. 컨테이너에 scoped 로 바인딩되어 요청·작업 단위로 하나를 쓴다.
 */
class LifecycleWarnings
{
    /**
     * 경고와 함께 끝난 artisan 명령의 출력 표지 — 그 명령을 자식 프로세스로 부르는 쪽이 실패와 구분한다.
     */
    public const CLI_MARKER = '[g7-lifecycle-warnings]';

    /**
     * 항목 수준 — 반영되지 않은 실패.
     */
    public const LEVEL_WARNING = 'warning';

    /**
     * 항목 수준 — 실패는 아니지만 운영자가 알아야 할 안내(예: 압축 경로 정규화).
     */
    public const LEVEL_NOTICE = 'notice';

    /**
     * 자식 artisan 프로세스가 「완료(경고 포함)」로 끝났는지 판정합니다.
     *
     * 경고가 있는 생명주기 명령은 종료코드 1 과 {@see self::CLI_MARKER} 를 낸다. 표지 없는 1 은 실패다.
     *
     * @param  int|null  $exitCode  자식 종료코드
     * @param  string  $output  자식 출력
     * @return bool 종료코드 0, 또는 1 이면서 경고 표지가 있으면 true
     */
    public static function cliCompletedWithWarnings(?int $exitCode, string $output): bool
    {
        return $exitCode === 0 || ($exitCode === 1 && str_contains($output, self::CLI_MARKER));
    }

    /**
     * @var array<int, array{step: string, target: string, message: string, level: string}>
     */
    private array $items = [];

    /**
     * 현재 요청(또는 명령)의 수집기를 반환합니다.
     *
     * @return self 컨테이너에 scoped 로 바인딩된 인스턴스
     */
    public static function current(): self
    {
        return app(self::class);
    }

    /**
     * 경고 하나를 추가합니다.
     *
     * @param  string  $step  실패한 단계 (예: layout_register, seeder, cleanup)
     * @param  string  $target  실패한 대상 (파일명·확장 식별자·시더 클래스 등)
     * @param  string  $message  실패 사유 (DB 예외 원문이면 접속 정보·SQL 꼬리를 떼고 담는다 — 원문은 호출부 로그에 있다)
     * @param  string  $level  항목 수준 ({@see self::LEVEL_WARNING} 기본, 실패가 아닌 안내는 {@see self::LEVEL_NOTICE})
     * @return void 반환값 없음
     */
    public function add(string $step, string $target, string $message, string $level = self::LEVEL_WARNING): void
    {
        $this->items[] = [
            'step' => $step,
            'target' => $target,
            'message' => self::scrubMessage($message),
            'level' => $level === self::LEVEL_NOTICE ? self::LEVEL_NOTICE : self::LEVEL_WARNING,
        ];
    }

    /**
     * 실패가 아닌 안내 항목을 추가합니다.
     *
     * @param  string  $step  단계 (예: extract)
     * @param  string  $target  대상 (파일명 등)
     * @param  string  $message  안내 문구
     * @return void 반환값 없음
     */
    public function notice(string $step, string $target, string $message): void
    {
        $this->add($step, $target, $message, self::LEVEL_NOTICE);
    }

    /**
     * 항목 목록에 실패(안내가 아닌 항목)가 있는지 판정합니다.
     *
     * @param  array<int, array<string, mixed>>  $items  항목 목록
     * @return bool 실패 항목이 하나라도 있으면 true
     */
    public static function hasFailures(array $items): bool
    {
        foreach ($items as $item) {
            if (($item['level'] ?? self::LEVEL_WARNING) !== self::LEVEL_NOTICE) {
                return true;
            }
        }

        return false;
    }

    /**
     * 경고 사유에서 DB 접속 정보·SQL 문장을 뗍니다.
     *
     * Laravel QueryException 메시지는 「SQLSTATE[…]: 사유 (Connection: …, Host: …, Database: …, SQL: …)」 꼴이라 그대로 두면
     * 관리자 결과 창에 DB 호스트·이름·쿼리가 보인다. 사유 부분만 남긴다.
     *
     * @param  string  $message  원래 사유
     * @return string 접속 정보·SQL 을 뗀 사유
     */
    private static function scrubMessage(string $message): string
    {
        $scrubbed = preg_replace('/\s*\(Connection:.*$/s', '', $message);

        return is_string($scrubbed) && $scrubbed !== '' ? $scrubbed : $message;
    }

    /**
     * 모은 경고 전체를 반환합니다.
     *
     * @return array<int, array{step: string, target: string, message: string, level: string}>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * 경고가 하나도 없는지 확인합니다.
     *
     * @return bool 경고가 없으면 true
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * 경고 개수를 반환합니다.
     *
     * @return int 모은 경고 수
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * 지금까지 모은 경고 수를 반환합니다 — 작업 하나가 추가한 경고만 잘라 낼 때 시작점으로 씁니다.
     *
     * @return int 현재 경고 수
     */
    public function mark(): int
    {
        return count($this->items);
    }

    /**
     * mark() 이후 추가된 경고만 반환합니다.
     *
     * @param  int  $mark  mark() 가 돌려준 값
     * @return array<int, array{step: string, target: string, message: string, level: string}>
     */
    public function since(int $mark): array
    {
        return array_values(array_slice($this->items, $mark));
    }

    /**
     * 모은 경고를 모두 비웁니다.
     *
     * @return void 반환값 없음
     */
    public function clear(): void
    {
        $this->items = [];
    }
}
