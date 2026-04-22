<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Rules;

require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Support\Facades\Cache;
use Modules\Sirsoft\Board\Rules\BlockedKeywordsRule;
use Modules\Sirsoft\Board\Rules\CategoryInUseRule;
use Modules\Sirsoft\Board\Rules\CommentValidationRule;
use Modules\Sirsoft\Board\Rules\CooldownRule;
use Modules\Sirsoft\Board\Rules\PermissionRolesRequiredRule;
use Modules\Sirsoft\Board\Rules\SlugUniqueRule;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 게시판 Custom Validation Rule 단위 테스트
 *
 * 검증 목적:
 * - BlockedKeywordsRule: 금지 키워드 포함/미포함/대소문자 무시/null
 * - PermissionRolesRequiredRule: roles 누락 permission fail / 정상 통과
 * - SlugUniqueRule: 예약어 fail / 중복 slug fail / 새 slug 통과
 * - CooldownRule: 쿨다운 0은 통과 / 캐시 키 있으면 fail / 없으면 통과
 * - CategoryInUseRule: 제거 카테고리 사용 중이면 fail / 사용 안 하면 통과 / 제거 없으면 통과
 * - CommentValidationRule: blinded 게시글 fail / deleted 게시글 fail / 정상 통과
 *
 * @group board
 * @group unit
 * @group rules
 */
class BoardRulesTest extends BoardTestCase
{
    protected function getTestBoardSlug(): string
    {
        return 'board-rules-test';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '규칙 테스트 게시판', 'en' => 'Rules Test Board'],
            'is_active' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
            'max_comment_depth' => 3,
            'categories' => [],
        ];
    }

    // ==========================================
    // 헬퍼
    // ==========================================

    private function runRule(\Illuminate\Contracts\Validation\ValidationRule $rule, mixed $value): array
    {
        $failures = [];
        $rule->validate('field', $value, function (string $msg) use (&$failures) {
            $failures[] = $msg;
        });

        return $failures;
    }

    // ==========================================
    // BlockedKeywordsRule
    // ==========================================

    /**
     * 금지 키워드가 없으면 항상 통과합니다.
     */
    public function test_blocked_keywords_passes_when_no_keywords(): void
    {
        $rule = new BlockedKeywordsRule([]);
        $this->assertEmpty($this->runRule($rule, '어떤 내용이든 통과'));
    }

    /**
     * null 키워드 목록이면 통과합니다.
     */
    public function test_blocked_keywords_passes_when_keywords_null(): void
    {
        $rule = new BlockedKeywordsRule(null);
        $this->assertEmpty($this->runRule($rule, '스팸'));
    }

    /**
     * 금지 키워드가 포함되면 fail합니다.
     */
    public function test_blocked_keywords_fails_when_keyword_found(): void
    {
        $rule = new BlockedKeywordsRule(['스팸', '광고']);
        $failures = $this->runRule($rule, '스팸 게시글 내용');
        $this->assertNotEmpty($failures);
    }

    /**
     * 대소문자를 무시하여 검사합니다.
     */
    public function test_blocked_keywords_is_case_insensitive(): void
    {
        $rule = new BlockedKeywordsRule(['SPAM']);
        $failures = $this->runRule($rule, 'spam 내용');
        $this->assertNotEmpty($failures);
    }

    /**
     * 금지 키워드가 없는 내용은 통과합니다.
     */
    public function test_blocked_keywords_passes_when_no_match(): void
    {
        $rule = new BlockedKeywordsRule(['스팸']);
        $this->assertEmpty($this->runRule($rule, '정상적인 게시글 내용입니다.'));
    }

    /**
     * 값이 문자열이 아니면 통과합니다.
     */
    public function test_blocked_keywords_passes_when_value_not_string(): void
    {
        $rule = new BlockedKeywordsRule(['스팸']);
        $this->assertEmpty($this->runRule($rule, 123));
    }

    // ==========================================
    // PermissionRolesRequiredRule
    // ==========================================

    /**
     * permissions 배열에서 roles가 비어있으면 fail합니다.
     */
    public function test_permission_roles_required_fails_when_roles_empty(): void
    {
        $rule = new PermissionRolesRequiredRule();
        $value = [
            'posts_read' => ['roles' => []],
            'posts_write' => ['roles' => ['user']],
        ];
        $failures = $this->runRule($rule, $value);
        $this->assertNotEmpty($failures);
    }

    /**
     * 모든 permission에 role이 있으면 통과합니다.
     */
    public function test_permission_roles_required_passes_when_all_have_roles(): void
    {
        $rule = new PermissionRolesRequiredRule();
        $value = [
            'posts_read' => ['roles' => ['guest', 'user']],
            'posts_write' => ['roles' => ['user']],
        ];
        $this->assertEmpty($this->runRule($rule, $value));
    }

    /**
     * 값이 배열이 아니면 통과합니다.
     */
    public function test_permission_roles_required_passes_when_not_array(): void
    {
        $rule = new PermissionRolesRequiredRule();
        $this->assertEmpty($this->runRule($rule, 'not-an-array'));
    }

    // ==========================================
    // SlugUniqueRule
    // ==========================================

    /**
     * 예약된 slug 'boards'는 fail합니다.
     */
    public function test_slug_unique_fails_for_reserved_slug(): void
    {
        $rule = new SlugUniqueRule();
        $failures = $this->runRule($rule, 'boards');
        $this->assertNotEmpty($failures);
    }

    /**
     * 이미 존재하는 slug는 fail합니다.
     */
    public function test_slug_unique_fails_for_existing_slug(): void
    {
        $rule = new SlugUniqueRule();
        // $this->board->slug는 이미 DB에 존재
        $failures = $this->runRule($rule, $this->board->slug);
        $this->assertNotEmpty($failures);
    }

    /**
     * 새로운 slug는 통과합니다.
     */
    public function test_slug_unique_passes_for_new_slug(): void
    {
        $rule = new SlugUniqueRule();
        $this->assertEmpty($this->runRule($rule, 'completely-new-unique-slug-xyz'));
    }

    // ==========================================
    // CooldownRule
    // ==========================================

    /**
     * cooldownSeconds가 0이면 항상 통과합니다.
     */
    public function test_cooldown_rule_passes_when_disabled(): void
    {
        $rule = new CooldownRule('post', 0, $this->board->slug);
        $this->assertEmpty($this->runRule($rule, null));
    }

    /**
     * 쿨다운 캐시가 없으면 통과합니다.
     */
    public function test_cooldown_rule_passes_when_no_cache(): void
    {
        // 캐시 초기화
        Cache::flush();

        $rule = new CooldownRule('post', 60, $this->board->slug);
        $this->assertEmpty($this->runRule($rule, null));
    }

    // ==========================================
    // CategoryInUseRule
    // ==========================================

    /**
     * 제거 카테고리가 없으면 통과합니다.
     */
    public function test_category_in_use_passes_when_no_removal(): void
    {
        $rule = new CategoryInUseRule(
            $this->board->slug,
            ['tech', 'news'],
            ['tech', 'news', 'sports'] // 추가만 있음
        );
        $this->assertEmpty($this->runRule($rule, null));
    }

    /**
     * 제거 카테고리가 있어도 파티션 테이블이 없으면 통과합니다.
     */
    public function test_category_in_use_passes_when_table_not_exists(): void
    {
        // board_posts_{slug} 파티션 테이블 없음 (비파티션 환경)
        $rule = new CategoryInUseRule(
            'nonexistent-board-xyz',
            ['tech', 'news'],
            ['tech'] // news 제거
        );
        $this->assertEmpty($this->runRule($rule, null));
    }

    // ==========================================
    // CommentValidationRule
    // ==========================================

    /**
     * blinded 게시글에 댓글 작성 → fail
     */
    public function test_comment_validation_fails_for_blinded_post(): void
    {
        $postId = $this->createTestPost(['status' => 'blinded']);
        $rule = new CommentValidationRule($this->board->slug, 'post');
        $failures = $this->runRule($rule, $postId);
        $this->assertNotEmpty($failures);
    }

    /**
     * 소프트 삭제된 게시글에 댓글 작성 → fail
     */
    public function test_comment_validation_fails_for_deleted_post(): void
    {
        $postId = $this->createTestPost();
        // soft delete
        \Illuminate\Support\Facades\DB::table('board_posts')
            ->where('id', $postId)
            ->update(['deleted_at' => now()]);

        $rule = new CommentValidationRule($this->board->slug, 'post');
        $failures = $this->runRule($rule, $postId);
        $this->assertNotEmpty($failures);
    }

    /**
     * 존재하지 않는 게시글 ID → fail
     */
    public function test_comment_validation_fails_for_nonexistent_post(): void
    {
        $rule = new CommentValidationRule($this->board->slug, 'post');
        $failures = $this->runRule($rule, 99999999);
        $this->assertNotEmpty($failures);
    }

    /**
     * 정상 게시글에 댓글 작성 → 통과
     */
    public function test_comment_validation_passes_for_published_post(): void
    {
        $postId = $this->createTestPost(['status' => 'published']);
        $rule = new CommentValidationRule($this->board->slug, 'post');
        $this->assertEmpty($this->runRule($rule, $postId));
    }

    /**
     * 값이 비어있으면 통과합니다 (nullable).
     */
    public function test_comment_validation_passes_for_empty_value(): void
    {
        $rule = new CommentValidationRule($this->board->slug, 'post');
        $this->assertEmpty($this->runRule($rule, null));
    }
}
