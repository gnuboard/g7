<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;
use App\Enums\MarkupTokenType;

/**
 * `.g7.html` 저작본의 **문서 최상위 `<permissions>`** 를 읽고 고치는 편집기 (S18.5 축 A).
 *
 * ## 왜 저작본을 고쳐야 하는가
 *
 * S18.5 이전에 `layout:add-permissions` 는 짝 `.json` 에 권한을 **in-place 로 기록**했다. 짝이 전건
 * 병존하던 동안에는 결함이 0 이었으나, 굽기(`layout:decompile --sync`)가 `.json` 을 기계 산출로
 * 바꾸는 순간 그 편집은 **다음 굽기에 소실된다**. 32차가 이 자리를
 * `JSON_ONLY_LAYOUT_CONSUMER_DEBT` 에 등재하면서 "S18.5 착수 시 저작본을 고치도록 전환해야 하는
 * 자리" 라고 사유까지 적어 두었다.
 *
 * ## 왜 정규식이 아니라 토크나이저인가
 *
 * `<permissions>` 는 문서 최상위와 **노드 레벨** 두 층에서 같은 문법을 쓴다(D36). 문자열 매칭으로
 * 첫 `<permissions>` 를 잡으면 노드 안의 조건 렌더 권한을 문서 권한으로 오인해 덮어쓴다. 게다가
 * 속성값 안의 `>` 는 정규식으로 안전하게 다룰 수 없다. 토큰 깊이로 판정하면 두 문제가 함께 사라진다
 * — 19차가 정규식 주석 제거기로 가드 전량을 침묵시킨 사고와 같은 계열의 회피다.
 */
final class MarkupPermissionsEditor
{
    /**
     * 문서 최상위 `<permissions>` 가 있는지 판정합니다.
     *
     * @param  string  $source  `.g7.html` 소스
     * @param  string|null  $path  경로 (오류 메시지용)
     * @return bool 있으면 true
     *
     * @throws CompileError 소스가 저작 문법 부분집합을 벗어난 경우
     */
    public static function hasDocumentPermissions(string $source, ?string $path = null): bool
    {
        return self::locate(self::normalize($source), $path) !== null;
    }

    /**
     * 문서 최상위 `<permissions>` 를 주어진 권한 목록으로 교체하거나 삽입합니다.
     *
     * 기존 블록이 있으면 그 span 을 통째로 갈고, 없으면 루트 첫 자식 **앞 줄**에 넣는다(자식이
     * 없으면 루트 종료 태그 앞). 들여쓰기는 기준 토큰의 열에서 그대로 가져온다 — 저작본마다 관례가
     * 다를 수 있으므로 상수로 박지 않는다.
     *
     * @param  string  $source  `.g7.html` 소스
     * @param  array<int, string>  $permissions  권한 문자열 목록 (빈 배열이면 `<permissions></permissions>`)
     * @param  string|null  $path  경로 (오류 메시지용)
     * @return string 편집된 소스 (LF 고정)
     *
     * @throws CompileError 소스가 저작 문법 부분집합을 벗어난 경우
     */
    public static function apply(string $source, array $permissions, ?string $path = null): string
    {
        $normalized = self::normalize($source);
        $existing = self::locate($normalized, $path);

        if ($existing !== null) {
            return substr($normalized, 0, $existing['start'])
                .self::render($permissions, $existing['indent'])
                .substr($normalized, $existing['end']);
        }

        // 기준 토큰이 **있는 줄의 시작**에 통째로 끼워 넣는다. 토큰 자신의 오프셋에 넣으면 그 줄에
        // 이미 있는 들여쓰기와 우리가 붙인 들여쓰기가 겹쳐 첫 줄만 두 배가 된다.
        $anchor = self::insertionAnchor($normalized, $path);

        return substr($normalized, 0, $anchor['offset'])
            .self::render($permissions, $anchor['indent'])."\n"
            .substr($normalized, $anchor['offset']);
    }

    /**
     * 권한 목록을 `<permissions>` 블록 마크업으로 렌더합니다.
     *
     * @param  array<int, string>  $permissions  권한 문자열 목록
     * @param  int  $indent  블록 시작 들여쓰기 (칸 수)
     * @return string 블록 마크업 (앞뒤 개행 없음)
     */
    public static function render(array $permissions, int $indent): string
    {
        $pad = str_repeat(' ', $indent);

        // 빈 권한은 "공개" 가 아니라 "권한 키가 있고 비었다" 는 선언이다 — 자기닫힘(`<permissions/>`)
        // 대신 여닫이를 쓰는 이유는 나중에 항목을 넣을 자리를 그대로 보여 주기 위함이다.
        if ($permissions === []) {
            return $pad.'<permissions></permissions>';
        }

        $lines = [$pad.'<permissions>'];

        foreach ($permissions as $permission) {
            $lines[] = $pad.'  <permission>'.$permission.'</permission>';
        }

        $lines[] = $pad.'</permissions>';

        return implode("\n", $lines);
    }

    /**
     * 소스를 LF·BOM 제거 상태로 정규화합니다 (토큰 좌표가 이 형태를 기준으로 계산됩니다).
     *
     * @param  string  $source  원본 소스
     * @return string 정규화된 소스
     */
    private static function normalize(string $source): string
    {
        $source = str_starts_with($source, "\u{FEFF}") ? substr($source, 3) : $source;

        return str_replace(["\r\n", "\r"], "\n", $source);
    }

    /**
     * 문서 최상위 `<permissions>` 의 소스 span 을 찾습니다.
     *
     * @param  string  $source  정규화된 소스
     * @param  string|null  $path  경로 (오류 메시지용)
     * @return array{start: int, end: int, indent: int}|null span (없으면 null)
     *
     * @throws CompileError 토큰화 실패
     */
    private static function locate(string $source, ?string $path): ?array
    {
        $depth = 0;
        $start = null;
        $indent = 0;

        foreach ((new MarkupTokenizer)->tokenize($source, $path) as $token) {
            if ($token->type === MarkupTokenType::StartTag) {
                // 문서 직속(depth 1)의 `<permissions>` 만 레이아웃 권한이다. 노드 레벨(D36)은 더 깊다.
                if ($depth === 1 && $token->name === 'permissions' && $start === null) {
                    $start = self::offsetOf($source, $token->line, $token->column);
                    $indent = $token->column - 1;
                }

                if (! $token->selfClosing && ! $token->void) {
                    $depth++;
                } elseif ($depth === 1 && $token->name === 'permissions' && $start !== null) {
                    // `<permissions/>` — 시작 토큰 하나로 끝난다.
                    return ['start' => $start, 'end' => self::endOfTag($source, $start), 'indent' => $indent];
                }

                continue;
            }

            if ($token->type !== MarkupTokenType::EndTag) {
                continue;
            }

            $depth--;

            if ($depth === 1 && $token->name === 'permissions' && $start !== null) {
                return [
                    'start' => $start,
                    'end' => self::offsetOf($source, $token->line, $token->column) + strlen('</permissions>'),
                    'indent' => $indent,
                ];
            }
        }

        return null;
    }

    /**
     * `<permissions>` 를 넣을 자리 — 루트의 첫 자식 시작 태그(없으면 루트 종료 태그)가 **있는 줄의 시작**입니다.
     *
     * @param  string  $source  정규화된 소스
     * @param  string|null  $path  경로 (오류 메시지용)
     * @return array{offset: int, indent: int} 삽입 오프셋(줄 시작)과 들여쓰기
     *
     * @throws CompileError 토큰화 실패 또는 루트 요소 부재
     */
    private static function insertionAnchor(string $source, ?string $path): array
    {
        $depth = 0;

        foreach ((new MarkupTokenizer)->tokenize($source, $path) as $token) {
            if ($token->type === MarkupTokenType::StartTag) {
                if ($depth === 1) {
                    return ['offset' => self::offsetOf($source, $token->line, 1), 'indent' => $token->column - 1];
                }

                if (! $token->selfClosing && ! $token->void) {
                    $depth++;
                }

                continue;
            }

            if ($token->type === MarkupTokenType::EndTag) {
                // 자식 없는 루트 — 종료 태그 앞에 넣는다. 들여쓰기는 루트보다 한 단 안쪽.
                if ($depth === 1) {
                    return ['offset' => self::offsetOf($source, $token->line, 1), 'indent' => $token->column + 1];
                }

                $depth--;
            }
        }

        throw new CompileError(LayoutMarkupErrorCode::UnknownDocumentRoot, 1, 1, ['tag' => ''], $path);
    }

    /**
     * 시작 오프셋에서 시작하는 태그의 끝(닫는 `>` 다음) 오프셋을 구합니다.
     *
     * 속성값 안의 `>` 를 건너뛰기 위해 인용부호 상태를 따라간다.
     *
     * @param  string  $source  정규화된 소스
     * @param  int  $start  `<` 위치
     * @return int 태그 끝 다음 오프셋
     */
    private static function endOfTag(string $source, int $start): int
    {
        $quote = null;

        for ($i = $start; $i < strlen($source); $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if ($char === '>') {
                return $i + 1;
            }
        }

        return strlen($source);
    }

    /**
     * 토큰 좌표(1-기반 줄·열, **문자** 단위)를 바이트 오프셋으로 바꿉니다.
     *
     * 열이 바이트가 아니라 문자를 센다는 것이 토크나이저의 계약이므로(매니페스트
     * `column_counts_characters_not_bytes`), 한글 주석이 앞에 있는 줄에서 어긋나지 않도록
     * `mb_substr` 로 자른 뒤 그 바이트 길이를 더한다.
     *
     * @param  string  $source  정규화된 소스
     * @param  int  $line  1-기반 줄
     * @param  int  $column  1-기반 열 (문자 단위)
     * @return int 바이트 오프셋
     */
    private static function offsetOf(string $source, int $line, int $column): int
    {
        $lines = explode("\n", $source);
        $offset = 0;

        for ($i = 0; $i < $line - 1 && isset($lines[$i]); $i++) {
            $offset += strlen($lines[$i]) + 1;
        }

        return $offset + strlen(mb_substr($lines[$line - 1] ?? '', 0, $column - 1));
    }
}
