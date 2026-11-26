<?php

namespace Tests\Feature\Identity;

use App\Contracts\Extension\CacheInterface;
use App\Enums\IdentityVerificationStatus;
use App\Extension\HookManager;
use App\Helpers\InlineMediaPolicy;
use App\Models\Attachment;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Thumbnails\ThumbnailPresetRegistry;
use App\Thumbnails\ThumbnailResponder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Unit\Thumbnails\Support\TestImages;

/**
 * 코어 첨부(`/api/attachment/{hash}`)와 본인인증 정책.
 *
 * - 내려받기에 걸린 켜진 본인인증 정책(hook 범위 `core.attachment.before_download_action`, route 범위
 *   `api.attachment.download`)이 있으면 원본·썸네일을 `private` 로 낸다. 인증을 마친 사람에게만 가는 응답이
 *   공유 캐시(CDN·프록시)에 남아 인증하지 않은 사람에게 가지 않게 한다. 정책이 없으면 종전처럼 `public`.
 * - hook 범위 정책은 이미지(인라인) 경로에서도 막는다 — 종전에는 파일 내려받기 경로만 훅을 발화해 이미지 첨부는
 *   정책을 그냥 지나갔다. route 범위 정책은 원래 두 경로를 모두 막는다.
 */
class AttachmentIdentityPolicyServingTest extends TestCase
{
    use DatabaseTransactions;

    /** 시험 정책 목적 */
    private const PURPOSE = 'sensitive_action';

    /**
     * 디스크·썸네일 설정을 고정하고 정책 색인 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        Storage::fake('thumbnails');

        Config::set('attachment.disk', 'attachments');
        Config::set('core.image.driver', 'gd');
        Config::set('core.thumbnail.enabled', true);
        Config::set('core.thumbnail.presets', null);
        Config::set('core.thumbnail.disk_public', 'thumbnails');
        Config::set('core.thumbnail.disk_gated', 'thumbnails');
        Config::set('core.thumbnail.generate_per_minute', 120);
        Config::set('g7_settings.core.cache.layout_ttl', 86400);
        app(ThumbnailPresetRegistry::class)->flush();
        app(CacheInterface::class)->flush();
    }

    /**
     * 임시 이미지를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        TestImages::cleanup();

        parent::tearDown();
    }

    /**
     * 코어 첨부 하나를 심습니다.
     *
     * @param  string  $kind  image(800×600 JPEG, 인라인), svg(내려받기) 또는 text(비이미지 내려받기)
     * @return string 서빙 주소
     */
    private function attachment(string $kind): string
    {
        if ($kind === 'svg') {
            $bytes = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>';
            [$mime, $extension, $meta] = ['image/svg+xml', 'svg', null];
        } elseif ($kind === 'text') {
            $bytes = 'plain text attachment';
            [$mime, $extension, $meta] = ['text/plain', 'txt', null];
        } else {
            $bytes = (string) file_get_contents(TestImages::solid(800, 600, 'jpeg'));
            [$mime, $extension, $meta] = ['image/jpeg', 'jpg', ['width' => 800, 'height' => 600]];
        }

        $path = '2026/11/05/'.Str::uuid().'.'.$extension;
        Storage::disk('attachments')->put($path, $bytes);
        $row = Attachment::factory()->create([
            'disk' => 'attachments',
            'path' => $path,
            'stored_filename' => basename($path),
            'original_filename' => 'file.'.$extension,
            'mime_type' => $mime,
            'size' => strlen($bytes),
            'meta' => $meta,
        ]);

        return '/api/attachment/'.$row->hash;
    }

    /**
     * 본인인증 정책 하나를 만듭니다.
     *
     * @param  string  $scope  hook 또는 route
     * @param  bool  $enabled  켜짐 여부
     * @return IdentityPolicy 정책
     */
    private function policy(string $scope, bool $enabled = true): IdentityPolicy
    {
        return IdentityPolicy::create([
            'key' => 'g7test.attachment.'.$scope.'.'.Str::random(6),
            'scope' => $scope,
            'target' => $scope === 'hook' ? 'core.attachment.before_download_action' : 'api.attachment.download',
            'purpose' => self::PURPOSE,
            'grace_minutes' => 30,
            'enabled' => $enabled,
            'priority' => 100,
            'conditions' => [],
            'applies_to' => 'both',
            'fail_mode' => 'block',
            'source_type' => 'admin',
            'source_identifier' => 'admin',
        ]);
    }

    /**
     * 회원이 방금 본인인증을 마친 이력을 남깁니다 (정책 유예 시간 안).
     *
     * @param  User  $user  회원
     * @return void 반환값 없음
     */
    private function verified(User $user): void
    {
        $when = Carbon::now()->subMinute();

        IdentityVerificationLog::create([
            'id' => Str::uuid()->toString(),
            'provider_id' => 'g7:core.mail',
            'purpose' => self::PURPOSE,
            'channel' => 'email',
            'user_id' => $user->id,
            'target_hash' => hash('sha256', mb_strtolower((string) $user->email)),
            'status' => IdentityVerificationStatus::Verified->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 5,
            'verified_at' => $when,
            'expires_at' => $when->copy()->addMinutes(15),
            'created_at' => $when,
            'updated_at' => $when,
        ]);
    }

    /**
     * 응답의 Cache-Control 지시어 목록
     *
     * @param  TestResponse  $response  응답
     * @return list<string> 지시어
     */
    private function directives(TestResponse $response): array
    {
        $directives = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
        sort($directives);

        return $directives;
    }

    /**
     * 정책이 없거나 꺼져 있으면 원본은 운영에서 종전처럼 `public` 이다 — 공개 첨부의 공유 캐시 적중을 해치지 않는다.
     *
     * @scenario serving_point=core_attachment, request_param=none
     *
     * @effects core_attachment_private_behind_identity_policy
     *
     * @return void 반환값 없음
     */
    public function test_original_stays_public_without_an_enabled_identity_policy(): void
    {
        $this->policy('hook', enabled: false);
        $this->policy('route', enabled: false);
        $image = $this->attachment('image');
        $svg = $this->attachment('svg');
        $this->app['env'] = 'production';

        foreach ([$image, $svg] as $url) {
            $directives = $this->directives($this->get($url)->assertOk());

            $this->assertContains('public', $directives, $url);
            $this->assertNotContains('private', $directives, $url);
        }
    }

    /**
     * hook 범위 정책이 켜져 있으면 인증을 마친 회원이 받는 원본(이미지·내려받기)은 `private` 다.
     *
     * @scenario serving_point=core_attachment, request_param=none
     *
     * @effects core_attachment_private_behind_identity_policy
     *
     * @return void 반환값 없음
     */
    public function test_hook_policy_makes_the_original_private(): void
    {
        $this->policy('hook');
        $user = User::factory()->create();
        $this->verified($user);
        $image = $this->attachment('image');
        $svg = $this->attachment('svg');
        $this->app['env'] = 'production';

        foreach ([$image, $svg] as $url) {
            $directives = $this->directives($this->actingAs($user)->get($url)->assertOk());

            $this->assertContains('private', $directives, $url);
            $this->assertNotContains('public', $directives, $url);
        }
    }

    /**
     * route 범위 정책도 같다 — 인증하지 않은 회원은 두 경로 모두 428, 인증을 마친 회원의 원본은 `private`.
     *
     * @scenario serving_point=core_attachment, request_param=none
     *
     * @effects core_attachment_private_behind_identity_policy
     *
     * @return void 반환값 없음
     */
    public function test_route_policy_blocks_then_serves_private_after_verification(): void
    {
        $this->policy('route');
        $user = User::factory()->create();
        $image = $this->attachment('image');
        $svg = $this->attachment('svg');
        $this->app['env'] = 'production';

        foreach ([$image, $svg] as $url) {
            $this->actingAs($user)->get($url)->assertStatus(428)
                ->assertJsonPath('error_code', 'identity_verification_required');
        }

        $this->verified($user);

        foreach ([$image, $svg] as $url) {
            $directives = $this->directives($this->actingAs($user)->get($url)->assertOk());

            $this->assertContains('private', $directives, $url);
            $this->assertNotContains('public', $directives, $url);
        }
    }

    /**
     * hook 범위 정책은 이미지(인라인) 원본과 그 썸네일도 막는다 — 인증하지 않은 회원은 428.
     *
     * @scenario serving_point=core_attachment, request_param=size
     *
     * @effects identity_hook_policy_applies_to_inline_images
     *
     * @return void 반환값 없음
     */
    public function test_hook_policy_blocks_inline_images_without_verification(): void
    {
        $this->policy('hook');
        $user = User::factory()->create();
        $image = $this->attachment('image');
        $svg = $this->attachment('svg');

        foreach ([$image, $image.'?size=sm', $svg] as $url) {
            $this->actingAs($user)->get($url)->assertStatus(428)
                ->assertJsonPath('error_code', 'identity_verification_required');
        }

        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '막힌 요청이 썸네일을 만들었다');
    }

    /**
     * 유예 시간 안에 인증을 마친 회원은 이미지 원본·썸네일을 받는다. 썸네일도 `private` 다.
     *
     * @scenario serving_point=core_attachment, request_param=size
     *
     * @effects identity_hook_policy_applies_to_inline_images
     *
     * @return void 반환값 없음
     */
    public function test_verified_member_within_grace_gets_private_image_and_thumbnail(): void
    {
        $this->policy('hook');
        $user = User::factory()->create();
        $this->verified($user);
        $image = $this->attachment('image');
        $this->app['env'] = 'production';

        $original = $this->actingAs($user)->get($image)->assertOk();
        $this->assertContains('private', $this->directives($original));

        $thumbnail = $this->actingAs($user)->get($image.'?size=sm')->assertOk();
        $this->assertSame('miss', $thumbnail->headers->get(ThumbnailResponder::HEADER));
        $this->assertContains('private', $this->directives($thumbnail));
        $this->assertNotContains('public', $this->directives($thumbnail));
    }

    /**
     * 내려받기 전 액션 훅은 한 요청에 한 번만 발화한다 — 이미지·내려받기·썸네일(`?size=`)·조건부 요청(304) 모두.
     *
     * 304 도 정책을 지나야 한다 — 인증하지 않은 사람이 검증자만 보내 「바뀌지 않음」 을 확인할 수 있으면 안 된다.
     *
     * @scenario serving_point=core_attachment, request_param=none, request_param=size
     *
     * @effects identity_hook_policy_applies_to_inline_images
     *
     * @return void 반환값 없음
     */
    public function test_download_action_hook_fires_once_per_request_on_every_path(): void
    {
        $fired = 0;
        HookManager::addAction('core.attachment.before_download_action', function () use (&$fired) {
            $fired++;
        });

        foreach (['image', 'svg'] as $kind) {
            $url = $this->attachment($kind);

            $fired = 0;
            $etag = (string) $this->get($url)->assertOk()->headers->get('ETag');
            $this->assertSame(1, $fired, "{$kind} 경로에서 액션 훅이 {$fired}번 발화했다");

            $fired = 0;
            $this->get($url, ['If-None-Match' => $etag])->assertStatus(304);
            $this->assertSame(1, $fired, "{$kind} 조건부 요청(304)에서 액션 훅이 {$fired}번 발화했다");
        }

        $fired = 0;
        $this->get($this->attachment('image').'?size=sm')->assertOk();
        $this->assertSame(1, $fired, "썸네일 요청에서 액션 훅이 {$fired}번 발화했다");
    }

    /**
     * 내려받기 전 필터 훅(`core.attachment.before_download`)도 한 요청에 한 번만 돈다 — 이미지(인라인)·비이미지 내려받기·SVG
     * 내려받기·썸네일(`?size=`)·조건부 요청(304) 모두. 종전에는 비이미지에서 컨트롤러가 인라인 정보 조회 뒤 내려받기 정보 조회를
     * 이어 불러 두 조회가 모두 필터를 돌렸고, 내려받기 횟수를 세는 구독자가 한 번에 2씩 올렸다. 액션 훅도 같은 요청에서 한 번이다.
     *
     * @scenario serving_point=core_attachment, request_param=none, request_param=size
     *
     * @effects attachment_before_download_filter_runs_once_per_request
     *
     * @return void 반환값 없음
     */
    public function test_before_download_filter_runs_once_per_request_on_every_path(): void
    {
        $filtered = 0;
        $fired = 0;
        HookManager::addFilter('core.attachment.before_download', function ($attachment) use (&$filtered) {
            $filtered++;

            return $attachment;
        });
        HookManager::addAction('core.attachment.before_download_action', function () use (&$fired) {
            $fired++;
        });

        foreach (['image', 'text', 'svg'] as $kind) {
            $url = $this->attachment($kind);

            [$filtered, $fired] = [0, 0];
            $etag = (string) $this->get($url)->assertOk()->headers->get('ETag');
            $this->assertSame(1, $filtered, "{$kind} 경로에서 필터 훅이 {$filtered}번 돌았다");
            $this->assertSame(1, $fired, "{$kind} 경로에서 액션 훅이 {$fired}번 발화했다");

            [$filtered, $fired] = [0, 0];
            $this->get($url, ['If-None-Match' => $etag])->assertStatus(304);
            $this->assertSame(1, $filtered, "{$kind} 조건부 요청(304)에서 필터 훅이 {$filtered}번 돌았다");
            $this->assertSame(1, $fired, "{$kind} 조건부 요청(304)에서 액션 훅이 {$fired}번 발화했다");
        }

        [$filtered, $fired] = [0, 0];
        $this->get($this->attachment('image').'?size=sm')->assertOk();
        $this->assertSame(1, $filtered, "썸네일 요청에서 필터 훅이 {$filtered}번 돌았다");
        $this->assertSame(1, $fired, "썸네일 요청에서 액션 훅이 {$fired}번 발화했다");

        [$filtered, $fired] = [0, 0];
        $this->get($this->attachment('text').'?size=sm')->assertOk();
        $this->assertSame(1, $filtered, "비이미지에 썸네일을 요청했을 때 필터 훅이 {$filtered}번 돌았다");
        $this->assertSame(1, $fired, "비이미지에 썸네일을 요청했을 때 액션 훅이 {$fired}번 발화했다");
    }

    /**
     * 비이미지 내려받기도 한 요청에 첨부를 한 번 찾고 권한 매핑을 이미지 경로와 같은 횟수만 묻는다 — 종전에는 컨트롤러가 인라인
     * 정보 조회 뒤 내려받기 정보 조회를 이어 불러 같은 행을 다시 찾고(`findByHash` 2회) 권한 확인·공유 캐시 판정을 한 번씩 더 했다
     * (`permission_hooks` 조회가 이미지의 두 배). 권한 매핑 조회는 캐시가 없어 그대로 DB 질의다.
     *
     * @scenario serving_point=core_attachment, request_param=none
     *
     * @effects attachment_gate_checked_once_per_request
     *
     * @return void 반환값 없음
     */
    public function test_non_image_download_checks_the_gate_as_often_as_an_image(): void
    {
        $recording = false;
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$recording, &$queries) {
            if ($recording) {
                $queries[] = strtolower($query->sql);
            }
        });
        $count = function (string $url) use (&$recording, &$queries): array {
            $queries = [];
            $recording = true;
            $this->get($url)->assertOk();
            $recording = false;
            $prefix = strtolower(DB::connection()->getTablePrefix());

            return [
                'lookup' => count(array_filter($queries, fn (string $sql) => str_contains($sql, 'from `'.$prefix.'attachments` where `hash`'))),
                'gate' => count(array_filter($queries, fn (string $sql) => str_contains($sql, 'from `'.$prefix.'permission_hooks`'))),
            ];
        };

        $image = $count($this->attachment('image'));
        $this->assertSame(1, $image['lookup'], '이미지 경로 기준값 — 첨부 조회 질의를 세지 못했다');
        $this->assertGreaterThan(0, $image['gate'], '이미지 경로 기준값 — 권한 매핑 조회 질의를 세지 못했다');

        foreach (['text', 'svg'] as $kind) {
            $download = $count($this->attachment($kind));
            $this->assertSame(1, $download['lookup'], "{$kind} 내려받기에서 첨부를 {$download['lookup']}번 찾았다");
            $this->assertSame($image['gate'], $download['gate'], "{$kind} 내려받기의 권한 매핑 조회 {$download['gate']}번이 이미지 {$image['gate']}번과 다르다");
        }
    }

    /**
     * 내려받기 전 필터가 행의 MIME 을 바꾸도록 구독합니다.
     *
     * @param  string  $mime  바꿀 MIME
     * @return void 반환값 없음
     */
    private function filterMimeTo(string $mime): void
    {
        HookManager::addFilter('core.attachment.before_download', function ($attachment) use ($mime) {
            $attachment->mime_type = $mime;

            return $attachment;
        });
    }

    /**
     * 필터 구독자가 SVG 행의 MIME 을 image/png 로 바꾸면 인라인·내려받기 갈래는 필터를 지난 행 하나로 정해진다 —
     * 서비스와 컨트롤러가 같은 갈래를 탄다. 종전에는 서비스가 필터 전 MIME(SVG)으로 내려받기 응답을 만들고 컨트롤러는
     * 필터 뒤 MIME(PNG)으로 이미지 갈래를 골라, 이미지 갈래에서 `attachment` 응답이 나갔다.
     *
     * @scenario serving_point=core_attachment, request_param=none, request_param=size
     *
     * @effects attachment_serving_branch_follows_filtered_row
     *
     * @return void 반환값 없음
     */
    public function test_filter_turning_svg_into_png_serves_one_inline_branch(): void
    {
        $url = $this->attachment('svg');
        $this->filterMimeTo('image/png');

        $plain = $this->get($url)->assertOk();
        $this->assertSame('image/png', $plain->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $plain->headers->get('Content-Disposition'), '서비스가 내려받기로, 컨트롤러가 이미지로 갈래를 따로 골랐다');
        $this->assertSame('nosniff', $plain->headers->get('X-Content-Type-Options'));

        // 썸네일 요청도 같은 이미지 갈래다. 생성기는 실제 바이트(getimagesize)로 다시 판정하므로 PNG 라고 표시된 SVG 바이트로는
        // 파생본을 만들지 않고 원본에 사유(not-raster)를 붙인다
        $sized = $this->get($url.'?size=sm')->assertOk();
        $this->assertSame('image/png', $sized->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $sized->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $sized->headers->get('X-Content-Type-Options'));
        $this->assertSame('bypass:not-raster', $sized->headers->get(ThumbnailResponder::HEADER));
        Storage::disk('thumbnails')->assertDirectoryEmpty('/');
    }

    /**
     * 반대로 래스터(JPEG) 행의 MIME 을 SVG 로 바꾸면 내려받기 갈래 하나다 — 다운로드 강제·CSP·nosniff 가 붙고
     * 썸네일 요청은 `bypass:not-raster` 로 원본 내려받기를 낸다.
     *
     * @scenario serving_point=core_attachment, request_param=none, request_param=size
     *
     * @effects attachment_serving_branch_follows_filtered_row
     *
     * @return void 반환값 없음
     */
    public function test_filter_turning_raster_into_svg_serves_one_download_branch(): void
    {
        $url = $this->attachment('image');
        $this->filterMimeTo('image/svg+xml');

        foreach (['' => null, '?size=sm' => 'bypass:not-raster'] as $query => $marker) {
            $response = $this->get($url.$query)->assertOk();

            $this->assertSame('image/svg+xml', $response->headers->get('Content-Type'));
            $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
            $this->assertSame(InlineMediaPolicy::NON_INLINE_CSP, $response->headers->get('Content-Security-Policy'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

            if ($marker !== null) {
                $this->assertSame($marker, $response->headers->get(ThumbnailResponder::HEADER));
            }
        }
    }
}
