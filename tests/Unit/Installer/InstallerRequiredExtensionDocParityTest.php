<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

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
 * 목록 원본은 코어 `app/Support/Requirements/php-extensions.json` 이고 설치기 `config.php` 는
 * 그것을 읽어 `REQUIRED_EXTENSIONS` 를 정의한다(터미널 설치 도입과 함께 옮겨졌다).
 *
 * 커버 축: extension_list source·doc / doc_section inline·summary
 */
class InstallerRequiredExtensionDocParityTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /**
     * 저장소 루트 절대경로를 돌려준다.
     *
     * @return string 저장소 루트 경로
     */
    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * 설치기가 검사하는 필수 확장 목록을 그 원본에서 뽑는다.
     *
     * 설치기 `config.php` 는 목록을 직접 적지 않고 코어 원본
     * (`app/Support/Requirements/php-extensions.json`)을 `PhpExtensionRequirements::load()` 로 읽어
     * `REQUIRED_EXTENSIONS` 를 정의한다 — 관리자 정보 탭·코어 업데이트 사전점검과 같은 목록이다.
     * 그래서 「설치기가 그 원본을 읽는다」는 배선을 텍스트로 확인한 뒤 원본 JSON 에서 이름을 취한다.
     *
     * @return array<int, string> 확장 이름 목록 (선언 순서)
     */
    private function sourceExtensions(): array
    {
        $config = $this->sourceWithoutComments($this->repoRoot().'/public/install/includes/config.php');

        $this->assertSame(
            1,
            preg_match('/\$(\w+)\s*=\s*PhpExtensionRequirements::load\(\);/', $config, $load),
            '설치기 config.php 가 필수 확장 원본(PhpExtensionRequirements::load)을 읽지 않는다 — 선언 형태가 바뀌면 이 검사가 사문이 된다',
        );
        $this->assertMatchesRegularExpression(
            "/define\\('REQUIRED_EXTENSIONS',\\s*\\\$".preg_quote($load[1], '/')."\\['required'\\]\\s*\\);/",
            $config,
            'REQUIRED_EXTENSIONS 가 원본의 required 목록에서 오지 않는다 — 설치기와 문서·코어가 다른 목록을 본다',
        );

        $json = json_decode(
            (string) file_get_contents($this->repoRoot().'/app/Support/Requirements/php-extensions.json'),
            true,
        );

        $this->assertIsArray($json['required'] ?? null, 'php-extensions.json 의 required 목록을 읽지 못했다');

        return array_values($json['required']);
    }

    /**
     * 문서 §1.4 의 「필수 N개」 표기와 분류표의 「필수」 행 이름을 뽑는다.
     *
     * 자동 검사 문단은 이름을 다시 나열하지 않고 「위 필수 확장 N개를 … 모두 자동 검사」로 분류표를
     * 가리킨다. 그래서 개수는 §1.4 머리와 자동 검사 문단에서, 이름은 분류표의 「필수」 행에서 취한다.
     *
     * @return array{count: int, names: array<int, string>} 문서가 말하는 개수와 「필수」로 분류된 확장 이름 목록
     */
    private function documentedExtensions(): array
    {
        $doc = (string) file_get_contents($this->repoRoot().'/docs/requirements.md');

        $this->assertSame(
            1,
            preg_match('/^### 1\.4 PHP 확장 모듈\s+필수 (\d+)개/mu', $doc, $head),
            '§1.4 머리의 「필수 N개」 표기를 찾지 못했다',
        );
        $this->assertSame(
            1,
            preg_match('/위 필수 확장 (\d+)개를 설치 단계에서 모두 자동 검사/u', $doc, $auto),
            '§1.4 의 「위 필수 확장 N개를 설치 단계에서 모두 자동 검사」 문단을 찾지 못했다',
        );
        $this->assertSame(
            $head[1],
            $auto[1],
            '§1.4 머리의 개수와 자동 검사 문단의 개수가 어긋난다',
        );

        preg_match_all('/^\|\s*필수\s*\|\s*`([a-z0-9_]+)`\s*\|/mu', $doc, $names);

        return ['count' => (int) $head[1], 'names' => $names[1]];
    }

    /**
     * 설치기가 검사하는 필수 확장 목록과 공개 문서 §1.4 분류표의 「필수」 이름 목록이 순서와 무관하게 같은지 확인한다.
     *
     * @effects required_extension_list_matches_public_doc
     *
     * @return void 반환값 없음
     */
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

    /**
     * 공개 문서 §1.4 의 개수 표기와 §9 요약 표의 개수가 실제 필수 확장 수와 같은지 확인한다.
     *
     * @effects required_extension_count_matches_public_doc
     *
     * @return void 반환값 없음
     */
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
            '/\| PHP 확장 \| §1\.4 필수 확장 '.count($source).'개/u',
            $doc,
            '§9 요약 표의 개수가 §1.4 와 어긋난다',
        );
    }

    /**
     * 목록의 각 확장이 §1.4 표에서 「필수」로 분류되어 있어야 한다.
     *
     * 자동 검사 문단에만 이름을 적고 분류표에서 빠뜨리면, 그 확장은 문서상 근거 없이
     * 설치를 막는 항목이 된다.
     *
     * @effects required_extension_list_matches_public_doc
     *
     * @return void 반환값 없음
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
