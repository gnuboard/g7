<?php

namespace Tests\Unit\Services;

use App\Services\LanguagePack\CustomTranslationUsageScanner;
use PHPUnit\Framework\TestCase;

/**
 * CustomTranslationUsageScanner 단위 테스트.
 *
 * 레이아웃 content 에서 `$t:custom.*` 키 수집 / custom 아닌 `$t:` 제외 / 중복 dedup /
 * 중첩 노드·props·표현식 스캔 / normalizeLayoutKey SSoT 정규화를 검증합니다.
 */
class CustomTranslationUsageScannerTest extends TestCase
{
    private CustomTranslationUsageScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanner = new CustomTranslationUsageScanner();
    }

    public function test_collects_custom_key_from_node_text(): void
    {
        $content = [
            ['type' => 'Span', 'text' => '$t:custom.home.1'],
        ];

        $this->assertSame(['custom.home.1'], $this->scanner->collectReferencedKeys($content));
    }

    public function test_collects_from_nested_children_and_props(): void
    {
        $content = [
            [
                'type' => 'Div',
                'children' => [
                    ['type' => 'Span', 'text' => '$t:custom.home.1'],
                    ['type' => 'Button', 'props' => ['label' => '$t:custom.home.2']],
                ],
            ],
        ];

        $keys = $this->scanner->collectReferencedKeys($content);
        sort($keys);

        $this->assertSame(['custom.home.1', 'custom.home.2'], $keys);
    }

    public function test_excludes_non_custom_t_keys(): void
    {
        $content = [
            ['text' => '$t:common.save'],
            ['text' => '$t:layout_editor.title'],
            ['text' => '$t:custom.board_list.3'],
        ];

        $this->assertSame(['custom.board_list.3'], $this->scanner->collectReferencedKeys($content));
    }

    public function test_dedups_repeated_keys(): void
    {
        $content = [
            ['text' => '$t:custom.home.1'],
            ['text' => '$t:custom.home.1'],
            ['children' => [['text' => '$t:custom.home.1']]],
        ];

        $this->assertSame(['custom.home.1'], $this->scanner->collectReferencedKeys($content));
    }

    public function test_collects_from_expression_substring(): void
    {
        // 코드 편집기 경유 표현식/문자열 안에 섞인 참조도 보수적으로 수집.
        $content = [
            ['text' => '{{ flag ? "$t:custom.home.5" : "plain" }}'],
        ];

        $this->assertSame(['custom.home.5'], $this->scanner->collectReferencedKeys($content));
    }

    public function test_returns_empty_for_no_custom_keys(): void
    {
        $content = [
            ['type' => 'Div', 'children' => [['text' => 'hello'], ['text' => '$t:common.ok']]],
        ];

        $this->assertSame([], $this->scanner->collectReferencedKeys($content));
    }

    public function test_handles_empty_and_scalar_content(): void
    {
        $this->assertSame([], $this->scanner->collectReferencedKeys([]));
        $this->assertSame([], $this->scanner->collectReferencedKeys(''));
        $this->assertSame([], $this->scanner->collectReferencedKeys('plain text without keys'));
        // 단일 문자열도 보수적으로 스캔된다 (코드 편집기 경유 참조 보존):
        $this->assertSame(['custom.home.1'], $this->scanner->collectReferencedKeys('$t:custom.home.1'));
        $this->assertSame(
            ['custom.home.1'],
            $this->scanner->collectReferencedKeys('prefix $t:custom.home.1 suffix'),
        );
    }

    public function test_normalize_layout_key_matches_service_format(): void
    {
        $this->assertSame('board_list', CustomTranslationUsageScanner::normalizeLayoutKey('board/list'));
        $this->assertSame('home', CustomTranslationUsageScanner::normalizeLayoutKey('home'));
        $this->assertSame('a_b', CustomTranslationUsageScanner::normalizeLayoutKey('a  b'));
        $this->assertSame('layout', CustomTranslationUsageScanner::normalizeLayoutKey(''));
        $this->assertSame('shopproduct', CustomTranslationUsageScanner::normalizeLayoutKey('shop@product'));
    }
}
