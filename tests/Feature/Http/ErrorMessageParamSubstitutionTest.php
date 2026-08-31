<?php

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 오류 문구의 치환 자리가 비어 노출되는 것을 막는 회귀 테스트.
 *
 * `:error` 같은 치환 자리를 가진 다국어 키를 파라미터 없이 부르면, Laravel 은 그 자리를
 * 그대로 둔 문장을 돌려준다. 그래서 운영자 화면에는 "모듈 활성화에 실패했습니다: :error"
 * 처럼 내부 자리표시자가 그대로 보인다. 예외도 로그도 남지 않고, 실패했을 때만 드러나므로
 * 정상 흐름 테스트로는 잡히지 않는다.
 *
 * 호출부를 손으로 열거하지 않는다 — 새 실패 분기가 생겼을 때 그 지점만 조용히 빠진다.
 * lang 파일에서 `:error` 를 요구하는 키를 도출하고, 그 키를 쓰는 호출부 전수를 검사한다.
 */
class ErrorMessageParamSubstitutionTest extends TestCase
{
    #[Test]
    public function error_치환_자리를_가진_키는_언제나_파라미터와_함께_호출된다(): void
    {
        $missing = [];
        $checked = 0;

        foreach ($this->phpFilesIn(app_path()) as $file) {
            $source = File::get($file);

            foreach ($this->errorCallsIn($source) as [$offset, $call]) {
                if (! preg_match("/^\(\s*'([^']+)'/", $call, $m)) {
                    continue;
                }

                // 키를 실제로 해석해 판정한다. lang 파일을 직접 파싱하면 중첩 키
                // (`modules.errors.install_failed`)의 경로를 잃어 오탐·누락이 함께 생긴다.
                // 존재하지 않는 키는 번역기가 키 자체를 돌려주므로 자연히 걸러진다.
                if (! str_contains(trans($m[1], [], 'ko'), ':error')) {
                    continue;
                }

                $checked++;

                // messageParams(넷째 인자)에 'error' 키가 실렸는지 본다.
                // 셋째 인자 errors 페이로드는 다른 통로이므로 치환에 쓰이지 않는다.
                // 배열의 첫 키가 아닐 수 있으므로(`['module' => ..., 'error' => ...]`)
                // 인자를 최상위 쉼표 기준으로 갈라 넷째 인자만 본다.
                $params = $this->topLevelArguments($call)[3] ?? '';

                if (preg_match("/'error'\s*=>/", $params) === 1) {
                    continue;
                }

                $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                $missing[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).':'.$line.'  '.$m[1];
            }
        }

        $this->assertGreaterThan(
            50,
            $checked,
            '검사한 호출부가 너무 적다 — 호출부 탐지 정규식이 모집단을 잃었다'
        );

        $this->assertSame(
            [],
            $missing,
            '다음 호출부가 :error 치환 파라미터 없이 오류 문구를 반환한다 — '
            ."운영자 화면에 자리표시자 \":error\" 가 그대로 노출된다:\n  "
            .implode("\n  ", $missing)
        );
    }

    #[Test]
    public function error_치환_자리를_가진_키는_예외_생성자에서도_파라미터와_함께_전달된다(): void
    {
        $missing = [];
        $checked = 0;

        foreach ($this->phpFilesIn(app_path()) as $file) {
            $source = File::get($file);

            foreach ($this->exceptionConstructorsIn($source) as [$offset, $call]) {
                $args = $this->topLevelArguments($call);

                if (! preg_match("/^\s*'([^']+)'/", $args[0] ?? '', $m)) {
                    continue;
                }

                if (! str_contains(trans($m[1], [], 'ko'), ':error')) {
                    continue;
                }

                $checked++;

                // 예외 생성자는 `->error()` 와 인자 배치가 다르다 —
                // 파라미터 배열이 넷째가 아니라 **둘째** 인자다.
                if (preg_match("/'error'\s*=>/", $args[1] ?? '') === 1) {
                    continue;
                }

                $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                $missing[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).':'.$line.'  '.$m[1];
            }
        }

        $this->assertGreaterThan(
            0,
            $checked,
            '검사한 예외 생성자가 0건이다 — 탐지 정규식이 모집단을 잃었다'
        );

        $this->assertSame(
            [],
            $missing,
            '다음 예외 생성자가 :error 치환 파라미터 없이 던져진다 — 그 메시지가 응답으로 '
            ."나가면 운영자 화면에 자리표시자 \":error\" 가 그대로 노출된다:\n  "
            .implode("\n  ", $missing)
        );
    }

    /**
     * 치환 자리를 가진 키를 **번역기로 직접** 부르는 자리도 파라미터와 함께 부른다.
     *
     * 위 두 축(`->error()` · 예외 생성자)은 응답 헬퍼를 거치는 경로만 본다. 그런데 같은
     * 결함은 그 밖에서도 난다 — `__('키')` 를 그대로 부르고 그 결과를 예외 메시지·로그
     * 메시지·검증 실패 문구로 쓰는 자리다. 실제로 그 사각에 17건이 있었고, 그중에는
     * 「형제 호출부는 채우는데 이 한 자리만 빠진」 형태(`ValidOutboundProxyUrl` 의 두
     * `$fail()`)도 있었다. 두 축이 초록인 채로 그 자리들은 계속 자리표시자를 노출했다.
     *
     * 판정은 **키를 실제로 해석해서** 한다(lang 파일 직접 파싱은 중첩 키 경로를 잃는다).
     * 자리표시자로 인정하는 것은 문장 시작·공백·따옴표·괄호 뒤에 오는 `:이름` 뿐이다 —
     * 그 조건이 없으면 `php artisan core:update` · `HH:mm` · `socks5h://…:1080` 같은
     * 본문 텍스트가 전부 오탐이 된다.
     *
     * @param  string  $source  대상 소스
     */
    #[Test]
    public function 치환_자리를_가진_키는_번역기_직접_호출에서도_파라미터와_함께_불린다(): void
    {
        $missing = [];
        $checked = 0;

        foreach ($this->phpFilesIn(app_path()) as $file) {
            $source = File::get($file);

            foreach ($this->translatorCallsIn($source) as [$offset, $call]) {
                $args = $this->topLevelArguments($call);

                // 파라미터를 넘기는 호출은 이 축의 대상이 아니다 (그 내용의 정합은
                // 형제 두 축과 코드 리뷰가 본다).
                if (count($args) !== 1) {
                    continue;
                }

                if (! preg_match("/^\s*'([^']+)'\s*$/", $args[0], $m)) {
                    continue;
                }

                $key = $m[1];
                $text = trans($key, [], 'ko');

                // 그룹 전체를 가리키는 키는 배열을 돌려준다 — 문장이 아니므로 대상 밖이다.
                // 해석되지 않는 키(번역기가 키 자체를 돌려준 것)도 lang 파일에 없는
                // 것이므로 이 축의 판정 대상이 아니다.
                if (! is_string($text) || $text === $key) {
                    continue;
                }

                $checked++;

                if (! preg_match('/(?:^|[\s"\'(\[])(:([a-zA-Z_][a-zA-Z0-9_]*))/', $text, $pm)) {
                    continue;
                }

                // Validator 가 채우는 자리는 호출부의 책임이 아니다 — `messages()` 가
                // 돌려준 문구의 `:attribute`·`:max` 등은 규칙 평가 시점에 치환된다.
                if (in_array($pm[2], self::VALIDATOR_FILLED_PLACEHOLDERS, true)) {
                    continue;
                }

                $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                $missing[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).':'.$line
                    .'  '.$key.' → '.$pm[1];
            }
        }

        $this->assertGreaterThan(
            100,
            $checked,
            '검사한 번역기 호출이 너무 적다 — 탐지가 모집단을 잃었다'
        );

        $this->assertSame(
            [],
            $missing,
            '다음 호출부가 치환 파라미터 없이 문구를 만든다 — 그 자리표시자가 운영자 화면·'
            ."로그에 그대로 노출된다:\n  ".implode("\n  ", $missing)
        );
    }

    /**
     * Validator 가 스스로 채우는 치환 자리 이름.
     *
     * 이 이름들은 `messages()` 가 돌려준 문구 안에서 규칙 평가 시점에 치환되므로,
     * 호출부가 파라미터로 넘기지 않는 것이 정상이다.
     *
     * @var list<string>
     */
    private const VALIDATOR_FILLED_PLACEHOLDERS = [
        'attribute', 'max', 'min', 'size', 'values', 'value', 'other', 'date', 'format', 'input',
    ];

    /**
     * 소스에서 `__(...)` / `trans(...)` 호출 전체를 괄호 균형으로 잘라 반환합니다.
     *
     * @param  string  $source  대상 소스
     * @return array<int, array{0: int, 1: string}> [호출 시작 오프셋, 호출 전체 문자열]
     */
    private function translatorCallsIn(string $source): array
    {
        $calls = [];

        foreach (['__(', 'trans('] as $token) {
            foreach ($this->callsIn($source, $token) as [$at, $call]) {
                // `$this->__(` · `Str::trans(` 처럼 다른 심볼의 꼬리가 우연히 맞는
                // 경우를 배제한다 — 토큰 바로 앞이 식별자 문자면 다른 이름이다.
                $before = $at > 0 ? $source[$at - 1] : ' ';

                if (preg_match('/[A-Za-z0-9_>:$\\\\]/', $before) === 1) {
                    continue;
                }

                $calls[] = [$at, $call];
            }
        }

        return $calls;
    }

    /**
     * 소스에서 `new *OperationException(...)` 생성자 호출 전체를 잘라 반환합니다.
     *
     * `->error()` 스캐너는 첫 인자가 문자열 리터럴이 아니면 건너뛰므로, 키를 들고 다니는
     * 예외로 던지는 경로는 그 판정에 걸리지 않는다. 그 축을 이 스캐너가 덮는다.
     *
     * @param  string  $source  대상 소스
     * @return array<int, array{0: int, 1: string}> [호출 시작 오프셋, 호출 전체 문자열]
     */
    private function exceptionConstructorsIn(string $source): array
    {
        $calls = [];

        if (! preg_match_all('/new\s+\\\\?(\w*OperationException)\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
            return $calls;
        }

        foreach ($m[0] as [$match, $at]) {
            foreach ($this->callsIn(substr($source, $at), $match) as [$rel, $call]) {
                if ($rel === 0) {
                    $calls[] = [$at, $call];
                    break;
                }
            }
        }

        return $calls;
    }

    /**
     * 소스에서 `->error(...)` 호출 전체를 괄호 균형으로 잘라 반환합니다.
     *
     * 한 줄만 보면 여러 줄에 걸친 호출의 뒷부분(치환 파라미터)을 놓쳐 오탐이 된다.
     *
     * @param  string  $source  대상 소스
     * @return array<int, array{0: int, 1: string}> [호출 시작 오프셋, 호출 전체 문자열]
     */
    private function errorCallsIn(string $source): array
    {
        return $this->callsIn($source, '->error(');
    }

    /**
     * 소스에서 지정한 여는 토큰으로 시작하는 호출 전체를 괄호 균형으로 잘라 반환합니다.
     *
     * @param  string  $source  대상 소스
     * @param  string  $token  호출 시작 토큰 (여는 괄호 포함, 예: `->error(`)
     * @return array<int, array{0: int, 1: string}> [호출 시작 오프셋, 호출 전체 문자열]
     */
    private function callsIn(string $source, string $token): array
    {
        $calls = [];
        $offset = 0;

        while (($at = strpos($source, $token, $offset)) !== false) {
            $open = $at + strlen($token) - 1;
            $depth = 0;
            $end = null;

            for ($i = $open, $len = strlen($source); $i < $len; $i++) {
                if ($source[$i] === '(') {
                    $depth++;
                } elseif ($source[$i] === ')') {
                    $depth--;

                    if ($depth === 0) {
                        $end = $i;
                        break;
                    }
                }
            }

            if ($end === null) {
                break;
            }

            $calls[] = [$at, substr($source, $open, $end - $open + 1)];
            $offset = $at + 1;
        }

        return $calls;
    }

    /**
     * 호출 문자열 `( ... )` 을 최상위 쉼표 기준으로 갈라 인자 목록을 반환합니다.
     *
     * 중첩 괄호·대괄호와 따옴표 안의 쉼표는 경계로 보지 않는다.
     *
     * @param  string  $call  괄호를 포함한 호출 문자열
     * @return array<int, string> 인자 문자열 목록
     */
    private function topLevelArguments(string $call): array
    {
        $inner = substr($call, 1, -1);
        $args = [];
        $buffer = '';
        $depth = 0;
        $quote = null;

        for ($i = 0, $len = strlen($inner); $i < $len; $i++) {
            $char = $inner[$i];

            if ($quote !== null) {
                $buffer .= $char;

                if ($char === '\\') {
                    $buffer .= $inner[++$i] ?? '';
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $buffer .= $char;

                continue;
            }

            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $args[] = trim($buffer);
                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $args[] = trim($buffer);
        }

        return $args;
    }

    /**
     * 주어진 디렉토리 아래의 PHP 파일 경로를 모두 반환합니다.
     *
     * @param  string  $directory  대상 디렉토리
     * @return array<int, string> PHP 파일 경로 목록
     */
    private function phpFilesIn(string $directory): array
    {
        $files = [];

        foreach (File::allFiles($directory) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
