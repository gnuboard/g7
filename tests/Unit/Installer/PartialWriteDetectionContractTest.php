<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 부분 쓰기(잘린 파일)를 성공으로 통과시키지 않는다 — 쓰기 지점 전수 계약.
 *
 * `file_put_contents()` 는 실패했을 때만 `false` 를 돌려주는 것이 아니다. 디스크가
 * 가득 찼거나 quota 를 넘으면 **쓴 바이트 수**를 돌려주고 그 값은 truthy 다. 그래서
 * `=== false` 만 보는 호출부는 잘린 파일을 「성공」으로 읽는다.
 *
 * 그 통과가 치명인 이유는 대상 파일 때문이다 — `.env`(APP_KEY·DB 자격증명) ·
 * `runtime.php`(비밀 채널, `@include` 로 읽는 PHP 소스) · 설정 JSON · 설치 완료 플래그.
 * 잘린 채 확정되면 되돌릴 사본이 없고(제자리 쓰기), 운영자는 「완료」 로그를 본 뒤
 * 사이트가 뜨지 않는 상태를 만난다.
 *
 * 이 검사는 **모집단을 소스에서 도출**한다. 지점을 손으로 열거하면 나중에 추가되는
 * 쓰기가 조용히 사각으로 남기 때문이다. `disable_functions` 나 디스크 full 은
 * PHPUnit 으로 재현할 수 없으므로(실제 부분 쓰기를 일으킬 수단이 없다) 소스 계약으로
 * 잠근다 — 형제 축(`InstallerDisabledFunctionResilienceTest`)과 같은 방식이다.
 */
class PartialWriteDetectionContractTest extends TestCase
{
    /**
     * 판정 없이 통과시켜도 되는 지점과 그 근거.
     *
     * 여기 없는 지점이 새로 생기면 이 테스트가 red 가 된다 — 면제는 근거와 함께
     * 이 표에 적는 것이 유일한 통로다.
     *
     * @var array<string, string>
     */
    private const EXEMPT = [
        // 진단 로그 append — 잘려도 잃는 것은 로그 한 줄이고, 그 실패가 설치 상태를
        // 바꾸지 않는다. 로그 쓰기 실패로 설치를 멈추면 그쪽이 더 나쁘다.
        'public/install/includes/functions.php' => '진단 로그 append',

        // symlink 가용성 프로브 — 고정 짧은 문자열이고, 결과는 파일 내용이 아니라
        // 「썼는가」로만 쓰인다. 잘려도 판정이 뒤집히지 않는다.
        'public/install/includes/exposure-check.php' => 'symlink 프로브(내용 무관)',
    ];

    /**
     * 인스톨러의 모든 `file_put_contents()` 가 부분 쓰기를 판정한다.
     */
    #[Test]
    public function installer_write_sites_detect_partial_writes(): void
    {
        $offenders = [];

        foreach ($this->installerPhpFiles() as $file) {
            $relative = $this->relative($file);

            if (array_key_exists($relative, self::EXEMPT)) {
                continue;
            }

            foreach ($this->uncheckedWriteLines($file) as $line => $code) {
                $offenders[] = $relative.':'.$line.' — '.trim($code);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "부분 쓰기를 판정하지 않는 쓰기 지점이 있다. `installerWroteFully()` 를 쓰거나\n"
            ."반환값을 `strlen()` 과 대조하라. 판정이 필요 없는 지점이면 이 테스트의\n"
            ."EXEMPT 표에 근거와 함께 등재하라.\n\n"
            .implode("\n", $offenders)
        );
    }

    /**
     * 모집단이 비어 있지 않다 — 비면 위 검사가 공허하게 통과한다.
     */
    #[Test]
    public function the_population_is_not_empty(): void
    {
        $files = $this->installerPhpFiles();

        $this->assertNotEmpty($files, '인스톨러 PHP 파일을 하나도 찾지 못했다 — 경로 규칙이 바뀌었다');

        $writeSites = 0;

        foreach ($files as $file) {
            $writeSites += substr_count((string) file_get_contents($file), 'file_put_contents(');
        }

        $this->assertGreaterThanOrEqual(
            8,
            $writeSites,
            '인스톨러의 `file_put_contents()` 지점이 예상보다 적다 — 모집단 도출이 깨졌다'
        );
    }

    /**
     * 판정 헬퍼가 실제로 부분 쓰기를 걸러낸다 (항등식 픽스처 방지).
     */
    #[Test]
    public function the_helper_rejects_a_short_write(): void
    {
        // 인스톨러 파일은 Laravel 부팅 전 코드라 `BASE_PATH` 를 요구한다. 형제
        // 인스톨러 테스트와 같은 방식으로 세운다 (이미 정의돼 있으면 그대로 쓴다).
        if (! defined('BASE_PATH')) {
            define('BASE_PATH', base_path());
        }

        require_once base_path('public/install/includes/installer-runtime.php');

        $content = 'abcdefghij';

        $this->assertTrue(installerWroteFully(strlen($content), $content), '완전 쓰기는 통과해야 한다');
        $this->assertFalse(installerWroteFully(strlen($content) - 1, $content), '부분 쓰기는 거부해야 한다');
        $this->assertFalse(installerWroteFully(false, $content), '실패는 거부해야 한다');
        $this->assertFalse(installerWroteFully(0, $content), '0바이트도 거부해야 한다 (내용이 비어 있지 않으므로)');
        $this->assertTrue(installerWroteFully(0, ''), '빈 내용의 0바이트 쓰기는 완전 쓰기다');
    }

    /**
     * 인스톨러 PHP 파일 목록을 돌려줍니다.
     *
     * @return list<string> 절대 경로
     */
    private function installerPhpFiles(): array
    {
        $root = base_path('public/install');

        if (! is_dir($root)) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * 부분 쓰기를 판정하지 않는 쓰기 줄을 돌려줍니다.
     *
     * 판정으로 인정하는 형태는 둘이다 — `installerWroteFully(...)` 로 감싸거나,
     * 반환값을 변수에 담아 같은 줄 또는 뒤따르는 세 줄 안에서 `strlen()` 과 대조하거나.
     *
     * @param  string  $file  절대 경로
     * @return array<int, string> 줄 번호 => 원문
     */
    private function uncheckedWriteLines(string $file): array
    {
        // 줄 종결자만 자른다 — `\R` 은 `u` 수식자 없이 바이트 모드라 0x85(NEL)를
        // 개행으로 읽어 한글을 글자 한가운데서 쪼갠다 (CLAUDE.md 「한글 텍스트를
        // 정규식으로 자를 때」).
        $lines = preg_split('~\r\n|\r|\n~', (string) file_get_contents($file)) ?: [];
        $offenders = [];

        foreach ($lines as $index => $line) {
            if (! str_contains($line, 'file_put_contents(')) {
                continue;
            }

            // 주석·docblock 은 대상이 아니다.
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (str_contains($line, 'installerWroteFully(')) {
                continue;
            }

            // 반환값을 담은 뒤 근처에서 `strlen()` 과 대조하는 형태.
            $window = implode("\n", array_slice($lines, $index, 4));

            if (preg_match('~\$\w+\s*!==\s*strlen\(~', $window) === 1) {
                continue;
            }

            $offenders[$index + 1] = $line;
        }

        return $offenders;
    }

    /**
     * 프로젝트 루트 기준 상대 경로로 바꿉니다.
     *
     * @param  string  $path  절대 경로
     * @return string 슬래시 구분 상대 경로
     */
    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
    }
}
