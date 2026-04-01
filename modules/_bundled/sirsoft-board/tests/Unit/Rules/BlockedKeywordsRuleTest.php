<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Rules;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__ . '/../../ModuleTestCase.php';

use Modules\Sirsoft\Board\Rules\BlockedKeywordsRule;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * BlockedKeywordsRule 단위 테스트
 *
 * 금지 키워드 검증 규칙의 동작을 테스트합니다.
 */
class BlockedKeywordsRuleTest extends ModuleTestCase
{
    /**
     * 금지 키워드가 없을 때 검증을 통과하는지 테스트
     */
    public function test_passes_when_no_blocked_keywords(): void
    {
        $rule = new BlockedKeywordsRule(null);
        $failed = false;

        $rule->validate('content', '아무 내용이나 작성해도 됩니다.', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    /**
     * 빈 키워드 배열일 때 검증을 통과하는지 테스트
     */
    public function test_passes_when_empty_keywords_array(): void
    {
        $rule = new BlockedKeywordsRule([]);
        $failed = false;

        $rule->validate('content', '아무 내용이나 작성해도 됩니다.', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    /**
     * 금지 키워드가 포함된 경우 검증에 실패하는지 테스트
     */
    public function test_fails_when_blocked_keyword_found(): void
    {
        $rule = new BlockedKeywordsRule(['광고', '홍보']);
        $failed = false;

        $rule->validate('content', '이것은 광고 게시글입니다.', function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed);
    }

    /**
     * 금지 키워드가 없는 경우 검증을 통과하는지 테스트
     */
    public function test_passes_when_no_blocked_keyword_found(): void
    {
        $rule = new BlockedKeywordsRule(['광고', '홍보']);
        $failed = false;

        $rule->validate('content', '오늘 날씨가 좋습니다.', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    /**
     * 대소문자를 구분하지 않고 검증하는지 테스트
     */
    public function test_case_insensitive_matching(): void
    {
        $rule = new BlockedKeywordsRule(['spam']);
        $failed = false;

        $rule->validate('content', 'This is SPAM content', function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed);
    }

    /**
     * 빈 문자열 키워드는 건너뛰는지 테스트
     */
    public function test_skips_empty_keywords(): void
    {
        $rule = new BlockedKeywordsRule(['', '광고']);
        $failed = false;

        $rule->validate('content', '일반 게시글입니다.', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    /**
     * 문자열이 아닌 값에 대해 검증을 통과하는지 테스트
     */
    public function test_passes_for_non_string_values(): void
    {
        $rule = new BlockedKeywordsRule(['광고']);
        $failed = false;

        $rule->validate('content', ['array', 'value'], function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    /**
     * 첫 번째 발견된 키워드에서 즉시 실패하는지 테스트
     */
    public function test_fails_on_first_blocked_keyword(): void
    {
        $rule = new BlockedKeywordsRule(['광고', '홍보', '스팸']);
        $failCount = 0;

        $rule->validate('content', '이것은 광고이자 홍보이며 스팸입니다.', function () use (&$failCount) {
            $failCount++;
        });

        $this->assertEquals(1, $failCount);
    }

    /**
     * 한글 키워드 검증이 정상 동작하는지 테스트
     */
    public function test_korean_keyword_matching(): void
    {
        $rule = new BlockedKeywordsRule(['불법', '도박']);
        $failed = false;

        $rule->validate('content', '불법적인 내용이 포함되어 있습니다.', function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed);
    }
}
