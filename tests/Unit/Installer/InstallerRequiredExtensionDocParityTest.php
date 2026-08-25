<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;

/**
 * 인스톨러가 자동 검사하는 필수 PHP 확장 ↔ 공개 문서(`docs/requirements.md` §1.4·§9) 대조 (17차).
 *
 * 배경:
 *   `pdo_mysql` 이 `REQUIRED_EXTENSIONS` 에 편입됐는데 `requirements.md` 는 그대로
 *   「자동 검사 **14개**」와 「`pdo_mysql` 과 `zip` 은 자동 검사 대상이 아니다」를 말하고
 *   있었다. 그 문서는 릴리즈에 실려 나가므로, 운영자가 「pdo_mysql 은 내가 챙기면 된다」고
 *   읽고 준비한 환경이 설치 첫 화면에서 막힌다 — 그리고 화면에는 그 사유가 없다.
 *
 *   요구사항 목록은 코드가 SSoT 이고 문서가 따라와야 한다. 어느 쪽을 고치든 다른 쪽이
 *   함께 움직이지 않으면 이 검사가 운다.
 *
 * 이 검사는 소스를 **텍스트로** 읽는다 — `config.php` 를 require 하면 그것만으로
 * `BASE_PATH` 가 실 저장소 루트로 박혀 격리 속성이 필요해지기 때문이다 (§16.13.7 규약).
 *
 * 커버 축: extension_list source·doc / doc_section inline·summary
 *
 * @effects required_extension_list_matches_public_doc, required_extension_count_matches_public_doc
 */
class InstallerRequiredExtensionDocParityTest extends TestCase
{
    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * `config.php` 의 REQUIRED_EXTENSIONS 리터럴을 텍스트에서 뽑는다.
     *
     * @return array<int, string> 확장 이름 목록 (선언 순서)
     */
    private function sourceExtensions(): array
    {
        $source = (string) file_get_contents($this->repoRoot().'/public/install/includes/config.php');

        $this->assertSame(
            1,
            preg_match("/define\('REQUIRED_EXTENSIONS',\s*\[(.*?)\]\s*\);/s", $source, $m),
            'REQUIRED_EXTENSIONS 선언을 찾지 못했다 — 선언 형태가 바뀌면 이 검사가 사문이 된다',
        );

        // 주석 줄을 걷어내고 따옴표 안의 이름만 취한다.
        $body = preg_replace('!//[^\n]*!', '', (string) $m[1]);
        preg_match_all("/'([a-z0-9_]+)'/i", (string) $body, $names);

        return $names[1];
    }

    /**
     * 문서 §1.4 의 「자동 검사 N개(...)」 문단에서 개수와 이름을 뽑는다.
     *
     * @return array{count: int, names: array<int, string>}
     */
    private function documentedExtensions(): array
    {
        $doc = (string) file_get_contents($this->repoRoot().'/docs/requirements.md');

        $this->assertSame(
            1,
            preg_match('/필수 확장 중 (\d+)개\(([^)]*)\)/su', $doc, $m),
            '§1.4 의 「필수 확장 중 N개(...)」 문단을 찾지 못했다',
        );

        preg_match_all('/`([a-z0-9_]+)`/i', (string) $m[2], $names);

        return ['count' => (int) $m[1], 'names' => $names[1]];
    }

    public function test_required_extension_list_matches_public_doc(): void
    {
        $source = $this->sourceExtensions();
        $documented = $this->documentedExtensions();

        $this->assertEqualsCanonicalizing(
            $source,
            $documented['names'],
            "인스톨러가 자동 검사하는 확장과 공개 문서 §1.4 의 목록이 어긋난다.\n"
            .'문서를 믿고 환경을 준비한 운영자가 설치 첫 화면에서 사유 없이 막힌다.',
        );
    }

    public function test_required_extension_count_matches_public_doc(): void
    {
        $source = $this->sourceExtensions();
        $documented = $this->documentedExtensions();

        $this->assertSame(
            count($source),
            $documented['count'],
            '§1.4 의 개수 표기가 실제 검사 개수와 다르다',
        );

        // §9 요약 표도 같은 수를 말해야 한다 — 한쪽만 고치면 문서 안에서 서로 어긋난다.
        $doc = (string) file_get_contents($this->repoRoot().'/docs/requirements.md');
        $this->assertMatchesRegularExpression(
            '/\| PHP 확장 \| §1\.4 필수 확장 중 '.count($source).'개/u',
            $doc,
            '§9 요약 표의 개수가 §1.4 와 어긋난다',
        );
    }

    /**
     * 목록의 각 확장이 §1.4 표에서 「필수」로 분류되어 있어야 한다.
     *
     * 자동 검사 문단에만 이름을 적고 분류표에서 빠뜨리면, 그 확장은 문서상 근거 없이
     * 설치를 막는 항목이 된다.
     */
    public function test_every_checked_extension_is_classified_required_in_the_table(): void
    {
        $doc = (string) file_get_contents($this->repoRoot().'/docs/requirements.md');
        $missing = [];

        foreach ($this->sourceExtensions() as $extension) {
            if (preg_match('/\|\s*필수\s*\|\s*`'.preg_quote($extension, '/').'`\s*\|/u', $doc) !== 1) {
                $missing[] = $extension;
            }
        }

        $this->assertSame(
            [],
            $missing,
            '§1.4 분류표에 「필수」로 실리지 않은 채 설치를 막는 확장이 있다',
        );
    }
}
