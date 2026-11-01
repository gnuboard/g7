<?php

/**
 * 그누보드7 터미널 설치 - 대화형 입력
 *
 * 표준 입력이 터미널(TTY)이고 `--no-interaction` 이 없을 때만 묻는다(판정: `stream_isatty(STDIN)` —
 * artisan 확인 프롬프트와 같은 기준). 비밀번호는 화면·스크롤백에 남지 않게 숨김 입력으로만 받는다.
 */

if (! function_exists('g7_cli_stdin_is_tty')) {
    /**
     * 표준 입력이 터미널인지.
     *
     * @return bool TTY 면 true
     */
    function g7_cli_stdin_is_tty(): bool
    {
        return defined('STDIN') && function_exists('stream_isatty') && @stream_isatty(STDIN);
    }
}

if (! function_exists('g7_cli_read_line')) {
    /**
     * 한 줄을 읽는다 (EOF 면 null).
     *
     * @return string|null 입력 줄 (끝 개행 제거)
     */
    function g7_cli_read_line(): ?string
    {
        $line = fgets(STDIN);

        return $line === false ? null : rtrim($line, "\r\n");
    }
}

if (! function_exists('g7_cli_ask')) {
    /**
     * 값을 묻는다 — 빈 입력이면 기본값.
     *
     * @param  string  $label  질문 라벨
     * @param  string|null  $default  기본값
     * @return string 입력값
     */
    function g7_cli_ask(string $label, ?string $default = null): string
    {
        $suffix = ($default !== null && $default !== '') ? " [{$default}]" : '';
        fwrite(STDOUT, "  {$label}{$suffix}: ");
        $line = g7_cli_read_line();

        if ($line === null || trim($line) === '') {
            return (string) $default;
        }

        return trim($line);
    }
}

if (! function_exists('g7_cli_confirm')) {
    /**
     * 예/아니오를 묻는다.
     *
     * @param  string  $question  질문
     * @param  bool  $default  빈 입력의 답
     * @return bool 예면 true
     */
    function g7_cli_confirm(string $question, bool $default = false): bool
    {
        // 코어 콘솔 확인 규칙과 같다(docs/backend/console-confirm.md) — yes/y·no/n 만 받고 그 밖은 다시 묻는다.
        // 잘못 친 답을 조용히 「아니오」로 처리하면 약관·테이블 삭제 동의 같은 질문에서 의도와 다르게 멈춘다.
        // 이 헬퍼는 Laravel·vendor 없이 동작하므로 vendor 가 없는 설치 전에도 쓸 수 있다.
        require_once dirname(__DIR__, 4).'/app/Console/Helpers/ConsoleConfirm.php';

        return \App\Console\Helpers\ConsoleConfirm::ask(
            '  '.$question,
            $default,
            null,
            static function (string $text): void {
                fwrite(STDOUT, $text);
            },
        );
    }
}

if (! function_exists('g7_cli_choice')) {
    /**
     * 번호로 하나를 고른다.
     *
     * @param  string  $question  질문
     * @param  array<string, string>  $options  값 → 표시 이름
     * @param  string  $default  기본값
     * @return string 고른 값
     */
    function g7_cli_choice(string $question, array $options, string $default): string
    {
        fwrite(STDOUT, "  {$question}\n");
        $values = array_keys($options);
        foreach ($values as $i => $value) {
            $mark = $value === $default ? '*' : ' ';
            fwrite(STDOUT, sprintf("   %s %d) %s\n", $mark, $i + 1, $options[$value]));
        }

        while (true) {
            fwrite(STDOUT, '  > ');
            $line = trim((string) g7_cli_read_line());
            if ($line === '') {
                return $default;
            }
            if (ctype_digit($line) && isset($values[(int) $line - 1])) {
                return $values[(int) $line - 1];
            }
            if (isset($options[$line])) {
                return $line;
            }
        }
    }
}

if (! function_exists('g7_cli_hidden_input_supported')) {
    /**
     * 숨김 입력을 쓸 수 있는지 — 유닉스는 stty, Windows 는 hiddeninput.exe(vendor 에 있을 때).
     *
     * @return bool 사용 가능 여부
     */
    function g7_cli_hidden_input_supported(): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return is_file(BASE_PATH.'/vendor/symfony/console/Resources/bin/hiddeninput.exe');
        }

        return function_exists('shell_exec') && trim((string) @shell_exec('stty -g 2>/dev/null')) !== '';
    }
}

if (! function_exists('g7_cli_hidden')) {
    /**
     * 화면에 보이지 않게 비밀값을 묻는다.
     *
     * @param  string  $label  질문 라벨
     * @return string|null 입력값 (숨김 입력 불가면 null)
     */
    function g7_cli_hidden(string $label): ?string
    {
        if (! g7_cli_hidden_input_supported()) {
            return null;
        }

        fwrite(STDOUT, "  {$label}: ");

        if (DIRECTORY_SEPARATOR === '\\') {
            $value = (string) shell_exec(escapeshellarg(BASE_PATH.'/vendor/symfony/console/Resources/bin/hiddeninput.exe'));
            fwrite(STDOUT, "\n");

            return rtrim($value, "\r\n");
        }

        $mode = trim((string) shell_exec('stty -g'));
        shell_exec('stty -echo');
        try {
            $value = (string) g7_cli_read_line();
        } finally {
            shell_exec('stty '.escapeshellarg($mode));
            fwrite(STDOUT, "\n");
        }

        return $value;
    }
}

if (! function_exists('g7_cli_toggle_list')) {
    /**
     * 번호 토글로 여러 항목을 고른다 — 빈 줄이면 끝.
     *
     * @param  string  $title  목록 제목
     * @param  array<string, string>  $items  식별자 → 표시 이름
     * @param  array<int, string>  $selected  현재 선택
     * @param  bool  $single  하나만 고르는 목록이면 true (같은 번호로 해제)
     * @return array<int, string> 선택 결과
     */
    function g7_cli_toggle_list(string $title, array $items, array $selected, bool $single = false): array
    {
        if ($items === []) {
            return [];
        }

        $ids = array_keys($items);
        while (true) {
            fwrite(STDOUT, "\n  {$title}\n");
            foreach ($ids as $i => $id) {
                $on = in_array($id, $selected, true) ? 'x' : ' ';
                fwrite(STDOUT, sprintf("   [%s] %d) %s (%s)\n", $on, $i + 1, $items[$id], $id));
            }
            fwrite(STDOUT, '  '.lang('cli_toggle_hint').' > ');
            $line = trim((string) g7_cli_read_line());
            if ($line === '') {
                return $selected;
            }

            foreach (preg_split('/[\s,]+/', $line) ?: [] as $token) {
                if (! ctype_digit($token) || ! isset($ids[(int) $token - 1])) {
                    continue;
                }
                $id = $ids[(int) $token - 1];
                if (in_array($id, $selected, true)) {
                    $selected = array_values(array_diff($selected, [$id]));
                } else {
                    $selected = $single ? [$id] : [...$selected, $id];
                }
            }
        }
    }
}

if (! function_exists('g7_cli_page_text')) {
    /**
     * 긴 본문을 쪽 단위로 출력한다 (엔터로 다음 쪽, q 로 끝까지 건너뛰기).
     *
     * @param  string  $text  본문
     * @param  int  $pageLines  쪽당 줄 수
     * @return void 반환값 없음
     */
    function g7_cli_page_text(string $text, int $pageLines = 40): void
    {
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        foreach (array_chunk($lines, $pageLines) as $index => $chunk) {
            fwrite(STDOUT, implode("\n", $chunk)."\n");
            if (($index + 1) * $pageLines >= count($lines)) {
                break;
            }
            fwrite(STDOUT, '  -- '.lang('cli_page_more').' -- ');
            if (strtolower(trim((string) g7_cli_read_line())) === 'q') {
                break;
            }
        }
    }
}
