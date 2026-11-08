<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Rules\CooldownRule;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 게시글 작성 쿨다운 API 통합 시나리오 테스트
 *
 * 검증 목적:
 * - 쿨다운 비활성(0초): 연속 작성 허용
 * - 쿨다운 활성: 첫 작성 성공 후 두 번째 작성 422
 * - 쿨다운 활성: 캐시 만료 후 재작성 허용 (단위 테스트에서 검증)
 * - 회원은 user_id로, 비회원은 IP로 격리
 */
#[Group('board')]
#[Group('feature')]
#[Group('cooldown')]
class PostCooldownTest extends BoardTestCase
{
    private array $mockSettings = [];

    /**
     * 이 테스트가 쓰는 게시판 slug 를 돌려줍니다.
     *
     * @return string 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'post-cooldown-test';
    }

    /**
     * 이 테스트에 맞춘 게시판 속성을 반환합니다.
     *
     * @param  string  $slug  게시판 slug
     * @return array 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '쿨다운 테스트 게시판', 'en' => 'Cooldown Test Board'],
            'is_active' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
            'min_title_length' => 1,
            'max_title_length' => 200,
            'min_content_length' => 1,
            'max_content_length' => 10000,
        ];
    }

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->grantUserRolePermissions(['posts.read', 'posts.write']);
        $this->setGuestPermissions(['posts.read', 'posts.write']);

        $this->mockSettings = [
            'spam_security' => [
                'post_cooldown_seconds' => 0,
                'comment_cooldown_seconds' => 0,
                'report_cooldown_seconds' => 0,
                'view_count_cache_ttl' => 86400,
            ],
        ];
    }

    /**
     * 시험용 게시판 모듈 설정을 설정 미러(Config)에 반영합니다.
     *
     * @return void 반환값 없음
     */
    private function applySettings(): void
    {
        // g7_module_settings()는 Config::get("g7_settings.modules.sirsoft-board.*")를 읽음
        foreach ($this->mockSettings as $category => $values) {
            config(["g7_settings.modules.sirsoft-board.{$category}" => $values]);
        }
    }

    /**
     * 게시글 작성 API 주소를 돌려줍니다.
     *
     * @return string API 주소
     */
    private function postUrl(): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts";
    }

    /**
     * 검증을 통과하는 게시글 작성 본문을 돌려줍니다.
     *
     * @return array<string, string> 제목과 본문
     */
    private function validPostData(): array
    {
        return [
            'title' => '쿨다운 테스트 제목',
            'content' => '쿨다운 테스트 내용입니다.',
        ];
    }

    // ==========================================
    // 쿨다운 비활성 (0초)
    // ==========================================

    /**
     * 쿨다운 0초: 연속 작성 허용
     *
     * @scenario concern=write_cooldown, condition=cooldown_off
     *
     * @effects consecutive_posts_allowed_when_cooldown_disabled
     *
     * @return void 반환값 없음
     */
    public function test_consecutive_posts_allowed_when_cooldown_disabled(): void
    {
        $this->mockSettings['spam_security']['post_cooldown_seconds'] = 0;
        $this->applySettings();

        $user = $this->createUser();

        $this->actingAs($user)->postJson($this->postUrl(), $this->validPostData())
            ->assertStatus(201);

        $this->actingAs($user)->postJson($this->postUrl(), $this->validPostData())
            ->assertStatus(201);
    }

    // ==========================================
    // 쿨다운 활성
    // ==========================================

    /**
     * 쿨다운 활성: 첫 번째 작성 성공
     *
     * @scenario concern=write_cooldown, condition=cooldown_active
     *
     * @effects first_post_succeeds_when_cooldown_active
     *
     * @return void 반환값 없음
     */
    public function test_first_post_succeeds_when_cooldown_active(): void
    {
        $this->mockSettings['spam_security']['post_cooldown_seconds'] = 60;
        $this->applySettings();

        $user = $this->createUser();

        $this->actingAs($user)->postJson($this->postUrl(), $this->validPostData())
            ->assertStatus(201);
    }

    /**
     * 쿨다운 활성: 첫 작성 직후 두 번째 작성 422
     *
     * @scenario concern=write_cooldown, condition=cooldown_active
     *
     * @effects second_post_rejected_when_cooldown_active
     *
     * @return void 반환값 없음
     */
    public function test_second_post_rejected_when_cooldown_active(): void
    {
        $this->mockSettings['spam_security']['post_cooldown_seconds'] = 60;
        $this->applySettings();

        $user = $this->createUser();

        // 첫 번째 작성 성공 → 쿨다운 캐시 등록
        $this->actingAs($user)->postJson($this->postUrl(), $this->validPostData())
            ->assertStatus(201);

        // 두 번째 작성 시 422
        $this->actingAs($user)->postJson($this->postUrl(), $this->validPostData())
            ->assertStatus(422);
    }

    /**
     * 쿨다운 활성: 다른 사용자는 영향 없음 (user_id 격리)
     *
     * @scenario concern=write_cooldown, condition=cooldown_active
     *
     * @effects cooldown_is_isolated_per_user
     *
     * @return void 반환값 없음
     */
    public function test_cooldown_is_isolated_per_user(): void
    {
        $this->mockSettings['spam_security']['post_cooldown_seconds'] = 60;
        $this->applySettings();

        $userA = $this->createUser();
        $userB = $this->createUser();

        // userA 작성 → 쿨다운 등록
        $this->actingAs($userA)->postJson($this->postUrl(), $this->validPostData())
            ->assertStatus(201);

        // userB는 쿨다운 영향 없이 작성 가능
        $this->actingAs($userB)->postJson($this->postUrl(), $this->validPostData())
            ->assertStatus(201);
    }

    /**
     * 쿨다운 오류 응답에 422 상태코드와 errors 필드가 있다
     *
     * @scenario concern=write_cooldown, condition=cooldown_active
     *
     * @effects cooldown_rejection_returns_422_with_errors
     *
     * @return void 반환값 없음
     */
    public function test_cooldown_rejection_returns_422_with_errors(): void
    {
        $this->mockSettings['spam_security']['post_cooldown_seconds'] = 60;
        $this->applySettings();

        $user = $this->createUser();

        $this->actingAs($user)->postJson($this->postUrl(), $this->validPostData())
            ->assertStatus(201);

        $this->actingAs($user)->postJson($this->postUrl(), $this->validPostData())
            ->assertStatus(422)
            ->assertJsonStructure(['errors']);
    }

    // ==========================================
    // 원자적 선점 (#161 F7)
    // ==========================================

    /**
     * 회원의 게시글 쿨다운 캐시 키(모듈 캐시 접두사 포함)를 돌려줍니다.
     *
     * @param  int  $userId  회원 ID
     * @return string 캐시 키
     */
    private function postCooldownKey(int $userId): string
    {
        return "g7:module.sirsoft-board:post_cooldown_{$this->board->slug}_{$userId}";
    }

    /**
     * 쿨다운 규칙을 한 번 검증하고 실패 문구를 돌려줍니다.
     *
     * @return array<int, string> 실패 문구 (통과하면 빈 배열)
     */
    private function validateCooldownOnce(): array
    {
        $failures = [];
        (new CooldownRule('post', 60, $this->board->slug))->validate('title', '제목', function ($message) use (&$failures) {
            $failures[] = (string) $message;
        });

        return $failures;
    }

    /**
     * 쿨다운 자리는 검증 시점에 원자적으로 선점한다 — 동시에 들어온 두 요청이 아직 아무도 작성을 기록하지 않은 상태에서 검증하면,
     * 1.2.2 까지는 둘 다 통과해 쿨다운을 켠 게시판에 글이 여러 개 써졌다. 이제 두 번째 검증은 실패한다.
     *
     * @scenario concern=write_cooldown, condition=cooldown_active
     *
     * @effects concurrent_post_requests_claim_cooldown_atomically
     *
     * @return void 반환값 없음
     */
    public function test_rule_claims_atomically_so_second_concurrent_request_fails(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        // 두 요청의 검증이 Service 의 작성 기록보다 먼저 연달아 돈다
        $this->assertSame([], $this->validateCooldownOnce(), '첫 요청은 자리를 잡는다');
        $second = $this->validateCooldownOnce();

        $this->assertCount(1, $second, '두 번째 요청은 같은 자리를 잡지 못한다');
        $this->assertNotSame('', trim($second[0]), '쿨다운 안내 문구로 실패한다');
        $this->assertStringStartsWith('claim:', (string) Cache::get($this->postCooldownKey($user->id)), '자리는 첫 요청의 표지로 잡혀 있다');
    }

    /**
     * 다른 칸 검증에 실패한 요청은 응답 뒤 선점한 자리를 반납한다 — 반납하지 않으면 422 를 받은 사용자가 고쳐 다시 올려도
     * 쿨다운에 막힌다. 작성에 성공한 요청은 Service 가 값을 덮어써 쿨다운이 이어진다.
     *
     * @scenario concern=write_cooldown, condition=cooldown_active
     *
     * @effects failed_post_validation_releases_cooldown_claim
     *
     * @return void 반환값 없음
     */
    public function test_failed_validation_releases_cooldown_claim(): void
    {
        $this->mockSettings['spam_security']['post_cooldown_seconds'] = 60;
        $this->applySettings();

        $user = $this->createUser();

        // 본문이 비어 422 — 제목 칸의 쿨다운 규칙은 이미 자리를 잡았다
        $this->actingAs($user)->postJson($this->postUrl(), ['title' => '쿨다운 테스트 제목', 'content' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content'])
            ->assertJsonMissingValidationErrors(['title']);

        // 시험 요청도 응답 뒤 terminate 를 거친다 — 반납 콜백이 돌아 자리가 비었다
        $this->assertNull(Cache::get($this->postCooldownKey($user->id)), '실패한 요청의 자리는 반납된다');

        // 고쳐 다시 올리면 쿨다운에 막히지 않는다
        $this->actingAs($user)->postJson($this->postUrl(), $this->validPostData())
            ->assertStatus(201);

        // 성공한 작성은 쿨다운을 남긴다(반납 콜백이 Service 의 기록을 지우지 않는다)
        $this->assertTrue(Cache::get($this->postCooldownKey($user->id)));
        $this->actingAs($user)->postJson($this->postUrl(), $this->validPostData())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    /**
     * 댓글 쿨다운도 같은 원자적 선점을 쓴다 — 동시 두 댓글 요청 중 두 번째 검증은 실패하고, 글 쿨다운과 키가 달라 서로 막지 않는다(#161 F7).
     *
     * @scenario concern=write_cooldown, condition=cooldown_active
     *
     * @effects concurrent_comment_requests_claim_cooldown_atomically
     *
     * @return void 반환값 없음
     */
    public function test_comment_rule_claims_atomically_and_is_separate_from_post_cooldown(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $validate = function (string $type): array {
            $failures = [];
            (new CooldownRule($type, 60, $this->board->slug))->validate('content', '내용', function ($message) use (&$failures) {
                $failures[] = (string) $message;
            });

            return $failures;
        };

        $this->assertSame([], $validate('comment'), '첫 댓글 요청은 자리를 잡는다');
        $this->assertCount(1, $validate('comment'), '두 번째 댓글 요청은 같은 자리를 잡지 못한다');
        $this->assertSame([], $validate('post'), '댓글 쿨다운이 글 쿨다운 자리를 막지 않는다');
        $this->assertStringStartsWith('claim:', (string) Cache::get("g7:module.sirsoft-board:comment_cooldown_{$this->board->slug}_{$user->id}"));
    }

    /**
     * 쿨다운 캐시가 오류를 내면 오류를 기록하고 검증을 통과시킨다 — 도배 방지 장치가 캐시 장애로 글쓰기 전체를 막는 쪽이 더
     * 나쁘다(설계상 fail-open, 종전 `has()` 도 장애 시 통과였다).
     *
     * @scenario concern=write_cooldown, condition=cooldown_active
     *
     * @effects cooldown_cache_error_does_not_block_posting
     *
     * @return void 반환값 없음
     */
    public function test_cache_error_does_not_block_posting(): void
    {
        $this->mockSettings['spam_security']['post_cooldown_seconds'] = 60;
        $this->applySettings();

        config(['cache.stores.i161_board_broken_add' => ['driver' => 'i161_board_broken_add']]);
        Cache::extend('i161_board_broken_add', fn () => Cache::repository(new class extends ArrayStore
        {
            /**
             * 쿨다운 키의 선점은 저장소 오류를 내고, 다른 키는 「없을 때만 저장」을 그대로 한다.
             *
             * @param  string  $key  키
             * @param  mixed  $value  값
             * @param  int  $seconds  유지 시간(초)
             * @return bool 저장 여부
             *
             * @throws \RuntimeException 쿨다운 키일 때
             */
            public function add($key, $value, $seconds)
            {
                if (str_contains((string) $key, '_cooldown_')) {
                    throw new \RuntimeException('i161 cache store down C:\\fake\\g7');
                }

                if ($this->get($key) !== null) {
                    return false;
                }

                return $this->put($key, $value, $seconds);
            }
        }));
        $default = config('cache.default');
        config(['cache.default' => 'i161_board_broken_add']);

        Log::spy();
        $user = $this->createUser();

        try {
            $this->actingAs($user)->postJson($this->postUrl(), $this->validPostData())
                ->assertStatus(201);
        } finally {
            config(['cache.default' => $default]);
            Cache::forgetDriver('i161_board_broken_add');
        }

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context = []) => str_contains((string) $message, '쿨다운 캐시 오류')
                && str_contains((string) ($context['error'] ?? ''), 'i161 cache store down'))
            ->once();
    }
}
