<?php

namespace Modules\Sirsoft\Board\Database\Seeders\Sample;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\PostContentMediaService;

/**
 * #162 브라우저 실측용 게시판 데이터 시더 (CLI + G7_PLAYWRIGHT_BYPASS=1 전용)
 *
 * 비회원 업로드·본문 미디어·외부 영상·정화 표본을 실측하는 게시판 셋과 회원 둘, 표본 글을 만든다.
 * 모든 행 제목·이름에 표지 `[실측-162]` 를 붙여 정리 시더(`Issue162MeasureCleanupSeeder`)가 그것만 지운다.
 * 회원 비밀번호는 실행할 때 만들어 콘솔에만 출력한다(저장소·이력에 남기지 않는다).
 */
class Issue162MeasureSeeder extends Seeder
{
    /** 정리 표지 */
    public const MARKER = '[실측-162]';

    /** 실측 회원 이메일 */
    public const MEMBER_EMAILS = ['m162-a@example.test', 'm162-b@example.test'];

    /** 실측 게시판 슬러그 */
    public const BOARD_SLUGS = ['m162-guest', 'm162-nofile', 'm162-member-read'];

    /**
     * 시더를 실행합니다.
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        if (php_sapi_name() !== 'cli' || env('G7_PLAYWRIGHT_BYPASS') !== '1') {
            $this->command?->error(__('sirsoft-board::messages.seeders.issue162_measure.guard'));

            return;
        }

        $admin = User::whereHas('roles', fn ($q) => $q->where('identifier', 'admin'))->first();
        if ($admin) {
            Auth::login($admin);
        }

        $this->seedMembers();
        $this->seedBoards();
        $this->seedPosts();
        $this->seedMediaPosts();

        if ($admin) {
            Auth::logout();
        }

        $this->command?->info(__('sirsoft-board::messages.seeders.issue162_measure.done'));
    }

    /**
     * 실측 회원 A·B 를 만듭니다(있으면 비밀번호만 새로 정해 출력).
     *
     * @return void 반환값 없음
     */
    private function seedMembers(): void
    {
        $userRole = Role::where('identifier', 'user')->first();

        foreach (self::MEMBER_EMAILS as $index => $email) {
            $password = Str::password(16, symbols: false);
            $user = User::where('email', $email)->first();

            if (! $user) {
                $user = User::factory()->create([
                    'email' => $email,
                    'name' => self::MARKER.' 회원 '.chr(65 + $index),
                    'password' => Hash::make($password),
                ]);
                if ($userRole) {
                    $user->roles()->syncWithoutDetaching([$userRole->id]);
                }
            } else {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }

            $this->command?->line(__('sirsoft-board::messages.seeders.issue162_measure.member', ['email' => $email, 'password' => $password]));
        }
    }

    /**
     * 실측 게시판 셋을 만듭니다(있으면 건너뜀).
     *
     * @return void 반환값 없음
     */
    private function seedBoards(): void
    {
        $service = app(BoardService::class);
        $defaults = (array) g7_module_settings('sirsoft-board', 'basic_defaults', []);
        unset($defaults['default_board_permissions'], $defaults['blocked_keywords']);

        $boards = [
            [
                'slug' => 'm162-guest',
                'name' => ['ko' => self::MARKER.' 비회원 업로드', 'en' => self::MARKER.' Guest upload'],
                'use_file_upload' => true,
                'max_file_size' => 5,
                'max_file_count' => 5,
                'allowed_extensions' => ['jpg', 'jpeg', 'png', 'pdf', 'mp4', 'webm'],
                'secret_mode' => 'enabled',
                'permissions' => [
                    'posts_write' => ['roles' => ['admin', 'user', 'guest']],
                    'attachments_upload' => ['roles' => ['admin', 'user', 'guest']],
                ],
                'content_media_policy' => [
                    'image' => ['enabled' => true, 'max_size_mb' => 5, 'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp']],
                    'video' => ['enabled' => true, 'max_size_mb' => 1, 'extensions' => ['mp4', 'webm']],
                    'audio' => ['enabled' => false, 'max_size_mb' => 5, 'extensions' => ['mp3']],
                    'max_per_document' => 3,
                ],
            ],
            [
                'slug' => 'm162-nofile',
                'name' => ['ko' => self::MARKER.' 첨부 꺼짐', 'en' => self::MARKER.' No attachments'],
                'use_file_upload' => false,
                'permissions' => [
                    'posts_write' => ['roles' => ['admin', 'user']],
                    'attachments_upload' => ['roles' => ['admin', 'user']],
                ],
            ],
            [
                'slug' => 'm162-member-read',
                'name' => ['ko' => self::MARKER.' 회원 전용 읽기', 'en' => self::MARKER.' Members only'],
                'use_file_upload' => true,
                'permissions' => [
                    'posts_read' => ['roles' => ['admin', 'user']],
                    'posts_write' => ['roles' => ['admin', 'user']],
                    'attachments_upload' => ['roles' => ['admin', 'user']],
                    'attachments_download' => ['roles' => ['admin', 'user']],
                ],
            ],
        ];

        $hasPolicyColumn = Schema::hasColumn('boards', 'content_media_policy');

        foreach ($boards as $data) {
            if (! $hasPolicyColumn) {
                unset($data['content_media_policy']);
            }

            $existing = Board::where('slug', $data['slug'])->first();
            if ($existing) {
                if ($hasPolicyColumn && isset($data['content_media_policy'])) {
                    $existing->forceFill(['content_media_policy' => $data['content_media_policy']])->save();
                }
                $this->command?->warn(__('sirsoft-board::messages.seeders.issue162_measure.board_exists', ['slug' => $data['slug']]));

                continue;
            }

            $service->createBoard(array_merge($defaults, $data));
            $this->command?->info(__('sirsoft-board::messages.seeders.issue162_measure.board_created', ['slug' => $data['slug']]));
        }
    }

    /**
     * 표본 글을 만듭니다 — 비회원 비밀글, 외부 영상·정화 표본 공개글.
     *
     * @return void 반환값 없음
     */
    private function seedPosts(): void
    {
        $board = Board::where('slug', 'm162-guest')->first();
        if (! $board) {
            return;
        }

        $guestPassword = Str::password(12, symbols: false);
        $samples = [
            [
                'title' => self::MARKER.' 비회원 비밀글',
                'content' => '<p>비회원 비밀글 본문입니다.</p>',
                'is_secret' => true,
                'password' => Hash::make($guestPassword),
                'author_name' => self::MARKER.' 비회원',
            ],
            [
                'title' => self::MARKER.' 정화 표본',
                'content' => implode('', [
                    '<p>정화 표본 본문</p>',
                    '<figure class="media"><oembed url="https://www.youtube.com/watch?v=dQw4w9WgXcQ"></oembed></figure>',
                    // 남은 프리셋 셋(유튜브·비메오·틱톡)과 프리셋에서 뺀 서비스(링크로 남아야 함)
                    '<figure class="media"><oembed url="https://vimeo.com/76979871"></oembed></figure>',
                    '<figure class="media"><oembed url="https://www.tiktok.com/@scout2015/video/6718335390845095173"></oembed></figure>',
                    '<figure class="media"><oembed url="https://www.dailymotion.com/video/x8abc12"></oembed></figure>',
                    '<figure class="media"><oembed url="https://example.com/video/123"></oembed></figure>',
                    '<script>window.__m162 = 1</script>',
                    '<iframe src="https://example.com/embed"></iframe>',
                    '<video src="https://example.com/x.mp4" controls></video>',
                    '<video src="/api/modules/sirsoft-board/boards/m162-guest/content-media/xxxxxxxxxxxx" onloadstart="window.__m162v=1" controls></video>',
                    '<figure class="image"><img src="/favicon.ico" alt="캡션 표본"><figcaption>캡션 표본</figcaption></figure>',
                ]),
                'is_secret' => false,
                'password' => Hash::make($guestPassword),
                'author_name' => self::MARKER.' 비회원',
            ],
        ];

        foreach ($samples as $sample) {
            if (Post::where('board_id', $board->id)->where('title', $sample['title'])->exists()) {
                continue;
            }

            Post::create(array_merge([
                'board_id' => $board->id,
                'content_mode' => 'html',
                'user_id' => null,
                'ip_address' => '127.0.0.1',
                'is_notice' => false,
                'status' => 'published',
                'trigger_type' => 'user',
                'view_count' => 0,
                'depth' => 0,
            ], $sample));
        }

        $this->command?->info(__('sirsoft-board::messages.seeders.issue162_measure.posts', ['count' => count($samples), 'password' => $guestPassword]));
    }

    /**
     * 본문 미디어가 든 표본 글을 만듭니다(#883 4·5단계) — 비회원 비밀글(이미지 1·동영상 1), 회원 전용 읽기 글(동영상 1),
     * 공개 동영상 글 A·B(SPA 전환 실측). 파일은 실제 업로드 경로(`PostContentMediaService::upload` → `syncWithContent`)로 붙인다.
     *
     * @return void 반환값 없음
     */
    private function seedMediaPosts(): void
    {
        $fixture = base_path('tests/Fixtures/media/sample-1s.mp4');
        if (! is_file($fixture) || ! function_exists('imagecreatetruecolor')) {
            return;
        }

        $service = app(PostContentMediaService::class);
        $plans = [
            ['slug' => 'm162-guest', 'title' => self::MARKER.' 비회원 비밀 미디어글', 'secret' => true, 'image' => true],
            ['slug' => 'm162-member-read', 'title' => self::MARKER.' 회원 전용 미디어글', 'secret' => false, 'image' => false],
            ['slug' => 'm162-guest', 'title' => self::MARKER.' 동영상 글 A', 'secret' => false, 'image' => false],
            ['slug' => 'm162-guest', 'title' => self::MARKER.' 동영상 글 B', 'secret' => false, 'image' => false],
        ];
        $guestPassword = Str::password(12, symbols: false);
        $created = 0;

        foreach ($plans as $plan) {
            $board = Board::where('slug', $plan['slug'])->first();
            if (! $board || Post::where('board_id', $board->id)->where('title', $plan['title'])->exists()) {
                continue;
            }

            $tempKey = (string) Str::uuid();
            $html = '<p>'.e($plan['title']).'</p>';
            $base = '/api/modules/sirsoft-board/boards/'.$plan['slug'].'/content-media/';

            if ($plan['image']) {
                $png = tempnam(sys_get_temp_dir(), 'm162').'.png';
                $img = imagecreatetruecolor(120, 80);
                imagefill($img, 0, 0, imagecolorallocate($img, 40, 120, 200));
                imagepng($img, $png);
                imagedestroy($img);
                $row = $service->upload($plan['slug'], new UploadedFile($png, 'm162.png', 'image/png', null, true), $tempKey);
                $html .= '<figure class="image"><img src="'.$base.$row->hash.'" alt="m162"><figcaption>'.e($plan['title']).'</figcaption></figure>';
            }

            $copy = tempnam(sys_get_temp_dir(), 'm162').'.mp4';
            copy($fixture, $copy);
            $row = $service->upload($plan['slug'], new UploadedFile($copy, 'm162.mp4', 'video/mp4', null, true), $tempKey);
            $html .= '<video controls src="'.$base.$row->hash.'"></video>';

            $post = Post::create([
                'board_id' => $board->id,
                'title' => $plan['title'],
                'content' => $html,
                'content_mode' => 'html',
                'user_id' => null,
                'author_name' => self::MARKER.' 비회원',
                'password' => Hash::make($guestPassword),
                'ip_address' => '127.0.0.1',
                'is_notice' => false,
                'is_secret' => $plan['secret'],
                'status' => 'published',
                'trigger_type' => 'user',
                'view_count' => 0,
                'depth' => 0,
            ]);
            $service->syncWithContent($plan['slug'], $post, $tempKey);
            $created++;
        }

        $this->command?->info(__('sirsoft-board::messages.seeders.issue162_measure.media_posts', ['count' => $created, 'password' => $guestPassword]));
    }
}
