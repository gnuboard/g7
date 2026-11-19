<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 인스톨러 화면이 쓰는 CSS 클래스가 실제로 선언돼 있는지 검사한다.
 *
 * 미선언 `var()` 는 여러 라운드가 0 으로 몰았지만 **미선언 클래스**는 검사된 적이 없었다.
 * computed 값 비교는 "선언된 규칙이 같은 값을 내는가" 만 보므로 규칙이 **아예 없는** 경우를
 * 그대로 통과시킨다 — 그 사각에서 실제 결함이 나왔다:
 *
 *  - `.test-success` / `.test-error` — Step 3 의 모든 검사 결과가 성공·실패 구분 없이 같은 색
 *    (§11.5 「상태색이 두 모드에서 모두 구분되어야 한다」 정면 위반)
 *  - `.card-title` — 헤더가 space-between flex 라 제목이 30px 로 커지고 설명이 오른쪽에 붙음
 *  - `.alert-danger` — 중단 화면의 롤백 실패 안내가 배경·테두리 없는 평문
 *  - `.loading-spinner` — "확인 중…" 옆의 스피너가 보이지 않음
 *  - `.form-help` / `.form-hint` — 입력 보조 문구가 본문과 같은 16px
 *
 * 전부 텍스트는 정상 표시되므로 스크린샷을 사람이 봐도 지나간다. 그래서 정적 검사가 필요하다.
 *
 * 검사 범위는 인스톨러로 한정한다 (제품 결정 2026-08-23) — 저장소 전체 룰은 기존 화면의
 * 미해결 위반을 대량으로 끌어와 작업 범위가 예측 불가하게 커진다.
 *
 * 커버 축: style_class_declared declared·undeclared·external·allowlisted
 */
class InstallerStyleClassCoverageTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /**
     * 규칙이 없어도 되는 클래스와 그 근거.
     *
     * 이 목록은 **정확히** 고정한다 — 항목이 늘면 그만큼 사각이 늘고, 항목을 실제로
     * 스타일링해 해소했는데 목록에 남아 있으면 다음 사람이 "면제된 것" 으로 읽는다.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        // ① JS 선택자 훅 — 스타일이 아니라 querySelector 대상이다
        'execution-mode-card' => 'ExecutionModeUi 가 .execution-mode-card[data-mode=…] 로 고른다 (스타일은 vendor-mode-card)',
        'execution-mode-status-text' => 'applyDetection 이 문구를 갈아 끼우는 지점',
        'vendor-mode-status-text' => '같은 형제 — Vendor 카드의 상태 문구 지점',
        'installation-start-section' => 'installer.js 가 표시/숨김을 토글하는 컨테이너',
        'requirements-result' => 'installer.js 가 요구사항 결과를 갈아 끼우는 컨테이너',
        'validation-warning' => 'installer.js 가 경고를 붙였다 떼는 지점',

        // ② 부모 규칙이 담당 — .execution-mode-info 가 자손 p/ul/li 를 모두 스타일한다
        'execution-mode-detail' => '.execution-mode-info p 가 담당 (의미 표시용 이름)',
        'execution-mode-reasons' => '.execution-mode-info ul 이 담당',
        'execution-mode-reasons-label' => '.execution-mode-info p 가 담당',
        'execution-mode-changes' => '.execution-mode-info ul 이 담당',
        'execution-mode-changes-intro' => '.execution-mode-info p 가 담당',
        'env-hardening-hint' => '.result-section 안의 안내 블록 — 부모 규칙이 담당',
        'env-hardening-hint-title' => '동상',
        'env-hardening-hint-status' => '동상',
        'env-hardening-hint-option' => '동상',
        'env-hardening-hint-note' => '동상',
        'cron-hint' => '예약 작업 안내 블록의 식별 이름 — 함께 붙은 env-hardening-hint 규칙이 담당 (7.2.0 합류)',

        // ③ 소비처 0 — 종전 코드에 남아 있는 표식. 제거는 이 검사와 별개 결정이라 여기서는 고정만 한다
        'execution-mode-cards' => '소비처 0 (스타일은 vendor-mode-cards) — 제거는 별개 결정',
        // 모집단에 includes/**·api/** 를 넣으면서 드러난 둘. 호출자 0 인
        // renderProgressIndicator() 가 유일한 출처이며, 죽은 함수를 지우는 것은 이 검사와
        // 별개 결정이라 여기서는 사실만 고정한다.
        'progress-indicator' => '호출자 0 인 renderProgressIndicator() 의 출력 — 제거는 별개 결정',
        'progress-step' => '동상 (형제 — .progress-steps 와 다른 이름)',
        'installer-form' => '소비처 0 (폼 자체에 규칙 없음) — HEAD 기원',
        'form-group' => '소비처 0 — HEAD 기원, .form-grid/.form-fields 가 배치를 담당',
        'log-separator' => '소비처 0 — HEAD 기원',
        'result-version' => '소비처 0 — HEAD 기원',
        'btn-sm' => '소비처 0 — HEAD 기원',
        // classList 채널을 모집단에 넣으면서 드러난 항목
        'single-select' => '소비처 0 — 단일 선택 카드 표식만, 스타일·선택자 소비 없음 (HEAD 기원)',
    ];

    /**
     * 저장소 안 설치기 폴더의 절대 경로를 만든다.
     *
     * @return string 설치기 폴더 경로
     */
    private function installDir(): string
    {
        return dirname(__DIR__, 3).'/public/install';
    }

    /**
     * 인스톨러 스타일시트 전수가 선언하는 클래스 이름 집합.
     *
     * 종전에는 `installer.css` 한 파일을 하드코딩했다 — 스타일시트가 하나 더 생기면 그 안의
     * 규칙은 「선언 없음」으로 계상되어 정상 스타일이 위반으로 잡힌다.
     *
     * @return array<string, int> 스타일시트가 선언한 클래스 이름을 키로 둔 집합
     */
    private function declaredClasses(): array
    {
        return $this->declaredClassesIn($this->stylesheetSource());
    }

    /**
     * 인스톨러 스타일시트 전수를 이어붙인 소스 — 주석은 지운 채 (G3 회차 14 W4-8).
     *
     * 읽기와 주석 제거를 공용 진입점(`sourceWithoutComments()`, CSS 는 블록 주석만)에 묶는다.
     * 종전에는 원문을 이어붙이고 소비처마다 정규식으로 다시 지웠다 — 한 소비처가 그것을
     * 잊으면 주석 속 선택자가 「선언」으로 계상되어 규칙을 지우고 이름만 남겨도 초록이다.
     *
     * @return string CSS 소스
     */
    private function stylesheetSource(): string
    {
        $files = $this->scanFiles($this->installDir().'/assets/css', ['css']);
        $this->assertNotEmpty($files, '인스톨러 스타일시트를 하나도 찾지 못했다 — 스캔이 죽었다');

        return implode("\n", array_map(fn (string $f): string => $this->sourceWithoutComments($f), $files));
    }

    /**
     * 디렉토리를 **재귀**로 훑어 확장자가 맞는 파일을 모은다.
     *
     * 한 겹 `glob('*.php')` 은 하위 디렉토리가 하나 생기는 순간 그 안을 통째로 놓친다 —
     * 형제(격리 스캐너)에서 닫은 축이 여기에는 적용되지 않았다.
     *
     * @param  string  $dir  대상 디렉토리
     * @param  array<int, string>  $extensions  확장자
     * @return array<int, string> 절대 경로 (정렬됨)
     */
    private function scanFiles(string $dir, array $extensions): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), $extensions, true)) {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        sort($files);

        return $files;
    }

    /**
     * CSS 소스에서 선언된 클래스 이름을 뽑는다 (판정 본체).
     *
     * 실 경로와 픽스처가 **같은 이 메서드**를 부른다 — 픽스처가 같은 정규식을 다시 써서
     * 그 사본을 검증하면, 여기의 주석 제거를 지워도 픽스처는 초록이고 다른 검사들은
     * 더 관대해질 뿐이라 역시 초록이 된다. 앞서 닫았다고 기록한 축이 그 형태로
     * 열려 있었다.
     *
     * @param  string  $css  CSS 소스
     * @return array<string, int> 클래스 이름 → 임의 인덱스
     */
    private function declaredClassesIn(string $css): array
    {
        // 주석을 먼저 지운다 — 이 파일은 설계 주석이 많고 그 안에 클래스명이 흔히 등장한다.
        // 주석을 「선언」으로 세면 규칙을 지우고 이름만 남겨도 이 검사가 초록이 된다.
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        preg_match_all('/\.([A-Za-z][A-Za-z0-9_-]*)/', $css, $m);

        return array_flip($m[1]);
    }

    /**
     * 마크업이 실제로 붙이는 클래스 이름 → 그 이름이 나온 파일 목록.
     *
     * 제외 대상:
     *  - `403-forbidden.php` — installer.css 가 아니라 독립 페이지 CSS(`installerStandaloneCssUrl()`)를 싣는 페이지다
     *  - Font Awesome (`fa`/`fas`/`far`/`fab`/`fa-*`) — 외부 스타일시트 소유
     *  - `-` 로 끝나는 토큰 — `'alert alert-' + kind` 같은 문자열 조립의 앞조각
     *  - **주석 속** 클래스 — 소스는 주석을 지운 뒤 읽는다(`sourceWithoutComments()`, G3 회차 14
     *    W4-8). 주석에 적힌 `class="…"` 는 화면에 붙지 않는데 모집단에 들면 그 이름에 CSS 선언
     *    의무가 생겨(거짓 red) 다음 사람이 죽은 클래스에 규칙을 만들거나 면제 목록을 늘린다.
     *    반대 방향은 없다 — 주석으로 감싼 마크업이 모집단에서 빠지는 것은 실제로 붙지 않는
     *    클래스가 빠지는 것이라 옳다.
     *
     * @return array<string, array<int, string>> 클래스 이름별로 그 이름이 나온 파일 이름 목록
     */
    private function usedClasses(): array
    {
        $dir = $this->installDir();
        // 「전수」를 자처하면서 **PHP 헬퍼가 만드는 마크업**을 통째로 빼고 있었다 —
        // renderInstallResultSection()·getSvgIcon() 같은 함수가 그 채널이고, 앞서 신설한
        // `.result-icon-dot` 의 출처가 바로 여기다. 검사는 그 채널을 보지 않았다.
        // 인스톨러 전수를 **재귀**로 훑는다. 종전에는 세 디렉토리를 한 겹씩 glob 하고
        // JS 두 파일과 index.php 를 손으로 적었다 — 하위 디렉토리나 새 스크립트가 생기면 그
        // 마크업은 영원히 모집단 밖이고, 미선언 클래스가 그 안에 살아 있어도 초록이다.
        $files = array_merge(
            $this->scanFiles($dir, ['php']),
            $this->scanFiles($dir.'/assets/js', ['js']),
        );

        $used = [];
        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file, strlen($dir) + 1));
            if (basename($file) === '403-forbidden.php' || ! is_file($file)) {
                continue;
            }
            if (str_starts_with($relative, 'lang/')) {
                continue;   // 언어 파일은 문구의 정의이고 마크업이 아니다
            }

            $source = $this->sourceWithoutComments($file);

            foreach ($this->classesInSource($source) as $token) {
                $used[$token][] = basename($file);
            }
        }

        return $used;
    }

    /**
     * 한 소스가 붙이는 클래스 토큰.
     *
     * 두 채널을 함께 본다 — 속성 리터럴(`class="…"`)과 **동적 부착**
     * (`classList.add('x')`·`toggle`·`remove`). 뒤쪽을 빼면 진행 화면의 상태색을 담당하는
     * 이름(`task-failed`·`status-skipped` 등)이 통째로 사각이 되고, 실제로 그 사각에 미선언
     * 클래스가 살아 있었다.
     *
     * @param  string  $source  주석을 걷어낸 마크업 또는 스크립트 소스
     * @return array<int, string> 속성 리터럴과 동적 부착에서 모은 클래스 토큰
     */
    private function classesInSource(string $source): array
    {
        // 보간 조각을 먼저 걷어낸다 — 이 채널이 통째로 버려지고 있었다.
        //
        //  - JS 템플릿 리터럴: `class="requirement-card${modifier}"` 는 토큰 전체가 이름 규칙을
        //    통과하지 못해 **앞조각까지 함께** 사라졌다.
        //  - PHP 짧은 echo: 속성값 안에 조건부 출력 태그가 들어가면 속성 정규식이 값 안의
        //    첫 따옴표에서 끊겨 뒷조각을 잃었다.
        //    (여기에 그 태그를 예시로 적지 않는다 — 줄 주석 안의 닫는 태그는 PHP 를 실제로 닫는다.)
        //
        // 조건부 클래스는 **class 속성 안의** 출력 태그에만 있다 — 그 자리에서만 리터럴을 거둔다.
        // 소스 전체의 출력 태그를 리터럴로 치환하면 `value="…"` 같은 다른 속성의 값이 클래스
        // 이름으로 새어 들어온다(실측: auto·bundled·php_binary …).
        $attributes = self::conditionalClassLiterals($source);

        // 원본은 건드리지 않는다 — 출력 태그를 통째로 지우면 **PHP 코드 안의 문자열**에 들어 있는
        // 마크업(`$html .= '<div class="env-hardening-hint">'`)이 함께 사라진다(실측).
        if (preg_match_all('/class(?:Name)?\s*=\s*["\'`]([^"\'`]*)["\'`]/', $source, $matches)) {
            $attributes = array_merge($attributes, $matches[1]);
        }
        if (preg_match_all('/classList\.(?:add|toggle|remove)\(\s*[\'"`]([^\'"`]+)[\'"`]/', $source, $dynamic)) {
            $attributes = array_merge($attributes, $dynamic[1]);
        }

        $tokens = [];
        foreach ($attributes as $attribute) {
            // 보간은 **속성값 안에서만** 지운다. 소스 전체에 걸면 중첩 템플릿 리터럴에서 `}` 짝이
            // 어긋나 큰 구간이 통째로 사라진다(실측: `.execution-mode-reasons-label` 소실).
            $attribute = (string) preg_replace('/\$\{[^}]*\}/', ' ', $attribute);

            foreach (preg_split('/\s+/', $attribute) ?: [] as $token) {
                $token = trim($token);
                if (! preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $token)) {
                    continue;
                }
                if (str_ends_with($token, '-')) {
                    continue;
                }
                if (preg_match('/^fa[srbl]?$/', $token) || str_starts_with($token, 'fa-')) {
                    continue;
                }
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * `class` 속성 안의 PHP 출력 태그에서 조건부 클래스 리터럴을 거둔다.
     *
     * 조건부 클래스는 이 형태로만 나타난다 — 소스 전체의 출력 태그를 뒤지면 `value="…"` 같은
     * 다른 속성의 값이 클래스 이름으로 새어 들어온다 (구현 중 실측).
     *
     * @param  string  $source  원본
     * @return array<int, string> 공백 구분 클래스 문자열 목록
     */
    private static function conditionalClassLiterals(string $source): array
    {
        preg_match_all('/class(?:Name)?\s*=\s*"([^"]*(?:<\?[\s\S]*?\?>[^"]*)+)"/', $source, $matches);

        $collected = [];
        foreach ($matches[1] as $value) {
            // 출력 태그를 그 안의 **분기 리터럴**로 바꾼다 — 태그 앞뒤의 리터럴 클래스와
            // 태그 안의 조건부 클래스를 한 문자열로 합쳐 돌려준다.
            $collected[] = (string) preg_replace_callback(
                '/<\?(?:=|php)([\s\S]*?)\?>/',
                static fn (array $m): string => ' '.implode(' ', self::stringLiteralsIn($m[1])).' ',
                $value
            );
        }

        return $collected;
    }

    /**
     * PHP 코드 조각 안의 문자열 리터럴만 뽑는다.
     *
     * `<?= $blocked ? 'disabled' : '' ?>` 처럼 조건부 클래스가 echo 안의 리터럴로 들어오는
     * 표기가 흔하다 — 조각을 통째로 지우면 그 클래스가 모집단에서 사라진다.
     *
     * @param  string  $php  `<?= … ?>` 안쪽 코드
     * @return array<int, string> 리터럴 목록
     */
    private static function stringLiteralsIn(string $php): array
    {
        // **삼항 분기의 결과 자리**에 있는 문자열만 뽑는다. 조건부 클래스는 언제나 그 자리에
        // 온다. 반면 같은 조각의 배열 첨자(`$formData[…]`)와 비교 대상(`=== '…'`)은 데이터이지
        // 클래스가 아니다 — 구분 없이 거두면 `auto`·`use_read_db` 같은 값이 클래스 모집단에
        // 섞여 「미선언 클래스」 오탐이 된다(실측 9건). `??` 는 삼항이 아니므로 제외한다.
        preg_match_all('/(?<![?:])[?:]\s*\'([^\']*)\'/', $php, $matches);

        return array_values(array_filter($matches[1], static fn (string $v): bool => $v !== ''));
    }

    /**
     * 상태 문구는 **문구 전용 토큰**으로 칠한다.
     *
     * `--success-color` 등은 아이콘·테두리용 밝은 값이다. 12~14px 본문에 쓰면 라이트 테마에서
     * 1.9~3.4:1 로 §11.5 의 4.5:1 을 못 넘긴다 — 다크는 정상이라 어두운 화면만 보면 드러나지
     * 않는다. 앞서 W2 상태 줄에 이 규칙을 세웠지만 **형제 지점**(W3 진행 목록·그룹 배지·
     * 완료 문구)에는 적용되지 않아 한 목록 안에서 규칙이 갈려 있었다.
     *
     * 실제 대비 계산 대신 「어느 층의 토큰을 썼는가」를 본다 — 토큰 층이 곧 그 규칙이고,
     * rgba 합성까지 계산하는 검사는 배경 스택을 재현해야 해서 되레 신뢰도가 낮다.
     *
     * @scenario style_class_declared=declared
     *
     * @effects installer_status_text_uses_text_tier_tokens
     *
     * @return void 반환값 없음
     */
    public function test_status_text_uses_text_tier_tokens(): void
    {
        // 상태를 **글자로** 전하는 선택자들. 아이콘·테두리 규칙(`.task-* .task-icon`)은 도형이라
        // 대상이 아니다 — 그래서 선택자 끝이 정확히 이 이름들인 규칙만 본다.
        $statusSelectors = [
            // statusPresentation 에 pending 을 채우면서 도출 대조가 요구한 등재.
            '.task-pending',
            '.task-completed',
            '.task-failed',
            '.task-aborted',
            '.task-active',
            '.task-skipped',
            '.task-group-status.status-completed',
            '.task-group-status.status-error',
            '.task-group-status.status-aborted',
            '.task-group-status.status-in-progress',
            '.task-group-status.status-skipped',
            // 도출 대조(아래)가 잡아낸 누락 — 수기 13개 시절에 빠져 있던 구성원.
            '.task-group-status.status-pending',
            '.progress-status.progress-status--success',
            '.vendor-mode-card-status-ok',
            '.vendor-mode-card-status-error',
        ];

        // 아이콘·테두리 층 토큰 — 문구 색으로 쓰면 라이트에서 기준 미달이다.
        $iconTierTokens = ['--success-color', '--error-color', '--warning-color', '--primary-color'];

        $css = (string) preg_replace('#/\*.*?\*/#s', '', $this->stylesheetSource());

        // 위 목록은 수기다 — 수기 모집단은 새 구성원이 추가될 때 조용히 사각이 된다
        // (§16.25.10 규약 ②). 그래서 네 가족의 구성원을 소스에서 **도출**해 목록이 그
        // 상위집합인지 먼저 잠근다: 상태 가족 3종은 CSS 선택자에서, `.task-*` 상태 클래스는
        // installer.js 의 상태→클래스 매핑(statusPresentation — 그 축의 SSoT)에서 나온다.
        // 새 상태 클래스가 어느 쪽에 추가되든 이 대조가 red 가 되어 목록 갱신을 강제한다.
        $derived = [];
        foreach ([
            'task-group-status\.status-[a-z-]+',
            'progress-status\.progress-status--[a-z-]+',
            'vendor-mode-card-status-[a-z-]+',
        ] as $familyPattern) {
            preg_match_all('/\.('.$familyPattern.')\s*[,{]/', $css, $familyMatches);
            foreach ($familyMatches[1] as $member) {
                $derived['.'.$member] = true;
            }
        }
        $js = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/assets/js/installer.js');
        preg_match_all("/className:\s*'(task-[a-z-]+)'/", $js, $taskStates);
        foreach ($taskStates[1] as $member) {
            $derived['.'.$member] = true;
        }
        $this->assertNotEmpty($taskStates[1], 'JS 상태→클래스 매핑 도출 실패 — statusPresentation 형태가 바뀌었다면 이 도출도 갱신할 것');
        $underivedListed = array_diff(array_keys($derived), $statusSelectors);
        $this->assertSame(
            [],
            array_values($underivedListed),
            '소스에서 도출된 상태 선택자가 검사 목록에 없다 — 새 상태 클래스가 추가된 것이니 위 목록에 등재할 것'
        );

        $offenders = [];
        $checked = 0;

        foreach ($statusSelectors as $selector) {
            $pattern = '/(?<![A-Za-z0-9_.-])'.preg_quote($selector, '/').'\s*\{([^}]*)\}/';
            if (preg_match_all($pattern, $css, $matches) === 0) {
                $offenders[] = $selector.' (규칙 없음 — 선택자 이름이 바뀌었다면 이 목록도 갱신할 것)';

                continue;
            }

            foreach ($matches[1] as $body) {
                $checked++;
                if (preg_match('/(?:^|[;\s])color\s*:\s*var\(\s*(--[a-z-]+)/i', $body, $colorMatch) !== 1) {
                    continue;
                }
                if (in_array($colorMatch[1], $iconTierTokens, true)) {
                    $offenders[] = $selector.' → color: var('.$colorMatch[1].')';
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            count($statusSelectors),
            $checked,
            '검사한 규칙 수가 선택자 수보다 적다 — 목록이 실제 CSS 와 어긋났다'
        );
        $this->assertSame(
            [],
            $offenders,
            '상태 문구에 아이콘 층 토큰을 쓰면 라이트 테마에서 배경에 묻힌다 — 문구 층(--*-text) 토큰을 쓸 것'
        );
    }

    /**
     * 마크업 클래스 모집단과 스타일시트 선언 집합이 추출 실패로 비지 않고 하한 이상인지 확인한다.
     *
     * @scenario style_class_declared=declared
     *
     * @effects installer_markup_classes_have_style_rules
     *
     * @return void 반환값 없음
     */
    public function test_population_is_not_empty(): void
    {
        $used = $this->usedClasses();
        $this->assertGreaterThanOrEqual(150, count($used), '마크업 클래스 모집단이 이보다 작을 수 없다 — 추출이 깨진 것이다');
        $this->assertArrayHasKey('test-result', $used, '검사 결과 컨테이너가 모집단에 있어야 한다');
        $this->assertGreaterThanOrEqual(250, count($this->declaredClasses()), 'CSS 선언 집합이 비면 이 검사가 무의미하다');
    }

    /**
     * 마크업이 붙이는 모든 클래스가 스타일 규칙을 갖거나 면제 목록에 사유와 함께 올라 있는지 확인한다.
     *
     * @scenario style_class_declared=undeclared
     *
     * @effects installer_markup_classes_have_style_rules
     *
     * @return void 반환값 없음
     */
    public function test_every_markup_class_has_a_style_rule_or_a_stated_reason(): void
    {
        $declared = $this->declaredClasses();
        $offenders = [];

        foreach ($this->usedClasses() as $token => $files) {
            if (isset($declared[$token]) || isset(self::ALLOWED[$token])) {
                continue;
            }
            $offenders[] = $token.' ('.implode(', ', array_unique($files)).')';
        }

        sort($offenders);
        $this->assertSame(
            [],
            $offenders,
            "installer.css 에 규칙이 없는 클래스다 — 스타일을 만들거나, 규칙이 필요 없는 이유를 ALLOWED 에 적는다:\n  ".implode("\n  ", $offenders)
        );
    }

    /**
     * 면제 목록에 죽은 항목이 남지 않게 한다.
     *
     * 실제로 스타일링해 해소한 클래스가 목록에 남아 있으면 다음 사람이 "면제된 것" 으로
     * 읽고, 그 규칙을 지워도 아무도 모른다.
     *
     * @scenario style_class_declared=allowlisted
     *
     * @effects installer_markup_classes_have_style_rules
     *
     * @return void 반환값 없음
     */
    public function test_allowlist_has_no_stale_entries(): void
    {
        $declared = $this->declaredClasses();
        $used = $this->usedClasses();

        foreach (self::ALLOWED as $token => $reason) {
            $this->assertNotSame('', trim($reason), "{$token} 의 면제 사유가 비어 있다");
            $this->assertArrayNotHasKey(
                $token,
                $declared,
                "{$token} 는 이제 CSS 규칙이 있다 — 면제 목록에서 지운다"
            );
            $this->assertArrayHasKey(
                $token,
                $used,
                "{$token} 는 더 이상 마크업에 없다 — 면제 목록에서 지운다"
            );
        }
    }

    /**
     * 추출기의 부정·긍정 분기를 합성 입력으로 태운다.
     *
     * 실 소스가 준수 상태라 위 검사의 「미선언 0」은 추출기가 죽어도 유지된다 — 그 상태는
     * 「검사했으나 통과」가 아니라 「아무것도 보지 않음」이다.
     *
     * @scenario style_class_declared=external
     *
     * @effects installer_markup_classes_have_style_rules
     *
     * @return void 반환값 없음
     */
    public function test_class_extraction_covers_both_channels(): void
    {
        $tokens = $this->classesInSource(<<<'SRC'
            <div class="alert alert-error"></div>
            el.classList.add('task-failed');
            el.classList.toggle('status-skipped', flag);
            <i class="fas fa-check"></i>
            html += `<div class="alert alert-${kind}">`;
            html += `<div class="requirement-card${cardModifier}"></div>`;
            <label class="installation-mode-option <?= $blocked ? 'disabled' : '' ?>">
        SRC);

        $this->assertContains('alert-error', $tokens, '속성 리터럴 채널');
        $this->assertContains('task-failed', $tokens, 'classList.add 채널 — 종전 수집에서 빠져 있던 채널');
        $this->assertContains('status-skipped', $tokens, 'classList.toggle 채널');
        $this->assertContains('requirement-card', $tokens, '보간이 붙은 앞조각도 실재하는 클래스다');
        $this->assertContains('installation-mode-option', $tokens, 'PHP 짧은 echo 앞의 리터럴');
        $this->assertContains('disabled', $tokens, 'PHP 짧은 echo **안**의 조건부 클래스 — 종전에는 정규식이 여기서 끊겼다');
        $this->assertNotContains('fa-check', $tokens, 'Font Awesome 은 외부 스타일시트 소유다');
        $this->assertNotContains('fas', $tokens);
        $this->assertNotContains('alert-', $tokens, '문자열 조립의 앞조각은 클래스 이름이 아니다');
    }

    /**
     * 모집단 스캔이 **재귀**다 (부정 픽스처).
     *
     * 실 디렉토리가 지금은 평면이라 한 겹 glob 과 재귀 스캔의 결과가 같다 — 그 상태에서는
     * 스캔을 한 겹으로 되돌려도 red 가 나지 않는다. 하위 디렉토리를 실제로 만들어 잡는다.
     *
     * @scenario style_class_declared=undeclared
     *
     * @effects installer_markup_classes_have_style_rules
     *
     * @return void 반환값 없음
     */
    public function test_population_scan_is_recursive(): void
    {
        $temp = sys_get_temp_dir().'/g7-style-scan-'.bin2hex(random_bytes(4));
        @mkdir($temp.'/nested', 0777, true);
        file_put_contents($temp.'/top.php', '<?php');
        file_put_contents($temp.'/nested/deep.php', '<?php');

        try {
            $found = array_map('basename', $this->scanFiles($temp, ['php']));

            $this->assertContains('top.php', $found);
            $this->assertContains('deep.php', $found, '한 겹 glob 은 하위 디렉토리를 통째로 놓친다');
        } finally {
            @unlink($temp.'/nested/deep.php');
            @unlink($temp.'/top.php');
            @rmdir($temp.'/nested');
            @rmdir($temp);
        }
    }

    /**
     * 선언 추출은 주석 안의 이름을 세지 않는다.
     *
     * @scenario style_class_declared=declared
     *
     * @effects installer_markup_classes_have_style_rules
     *
     * @return void 반환값 없음
     */
    public function test_declaration_extraction_ignores_comments(): void
    {
        // 판정 본체를 **직접 부른다** — 같은 정규식을 여기 다시 쓰면 사본을 검증하는 것이라
        // 본체의 주석 제거를 지워도 이 픽스처가 초록으로 남는다.
        $css = '/* .ghost-class 는 설명일 뿐이다 */
.real-class { color: red; }
';
        $declared = $this->declaredClassesIn($css);

        $this->assertArrayHasKey('real-class', $declared);
        $this->assertArrayNotHasKey('ghost-class', $declared, '주석이 규칙 행세를 하면 규칙을 지우고 이름만 남겨도 초록이 된다');
    }
}
