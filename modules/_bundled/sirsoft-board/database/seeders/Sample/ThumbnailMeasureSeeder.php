<?php

namespace Modules\Sirsoft\Board\Database\Seeders\Sample;

use Database\Seeders\Sample\ThumbnailMeasureKit;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Services\AttachmentService;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\PostService;

/**
 * 이미지 썸네일 브라우저 실측 — 게시판 데이터.
 *
 * 개발 DB 전용 샘플 시더다. `--sample` 묶음에 넣지 않으므로 `--class` 로 지정했을 때만 돈다.
 * 코어 `Database\Seeders\Sample\ThumbnailMeasureSeeder` 가 만든 작성자 회원이 있어야 돈다.
 *
 * 만드는 것 (제목, 이름 전부 `[실측-썸네일]` 표지):
 *   - 카드형 게시판 `thumb-measure-card`(비밀글 선택 가능, 답글 사용), 갤러리형 게시판 `thumb-measure-gallery`
 *   - 카드형: 일반글(2000×2000 JPEG, 2000×2000 투명 PNG, 2000×800 JPEG, 600×2000 JPEG 첨부),
 *     비밀글(작성자 = 실측 작성자, 첨부 1), 블라인드 글(첨부 1), 변종 글(움직이는 GIF, SVG, 100×100 PNG ,
 *     2000×2000 WebP 첨부), 답글 달린 글(원글, 답글 본문에 그 글의 첨부 이미지를 넣는다 — 접힌 답글, 원글
 *     미리보기에서 펼치기 전 이미지 요청이 0 인지 잰다)
 *   - 갤러리형: 일반글 2건(2000×2000 JPEG, 2000×800 JPEG) — 카드형 → 갤러리형 화면 이동(새로고침 없음)에서 잰다
 *
 * 글은 운영 글쓰기와 같은 순서로 만든다: 첨부를 임시 키로 먼저 올리고(`AttachmentService::upload` → 저장소
 * 드라이버 put, 임시 경로) 글 저장(`PostService::createPost`)이 그 첨부를 본경로로 옮긴다(get + put + delete).
 * 그래서 임시 경로 파일은 썸네일 대상이 아니라는 것과 이동 뒤 본경로에서 생성이 예약되는 것까지 함께 실측된다.
 * 블라인드는 관리자 처리 경로(`PostService::blindPost`)를 쓴다.
 *
 * 재실행하면 같은 표식의 데이터를 먼저 지운다(`ThumbnailMeasureCleanupSeeder::purge`).
 *
 * 실행 (PowerShell):
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan module:seed sirsoft-board --class="Modules\Sirsoft\Board\Database\Seeders\Sample\ThumbnailMeasureSeeder"
 */
class ThumbnailMeasureSeeder extends Seeder
{
    /** 실측 게시판 슬러그 — 정리 시더가 이 둘만 지운다 */
    public const CARD_SLUG = 'thumb-measure-card';

    public const GALLERY_SLUG = 'thumb-measure-gallery';

    /** 실측 첨부 허용 확장자 — 변종 글의 GIF, SVG 를 받으려고 svg 를 더한다 */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

    private ThumbnailMeasureKit $kit;

    /** @var list<list<scalar|null>> 콘솔 표 행 */
    private array $rows = [];

    /**
     * 시더를 실행합니다.
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        $this->command?->info(__('sirsoft-board::messages.seeders.thumbnail_measure.start'));

        ThumbnailMeasureKit::guard();

        $this->kit = new ThumbnailMeasureKit;
        $this->kit->silenceOutbound();

        $admin = ThumbnailMeasureKit::superAdmin();
        $author = ThumbnailMeasureKit::member(ThumbnailMeasureKit::AUTHOR_EMAIL);

        $previous = (new ThumbnailMeasureCleanupSeeder)->purge();
        if (array_sum($previous) > 0) {
            $this->command?->line(__('sirsoft-board::messages.seeders.thumbnail_measure.previous_removed', ['counts' => ThumbnailMeasureKit::summarize($previous, __('sirsoft-board::messages.seeders.thumbnail_measure.cleanup_headers'))]));
        }

        try {
            Auth::setUser($admin);
            $card = $this->board(self::CARD_SLUG, 'card', '카드형', 'Card', 'enabled');
            $gallery = $this->board(self::GALLERY_SLUG, 'gallery', '갤러리형', 'Gallery', 'disabled');

            Auth::setUser($author);

            $this->post($card, __('sirsoft-board::messages.seeders.thumbnail_measure.kinds.general'), '일반글 (JPEG · 투명 PNG · 가로형 · 세로형)', [
                [$this->kit->jpeg(2000, 2000, [190, 70, 60], 'BOARD JPEG 2000'), 'thumb-board-2000.jpg'],
                [$this->kit->png(2000, 2000, [40, 120, 200], 'BOARD PNG ALPHA', true), 'thumb-board-alpha-2000.png'],
                [$this->kit->jpeg(2000, 800, [70, 140, 90], 'BOARD WIDE 2000x800'), 'thumb-board-wide-2000x800.jpg'],
                [$this->kit->jpeg(600, 2000, [150, 90, 170], 'TALL'), 'thumb-board-tall-600x2000.jpg'],
            ]);

            $this->post($card, __('sirsoft-board::messages.seeders.thumbnail_measure.kinds.secret'), '비밀글 (작성자만)', [
                [$this->kit->jpeg(2000, 2000, [60, 60, 70], 'BOARD SECRET'), 'thumb-board-secret-2000.jpg'],
            ], ['is_secret' => true]);

            $blinded = $this->post($card, __('sirsoft-board::messages.seeders.thumbnail_measure.kinds.blinded'), '블라인드 글', [
                [$this->kit->jpeg(2000, 2000, [120, 120, 40], 'BOARD BLINDED'), 'thumb-board-blinded-2000.jpg'],
            ]);
            Auth::setUser($admin);
            app(PostService::class)->blindPost(self::CARD_SLUG, $blinded->id, ThumbnailMeasureKit::MARK.' 실측용 블라인드');
            Auth::setUser($author);

            $this->post($card, __('sirsoft-board::messages.seeders.thumbnail_measure.kinds.variants'), '변종 (움직이는 GIF · SVG · 100px · WebP)', [
                [$this->kit->animatedGif(480, 320, 3), 'thumb-board-animated.gif'],
                [$this->kit->svg(800, 600), 'thumb-board-vector.svg'],
                [$this->kit->png(100, 100, [230, 120, 30], 'S', false), 'thumb-board-100.png'],
                [$this->kit->webp(2000, 2000, [30, 150, 150], 'BOARD WEBP 2000'), 'thumb-board-2000.webp'],
            ]);

            $parent = $this->post($card, __('sirsoft-board::messages.seeders.thumbnail_measure.kinds.reply_parent'), '답글 달린 원글 (본문 이미지)', [
                [$this->kit->jpeg(2000, 2000, [200, 140, 60], 'PARENT BODY'), 'thumb-board-parent-body.jpg'],
            ], [], true);

            $this->post($card, __('sirsoft-board::messages.seeders.thumbnail_measure.kinds.reply'), '답글 (본문 이미지)', [
                [$this->kit->jpeg(2000, 2000, [80, 170, 200], 'REPLY BODY'), 'thumb-board-reply-body.jpg'],
            ], ['parent_id' => $parent->id], true);

            $this->post($gallery, __('sirsoft-board::messages.seeders.thumbnail_measure.kinds.gallery'), '갤러리 일반글 1', [
                [$this->kit->jpeg(2000, 2000, [210, 100, 140], 'GALLERY 2000'), 'thumb-gallery-2000.jpg'],
            ]);

            $this->post($gallery, __('sirsoft-board::messages.seeders.thumbnail_measure.kinds.gallery'), '갤러리 일반글 2 (가로형)', [
                [$this->kit->jpeg(2000, 800, [100, 110, 210], 'GALLERY WIDE'), 'thumb-gallery-wide.jpg'],
            ]);
        } finally {
            $this->kit->cleanup();
            Auth::forgetUser();
        }

        ThumbnailMeasureKit::table($this->command, __('sirsoft-board::messages.seeders.thumbnail_measure.data_title'), array_values((array) __('sirsoft-board::messages.seeders.thumbnail_measure.data_headers')), $this->rows);
        $this->command?->line(__('sirsoft-board::messages.seeders.thumbnail_measure.boards', [
            'card' => '/board/'.self::CARD_SLUG,
            'card_id' => $card->id,
            'gallery' => '/board/'.self::GALLERY_SLUG,
            'gallery_id' => $gallery->id,
        ]));
        $this->command?->info(__('sirsoft-board::messages.seeders.thumbnail_measure.done'));
    }

    /**
     * 실측 게시판을 만듭니다 (관리자 폼과 같은 출발점 — 모듈 기본값 위에 실측 설정을 덮는다).
     *
     * 알림은 끈다 — 실측 글 작성마다 관리자 알림이 쌓이지 않게.
     *
     * @param  string  $slug  슬러그
     * @param  string  $type  게시판 유형 (card|gallery)
     * @param  string  $koName  한국어 이름 꼬리
     * @param  string  $enName  영어 이름 꼬리
     * @param  string  $secretMode  비밀글 모드
     * @return Board 게시판
     */
    private function board(string $slug, string $type, string $koName, string $enName, string $secretMode): Board
    {
        $settings = g7_module_settings('sirsoft-board', 'basic_defaults', []);
        $base = Arr::except(is_array($settings) ? $settings : [], ['default_board_permissions', 'blocked_keywords']);

        return app(BoardService::class)->createBoard(array_merge($base, [
            'name' => ['ko' => ThumbnailMeasureKit::MARK.' '.$koName, 'en' => ThumbnailMeasureKit::MARK.' '.$enName],
            'slug' => $slug,
            'description' => ['ko' => ThumbnailMeasureKit::MARK.' 이미지 썸네일 브라우저 실측 전용', 'en' => ThumbnailMeasureKit::MARK.' Thumbnail browser measurement only'],
            'type' => $type,
            'is_active' => true,
            'secret_mode' => $secretMode,
            'use_comment' => true,
            'use_reply' => $type === 'card',
            'use_report' => true,
            'use_scrap' => false,
            'use_reaction' => false,
            'use_file_upload' => true,
            'max_file_size' => 10,
            'max_file_count' => 10,
            'allowed_extensions' => self::ALLOWED_EXTENSIONS,
            'per_page' => 12,
            'notify_admin_on_post' => false,
            'notify_author' => false,
            'blocked_keywords' => [],
            'board_manager_ids' => [],
        ]));
    }

    /**
     * 글을 운영 글쓰기 순서로 만듭니다 (임시 업로드 → 글 저장이 본경로로 이동).
     *
     * @param  Board  $board  게시판
     * @param  string  $kind  표 구분 (번역된 표시 이름)
     * @param  string  $title  제목 꼬리 (표지가 앞에 붙는다)
     * @param  list<array{0: string, 1: string}>  $files  [임시 파일 경로, 원래 이름]
     * @param  array<string, mixed>  $extra  추가 필드 (is_secret, parent_id)
     * @param  bool  $imagesInBody  첨부 이미지를 본문에도 넣을지
     * @return Post 글
     */
    private function post(Board $board, string $kind, string $title, array $files, array $extra = [], bool $imagesInBody = false): Post
    {
        $attachments = app(AttachmentService::class);
        $tempKey = (string) Str::uuid();
        $uploaded = [];

        foreach ($files as [$path, $name]) {
            /** @var UploadedFile $file */
            $file = $this->kit->file($path, $name);
            $uploaded[] = $attachments->upload($board->slug, $file, null, 'attachments', $tempKey);
        }

        $body = '<p>'.e(ThumbnailMeasureKit::MARK.' '.$title).'</p>';
        if ($imagesInBody) {
            // 미리보기 주소는 해시로 정해지고 이동 뒤에도 같다 — 저장 전에 본문에 넣어도 된다
            foreach ($uploaded as $attachment) {
                $attachment->setRelation('board', $board);
                $body .= '<p><img src="'.e((string) $attachment->preview_url).'" alt="'.e($attachment->original_filename).'"></p>';
            }
        }

        $post = app(PostService::class)->createPost($board->slug, array_merge([
            'title' => ThumbnailMeasureKit::MARK.' '.$title,
            'content' => $body,
            'content_mode' => 'html',
            'user_id' => Auth::id(),
            'ip_address' => '127.0.0.1',
            'is_secret' => false,
            'temp_key' => $tempKey,
        ], $extra), [], [], ['skip_notification' => true]);

        foreach ($uploaded as $attachment) {
            $this->rows[] = self::attachmentRow($board->slug, $kind, $post->id, Attachment::query()->with('board')->find($attachment->id));
        }

        return $post;
    }

    /**
     * 실측 데이터 표의 한 행을 만듭니다 (열 순서는 `data_headers` 와 같다).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $kind  구분 (번역된 표시 이름)
     * @param  int  $postId  글 ID
     * @param  object|null  $attachment  첨부 (id, hash, mime_type, meta, preview_url) — 다시 읽지 못했으면 null
     * @return list<scalar|null> 행
     */
    public static function attachmentRow(string $slug, string $kind, int $postId, ?object $attachment): array
    {
        $meta = $attachment?->meta ?? [];

        return [
            $slug,
            $kind,
            $postId,
            $attachment?->id,
            $attachment?->hash,
            $attachment?->mime_type,
            isset($meta['width'], $meta['height']) ? $meta['width'].'x'.$meta['height'] : '-',
            $attachment?->preview_url ?? '-',
        ];
    }
}
