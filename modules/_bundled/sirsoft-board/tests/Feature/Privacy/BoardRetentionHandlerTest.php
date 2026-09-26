<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Privacy;

use App\Extension\Storage\ModuleStorageDriver;
use App\Models\User;
use App\Privacy\PrivacyRetentionManager;
use App\Services\ModuleSettingsService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Privacy\BoardGuestRetentionHandler;
use Modules\Sirsoft\Board\Privacy\BoardIpRetentionHandler;
use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * 게시판 개인정보 보유기간 핸들러 테스트 (이슈 #636 S5)
 *
 * 두 축(작성 IP · 비회원 작성자 정보)이 코어 보유기간 계약을 지키는지 고정합니다 —
 * 기본 꺼짐, 미리보기 건수 = 실제 처리 건수, 재실행 멱등, 휴지통 행 포함, 수정 시각 보존.
 *
 * 핸들러는 게시판 전체를 훑으므로 다른 테스트가 남긴 행이 섞일 수 있다. 그래서 건수는
 * **처리 전후 차이**로 단언하고, 이 테스트의 행은 충분히 오래된 작성 시각으로 만든다.
 */
class BoardRetentionHandlerTest extends BoardTestCase
{
    /** 보존기간 밖으로 밀어 둘 작성 시각(일 전) */
    private const OLD_DAYS = 4000;

    /**
     * 게시판 모듈 설정 판독을 지정 값으로 고정합니다.
     *
     * @param  array<string, mixed>  $values  설정 키 => 값
     * @return void 반환값 없음
     */
    private function fakeRetentionSettings(array $values): void
    {
        $service = Mockery::mock(ModuleSettingsService::class);

        $service->shouldReceive('get')
            ->andReturnUsing(function (string $identifier, ?string $key = null, mixed $default = null) use ($values) {
                if ($identifier !== 'sirsoft-board') {
                    return $default;
                }

                return array_key_exists($key, $values) ? $values[$key] : $default;
            });

        $this->app->instance(ModuleSettingsService::class, $service);
    }

    /**
     * 보존기간을 넘긴 작성 시각 문자열을 반환합니다.
     *
     * @return string 작성 시각
     */
    private function oldTimestamp(): string
    {
        return now()->subDays(self::OLD_DAYS)->format('Y-m-d H:i:s');
    }

    /**
     * 회원 한 명을 만듭니다.
     *
     * @return User 회원
     */
    private function member(): User
    {
        return User::factory()->create();
    }

    /**
     * 게시글 신고 로그를 스냅샷과 함께 만듭니다.
     *
     * @param  string  $targetType  신고 대상 종류
     * @param  int  $targetId  대상 ID
     * @return int 신고 로그 ID
     */
    private function reportWithSnapshot(string $targetType, int $targetId): int
    {
        $reportId = DB::table('boards_reports')->insertGetId([
            'board_id' => $this->board->id,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('boards_report_logs')->insertGetId([
            'report_id' => $reportId,
            'reporter_id' => null,
            'snapshot' => json_encode([
                'board_name' => '게시판',
                'title' => '비회원이 쓴 제목',
                'content' => '연락처 010-1234-5678',
                'author_name' => '홍길동',
            ], JSON_UNESCAPED_UNICODE),
            'reason_type' => 'spam',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 기본값(꺼짐)에서는 두 축 모두 아무것도 바꾸지 않고 「꺼짐」으로 보고한다.
     *
     * @return void 반환값 없음
     *
     * @scenario axis=ip, toggle=off, guest_mode=anonymize
     *
     * @effects board_retention_is_off_by_default
     */
    #[Test]
    public function both_axes_are_off_by_default_and_touch_nothing(): void
    {
        $postId = $this->createTestPost(['created_at' => $this->oldTimestamp(), 'ip_address' => '203.0.113.1']);

        $ip = app(BoardIpRetentionHandler::class);
        $guest = app(BoardGuestRetentionHandler::class);

        $this->assertFalse($ip->isEnabled());
        $this->assertFalse($guest->isEnabled());
        $this->assertSame(['count' => 0, 'mode' => 'disabled'], $ip->process(false));
        $this->assertSame(['count' => 0, 'mode' => 'disabled'], $guest->process(false));

        $row = DB::table('board_posts')->find($postId);
        $this->assertSame('203.0.113.1', $row->ip_address);
        $this->assertSame('테스트', $row->author_name);
    }

    /**
     * 대상 목록: 꺼져 있어도 건수와 같은 두 쿼리(글 + 댓글)의 명단이 나오고, 비회원 보존기간 0(미적용)
     * 이면 비회원 축 건수·명단이 비며, 코어 목록 API 가 「설정 읽기 + 게시판 관리 읽기」로 검사한다.
     *
     * @return void 반환값 없음
     *
     * @scenario axis=ip, toggle=off, guest_mode=anonymize
     *
     * @effects board_retention_targets_match_the_population
     */
    #[Test]
    public function target_lists_match_the_population_while_disabled(): void
    {
        $this->fakeRetentionSettings([
            'privacy_retention.enabled' => false,
            'privacy_retention.ip_retention_days' => 365,
            'privacy_retention.guest_retention_days' => 0,
        ]);

        $old = $this->oldTimestamp();
        $member = $this->member();
        $guestPost = $this->createTestPost(['title' => '오래된 비회원 글', 'author_name' => '홍길동', 'created_at' => $old, 'ip_address' => '203.0.113.21']);
        $memberComment = $this->createTestComment($guestPost, ['user_id' => $member->id, 'author_name' => null, 'created_at' => $old, 'ip_address' => '203.0.113.22']);

        $ip = app(BoardIpRetentionHandler::class);
        $guest = app(BoardGuestRetentionHandler::class);

        $this->assertFalse($ip->isEnabled());
        $count = $ip->preview();
        $this->assertGreaterThanOrEqual(2, $count, '꺼져 있어도 IP 축 건수를 센다');

        $page = $ip->targets(1, $count);
        $this->assertSame($count, $page->total(), '명단 수 = 건수(글 + 댓글)');
        $ids = collect($page->items())->pluck('id')->all();
        $expected = array_merge(
            $ip->postQuery()->pluck('id')->map(fn ($id) => 'post-'.$id)->all(),
            $ip->commentQuery()->pluck('id')->map(fn ($id) => 'comment-'.$id)->all(),
        );
        sort($ids);
        sort($expected);
        $this->assertSame($expected, $ids, '명단 = 배치의 두 쿼리');

        $rows = collect($page->items())->keyBy('id');
        $postRow = $rows->get('post-'.$guestPost);
        $this->assertSame('오래된 비회원 글', $postRow->title);
        $this->assertSame('홍길동', $postRow->subtitle);
        $this->assertStringContainsString($this->board->getLocalizedName(), (string) $postRow->context);
        // 관리자 상세 — 대상에 휴지통 글이 들고, 관리자 상세는 게시판 관리 권한으로 삭제글을 보인다.
        $this->assertSame('/admin/board/'.$this->board->slug.'/post/'.$guestPost, $postRow->url);

        $commentRow = $rows->get('comment-'.$memberComment);
        $this->assertSame('오래된 비회원 글', $commentRow->title, '댓글 행은 그 글의 제목을 보인다');
        $this->assertSame('/admin/board/'.$this->board->slug.'/post/'.$guestPost, $commentRow->url, '댓글 행은 그 글의 관리자 상세');
        $this->assertSame($member->nickname ?: $member->name, $commentRow->subtitle, '회원 작성자는 닉네임');

        // 비회원 보존기간 0 = 이 축을 쓰지 않음 — 건수·명단이 모든 비회원 글로 부풀지 않는다.
        $this->assertSame(0, $guest->preview());
        $this->assertSame(0, $guest->targets(1, 20)->total());

        // 권한 거부를 먼저 잰다 — 이 베이스의 createAdminUser 는 권한을 공유 admin 역할에 붙이므로
        // 도메인 읽기를 준 관리자를 먼저 만들면 뒤에 만든 관리자도 그 권한을 물려받는다.
        $settingsOnly = $this->createAdminUser(['sirsoft-board.settings.read']);
        $this->actingAs($settingsOnly)
            ->getJson('/api/admin/privacy/retention-targets/sirsoft-board.guest')
            ->assertForbidden();

        $viewer = $this->createAdminUser(['sirsoft-board.settings.read', 'sirsoft-board.boards.read']);
        $this->actingAs($viewer)
            ->getJson('/api/admin/privacy/retention-targets/sirsoft-board.ip?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', $count);
    }

    /**
     * 두 핸들러가 코어 보유기간 수집기에 등록된다 — 게시판은 스케줄을 따로 두지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario axis=ip, toggle=off, guest_mode=anonymize
     *
     * @effects board_retention_handlers_are_registered_with_core
     */
    #[Test]
    public function both_handlers_are_collected_by_the_core_manager(): void
    {
        $handlers = app(PrivacyRetentionManager::class)->handlers();

        $this->assertArrayHasKey('sirsoft-board.ip', $handlers);
        $this->assertArrayHasKey('sirsoft-board.guest', $handlers);
        $this->assertArrayHasKey('user-withdrawn', $handlers, '확장 등록이 코어 기본을 밀어내지 않는다');
    }

    /**
     * IP 축: 보존기간이 지난 게시글·댓글(회원 글·휴지통 포함)의 IP 만 지우고, 미리보기 건수와
     * 처리 건수가 같으며, 수정 시각을 바꾸지 않고, 두 번째 실행은 0건이다.
     *
     * @return void 반환값 없음
     *
     * @scenario axis=ip, toggle=on, guest_mode=anonymize
     *
     * @effects board_ip_is_erased_after_retention, board_retention_preview_equals_processed, board_retention_is_idempotent
     */
    #[Test]
    public function ip_axis_erases_only_expired_ips_and_matches_preview(): void
    {
        $this->fakeRetentionSettings([
            'privacy_retention.enabled' => true,
            'privacy_retention.ip_retention_days' => 365,
        ]);
        $handler = app(BoardIpRetentionHandler::class);
        $baseline = $handler->preview();

        $old = $this->oldTimestamp();
        $member = $this->member();

        $memberPost = $this->createTestPost(['user_id' => $member->id, 'created_at' => $old, 'updated_at' => $old, 'ip_address' => '203.0.113.10']);
        $trashedPost = $this->createTestPost(['created_at' => $old, 'ip_address' => '203.0.113.11', 'deleted_at' => now()]);
        $recentPost = $this->createTestPost(['ip_address' => '203.0.113.12']);
        $oldComment = $this->createTestComment($recentPost, ['created_at' => $old, 'ip_address' => '203.0.113.13']);
        $recentComment = $this->createTestComment($recentPost, ['ip_address' => '203.0.113.14']);

        $this->assertSame($baseline + 3, $handler->preview());

        $dry = $handler->process(true);
        $this->assertSame($baseline + 3, $dry['count']);
        $this->assertSame('203.0.113.10', DB::table('board_posts')->find($memberPost)->ip_address, 'dry-run 은 데이터를 바꾸지 않는다');

        $result = $handler->process(false);

        $this->assertSame($dry['count'], $result['count'], '미리보기 건수 = 실제 처리 건수');
        $this->assertSame('erase_ip', $result['mode']);
        $this->assertNull(DB::table('board_posts')->find($memberPost)->ip_address, '회원 글의 IP 도 접속기록이라 지운다');
        $this->assertNull(DB::table('board_posts')->find($trashedPost)->ip_address, '휴지통 글에 남은 IP 도 지운다');
        $this->assertNull(DB::table('board_comments')->find($oldComment)->ip_address);
        $this->assertSame('203.0.113.12', DB::table('board_posts')->find($recentPost)->ip_address);
        $this->assertSame('203.0.113.14', DB::table('board_comments')->find($recentComment)->ip_address);
        $this->assertSame($old, (string) DB::table('board_posts')->find($memberPost)->updated_at, 'IP 파기는 글 수정이 아니다 — 수정 시각 보존');

        $this->assertSame(0, $handler->preview(), '두 번째 실행 대상은 0건');
        $this->assertSame(0, $handler->process(false)['count']);
    }

    /**
     * 비회원 익명화: 보존기간이 지난 비회원 글·댓글(휴지통 포함)의 작성자명만 가리고, 회원 글과
     * 기간 안의 글은 두며, 신고 스냅샷의 작성자명도 함께 덮는다.
     *
     * @return void 반환값 없음
     *
     * @scenario axis=guest, toggle=on, guest_mode=anonymize
     *
     * @effects board_guest_author_is_anonymized, board_report_snapshot_is_scrubbed, board_retention_preview_equals_processed, board_retention_is_idempotent
     */
    #[Test]
    public function guest_anonymize_masks_only_expired_guest_authors_and_report_snapshots(): void
    {
        $this->fakeRetentionSettings([
            'privacy_retention.enabled' => true,
            'privacy_retention.guest_retention_days' => 30,
            'privacy_retention.guest_mode' => 'anonymize',
        ]);
        $handler = app(BoardGuestRetentionHandler::class);
        $baseline = $handler->preview();

        $old = $this->oldTimestamp();
        $member = $this->member();

        $guestPost = $this->createTestPost(['author_name' => '홍길동', 'created_at' => $old, 'updated_at' => $old]);
        $trashedGuestPost = $this->createTestPost(['author_name' => '김철수', 'created_at' => $old, 'deleted_at' => now()]);
        $memberPost = $this->createTestPost(['user_id' => $member->id, 'author_name' => null, 'created_at' => $old]);
        $recentGuestPost = $this->createTestPost(['author_name' => '이영희']);
        $guestComment = $this->createTestComment($memberPost, ['author_name' => '박민수', 'created_at' => $old]);
        $logId = $this->reportWithSnapshot('post', $guestPost);

        $this->assertSame($baseline + 3, $handler->preview());
        $dry = $handler->process(true);
        $this->assertSame('홍길동', DB::table('board_posts')->find($guestPost)->author_name, 'dry-run 은 데이터를 바꾸지 않는다');
        $this->assertSame('홍길동', json_decode(DB::table('boards_report_logs')->find($logId)->snapshot, true)['author_name'], 'dry-run 은 신고 스냅샷도 두다');

        $result = $handler->process(false);

        $this->assertSame($dry['count'], $result['count'], '미리보기 건수 = 실제 처리 건수');
        $this->assertSame('anonymize', $result['mode']);

        $row = DB::table('board_posts')->find($guestPost);
        $this->assertSame(BoardGuestRetentionHandler::ANONYMIZED_AUTHOR, $row->author_name);
        $this->assertSame('테스트 내용입니다.', $row->content, '익명화는 본문을 두고 작성자만 가린다');
        $this->assertNull($row->deleted_at, '익명화는 글을 지우지 않는다');
        $this->assertSame($old, (string) $row->updated_at, '수정 시각 보존');
        $this->assertSame(BoardGuestRetentionHandler::ANONYMIZED_AUTHOR, DB::table('board_posts')->find($trashedGuestPost)->author_name);
        $this->assertSame(BoardGuestRetentionHandler::ANONYMIZED_AUTHOR, DB::table('board_comments')->find($guestComment)->author_name);
        $this->assertNull(DB::table('board_posts')->find($memberPost)->author_name, '회원 글은 대상이 아니다');
        $this->assertSame('이영희', DB::table('board_posts')->find($recentGuestPost)->author_name);

        $snapshot = json_decode(DB::table('boards_report_logs')->find($logId)->snapshot, true);
        $this->assertSame(BoardGuestRetentionHandler::ANONYMIZED_AUTHOR, $snapshot['author_name'], '신고 스냅샷의 작성자명 사본도 덮는다');
        $this->assertSame('비회원이 쓴 제목', $snapshot['title'], '익명화는 스냅샷의 제목·본문을 두다');

        $this->assertSame(0, $handler->process(false)['count'], '두 번째 실행은 0건');
    }

    /**
     * 비회원 삭제: 살아 있는 비회원 글은 게시판 삭제 경로로 지워지고(댓글 연쇄), 행에는 작성자명·
     * 비밀번호·IP·제목·본문이 남지 않으며, 첨부는 파일째 사라지고, 신고 스냅샷의 제목·본문도 덮인다.
     *
     * @return void 반환값 없음
     *
     * @scenario axis=guest, toggle=on, guest_mode=delete
     *
     * @effects board_guest_post_is_deleted_and_scrubbed, board_guest_attachments_are_purged, board_report_snapshot_is_scrubbed, board_retention_preview_equals_processed, board_retention_is_idempotent
     */
    #[Test]
    public function guest_delete_removes_post_through_service_and_scrubs_remaining_row(): void
    {
        $this->fakeRetentionSettings([
            'privacy_retention.enabled' => true,
            'privacy_retention.guest_retention_days' => 30,
            'privacy_retention.guest_mode' => 'delete',
        ]);
        $handler = app(BoardGuestRetentionHandler::class);
        $baseline = $handler->preview();

        $old = $this->oldTimestamp();
        $member = $this->member();

        $guestPost = $this->createTestPost([
            'author_name' => '홍길동',
            'password' => bcrypt('secret'),
            'ip_address' => '203.0.113.20',
            'created_at' => $old,
        ]);
        $memberComment = $this->createTestComment($guestPost, ['user_id' => $member->id, 'author_name' => null]);
        $memberPost = $this->createTestPost(['user_id' => $member->id, 'author_name' => null, 'created_at' => $old]);
        $guestCommentOnMemberPost = $this->createTestComment($memberPost, ['author_name' => '박민수', 'created_at' => $old]);

        $path = 'free/2026/01/'.uniqid('guest-').'.png';
        $storage = new ModuleStorageDriver('sirsoft-board', 'modules');
        $storage->put('attachments', $path, 'bytes');
        $attachment = Attachment::create([
            'board_id' => $this->board->id,
            'post_id' => $guestPost,
            'original_filename' => 'photo.png',
            'stored_filename' => basename($path),
            'disk' => 'modules',
            'path' => $path,
            'mime_type' => 'image/png',
            'size' => 5,
            'collection' => 'attachments',
            'order' => 1,
        ]);
        $logId = $this->reportWithSnapshot('post', $guestPost);

        $this->assertSame($baseline + 2, $handler->preview());
        $dry = $handler->process(true);
        $this->assertNull(DB::table('board_posts')->find($guestPost)->deleted_at, 'dry-run 은 글을 지우지 않는다');
        $this->assertTrue($storage->exists('attachments', $path), 'dry-run 은 첨부 파일을 두다');

        $result = $handler->process(false);

        $this->assertSame($dry['count'], $result['count'], '미리보기 건수 = 실제 처리 건수');
        $this->assertSame('delete', $result['mode']);

        $row = DB::table('board_posts')->find($guestPost);
        $this->assertNotNull($row->deleted_at, '게시판 삭제 경로로 목록에서 사라진다');
        $this->assertSame('deleted', $row->status);
        $this->assertSame('system', $row->trigger_type, '시스템 삭제로 기록된다');
        $this->assertSame(BoardGuestRetentionHandler::ANONYMIZED_AUTHOR, $row->author_name);
        $this->assertNull($row->password);
        $this->assertNull($row->ip_address);
        $this->assertSame(BoardGuestRetentionHandler::ANONYMIZED_AUTHOR, $row->title, '관리자 목록(휴지통 포함)에서 알아볼 수 있게 제목은 치환값');
        $this->assertSame('', $row->content);

        $this->assertNotNull(DB::table('board_comments')->find($memberComment)->deleted_at, '비회원 글 아래 댓글은 삭제 경로가 연쇄 삭제한다');

        $comment = DB::table('board_comments')->find($guestCommentOnMemberPost);
        $this->assertNotNull($comment->deleted_at);
        $this->assertSame('', $comment->content);
        $this->assertSame(BoardGuestRetentionHandler::ANONYMIZED_AUTHOR, $comment->author_name);
        $this->assertNull(DB::table('board_posts')->find($memberPost)->deleted_at, '회원 글은 대상이 아니다');
        $this->assertSame($member->email, DB::table('users')->find($member->id)->email, '회원 행은 건드리지 않는다(D22)');

        $this->assertNull(Attachment::withTrashed()->find($attachment->id), '첨부 기록이 사라진다');
        $this->assertFalse($storage->exists('attachments', $path), '첨부 파일이 곧바로 지워진다');

        $snapshot = json_decode(DB::table('boards_report_logs')->find($logId)->snapshot, true);
        $this->assertSame(BoardGuestRetentionHandler::ANONYMIZED_AUTHOR, $snapshot['title']);
        $this->assertSame('', $snapshot['content']);
        $this->assertSame(BoardGuestRetentionHandler::ANONYMIZED_AUTHOR, $snapshot['author_name']);

        $this->assertSame(0, $handler->process(false)['count'], '두 번째 실행은 0건');
    }

    /**
     * 익명화했던 글을 삭제 모드로 바꾸면 본문이 남아 있으므로 다시 대상이 된다.
     *
     * 멱등 표지를 「작성자명 치환값」 하나로 두면 익명화만 된 글이 삭제 모드에서 영구히 빠진다
     * — 이 테스트가 그 표지를 모드별로 가르는 이유를 고정한다.
     *
     * @return void 반환값 없음
     *
     * @scenario axis=guest, toggle=on, guest_mode=delete
     *
     * @effects board_guest_post_is_deleted_and_scrubbed
     */
    #[Test]
    public function switching_from_anonymize_to_delete_retargets_anonymized_posts(): void
    {
        $old = $this->oldTimestamp();
        $postId = $this->createTestPost(['author_name' => '홍길동', 'created_at' => $old]);

        $this->fakeRetentionSettings([
            'privacy_retention.enabled' => true,
            'privacy_retention.guest_retention_days' => 30,
            'privacy_retention.guest_mode' => 'anonymize',
        ]);
        app(BoardGuestRetentionHandler::class)->process(false);
        $this->assertSame(BoardGuestRetentionHandler::ANONYMIZED_AUTHOR, DB::table('board_posts')->find($postId)->author_name);

        $this->fakeRetentionSettings([
            'privacy_retention.enabled' => true,
            'privacy_retention.guest_retention_days' => 30,
            'privacy_retention.guest_mode' => 'delete',
        ]);
        app(BoardGuestRetentionHandler::class)->process(false);

        $row = DB::table('board_posts')->find($postId);
        $this->assertNotNull($row->deleted_at);
        $this->assertSame('', $row->content);
    }

    /**
     * 신고 스냅샷 파기가 실패한 행은 처리 표지를 찍지 않는다 — 다음 실행이 다시 잡아 사본까지 지운다.
     *
     * 표지(작성자명 치환값)를 먼저 찍고 스냅샷을 나중에 지우면, 스냅샷 쪽 실패가 그 행을 다음 실행의
     * 대상에서 영구히 빼 신고 로그 사본의 개인정보가 남는다.
     *
     * @return void 반환값 없음
     *
     * @scenario axis=guest, toggle=on, guest_mode=anonymize
     *
     * @effects board_report_snapshot_is_scrubbed, board_retention_is_idempotent
     */
    #[Test]
    public function snapshot_scrub_failure_leaves_the_row_unmarked_for_the_next_run(): void
    {
        $this->fakeRetentionSettings([
            'privacy_retention.enabled' => true,
            'privacy_retention.guest_retention_days' => 30,
            'privacy_retention.guest_mode' => 'anonymize',
        ]);
        $postId = $this->createTestPost(['author_name' => '홍길동', 'created_at' => $this->oldTimestamp()]);

        $failing = Mockery::mock(ReportRepositoryInterface::class);
        $failing->shouldReceive('scrubSnapshotsForTargets')->andThrow(new RuntimeException('신고 로그 잠금'));
        $this->app->instance(ReportRepositoryInterface::class, $failing);

        $handler = app(BoardGuestRetentionHandler::class);
        $before = $handler->preview();
        $result = $handler->process(false);

        $this->assertSame(0, $result['count'], '스냅샷까지 지우지 못한 행은 처리 건수에 넣지 않는다');
        $this->assertSame('홍길동', DB::table('board_posts')->find($postId)->author_name, '표지를 찍지 않아 다음 실행이 다시 잡는다');
        $this->assertSame($before, $handler->preview());
    }

    /**
     * 게시판 행이 사라진 고아 비회원 글도 삭제 모드에서 한 번 처리되면 다시 잡히지 않는다.
     *
     * 게시판 삭제 경로는 게시판 slug 가 있어야 탈 수 있으므로 고아 행은 파기만 한다 — 이때 삭제
     * 시각을 찍지 않으면 「삭제됨」 표지가 영영 서지 않아 매 실행 다시 대상이 되고 dry-run 이 0 이 되지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario axis=guest, toggle=on, guest_mode=delete
     *
     * @effects board_guest_post_is_deleted_and_scrubbed, board_retention_is_idempotent
     */
    #[Test]
    public function orphan_guest_rows_are_marked_deleted_once_in_delete_mode(): void
    {
        $this->fakeRetentionSettings([
            'privacy_retention.enabled' => true,
            'privacy_retention.guest_retention_days' => 30,
            'privacy_retention.guest_mode' => 'delete',
        ]);
        $old = $this->oldTimestamp();
        $postId = $this->createTestPost(['author_name' => '홍길동', 'created_at' => $old]);
        $commentId = $this->createTestComment($postId, ['author_name' => '박민수', 'created_at' => $old]);

        // 게시판 행만 없앤 고아 상태 — 트랜잭션 격리라 테스트 뒤 되돌려진다.
        DB::table('boards')->where('id', $this->board->id)->delete();

        $handler = app(BoardGuestRetentionHandler::class);
        $handler->process(false);

        $post = DB::table('board_posts')->find($postId);
        $this->assertNotNull($post->deleted_at, '고아 글도 삭제 표지가 선다');
        $this->assertSame('', $post->content);
        $this->assertNotNull(DB::table('board_comments')->find($commentId)->deleted_at, '고아 댓글도 삭제 표지가 선다');
        $this->assertSame(0, $handler->preview(), '두 번째 실행 대상은 0건');
    }

    /**
     * 비회원 보존기간 0 은 「그 축을 쓰지 않음」이라 켜 두어도 꺼짐으로 보고한다.
     *
     * @return void 반환값 없음
     *
     * @scenario axis=guest, toggle=off, guest_mode=anonymize
     *
     * @effects board_retention_is_off_by_default
     */
    #[Test]
    public function guest_axis_with_zero_days_is_reported_as_disabled(): void
    {
        $this->fakeRetentionSettings([
            'privacy_retention.enabled' => true,
            'privacy_retention.guest_retention_days' => 0,
        ]);

        $postId = $this->createTestPost(['author_name' => '홍길동', 'created_at' => $this->oldTimestamp()]);
        $handler = app(BoardGuestRetentionHandler::class);

        $this->assertFalse($handler->isEnabled());
        $this->assertSame('disabled', $handler->process(false)['mode']);
        $this->assertSame('홍길동', DB::table('board_posts')->find($postId)->author_name);
    }

    /**
     * 판독부 바닥값: 저장 검증을 거치지 않은 IP 보존기간 0 이 「방금 쓴 글까지 파기」가 되지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario axis=ip, toggle=on, guest_mode=anonymize
     *
     * @effects board_ip_is_erased_after_retention
     */
    #[Test]
    public function ip_retention_days_below_one_is_clamped_to_one(): void
    {
        $this->fakeRetentionSettings([
            'privacy_retention.enabled' => true,
            'privacy_retention.ip_retention_days' => 0,
        ]);

        $postId = $this->createTestPost(['ip_address' => '203.0.113.30']);
        app(BoardIpRetentionHandler::class)->process(false);

        $this->assertSame(1, app(BoardIpRetentionHandler::class)->retentionDays());
        $this->assertSame('203.0.113.30', DB::table('board_posts')->find($postId)->ip_address, '오늘 쓴 글의 IP 는 남는다');
    }

    /**
     * 관리자 직권 실행(`force`)은 꺼져 있어도 저장된 기간값으로 IP 를 지우고, 자동 배치는 여전히 꺼짐으로 멈춘다.
     * 두 축의 직권 실행 권한은 설정 수정 + 게시판 관리 수정이다.
     *
     * @return void 반환값 없음
     *
     * @scenario axis=ip, toggle=off, guest_mode=anonymize
     *
     * @effects board_manual_run_processes_while_off, board_manual_run_requires_settings_and_board_update
     */
    #[Test]
    public function manual_run_erases_ips_while_off_and_requires_update_permissions(): void
    {
        $this->fakeRetentionSettings([
            'privacy_retention.enabled' => false,
            'privacy_retention.ip_retention_days' => 365,
        ]);
        $handler = app(BoardIpRetentionHandler::class);
        $post = $this->createTestPost(['created_at' => $this->oldTimestamp(), 'ip_address' => '203.0.113.21']);

        $this->assertSame(['count' => 0, 'mode' => 'disabled'], $handler->process(false), '자동 배치는 토글을 본다');
        $this->assertSame('203.0.113.21', DB::table('board_posts')->find($post)->ip_address);

        $expected = $handler->preview();
        $result = $handler->process(false, true);

        $this->assertSame($expected, $result['count'], '직권 실행도 미리보기와 같은 모집단');
        $this->assertSame('erase_ip', $result['mode']);
        $this->assertNull(DB::table('board_posts')->find($post)->ip_address, '직권 실행은 꺼져 있어도 지운다');

        $expectedPermissions = ['sirsoft-board.settings.update', 'sirsoft-board.boards.update'];
        $this->assertSame($expectedPermissions, $handler->runPermissions());
        $this->assertSame($expectedPermissions, app(BoardGuestRetentionHandler::class)->runPermissions());
    }
}
