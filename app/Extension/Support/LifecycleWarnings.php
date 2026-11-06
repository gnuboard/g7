<?php

namespace App\Extension\Support;

use Illuminate\Support\Facades\Lang;

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
     * 항목 원인 — 확장 manifest 가 선언한 값의 형식이 틀려 그 부분을 버렸다(예: search_scopes·necessary_storage).
     *
     * 작업을 다시 실행해도 같은 결과가 나오므로 운영자가 아니라 확장 개발자가 선언을 고쳐야 한다. 관리자 결과 창은
     * 이 표지가 붙은 항목에 「다시 실행」 대신 확장 개발자에게 알리라는 안내를 붙인다. 항목의 `cause` 키는 이 값일
     * 때만 싣는다(항목 모양 하위 호환).
     */
    public const CAUSE_DECLARATION = 'declaration';

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
     * @var array<int, array{step: string, target: string, target_name?: string, message: string, level: string, cause?: string}>
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
     * @param  string|null  $targetName  대상의 사람이 읽는 이름 — 관리자 결과 창의 대상 칸이 식별자 대신 보인다.
     *                                   비었거나 식별자와 같으면 싣지 않는다(항목 모양 하위 호환, `target` 은 늘 식별자·경로)
     * @param  string|null  $cause  항목 원인 — {@see self::CAUSE_DECLARATION} 만 싣는다(그 밖의 값·null 은 싣지 않음)
     * @return void 반환값 없음
     */
    public function add(string $step, string $target, string $message, string $level = self::LEVEL_WARNING, ?string $targetName = null, ?string $cause = null): void
    {
        $item = ['step' => $step, 'target' => $target];

        if ($targetName !== null && $targetName !== '' && $targetName !== $target) {
            $item['target_name'] = $targetName;
        }

        $item = [
            ...$item,
            'message' => self::scrubMessage($message),
            'level' => $level === self::LEVEL_NOTICE ? self::LEVEL_NOTICE : self::LEVEL_WARNING,
        ];

        if ($cause === self::CAUSE_DECLARATION) {
            $item['cause'] = self::CAUSE_DECLARATION;
        }

        $this->items[] = $item;
    }

    /**
     * 확장 manifest 선언의 형식 오류를 추가합니다 — 틀린 부분은 버렸고, 확장 개발자가 선언을 고쳐야 하는 실패.
     *
     * 수준은 {@see self::LEVEL_WARNING}(선언 일부가 반영되지 않음)이고 `cause` 에 {@see self::CAUSE_DECLARATION} 를 싣는다.
     *
     * @param  string  $step  단계 (예: search_scopes, necessary_storage)
     * @param  string  $target  선언한 확장 식별자
     * @param  string  $message  무엇을 왜 버렸는지 사람이 읽는 사유
     * @param  string|null  $targetName  대상의 사람이 읽는 이름 ({@see self::add()})
     * @return void 반환값 없음
     */
    public function declaration(string $step, string $target, string $message, ?string $targetName = null): void
    {
        $this->add($step, $target, $message, self::LEVEL_WARNING, $targetName, self::CAUSE_DECLARATION);
    }

    /**
     * 경고 사유 문장을 번역합니다 — 번역이 없으면(그 문장이 없는 옛 언어팩·번역 반영 전) 키 대신 원문 항목을 돌려줍니다.
     *
     * 키 글자만 남으면 무엇을 버렸는지조차 사라지므로, 번역이 없을 때는 버린 항목 원문이라도 보이게 한다.
     *
     * @param  string  $key  번역 키
     * @param  array<string, mixed>  $replace  자리표시자 값
     * @param  string  $raw  번역이 없을 때 보일 원문 항목
     * @return string 사유 문장
     */
    public static function reason(string $key, array $replace, string $raw): string
    {
        return Lang::has($key) ? (string) __($key, $replace) : $raw;
    }

    /**
     * 항목이 확장 선언 형식 오류인지 판정합니다.
     *
     * @param  array<string, mixed>  $item  항목
     * @return bool `cause` 가 {@see self::CAUSE_DECLARATION} 이면 true
     */
    public static function isDeclarationError(array $item): bool
    {
        return ($item['cause'] ?? null) === self::CAUSE_DECLARATION;
    }

    /**
     * 실패가 아닌 안내 항목을 추가합니다.
     *
     * @param  string  $step  단계 (예: extract)
     * @param  string  $target  대상 (파일명 등)
     * @param  string  $message  안내 문구
     * @param  string|null  $targetName  대상의 사람이 읽는 이름 ({@see self::add()})
     * @return void 반환값 없음
     */
    public function notice(string $step, string $target, string $message, ?string $targetName = null): void
    {
        $this->add($step, $target, $message, self::LEVEL_NOTICE, $targetName);
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
     * @return array<int, array{step: string, target: string, target_name?: string, message: string, level: string, cause?: string}>
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
     * @return array<int, array{step: string, target: string, target_name?: string, message: string, level: string, cause?: string}>
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
