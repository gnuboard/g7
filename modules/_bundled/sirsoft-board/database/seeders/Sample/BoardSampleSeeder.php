<?php

namespace Modules\Sirsoft\Board\Database\Seeders\Sample;

use App\Concerns\Seeder\HasTranslatableSeeder;
use App\Contracts\Seeder\TranslatableSeederInterface;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Database\Seeders\Sample\Concerns\BoardSampleMarker;
use Modules\Sirsoft\Board\Database\Seeders\Sample\Concerns\SampleRunRegistry;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Services\BoardService;

/**
 * 게시판 샘플 시더
 *
 * 다양한 설정의 게시판을 생성합니다.
 * BoardService를 사용하여 테이블과 권한이 자동으로 생성됩니다.
 *
 * 서비스를 직접 호출하므로 관리자 폼(StoreBoardRequest)의 기본값 병합을 거치지 않는다. 그래서
 * 모듈 환경설정 basic_defaults 를 먼저 병합한다 — 빠뜨리면 반응이 전 게시판에서 꺼지고(B-1),
 * 입력 제한이 모듈 기본값이 아니라 DB 컬럼 기본값으로 들어간다(B-23).
 *
 * 게시판 이름·설명은 활성 언어팩의 seed/sample_boards.json 이 있으면 그 언어 값을 덧붙이고,
 * 그래도 사이트 기본 언어 값이 없으면 대체 언어(app.fallback_locale) 값으로 채운다 — 관리자 수정 화면은
 * 사이트 기본 언어 이름을 필수로 요구하기 때문이다(SB-4).
 */
class BoardSampleSeeder extends Seeder implements TranslatableSeederInterface
{
    use HasTranslatableSeeder;

    /**
     * 게시판 샘플 데이터
     *
     * 테스트 시나리오 (9개 게시판):
     * 1. notice  - 공지사항    : 관리자만 글쓰기, 댓글/파일 없음, 반응 끔, mail 알림
     * 2. free    - 자유게시판  : 비회원 개방, 비밀글 선택, 입력 제한 커스텀, 반응 like+dislike
     * 3. gallery - 갤러리      : gallery 타입, 이미지만, 최신댓글 상단(DESC), 조회수 정렬, 반응 like
     * 4. event   - 이벤트      : card 타입, 관리자만 글쓰기, 댓글/파일 없음, 오래된 순 정렬(ASC)
     * 5. qna     - Q&A         : 카테고리, 답글 depth, 비밀글 선택, 회원 비밀글 읽기 허용,
     *                            반응 like+dislike·댓글 반응 끔, 답글 삭제 정책 block
     * 6. members - 회원전용    : 비회원 차단, secret_mode:always + 댓글 허용, 반응 like
     * 7. inquiry - 1:1 문의    : 비밀글 필수, 답글 depth:1, 비회원 글쓰기, 반응 끔, mail 알림
     * 8. archive - 아카이브    : 비활성(is_active:false), 금지어 = 모듈 기본 목록 명시, ASC 정렬
     * 9. product-inquiry - 상품 문의 : 이커머스 상품 문의 저장소. 비밀글 필수, 답글 1단, 댓글·반응·스크랩 끔.
     *                            글은 이커머스 샘플 시더가 문의와 함께 만든다(이 게시판에는 게시글 샘플 없음)
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
     * - blocked_keywords: 커스텀 ✓ / 모듈 기본 목록(archive) ✓ / 지정 안 함 = 금지어 없음 ✓
     *                     (게시판 금지어는 기본값으로 폴백하지 않는다 — 값이 없으면 검사하지 않는다)
     * - 반응            : 끔 ✓ / like ✓ / like+dislike ✓, 댓글 반응 끔(qna) ✓
     * - 답글 삭제 정책  : cascade(기본) ✓ / block(qna) ✓
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
            'name' => ['ko' => '공지사항', 'en' => 'Notice'],
            'slug' => 'notice',
            'description' => ['ko' => '중요한 공지사항을 확인하세요', 'en' => 'Check important announcements'],
            'type' => 'basic',
            'is_active' => true,
            'secret_mode' => 'disabled',
            'use_comment' => false,
            'use_reply' => false,
            'use_report' => false,
            'use_scrap' => true,
            // 공지는 반응을 받지 않는다
            'use_reaction' => false,
            'use_file_upload' => false,
            'show_view_count' => true,
            'per_page' => 15,
            'new_display_hours' => 72,
            'notify_author' => false,
            'notify_admin_on_post' => true,
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
            'name' => ['ko' => '자유게시판', 'en' => 'Free Board'],
            'slug' => 'free',
            'description' => ['ko' => '자유롭게 이야기를 나누세요', 'en' => 'Share your thoughts freely'],
            'type' => 'basic',
            'is_active' => true,
            'secret_mode' => 'enabled',
            'use_comment' => true,
            'use_reply' => false,
            'use_report' => true,
            'use_scrap' => true,
            'use_file_upload' => true,
            'max_file_size' => 5, // 5MB
            'max_file_count' => 5,
            'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'zip'],
            'show_view_count' => true,
            'per_page' => 20,
            'new_display_hours' => 24,
            // 입력 제한 커스텀 — 기본값과 다른 값으로 StorePostRequest 검증 동작 확인
            'min_title_length' => 5,
            'max_title_length' => 100,
            'min_content_length' => 0,   // 내용 제한 없음 (링크/이미지만 올리는 경우)
            'max_content_length' => 2000,
            'min_comment_length' => 0,   // 짧은 반응 허용
            'max_comment_length' => 500,
            'notify_author' => true,
            'notify_admin_on_post' => false,
            // 반응: 추천 + 비추천
            'use_reaction' => true,
            'active_reaction_types' => ['like', 'dislike'],
            // 금지어: 커스텀 — 모듈 기본 목록 대신 광고/스팸 특화
            'blocked_keywords' => ['광고', '홍보', 'spam', '도박', '대출'],
            // 기본값: posts_write = ['admin', 'user'] → guest 추가 (비회원도 글쓰기/댓글)
            'permissions' => [
                'posts_write' => ['roles' => ['admin', 'user', 'guest']],
                'comments_write' => ['roles' => ['admin', 'user', 'guest']],
            ],
        ],

        // =========================================================
        // 3. 갤러리
        // 검증: gallery 타입 / 이미지 파일만 / 조회수 정렬(view_count) / 최신 댓글 상단(comment_order:DESC)
        // =========================================================
        [
            'name' => ['ko' => '갤러리', 'en' => 'Gallery'],
            'slug' => 'gallery',
            'description' => ['ko' => '사진과 이미지를 공유하세요', 'en' => 'Share your photos and images'],
            'type' => 'gallery',
            'is_active' => true,
            'secret_mode' => 'disabled',
            'use_comment' => true,
            'use_reply' => false,
            'use_report' => true,
            'use_scrap' => true,
            'use_file_upload' => true,
            'max_file_size' => 10, // 10MB
            'max_file_count' => 10,
            'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'show_view_count' => true,
            'per_page' => 12,
            'new_display_hours' => 24,
            // 조회수 기준 내림차순 정렬 — 인기글이 상단에 노출
            'order_by' => 'view_count',
            'order_direction' => 'DESC',
            // 최신 댓글 상단 — 뉴스피드형 게시판에서 사용
            'comment_order' => 'DESC',
            'max_comment_depth' => 3,
            'notify_author' => true,
            'notify_admin_on_post' => false,
            // 반응: 추천만
            'use_reaction' => true,
            'active_reaction_types' => ['like'],
            // 기본값 그대로 — 회원만 글쓰기/댓글, 전체 읽기/다운로드
        ],

        // =========================================================
        // 4. 이벤트
        // 검증: card 타입 / 댓글·답글·파일 없음 / 오래된 순 정렬(order_direction:ASC)
        // order_direction:ASC — 이벤트 번호순, FAQ 순서 고정형 게시판에서 사용
        // =========================================================
        [
            'name' => ['ko' => '이벤트', 'en' => 'Event'],
            'slug' => 'event',
            'description' => ['ko' => '진행 중인 이벤트를 확인하세요', 'en' => 'Check ongoing events'],
            'type' => 'card',
            'is_active' => true,
            'secret_mode' => 'disabled',
            'use_comment' => false,
            'use_reply' => false,
            'use_report' => false,
            'use_scrap' => false,
            'use_file_upload' => true,
            'max_file_size' => 10, // 10MB
            'max_file_count' => 5,
            'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'show_view_count' => true,
            'per_page' => 12,
            'new_display_hours' => 72,
            // 등록순 오름차순 — 이벤트 번호순 노출
            'order_by' => 'created_at',
            'order_direction' => 'ASC',
            'notify_author' => false,
            'notify_admin_on_post' => false,
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
            'name' => ['ko' => 'Q&A', 'en' => 'Q&A'],
            'slug' => 'qna',
            'description' => ['ko' => '궁금한 점을 질문하고 답변을 받으세요', 'en' => 'Ask questions and get answers'],
            'type' => 'basic',
            'is_active' => true,
            'secret_mode' => 'enabled',
            'use_comment' => true,
            'use_reply' => true,
            'max_reply_depth' => 3,
            'max_comment_depth' => 5,
            'use_report' => true,
            'use_scrap' => true,
            'use_file_upload' => true,
            'max_file_size' => 5, // 5MB
            'max_file_count' => 3,
            'allowed_extensions' => ['jpg', 'jpeg', 'png', 'pdf', 'zip', 'txt'],
            'show_view_count' => true,
            'per_page' => 20,
            'new_display_hours' => 24,
            'categories' => ['일반문의', '기술문의', '결제문의', '기타'],
            'notify_author' => true,
            'notify_admin_on_post' => true,
            'blocked_keywords' => ['욕설테스트', 'badword'],
            // 반응: 추천 + 비추천, 댓글 반응은 끔
            'use_reaction' => true,
            'use_comment_reaction' => false,
            'active_reaction_types' => ['like', 'dislike'],
            // 답글 삭제 정책 block — 답글이 달린 질문은 삭제할 수 없다
            'reply_delete_policy' => 'block',
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
            'name' => ['ko' => '회원전용', 'en' => 'Members Only'],
            'slug' => 'members',
            'description' => ['ko' => '회원만 이용 가능한 게시판입니다', 'en' => 'Members only board'],
            'type' => 'basic',
            'is_active' => true,
            'secret_mode' => 'always',
            'use_comment' => true,
            'use_reply' => true,
            'max_reply_depth' => 5,
            'max_comment_depth' => 10,
            'use_report' => true,
            'use_scrap' => true,
            'use_file_upload' => true,
            'max_file_size' => 5, // 5MB
            'max_file_count' => 5,
            'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'zip'],
            'show_view_count' => true,
            'per_page' => 20,
            'new_display_hours' => 24,
            'notify_author' => true,
            'notify_admin_on_post' => false,
            // 반응: 추천만
            'use_reaction' => true,
            'active_reaction_types' => ['like'],
            // 금지어: 모듈 기본 목록 대신 스팸 특화 커스텀
            'blocked_keywords' => ['스팸', 'advertisement'],
            // 기본값: posts.read = ['admin', 'user', 'guest'] → guest 제거 (비회원 읽기 차단)
            'permissions' => [
                'posts_read' => ['roles' => ['admin', 'user']],
                'comments_read' => ['roles' => ['admin', 'user']],
                'attachments_download' => ['roles' => ['admin', 'user']],
            ],
        ],

        // =========================================================
        // 7. 1:1 문의
        // 검증: 비밀글 필수(always) / 댓글 없음 / 답글 depth:1 / 비회원 글쓰기 / mail 알림
        // =========================================================
        [
            'name' => ['ko' => '1:1 문의', 'en' => 'Contact Us'],
            'slug' => 'inquiry',
            'description' => ['ko' => '1:1로 문의하실 수 있습니다', 'en' => 'Contact us directly'],
            'type' => 'basic',
            'is_active' => true,
            'secret_mode' => 'always',
            'use_comment' => false,
            'use_reply' => true,
            'max_reply_depth' => 1,
            'max_comment_depth' => 1,
            'use_report' => false,
            'use_scrap' => false,
            'use_file_upload' => true,
            'max_file_size' => 5, // 5MB
            'max_file_count' => 3,
            'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'zip'],
            'show_view_count' => false,
            'per_page' => 20,
            'new_display_hours' => 24,
            'notify_author' => true,
            'notify_admin_on_post' => true,
            // 1:1 문의는 반응을 받지 않는다
            'use_reaction' => false,
            // 기본값: posts_write = ['admin', 'user'] → guest 추가 (비회원도 문의 가능)
            'permissions' => [
                'posts_write' => ['roles' => ['admin', 'user', 'guest']],
                'attachments_upload' => ['roles' => ['admin', 'user', 'guest']],
            ],
        ],

        // =========================================================
        // 8. 아카이브
        // 검증: is_active:false (비활성) / 금지어 = 모듈 기본 목록(basic_defaults.blocked_keywords) 명시
        //       order_direction:ASC (오래된 순)
        // is_active:false — 프론트에서 접근 시 403 동작 확인
        // 금지어는 run() 이 모듈 기본 목록을 넣는다 — 게시판 금지어는 null 이면 기본값으로 폴백하지 않고
        // 「금지어 없음」이므로, 기본 목록 시연은 값을 명시해야 한다(B-20)
        // =========================================================
        [
            'name' => ['ko' => '아카이브', 'en' => 'Archive'],
            'slug' => 'archive',
            'description' => ['ko' => '과거 게시글 아카이브입니다 (현재 비활성)', 'en' => 'Archive of past posts (currently inactive)'],
            'type' => 'basic',
            'is_active' => false,  // 비활성 게시판
            'secret_mode' => 'disabled',
            'use_comment' => true,
            'use_reply' => false,
            'use_report' => false,
            'use_scrap' => true,
            'use_file_upload' => false,
            'show_view_count' => true,
            'per_page' => 30,
            'new_display_hours' => 0,
            // 오래된 순 정렬 — 아카이브는 시간순 탐색
            'order_by' => 'created_at',
            'order_direction' => 'ASC',
            'notify_author' => false,
            'notify_admin_on_post' => false,
            // 금지어: run() 이 모듈 기본 목록(basic_defaults.blocked_keywords)으로 채운다
            'blocked_keywords' => self::USE_MODULE_DEFAULT_KEYWORDS,
            // 기본값 그대로 사용
        ],

        // =========================================================
        // 9. 상품 문의 (이커머스 상품 문의 저장소)
        // 검증: 이커머스 상품 상세 Q&A·관리자 문의 화면이 이 게시판을 쓴다.
        //       비밀글 필수 / 답글 1단(판매자 답변) / 댓글·반응·스크랩·신고 끔 / 비회원 문의 가능
        // 이커머스 샘플 시더는 inquiry.board_slug 가 비어 있을 때만 이 slug 를 설정에 기록한다.
        // =========================================================
        [
            'name' => ['ko' => '상품 문의', 'en' => 'Product Q&A'],
            'slug' => BoardSampleMarker::PRODUCT_INQUIRY_SLUG,
            'description' => ['ko' => '상품에 대해 궁금한 점을 문의하세요', 'en' => 'Ask questions about products'],
            'type' => 'basic',
            'is_active' => true,
            'secret_mode' => 'always',
            'use_comment' => false,
            'use_reply' => true,
            'max_reply_depth' => 1,
            'max_comment_depth' => 1,
            'use_report' => false,
            'use_scrap' => false,
            'use_reaction' => false,
            'use_comment_reaction' => false,
            'use_file_upload' => false,
            'show_view_count' => false,
            'per_page' => 20,
            'new_display_hours' => 24,
            'notify_author' => true,
            'notify_admin_on_post' => true,
            // 기본값: posts_write = ['admin', 'user'] → guest 추가 (비회원도 상품 문의 가능)
            'permissions' => [
                'posts_write' => ['roles' => ['admin', 'user', 'guest']],
            ],
        ],
    ];

    /**
     * blocked_keywords 자리표시 — run() 이 모듈 기본 금지어 목록으로 바꾼다
     */
    private const USE_MODULE_DEFAULT_KEYWORDS = '__module_default__';

    /**
     * 언어팩 시드 필터 키(`seed.sirsoft-board.sample_boards.translations`)의 확장 식별자를 반환합니다.
     *
     * @return string 확장 식별자
     */
    public function getExtensionIdentifier(): string
    {
        return 'sirsoft-board';
    }

    /**
     * 언어팩 시드 파일명(seed/sample_boards.json)의 entity 이름을 반환합니다.
     *
     * @return string entity 이름
     */
    public function getTranslatableEntity(): string
    {
        return 'sample_boards';
    }

    /**
     * 언어팩 seed 항목을 기본값에 맞춰 붙일 때 쓰는 키를 반환합니다.
     *
     * @return string 매칭 키
     */
    public function getMatchKey(): string
    {
        return 'slug';
    }

    /**
     * 언어팩 머지 전 샘플 게시판 정의를 반환합니다.
     *
     * @return array<int, array<string, mixed>> 샘플 게시판 목록
     */
    public function getDefaults(): array
    {
        return self::SAMPLE_BOARDS;
    }

    /**
     * 시더 실행
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        $this->command->info('게시판 샘플 데이터 생성 중...');

        // 이번 샘플 실행의 생성 범위 기록을 새로 시작한다 (활동 로그 샘플이 이 범위에만 로그를 만든다)
        SampleRunRegistry::reset();

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

        // 관리자 폼과 같은 출발점 — 모듈 기본값을 먼저 깔고 샘플 정의로 덮는다(B-1·B-23).
        // 권한은 서비스가 기본 권한을 쓰므로, 금지어는 게시판마다 시연 의도가 달라 병합에서 뺀다.
        $settings = g7_module_settings('sirsoft-board', 'basic_defaults', []);
        $base = Arr::except(is_array($settings) ? $settings : [], ['default_board_permissions', 'blocked_keywords']);
        $defaultKeywords = array_values((array) ($settings['blocked_keywords'] ?? []));

        $samples = $this->resolveTranslatedDefaults();

        foreach ($samples as $boardData) {
            $boardData = array_merge($base, $boardData);

            if (($boardData['blocked_keywords'] ?? null) === self::USE_MODULE_DEFAULT_KEYWORDS) {
                $boardData['blocked_keywords'] = $defaultKeywords;
            }

            foreach (['name', 'description'] as $field) {
                if (isset($boardData[$field]) && is_array($boardData[$field])) {
                    $boardData[$field] = $this->fillSiteLocale($boardData[$field]);
                }
            }

            $boardData['board_manager_ids'] = $adminIds;
            // 이미 존재하는 게시판은 스킵
            if (Board::where('slug', $boardData['slug'])->exists()) {
                $this->command->warn("  - {$boardData['slug']} 게시판이 이미 존재합니다. 스킵합니다.");

                continue;
            }

            try {
                $board = $boardService->createBoard($boardData);
                SampleRunRegistry::addBoard((int) $board->id);
                $this->command->info("  - {$board->slug} 게시판 생성 완료 (테이블 + 권한 포함)");
            } catch (\Exception $e) {
                $this->command->error("  - {$boardData['slug']} 게시판 생성 실패: ".$e->getMessage());
            }
        }

        if ($admin) {
            Auth::logout();
        }

        $this->command->info('게시판 샘플 데이터 생성 완료: '.count($samples).'개');
    }

    /**
     * 다국어 값에 사이트 기본 언어 키가 없으면 fallback 언어(app.fallback_locale) 값, 그것도 없으면 첫 값으로 채웁니다.
     *
     * 관리자 게시판 수정 화면은 사이트 기본 언어 이름을 필수로 검증한다. 사이트 기본 언어가 ko·en 이
     * 아니고 그 언어의 언어팩 시드가 없을 때 샘플 게시판이 수정 불가 상태로 만들어지는 것을 막는다.
     *
     * @param  array<string, string>  $values  로케일별 값
     * @return array<string, string> 사이트 기본 언어 값이 보장된 로케일별 값
     */
    private function fillSiteLocale(array $values): array
    {
        $siteLocale = (string) config('app.site_locale', config('app.locale', 'ko'));

        if ($siteLocale === '' || (isset($values[$siteLocale]) && $values[$siteLocale] !== '')) {
            return $values;
        }

        // 다국어 fallback chain — app.fallback_locale 값, 없으면 첫 값
        $fallback = $values[config('app.fallback_locale', 'ko')] ?? (reset($values) ?: null);

        if ($fallback !== null) {
            $values[$siteLocale] = $fallback;
        }

        return $values;
    }
}
