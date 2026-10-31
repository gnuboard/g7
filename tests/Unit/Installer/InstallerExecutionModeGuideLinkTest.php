<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * W1 실행 방식 정보 카드의 [자세히 보기] 링크 계약 (D32 · §11.12 · P7).
 *
 * 이 링크는 화면에 한 줄로 보이지만 지키는 축이 둘이고, 둘 다 조용히 깨진다.
 *
 *  ① 링크가 사라지는 것 — 카드 본문은 문자열 템플릿이라 지워도 오류가 나지 않는다.
 *  ② 링크가 남은 채 **목적지가 없어지는 것** — `INSTALL.md` 의 제목을 고치면 앵커가
 *     달라지는데, 링크는 그대로라 404 가 아니라 **문서 맨 위**가 열린다. 운영자는
 *     자기가 찾던 절이 없다고 읽는다.
 *
 * 그래서 링크 존재만 재지 않고, 주소의 조각(fragment)을 디코드해 `INSTALL.md` 에
 * **실제로 그 앵커를 만드는 제목이 있는지**까지 대조한다.
 *
 * 그 계약의 모집단은 이 화면 하나가 아니다 — 같은 형태의 안내 링크는 관리자 화면에도
 * 있고(코어 업데이트 안내 모달), 앞으로도 늘어난다. 그래서 두 이름을 손으로 적지 않고
 * **git 이 추적하는 파일 전수에서 도출**한다(활성 확장 디렉토리 사본은 추적 대상이
 * 아니라 자동으로 빠진다). 손으로 적으면 세 번째 링크가 생기는 날 그 자리가 사각이 된다.
 *
 * 커버 축: installer_gate_status info_blue
 */
class InstallerExecutionModeGuideLinkTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /**
     * 이 테스트 파일에서 세 단계 위인 저장소 루트 경로를 돌려준다.
     *
     * @return string 저장소 루트 절대 경로
     */
    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * 설치 화면 스크립트 원문을 주석을 지운 형태로 읽는다.
     *
     * @return string 주석을 지운 설치 화면 스크립트 소스
     */
    private function installerJs(): string
    {
        return $this->sourceWithoutComments($this->repoRoot().'/public/install/assets/js/installer.js');
    }

    /**
     * GitHub 이 마크다운 제목에서 앵커를 만드는 규칙.
     *
     * 소문자화 → 공백을 하이픈으로 → 그 외 비단어 문자 제거. 한글은 그대로 남는다.
     *
     * @param  string  $heading  앵커로 바꿀 마크다운 제목 문장
     * @return string GitHub 규칙으로 만든 앵커 조각
     */
    private function slug(string $heading): string
    {
        $heading = mb_strtolower(trim($heading), 'UTF-8');

        $out = '';
        $len = mb_strlen($heading, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($heading, $i, 1, 'UTF-8');
            if ($ch === ' ') {
                $out .= '-';
            } elseif ($ch === '-' || $ch === '_' || preg_match('/^[\p{L}\p{N}]$/u', $ch)) {
                $out .= $ch;
            }
        }

        return $out;
    }

    /**
     * W1 실행 방식 정보 카드 본문이 안내 주소 상수와 다국어 [자세히 보기] 문구를 쓰고, 새 창 링크에 noopener 를 붙이는지 확인한다.
     *
     * @scenario installer_gate_status=info_blue
     *
     * @effects installer_execution_mode_card_links_to_install_guide
     *
     * @return void 반환값 없음
     */
    public function test_info_card_renders_the_guide_link(): void
    {
        $js = $this->installerJs();

        $this->assertMatchesRegularExpression(
            '/const EXECUTION_MODE_GUIDE_URL = \'[^\']+\';/',
            $js,
            '실행 방식 안내 주소 상수가 없다 — [자세히 보기] 링크의 목적지가 사라졌다 (D32)'
        );

        $card = $this->extractInfoCardBody($js);

        $this->assertStringContainsString(
            'EXECUTION_MODE_GUIDE_URL',
            $card,
            'W1 정보 카드가 안내 주소를 쓰지 않는다 — 상수만 남고 링크가 빠졌다'
        );
        $this->assertStringContainsString(
            "lang('execution_mode_learn_more')",
            $card,
            '[자세히 보기] 문구가 다국어 키를 거치지 않는다'
        );
        $this->assertStringContainsString(
            'rel="noopener noreferrer"',
            $card,
            '새 창으로 여는 외부 링크에 noopener 가 없다'
        );
    }

    /**
     * 안내 주소가 공개 저장소의 설치 문서를 가리키고, 그 주소의 앵커를 만드는 제목이 설치 문서에 실제로 있는지 확인한다.
     *
     * @scenario installer_gate_status=info_blue
     *
     * @effects installer_execution_mode_card_links_to_install_guide
     *
     * @return void 반환값 없음
     */
    public function test_guide_url_points_at_a_heading_that_exists_in_install_md(): void
    {
        $js = $this->installerJs();

        $this->assertSame(
            1,
            preg_match('/const EXECUTION_MODE_GUIDE_URL = \'([^\']+)\';/', $js, $m),
            '안내 주소 상수를 읽지 못했다'
        );

        $url = $m[1];
        $this->assertStringStartsWith(
            'https://github.com/gnuboard/g7/blob/main/INSTALL.md#',
            $url,
            '안내 주소가 공개 저장소의 INSTALL.md 를 가리키지 않는다'
        );

        $fragment = rawurldecode((string) parse_url($url, PHP_URL_FRAGMENT));
        $this->assertNotSame('', $fragment, '안내 주소에 절 앵커가 없다 — 문서 맨 위가 열린다');

        $install = (string) file_get_contents($this->repoRoot().'/INSTALL.md');
        $headingCount = preg_match_all('/^#{1,6}\s+(.*)$/m', $install, $heads);

        // 빈 모집단은 조용히 통과한다 — 제목을 하나도 못 읽었으면 그 사실을 먼저 드러낸다.
        $this->assertGreaterThan(0, $headingCount, 'INSTALL.md 에서 제목을 하나도 읽지 못했다');

        $anchors = array_map(fn (string $h): string => $this->slug($h), $heads[1]);

        $this->assertContains(
            $fragment,
            $anchors,
            "INSTALL.md 에 앵커 '{$fragment}' 를 만드는 제목이 없다 — 링크는 살아 있지만 목적지가 사라져 문서 맨 위가 열린다 (D32)"
        );
    }

    /**
     * 설치기 언어 폴더에서 찾은 모든 로케일 파일에 [자세히 보기] 문구 키가 있는지 확인한다.
     *
     * @scenario installer_gate_status=info_blue
     *
     * @effects installer_execution_mode_card_links_to_install_guide
     *
     * @return void 반환값 없음
     */
    public function test_learn_more_label_exists_in_every_installer_locale(): void
    {
        $codes = $this->localeCodes();

        $this->assertNotEmpty($codes, '인스톨러 언어 파일을 찾지 못했다 — 빈 모집단은 조용히 통과한다');

        foreach ($codes as $code) {
            // 경로를 리터럴로 조립한다 — `glob` 결과를 그대로 require 하면 무엇을 로드하는지
            // 정적으로 판정할 수 없어, 인스톨러 테스트 격리 게이트(R7)가 이 클래스를
            // 「BASE_PATH 정의자를 로드할 수도 있는 테스트」로 보고 프로세스 격리를 요구한다.
            $path = $this->repoRoot().'/public/install/lang/'.$code.'.php';
            $messages = require $path;

            $this->assertArrayHasKey(
                'execution_mode_learn_more',
                $messages,
                $code.'.php 에 [자세히 보기] 문구가 없다 — 그 로케일에서는 키 문자열이 그대로 보인다'
            );
        }
    }

    /**
     * 저장소 전체의 `INSTALL.md` 안내 링크가 **전부** 실재하는 제목을 가리킨다.
     *
     * 링크가 죽어도 404 가 아니라 문서 맨 위가 열리므로 오류로 드러나지 않는다. 화면마다
     * 따로 재면 새로 생긴 링크가 사각이 되므로 모집단을 추적 파일에서 도출한다.
     *
     * @scenario installer_gate_status=info_blue
     *
     * @effects install_guide_link_targets_exist_repo_wide
     *
     * @return void 반환값 없음
     */
    public function test_every_install_guide_link_in_the_repository_points_at_an_existing_heading(): void
    {
        $occurrences = $this->guideLinkOccurrences();

        // 빈 모집단은 조용히 통과한다 — 도출이 실패했으면 그 사실을 먼저 드러낸다.
        $this->assertNotEmpty(
            $occurrences,
            'INSTALL.md 안내 링크를 하나도 찾지 못했다 — 모집단 도출이 깨졌다(git 추적 파일 스캔)'
        );

        $anchors = $this->installMdAnchors();

        foreach ($occurrences as [$file, $fragment]) {
            $this->assertNotSame('', $fragment, $file.' 의 안내 링크에 절 앵커가 없다 — 문서 맨 위가 열린다');
            $this->assertContains(
                $fragment,
                $anchors,
                "{$file} 의 안내 링크가 가리키는 '{$fragment}' 를 만드는 제목이 INSTALL.md 에 없다 — 링크는 살아 있지만 목적지가 사라져 문서 맨 위가 열린다"
            );
        }
    }

    /**
     * 안내 링크 전수 — git 이 추적하는 파일만 본다.
     *
     * `git grep` 은 추적 파일만 훑으므로 활성 확장 디렉토리(`templates/<id>/…`)의 사본처럼
     * 무시 대상인 중복은 모집단에 들어오지 않는다. 그 사본까지 세면 같은 링크를 두 번 재고,
     * 정본을 고쳤는데 사본이 남은 상태를 실패로 오인한다.
     *
     * 두 영역만 뺀다(아래 pathspec 과 같은 표기다). `.claude` 트리는 release 빌드에 실리지
     * 않는 내부 기록이고(지난 기록은 그 시점의 제목을 가리키는 것이 정상이다), `tests/**` 의
     * 같은 리터럴은 화면에 그려지는 링크가 아니라 **이 계약을 재는 코드 자신**이다.
     *
     * `core.quotepath` 는 끈다 — 켜져 있으면 비ASCII 경로가 따옴표에 감싸인 이스케이프
     * 문자열로 나와 그대로는 열 수 없다.
     *
     * @return list<array{0: string, 1: string}> [상대 경로, 디코드된 fragment]
     */
    private function guideLinkOccurrences(): array
    {
        $prefix = 'https://github.com/gnuboard/g7/blob/main/INSTALL.md#';

        $files = [];
        exec(
            'git -c core.quotepath=false -C '.escapeshellarg($this->repoRoot())
                .' grep -l --fixed-strings '.escapeshellarg($prefix)
                .' -- '.escapeshellarg(':(exclude).claude').' '.escapeshellarg(':(exclude)tests')
                .' 2>&1',
            $files,
            $status
        );

        // git grep 은 일치가 없으면 1 을 돌려준다 — 그 경우는 위 assertNotEmpty 가 잡는다.
        if ($status > 1) {
            return [];
        }

        $out = [];
        foreach ($files as $relative) {
            $relative = trim($relative);
            if ($relative === '') {
                continue;
            }

            // meta:allow raw-source-read — 링크 모집단은 스크립트만이 아니라 레이아웃 JSON 과 템플릿 HTML 까지
            // 형식을 가리지 않는다. 언어별 주석 규칙을 고를 수 없고, 주석 속 안내 링크도 죽으면 안 되는 링크라
            // 원문 전체를 본다.
            $content = (string) file_get_contents($this->repoRoot().'/'.$relative);
            preg_match_all('~'.preg_quote($prefix, '~').'([A-Za-z0-9%._-]*)~', $content, $m);

            foreach ($m[1] as $rawFragment) {
                $out[] = [$relative, rawurldecode($rawFragment)];
            }
        }

        return $out;
    }

    /**
     * `INSTALL.md` 의 제목이 만드는 앵커 전수 (중복 제목은 GitHub 규칙대로 `-N` 을 붙인다).
     *
     * @return list<string> 설치 문서 제목이 만드는 앵커 목록
     */
    private function installMdAnchors(): array
    {
        $install = (string) file_get_contents($this->repoRoot().'/INSTALL.md');
        $headingCount = preg_match_all('/^#{1,6}\s+(.*)$/m', $install, $heads);

        $this->assertGreaterThan(0, $headingCount, 'INSTALL.md 에서 제목을 하나도 읽지 못했다');

        $seen = [];
        $anchors = [];
        foreach ($heads[1] as $heading) {
            $slug = $this->slug($heading);
            $n = $seen[$slug] ?? 0;
            $anchors[] = $n === 0 ? $slug : $slug.'-'.$n;
            $seen[$slug] = $n + 1;
        }

        return $anchors;
    }

    /**
     * 인스톨러 로케일 코드 전수 — 목록을 손으로 적지 않는다(새 로케일이 사각이 된다).
     *
     * @return list<string> 정렬된 설치기 로케일 코드 목록
     */
    private function localeCodes(): array
    {
        $codes = [];
        // 재귀로 훑는다 — 한 겹 나열은 하위 폴더가 생기는 순간 그 안의 로케일을 놓친다.
        // 코드는 언어 폴더 기준 상대 경로라 아래 require 경로 조립과 그대로 맞물린다.
        $langDir = $this->repoRoot().'/public/install/lang';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($langDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($langDir) + 1));
                $codes[] = substr($relative, 0, -strlen('.php'));
            }
        }
        sort($codes);

        return $codes;
    }

    /**
     * 정보 카드 렌더 함수의 본문만 잘라낸다 — 형제 카드(fail)의 선언이 판정을 가리지 않게.
     *
     * @param  string  $js  주석을 지운 설치 화면 스크립트 소스
     * @return string 정보 카드 렌더 함수의 본문
     */
    private function extractInfoCardBody(string $js): string
    {
        $start = strpos($js, 'function renderExecutionModeInfoCard(');
        $this->assertNotFalse($start, 'W1 정보 카드 렌더 함수가 없다');

        $end = strpos($js, "\n    function ", $start + 10);
        $this->assertNotFalse($end, '정보 카드 함수의 끝을 찾지 못했다');

        return substr($js, $start, $end - $start);
    }
}
