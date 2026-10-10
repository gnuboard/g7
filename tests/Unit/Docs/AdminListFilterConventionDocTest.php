<?php

declare(strict_types=1);

namespace Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * 확장 필터 주입 안내 문서가 실제 규약과 어긋나지 않는지 잠급니다 (#705).
 *
 * 확장 조각은 어느 템플릿에 주입될지 모른다. 그래서 이커머스 예제 조각은 관리자 템플릿의
 * 컴포넌트 클래스(`filter-row`·`filter-label`)를 걷어내고 평범한 유틸로 행을 편다 — 다른
 * 템플릿 CSS 에는 그 규칙이 없어 배치가 무너지기 때문이다(`check-extension-css-coverage`
 * 「어휘 오용」). 그런데 그 규칙을 조각에만 적용하고 공개 문서의 예시는 그대로 두면, 문서를
 * 보고 만든 서드파티 조각이 같은 결함을 다시 들인다. 조각 쪽 가드(이커머스 Vitest)는 문서를
 * 읽지 않으므로 이 축은 여기서 잰다.
 *
 * 모집단은 손으로 적지 않는다 — 금지 어휘는 관리자 템플릿 `@layer components` 에만 있고 사용자
 * 템플릿 CSS 에는 없는 클래스, 검사 대상은 `docs/` 전체에서 `<extension extension-point=` 를 담은
 * 코드 블록과 컨벤션 문서의 「확장 필터 주입」 절이다. 어느 쪽이든 비면 그 자체를 red 로 만든다.
 */
final class AdminListFilterConventionDocTest extends TestCase
{
    /** 관리자 템플릿 CSS (컴포넌트 클래스의 정본) */
    private const ADMIN_CSS = 'templates/_bundled/sirsoft-admin_basic/src/styles/main.css';

    /** 사용자 템플릿 CSS — 여기에도 있는 클래스는 템플릿 공용 어휘로 본다 */
    private const USER_CSS = 'templates/_bundled/sirsoft-basic/src/styles/main.css';

    /** 목록 필터 컨벤션 문서 */
    private const CONVENTION_DOC = 'docs/frontend/admin-list-filter-convention.md';

    /** 컨벤션 문서에서 확장 주입을 다루는 절 제목 */
    private const CONVENTION_EXT_HEADING = '## 확장 필터 주입';

    /** 확장 필터 훅 표를 싣는 훅 문서와 그 절 제목 */
    private const HOOKS_DOC = 'docs/extension/hooks.md';

    private const HOOKS_HEADING = '### 관리자 회원 목록 확장 필터 훅';

    /**
     * 확장 조각 예시와 확장 주입 안내가 관리자 템플릿 전용 클래스를 쓰라고 하지 않는지 잠급니다.
     *
     * @scenario surface=extension_filter_docs
     *
     * @effects extension_filter_docs_use_template_neutral_classes
     *
     * @return void 단언만 수행한다
     */
    public function test_extension_guidance_uses_no_admin_template_only_class(): void
    {
        $adminOnly = array_diff($this->componentClasses(self::ADMIN_CSS), $this->allClasses(self::USER_CSS));

        $this->assertNotSame(
            [],
            $adminOnly,
            '관리자 템플릿 전용 컴포넌트 클래스를 하나도 파생하지 못했습니다 — CSS 경로나 @layer 구조가 바뀌었습니다.'
        );

        $units = $this->extensionExampleBlocks();

        $this->assertNotSame(
            [],
            $units,
            '`<extension extension-point=` 예시 코드 블록을 하나도 찾지 못했습니다 — 탐색 경로가 낡았습니다.'
        );

        $units[self::CONVENTION_DOC.' '.self::CONVENTION_EXT_HEADING] = $this->section(
            $this->read(self::CONVENTION_DOC),
            self::CONVENTION_EXT_HEADING
        );

        $violations = [];

        foreach ($units as $where => $text) {
            $used = array_intersect($this->classTokens($text), $adminOnly);

            if ($used !== []) {
                $violations[] = $where.' → '.implode(', ', array_values(array_unique($used)));
            }
        }

        $this->assertSame(
            [],
            $violations,
            "확장 조각 안내가 관리자 템플릿에만 있는 클래스를 씁니다 — 다른 템플릿에 주입되면 그 행의 배치가 무너집니다.\n"
            .implode("\n", $violations)
        );
    }

    /**
     * 컨벤션 문서의 확장 주입 절이 확장 필터 훅을 이름으로 모두 적는지 잠급니다.
     *
     * 계획서 §7-3-5 는 재사용 자산(훅 포함)의 쓰는 법을 **한 문서에** 못박으라고 했다 —
     * 「검증 훅」「쿼리 확장 훅」 같은 일반명과 링크만 두면 이 문서만 보고는 구독할 수 없다.
     *
     * @scenario surface=extension_filter_docs
     *
     * @effects convention_doc_names_every_user_list_extension_hook
     *
     * @return void 단언만 수행한다
     */
    public function test_convention_doc_names_every_user_list_extension_hook(): void
    {
        $hooksSection = $this->section($this->read(self::HOOKS_DOC), self::HOOKS_HEADING);

        preg_match_all('/^\|\s*`(core\.user\.[a-z_]+)`/m', $hooksSection, $m);
        $hooks = array_values(array_unique($m[1]));

        $this->assertNotSame(
            [],
            $hooks,
            '훅 문서의 「'.self::HOOKS_HEADING.'」 표에서 훅을 하나도 읽지 못했습니다 — 절 제목이나 표 형식이 바뀌었습니다.'
        );

        $convention = $this->section($this->read(self::CONVENTION_DOC), self::CONVENTION_EXT_HEADING);

        $missing = array_values(array_filter(
            $hooks,
            fn (string $hook): bool => ! str_contains($convention, '`'.$hook.'`')
        ));

        $this->assertSame(
            [],
            $missing,
            "컨벤션 문서의 확장 주입 절이 이름으로 적지 않은 확장 필터 훅이 있습니다.\n".implode("\n", $missing)
        );
    }

    /**
     * `docs/` 아래에서 `<extension extension-point=` 를 담은 코드 블록을 모읍니다.
     *
     * @return array<string, string> "파일 #블록번호" => 블록 본문
     */
    private function extensionExampleBlocks(): array
    {
        $blocks = [];
        $root = $this->root();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/docs', \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }

            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            preg_match_all('/```[a-z]*\R(.*?)```/s', (string) file_get_contents($file->getPathname()), $m);

            foreach ($m[1] as $i => $block) {
                if (str_contains($block, '<extension extension-point=')) {
                    $blocks[$rel.' #'.($i + 1)] = $block;
                }
            }
        }

        return $blocks;
    }

    /**
     * 텍스트에서 클래스로 쓰이는 토큰을 모읍니다 — `class`/`className` 속성값과 백틱 안 낱말.
     *
     * @param  string  $text  검사할 텍스트
     * @return array<int, string> 토큰 목록
     */
    private function classTokens(string $text): array
    {
        $tokens = [];

        preg_match_all('/class(?:Name)?="([^"]*)"/', $text, $attrs);
        foreach ($attrs[1] as $value) {
            array_push($tokens, ...preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY));
        }

        preg_match_all('/`([a-z][a-z0-9-]*)`/', $text, $ticks);
        array_push($tokens, ...$ticks[1]);

        return array_values(array_unique($tokens));
    }

    /**
     * CSS 의 `@layer components { … }` 블록 안에서 정의된 클래스 선택자를 모읍니다.
     *
     * @param  string  $relPath  저장소 기준 CSS 경로
     * @return array<int, string> 클래스 이름 목록
     */
    private function componentClasses(string $relPath): array
    {
        $css = $this->read($relPath);
        $at = strpos($css, '@layer components');
        $this->assertNotFalse($at, $relPath.' 에 @layer components 가 없습니다.');

        $open = strpos($css, '{', $at);
        $depth = 0;
        $end = strlen($css);

        for ($i = $open; $i < strlen($css); $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}' && --$depth === 0) {
                $end = $i;
                break;
            }
        }

        return $this->selectors(substr($css, $open, $end - $open));
    }

    /**
     * CSS 전체에서 클래스 선택자를 모읍니다.
     *
     * @param  string  $relPath  저장소 기준 CSS 경로
     * @return array<int, string> 클래스 이름 목록
     */
    private function allClasses(string $relPath): array
    {
        return $this->selectors($this->read($relPath));
    }

    /**
     * CSS 텍스트에서 `.name` 선택자를 뽑습니다.
     *
     * @param  string  $css  CSS 텍스트
     * @return array<int, string> 클래스 이름 목록
     */
    private function selectors(string $css): array
    {
        preg_match_all('/\.([a-z][a-z0-9-]*)(?=[\s,{:.>])/', $css, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * 마크다운에서 주어진 제목의 절(다음 같은 수준 이상 제목 전까지)을 잘라 냅니다.
     *
     * @param  string  $markdown  문서 본문
     * @param  string  $heading  `## 제목` 형태의 절 제목 줄
     * @return string 절 본문
     */
    private function section(string $markdown, string $heading): string
    {
        $start = strpos($markdown, $heading."\n");
        $this->assertNotFalse($start, '「'.$heading.'」 절을 찾지 못했습니다.');

        $level = strspn($heading, '#');
        $rest = substr($markdown, $start + strlen($heading));

        if (preg_match('/^#{1,'.$level.'} /m', $rest, $m, PREG_OFFSET_CAPTURE)) {
            $rest = substr($rest, 0, $m[0][1]);
        }

        return $rest;
    }

    /**
     * 저장소 기준 경로의 파일을 읽습니다.
     *
     * @param  string  $relPath  저장소 기준 경로
     * @return string 파일 내용
     */
    private function read(string $relPath): string
    {
        $path = $this->root().'/'.$relPath;
        $this->assertFileExists($path);

        return str_replace("\r\n", "\n", (string) file_get_contents($path));
    }

    /**
     * 저장소 루트 경로를 돌려줍니다.
     *
     * @return string 루트 절대 경로
     */
    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
