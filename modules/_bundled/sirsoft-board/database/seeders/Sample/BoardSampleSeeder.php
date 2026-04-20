<?php

namespace Modules\Sirsoft\Board\Database\Seeders\Sample;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Services\BoardService;

/**
 * 게시판 샘플 시더
 *
 * 다양한 설정의 게시판을 생성합니다.
 * BoardService를 사용하여 테이블과 권한이 자동으로 생성됩니다.
 */
class BoardSampleSeeder extends Seeder
{
    /**
     * 게시판 샘플 데이터
     *
     * 테스트 시나리오 (8개 게시판):
     * 1. notice  - 공지사항    : 관리자만 글쓰기, 댓글/파일 없음, mail 알림
     * 2. free    - 자유게시판  : 비회원 개방, 비밀글 선택, 입력 제한 커스텀
     * 3. gallery - 갤러리      : gallery 타입, 이미지만, 최신댓글 상단(DESC), 조회수 정렬
     * 4. event   - 이벤트      : card 타입, 댓글/파일 없음, 오래된 순 정렬(ASC)
     * 5. qna     - Q&A         : 카테고리, 답글 depth, 비밀글 선택, 회원 비밀글 읽기 허용
     * 6. members - 회원전용    : 비회원 차단, secret_mode:always + 댓글 허용
     * 7. inquiry - 1:1 문의    : 비밀글 필수, 답글 depth:1, 비회원 글쓰기, mail 알림
     * 8. archive - 아카이브    : 비활성(is_active:false), blocked_keywords:null(defaults 45개 적용), ASC 정렬
     *
     * 커버하는 설정 케이스:
     * - type            : basic ✓ / gallery ✓ / card ✓
     * - secret_mode     : disabled ✓ / enabled ✓ / always ✓
     * - use_comment     : true ✓ / false ✓
     * - use_reply       : true(depth 1/3/5) ✓ / false ✓
     * - use_report      : true ✓ / false ✓
     * - use_file_upload : true ✓ / false ✓
     * - is_active       : true ✓ / false ✓
     * - order_by        : created_at ✓ / view_count ✓
     * - order_direction : ASC ✓ / DESC ✓
     * - comment_order   : ASC ✓ / DESC ✓
     * - notify channels : mail ✓ / database ✓
     * - blocked_keywords: null(defaults 적용) ✓ / 커스텀 ✓
     * - 입력 제한       : 기본값 ✓ / 커스텀 ✓
     * - secret+댓글 조합: always+댓글허용 ✓
     * - 권한            : posts.read-secret에 user 추가 ✓
     *
     * 권한 설정 원칙:
     * - 기본값: g7_module_settings('sirsoft-board', 'basic_defaults.default_board_permissions') 사용
     * - permissions 배열: 기본값에서 변경이 필요한 권한만 오버라이드
     * - admin은 모든 권한에 포함 (기본값에서 보장)
     */
    private const SAMPLE_BOARDS = [
        // =========================================================
        // 1. 공지사항
        // 검증: 관리자 전용 글쓰기 / 댓글·답글·파일 없음 / mail 알림 채널
        // notify_*_channels: defaults는 ['mail']이지만 시더에서 명시적으로 검증
        // =========================================================
        [
            'name'                          => ['ko' => '공지사항', 'en' => 'Notice'],
            'slug'                          => 'notice',
            'description'                   => ['ko' => '중요한 공지사항을 확인하세요', 'en' => 'Check important announcements'],
            'type'                          => 'basic',
            'is_active'                     => true,
            'secret_mode'                   => 'disabled',
            'use_comment'                   => false,
            'use_reply'                     => false,
            'use_report'                    => false,
            'use_file_upload'               => false,
            'show_view_count'               => true,
            'per_page'                      => 15,
            'new_display_hours'             => 72,
            'notify_author'                 => false,
            'notify_admin_on_post'          => true,
            // 기본값: posts_write = ['admin', 'user'] → user 제거 (관리자만 글쓰기)
            'permissions' => [
                'posts_write' => ['roles' => ['admin']],
            ],
        ],

        // =========================================================
        // 2. 자유게시판
        // 검증: 비회원 전체 개방 / 비밀글 선택 / 입력 제한 커스텀값 / 커스텀 금지어
        // 입력 제한: 제목(5~100자), 내용(0~2000자), 댓글(0~500자)
        // min_content_length: 0 → 내용 없이도 등록 가능
        // min_comment_length: 0 → 짧은 댓글 허용 (예: "👍", "+1")
        // =========================================================
        [
            'name'                          => ['ko' => '자유게시판', 'en' => 'Free Board'],
            'slug'                          => 'free',
            'description'                   => ['ko' => '자유롭게 이야기를 나누세요', 'en' => 'Share your thoughts freely'],
            'type'                          => 'basic',
            'is_active'                     => true,
            'secret_mode'                   => 'enabled',
            'use_comment'                   => true,
            'use_reply'                     => false,
            'use_report'                    => true,
            'use_file_upload'               => true,
            'max_file_size'                 => 5242880, // 5MB
            'max_file_count'                => 5,
            'allowed_extensions'            => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'zip'],
            'show_view_count'               => true,
            'per_page'                      => 20,
            'new_display_hours'             => 24,
            // 입력 제한 커스텀 — 기본값과 다른 값으로 StorePostRequest 검증 동작 확인
            'min_title_length'              => 5,
            'max_title_length'              => 100,
            'min_content_length'            => 0,   // 내용 제한 없음 (링크/이미지만 올리는 경우)
            'max_content_length'            => 2000,
            'min_comment_length'            => 0,   // 짧은 반응 허용
            'max_comment_length'            => 500,
            'notify_author'                 => true,
            'notify_admin_on_post'          => false,
            // 금지어: 커스텀 — 기본 45개 대신 광고/스팸 특화
            'blocked_keywords'              => ['광고', '홍보', 'spam', '도박', '대출'],
            // 기본값: posts_write = ['admin', 'user'] → guest 추가 (비회원도 글쓰기/댓글)
            'permissions' => [
                'posts_write'    => ['roles' => ['admin', 'user', 'guest']],
                'comments_write' => ['roles' => ['admin', 'user', 'guest']],
            ],
        ],

        // =========================================================
        // 3. 갤러리
        // 검증: gallery 타입 / 이미지 파일만 / 조회수 정렬(view_count) / 최신 댓글 상단(comment_order:DESC)
        // =========================================================
        [
            'name'                          => ['ko' => '갤러리', 'en' => 'Gallery'],
            'slug'                          => 'gallery',
            'description'                   => ['ko' => '사진과 이미지를 공유하세요', 'en' => 'Share your photos and images'],
            'type'                          => 'gallery',
            'is_active'                     => true,
            'secret_mode'                   => 'disabled',
            'use_comment'                   => true,
            'use_reply'                     => false,
            'use_report'                    => true,
            'use_file_upload'               => true,
            'max_file_size'                 => 10485760, // 10MB
            'max_file_count'                => 10,
            'allowed_extensions'            => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'show_view_count'               => true,
            'per_page'                      => 12,
            'new_display_hours'             => 24,
            // 조회수 기준 내림차순 정렬 — 인기글이 상단에 노출
            'order_by'                      => 'view_count',
            'order_direction'               => 'DESC',
            // 최신 댓글 상단 — 뉴스피드형 게시판에서 사용
            'comment_order'                 => 'DESC',
            'max_comment_depth'             => 3,
            'notify_author'                 => true,
            'notify_admin_on_post'          => false,
            // 기본값 그대로 — 회원만 글쓰기/댓글, 전체 읽기/다운로드
        ],

        // =========================================================
        // 4. 이벤트
        // 검증: card 타입 / 댓글·답글·파일 없음 / 오래된 순 정렬(order_direction:ASC)
        // order_direction:ASC — 이벤트 번호순, FAQ 순서 고정형 게시판에서 사용
        // =========================================================
        [
            'name'                          => ['ko' => '이벤트', 'en' => 'Event'],
            'slug'                          => 'event',
            'description'                   => ['ko' => '진행 중인 이벤트를 확인하세요', 'en' => 'Check ongoing events'],
            'type'                          => 'card',
            'is_active'                     => true,
            'secret_mode'                   => 'disabled',
            'use_comment'                   => false,
            'use_reply'                     => false,
            'use_report'                    => false,
            'use_file_upload'               => true,
            'max_file_size'                 => 10485760, // 10MB
            'max_file_count'                => 5,
            'allowed_extensions'            => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'show_view_count'               => true,
            'per_page'                      => 12,
            'new_display_hours'             => 72,
            // 등록순 오름차순 — 이벤트 번호순 노출
            'order_by'                      => 'created_at',
            'order_direction'               => 'ASC',
            'notify_author'                 => false,
            'notify_admin_on_post'          => false,
            // 기본값: posts_write = ['admin', 'user'] → user 제거 (관리자만 등록)
            'permissions' => [
                'posts_write' => ['roles' => ['admin']],
            ],
        ],

        // =========================================================
        // 5. Q&A
        // 검증: 카테고리 4개 / 답글 다단계(depth 3) / 비밀글 선택
        //       posts.read-secret에 user 추가 — 비밀글을 작성자 외 회원도 열람 가능
        // =========================================================
        [
            'name'                          => ['ko' => 'Q&A', 'en' => 'Q&A'],
            'slug'                          => 'qna',
            'description'                   => ['ko' => '궁금한 점을 질문하고 답변을 받으세요', 'en' => 'Ask questions and get answers'],
            'type'                          => 'basic',
            'is_active'                     => true,
            'secret_mode'                   => 'enabled',
            'use_comment'                   => true,
            'use_reply'                     => true,
            'max_reply_depth'               => 3,
            'max_comment_depth'             => 5,
            'use_report'                    => true,
            'use_file_upload'               => true,
            'max_file_size'                 => 5242880, // 5MB
            'max_file_count'                => 3,
            'allowed_extensions'            => ['jpg', 'jpeg', 'png', 'pdf', 'zip', 'txt'],
            'show_view_count'               => true,
            'per_page'                      => 20,
            'new_display_hours'             => 24,
            'categories'                    => ['일반문의', '기술문의', '결제문의', '기타'],
            'notify_author'                 => true,
            'notify_admin_on_post'          => true,
            'blocked_keywords'              => ['욕설테스트', 'badword'],
            // 기본값: posts.read-secret = ['admin'] → user 추가
            // 비밀글을 작성자 외 로그인 회원도 읽을 수 있는 케이스 (고객센터 담당자 등)
            'permissions' => [
                'posts_read-secret' => ['roles' => ['admin', 'user']],
            ],
        ],

        // =========================================================
        // 6. 회원전용
        // 검증: 비회원 읽기/쓰기 차단 / secret_mode:always + use_comment:true 조합
        // secret_mode:always이면서 댓글까지 허용 — 비밀게시판 내 댓글 동작 확인
        // =========================================================
        [
            'name'                          => ['ko' => '회원전용', 'en' => 'Members Only'],
            'slug'                          => 'members',
            'description'                   => ['ko' => '회원만 이용 가능한 게시판입니다', 'en' => 'Members only board'],
            'type'                          => 'basic',
            'is_active'                     => true,
            'secret_mode'                   => 'always',
            'use_comment'                   => true,
            'use_reply'                     => true,
            'max_reply_depth'               => 5,
            'max_comment_depth'             => 10,
            'use_report'                    => true,
            'use_file_upload'               => true,
            'max_file_size'                 => 5242880, // 5MB
            'max_file_count'                => 5,
            'allowed_extensions'            => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'zip'],
            'show_view_count'               => true,
            'per_page'                      => 20,
            'new_display_hours'             => 24,
            'notify_author'                 => true,
            'notify_admin_on_post'          => false,
            // 금지어: 기본 45개 대신 스팸 특화 커스텀
            'blocked_keywords'              => ['스팸', 'advertisement'],
            // 기본값: posts.read = ['admin', 'user', 'guest'] → guest 제거 (비회원 읽기 차단)
            'permissions' => [
                'posts_read'          => ['roles' => ['admin', 'user']],
                'comments_read'       => ['roles' => ['admin', 'user']],
                'attachments_download' => ['roles' => ['admin', 'user']],
            ],
        ],

        // =========================================================
        // 7. 1:1 문의
        // 검증: 비밀글 필수(always) / 댓글 없음 / 답글 depth:1 / 비회원 글쓰기 / mail 알림
        // =========================================================
        [
            'name'                          => ['ko' => '1:1 문의', 'en' => 'Contact Us'],
            'slug'                          => 'inquiry',
            'description'                   => ['ko' => '1:1로 문의하실 수 있습니다', 'en' => 'Contact us directly'],
            'type'                          => 'basic',
            'is_active'                     => true,
            'secret_mode'                   => 'always',
            'use_comment'                   => false,
            'use_reply'                     => true,
            'max_reply_depth'               => 1,
            'max_comment_depth'             => 1,
            'use_report'                    => false,
            'use_file_upload'               => true,
            'max_file_size'                 => 5242880, // 5MB
            'max_file_count'                => 3,
            'allowed_extensions'            => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'zip'],
            'show_view_count'               => false,
            'per_page'                      => 20,
            'new_display_hours'             => 24,
            'notify_author'                 => true,
            'notify_admin_on_post'          => true,
            // 기본값: posts_write = ['admin', 'user'] → guest 추가 (비회원도 문의 가능)
            'permissions' => [
                'posts_write'        => ['roles' => ['admin', 'user', 'guest']],
                'attachments_upload' => ['roles' => ['admin', 'user', 'guest']],
            ],
        ],

        // =========================================================
        // 8. 아카이브
        // 검증: is_active:false (비활성) / blocked_keywords:null (defaults.json 45개 적용)
        //       order_direction:ASC (오래된 순)
        // is_active:false — 프론트에서 접근 시 403 동작 확인
        // blocked_keywords:null — 금지어를 명시하지 않았을 때 defaults.json 기본 45개가 적용되는지 확인
        // =========================================================
        [
            'name'                          => ['ko' => '아카이브', 'en' => 'Archive'],
            'slug'                          => 'archive',
            'description'                   => ['ko' => '과거 게시글 아카이브입니다 (현재 비활성)', 'en' => 'Archive of past posts (currently inactive)'],
            'type'                          => 'basic',
            'is_active'                     => false,  // 비활성 게시판
            'secret_mode'                   => 'disabled',
            'use_comment'                   => true,
            'use_reply'                     => false,
            'use_report'                    => false,
            'use_file_upload'               => false,
            'show_view_count'               => true,
            'per_page'                      => 30,
            'new_display_hours'             => 0,
            // 오래된 순 정렬 — 아카이브는 시간순 탐색
            'order_by'                      => 'created_at',
            'order_direction'               => 'ASC',
            'notify_author'                 => false,
            'notify_admin_on_post'          => false,
            // blocked_keywords: null → defaults.json의 기본 45개 금지어 적용 확인
            'blocked_keywords'              => null,
            // 기본값 그대로 사용
        ],
    ];

    /**
     * 시더 실행
     */
    public function run(): void
    {
        $this->command->info('게시판 샘플 데이터 생성 중...');

        // 관리자 사용자로 인증 (생성자 기록용)
        $admin = User::whereHas('roles', function ($query) {
            $query->where('identifier', 'admin');
        })->first();

        if ($admin) {
            Auth::login($admin);
        }

        /** @var BoardService $boardService */
        $boardService = app(BoardService::class);

        // admin 사용자 UUID 목록 조회 → board_manager_ids 동적 주입
        $adminIds = User::whereHas('roles', function ($query) {
            $query->where('identifier', 'admin');
        })->pluck('uuid')->toArray();

        if (empty($adminIds)) {
            $this->command->warn('  ⚠ admin 역할 사용자가 없습니다. board_manager_ids가 비어있게 됩니다.');
        }

        foreach (self::SAMPLE_BOARDS as $boardData) {
            $boardData['board_manager_ids'] = $adminIds;
            // 이미 존재하는 게시판은 스킵
            if (Board::where('slug', $boardData['slug'])->exists()) {
                $this->command->warn("  - {$boardData['slug']} 게시판이 이미 존재합니다. 스킵합니다.");

                continue;
            }

            try {
                $board = $boardService->createBoard($boardData);
                $this->command->info("  - {$board->slug} 게시판 생성 완료 (테이블 + 권한 포함)");
            } catch (\Exception $e) {
                $this->command->error("  - {$boardData['slug']} 게시판 생성 실패: ".$e->getMessage());
            }
        }

        if ($admin) {
            Auth::logout();
        }

        $this->command->info('게시판 샘플 데이터 생성 완료: '.count(self::SAMPLE_BOARDS).'개');
    }
}
