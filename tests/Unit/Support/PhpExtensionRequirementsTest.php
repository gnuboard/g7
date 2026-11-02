<?php

namespace Tests\Unit\Support;

use App\Support\PhpExtensionRequirements;
use PHPUnit\Framework\TestCase;

/**
 * 코어 PHP 확장 요구 목록 SSoT 단위 테스트 (#151)
 *
 * 인스톨러·관리자 정보 탭·코어 업데이트 사전점검·audit 룰이 같은 JSON 을 읽는다. 문서(docs/requirements.md §1.4)와
 * 목록이 다시 어긋나지 않도록 표와 대조해 잠근다. 인스톨러가 프레임워크 없이 `require_once` 하므로 이 클래스는
 * Laravel 부트 없이 동작해야 한다 — 그래서 `PHPUnit\Framework\TestCase` 를 직접 상속한다.
 */
class PhpExtensionRequirementsTest extends TestCase
{
    /**
     * JSON 구조(필수·선택·심볼 표)와 필수 16종(zip·pdo_mysql 포함, bcmath 제외)을 확인합니다.
     *
     * @scenario surface=ssot, list=required
     *
     * @effects ssot_lists_sixteen_required_with_zip_and_pdo_mysql
     *
     * @return void 반환값 없음
     */
    public function test_json_structure_and_required_list(): void
    {
        $data = PhpExtensionRequirements::load();

        $this->assertCount(16, $data['required']);
        $this->assertContains('zip', $data['required']);
        $this->assertContains('pdo_mysql', $data['required']);
        $this->assertNotContains('bcmath', $data['required']);
        $this->assertContains('bcmath', $data['optional']);
        $this->assertSame([], array_values(array_intersect($data['required'], $data['optional'])), '필수와 선택이 겹친다');

        foreach (['bcmath', 'soap', 'iconv', 'zlib', 'phar', 'gd'] as $extension) {
            $this->assertNotEmpty($data['symbols'][$extension] ?? [], "{$extension} 심볼 표가 비었다");
        }
        $this->assertSame([], array_values(array_intersect(array_keys($data['symbols']), $data['required'])), '필수 확장은 심볼 표 대상이 아니다');
    }

    /**
     * 필수·선택 목록이 docs/requirements.md §1.4 표와 같습니다.
     *
     * @scenario surface=ssot, list=docs_table
     *
     * @effects ssot_matches_requirements_doc_table
     *
     * @return void 반환값 없음
     */
    public function test_lists_match_requirements_doc_table(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 3).'/docs/requirements.md');
        preg_match_all('/^\| 필수 \| `([a-z_]+)` \|/m', $doc, $required);
        preg_match_all('/^\| 선택 \| `([a-z_]+)` \|/m', $doc, $optional);

        $data = PhpExtensionRequirements::load();

        $this->assertEqualsCanonicalizing($required[1], $data['required']);
        $this->assertEqualsCanonicalizing($optional[1], $data['optional']);
    }

    /**
     * missing() 는 주입한 로드 판정으로 없는 확장만 입력 순서대로 돌려줍니다.
     *
     * @scenario surface=ssot, list=missing
     *
     * @effects missing_uses_injected_probe
     *
     * @return void 반환값 없음
     */
    public function test_missing_uses_injected_probe(): void
    {
        $loaded = fn (string $name): bool => ! in_array($name, ['zip', 'soap'], true);

        $this->assertSame(['soap', 'zip'], PhpExtensionRequirements::missing(['json', 'soap', 'zip'], $loaded));
        $this->assertSame(['zip'], PhpExtensionRequirements::requiredMissing($loaded));
        $this->assertSame([], PhpExtensionRequirements::missing([], $loaded));
    }

    /**
     * 다른 경로(새 배포본)의 JSON 을 읽고, 잘못된 파일은 예외로 알립니다.
     *
     * @scenario surface=ssot, list=custom_path
     *
     * @effects load_reads_given_path_and_rejects_invalid
     *
     * @return void 반환값 없음
     */
    public function test_load_reads_given_path_and_rejects_invalid_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'g7req');
        file_put_contents($path, json_encode(['required' => ['json'], 'optional' => []]));

        try {
            $this->assertSame(['json'], PhpExtensionRequirements::load($path)['required']);
            $this->assertSame([], PhpExtensionRequirements::load($path)['symbols']);

            file_put_contents($path, '{"broken":');
            $this->expectException(\RuntimeException::class);
            PhpExtensionRequirements::load($path);
        } finally {
            @unlink($path);
        }
    }
}
