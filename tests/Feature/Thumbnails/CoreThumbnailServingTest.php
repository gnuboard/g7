<?php

namespace Tests\Feature\Thumbnails;

use App\Enums\ExtensionStatus;
use App\Enums\PermissionType;
use App\Extension\HookManager;
use App\Http\Responses\NotModifiedResponse;
use App\Models\Attachment;
use App\Models\Permission;
use App\Models\PermissionHook;
use App\Models\Role;
use App\Models\Template;
use App\Models\TemplateLayoutAttachment;
use App\Models\User;
use App\Thumbnails\ThumbnailPresetRegistry;
use App\Thumbnails\ThumbnailResponder;
use App\Thumbnails\ThumbnailStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Thumbnails\Support\TestImages;

/**
 * 코어 서빙 2곳의 썸네일 테스트 — `/api/attachment/{hash}`(게이트) · `/api/templates/{id}/layout-attachments/{id}/file`(공개)
 *
 * 파라미터가 없으면 응답이 썸네일 도입 전과 같고, `?size=`·`?w=` 이면 첫 요청은 만들고(miss) 다음은 낸다(hit).
 * 낼 수 없으면 원본에 `X-G7-Thumbnail: bypass:{사유}` 만 붙는다. 게이트 원본의 썸네일은 비공개 디스크에서
 * `private` 캐시로, 공개 원본의 썸네일은 공개 썸네일 디스크가 공개 자산 디스크면 302 로 낸다.
 */
class CoreThumbnailServingTest extends TestCase
{
    use DatabaseTransactions;

    /** 시험 템플릿 식별자 (공식 템플릿과 겹치지 않는 가짜 이름) */
    private const TEMPLATE = 'g7test-thumbtpl';

    /** @var Template|null 레이아웃 첨부가 속할 템플릿 */
    private ?Template $template = null;

    /**
     * 디스크를 가짜로 바꾸고 썸네일·캐시 설정을 기본값이 아닌 자리까지 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        HookManager::resetAll();
        Storage::fake('attachments');
        Storage::fake('thumbnails');
        Storage::fake('public');

        Config::set('attachment.disk', 'attachments');
        Config::set('attachment.image_quality', 85);
        Config::set('core.image.driver', 'gd');
        Config::set('core.thumbnail.enabled', true);
        Config::set('core.thumbnail.presets', null);
        Config::set('core.thumbnail.disk_public', 'thumbnails');
        Config::set('core.thumbnail.disk_gated', 'thumbnails');
        Config::set('core.thumbnail.generate_per_minute', 120);
        Config::set('core.thumbnail.max_source_pixels', 40000000);
        Config::set('g7_settings.core.cache.layout_ttl', 86400);
        Config::set('g7_settings.core.seo.og_image_default_width', 1200);
        Config::set('g7_settings.core.seo.og_image_default_height', 630);
        app(ThumbnailPresetRegistry::class)->flush();
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
     * 서빙 지점 표
     *
     * @return array<string, array{0:string}> 지점
     */
    public static function pointProvider(): array
    {
        return [
            'core_attachment' => ['core_attachment'],
            'layout_attachment' => ['layout_attachment'],
        ];
    }

    /**
     * 서빙 지점 하나에 원본을 심습니다.
     *
     * @param  string  $point  서빙 지점 (core_attachment 또는 layout_attachment)
     * @param  string|null  $file  원본 파일 경로 (null 이면 800×600 JPEG)
     * @param  string  $mime  원본 MIME
     * @param  bool  $withMeta  첨부 행에 가로·세로 메타를 실을지 (코어 첨부만)
     * @return array{url:string, disk:string, path:string, bytes:string, mime:string} 원본 정보 (path 는 디스크 위 전체 경로)
     */
    private function fixture(string $point, ?string $file = null, string $mime = 'image/jpeg', bool $withMeta = true): array
    {
        $file ??= TestImages::halves(800, 600, 'vertical', [255, 0, 0], [0, 0, 255], 'jpeg');
        $bytes = (string) file_get_contents($file);
        $extension = match ($mime) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            default => 'jpg',
        };
        $size = @getimagesize($file);

        if ($point === 'core_attachment') {
            $path = '2026/11/05/'.Str::uuid().'.'.$extension;
            Storage::disk('attachments')->put($path, $bytes);
            $attachment = Attachment::factory()->create([
                'disk' => 'attachments',
                'path' => $path,
                'stored_filename' => basename($path),
                'original_filename' => 'photo.'.$extension,
                'mime_type' => $mime,
                'size' => strlen($bytes),
                'meta' => $withMeta && $size !== false ? ['width' => $size[0], 'height' => $size[1]] : null,
            ]);

            return ['url' => '/api/attachment/'.$attachment->hash, 'disk' => 'attachments', 'path' => $path, 'bytes' => $bytes, 'mime' => $mime];
        }

        $this->template ??= Template::create([
            'identifier' => self::TEMPLATE,
            'vendor' => 'g7test',
            'name' => ['ko' => '시험', 'en' => 'Test'],
            'version' => '1.0.0',
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '시험', 'en' => 'Test'],
        ]);

        $relative = self::TEMPLATE.'/2026/11/05/'.Str::uuid().'.'.$extension;
        Storage::disk('attachments')->put('template-layout-attachments/'.$relative, $bytes);
        $row = TemplateLayoutAttachment::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'disk' => 'attachments',
            'path' => $relative,
            'original_name' => 'bg.'.$extension,
            'mime_type' => $mime,
            'size' => strlen($bytes),
        ]);

        return [
            'url' => '/api/templates/'.self::TEMPLATE.'/layout-attachments/'.$row->id.'/file',
            'disk' => 'attachments',
            'path' => 'template-layout-attachments/'.$relative,
            'bytes' => $bytes,
            'mime' => $mime,
        ];
    }

    /**
     * 파생본 키 (현재 엔진·규격 지문)
     *
     * @param  array{disk:string, path:string}  $fixture  원본
     * @param  string  $preset  규격
     * @param  string  $extension  결과 확장자
     * @return string 키
     */
    private function key(array $fixture, string $preset, string $extension = 'jpg'): string
    {
        $spec = app(ThumbnailPresetRegistry::class)->find($preset);

        return ThumbnailStore::ROOT."/{$fixture['disk']}/{$fixture['path']}/{$preset}_".$spec->fingerprint('gd').'.'.$extension;
    }

    /**
     * 응답 본문 (스트림 포함)
     *
     * @param  TestResponse  $response  응답
     * @return string 본문
     */
    private function body(TestResponse $response): string
    {
        return $response->baseResponse instanceof NotModifiedResponse ? '' : (string) $response->streamedContent();
    }

    /**
     * 비교에 쓰는 헤더 (요청마다 달라지는 Date·썸네일 헤더 제외)
     *
     * @param  TestResponse  $response  응답
     * @return array<string, mixed> 헤더
     */
    private function stableHeaders(TestResponse $response): array
    {
        $headers = $response->headers->all();
        unset($headers['date'], $headers[strtolower(ThumbnailResponder::HEADER)]);
        ksort($headers);

        return $headers;
    }

    /**
     * 파라미터가 없으면 썸네일 헤더 없이 원본을 기존 캐시 계약 그대로 낸다.
     *
     * @scenario serving_point=core_attachment, serving_point=layout_attachment, request_param=none
     *
     * @effects original_response_unchanged_without_params
     *
     * @param  string  $point  서빙 지점
     * @return void 반환값 없음
     */
    #[DataProvider('pointProvider')]
    public function test_no_params_serves_the_original_unchanged(string $point): void
    {
        $fixture = $this->fixture($point);

        $response = $this->get($fixture['url']);

        $response->assertOk();
        $this->assertNull($response->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame($fixture['bytes'], $this->body($response));
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', (string) $response->headers->get('ETag'));
        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '파라미터 없이 파생본을 만들었다');
    }

    /**
     * 첫 요청은 만들고(miss) 다음 요청은 이미 만든 것을 낸다(hit) — 결과는 규격 크기이고 파일은 하나다.
     *
     * @scenario serving_point=core_attachment, serving_point=layout_attachment, request_param=size, request_param=w
     *
     * @effects thumbnail_generated_on_first_request, thumbnail_served_from_cache_on_second, thumbnail_key_mirrors_source_path
     *
     * @param  string  $point  서빙 지점
     * @return void 반환값 없음
     */
    #[DataProvider('pointProvider')]
    public function test_miss_then_hit(string $point): void
    {
        $fixture = $this->fixture($point);

        $first = $this->get($fixture['url'].'?size=sm');
        $first->assertOk();
        $this->assertSame('miss', $first->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame('image/jpeg', $first->headers->get('Content-Type'));
        $this->assertSame('nosniff', $first->headers->get('X-Content-Type-Options'));

        $thumbnail = $this->body($first);
        $this->assertNotSame($fixture['bytes'], $thumbnail);
        $this->assertSame([160, 160], array_slice(getimagesizefromstring($thumbnail), 0, 2));
        Storage::disk('thumbnails')->assertExists($this->key($fixture, 'sm'));

        $second = $this->get($fixture['url'].'?size=sm');
        $second->assertOk();
        $this->assertSame('hit', $second->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame($thumbnail, $this->body($second));
        $this->assertCount(1, Storage::disk('thumbnails')->allFiles(), '같은 주소에 파생본이 둘 생겼다');

        // ?w=300 은 md(가로 640)로 올려 맞춘다
        $numeric = $this->get($fixture['url'].'?w=300');
        $this->assertSame('miss', $numeric->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([640, 480], array_slice(getimagesizefromstring($this->body($numeric)), 0, 2));
        Storage::disk('thumbnails')->assertExists($this->key($fixture, 'md'));
    }

    /**
     * 썸네일 응답의 ETag 로 재요청하면 304 — 캐시 헤더는 200 과 같다.
     *
     * @scenario serving_point=core_attachment, serving_point=layout_attachment, request_param=size
     *
     * @effects thumbnail_conditional_request_304
     *
     * @param  string  $point  서빙 지점
     * @return void 반환값 없음
     */
    #[DataProvider('pointProvider')]
    public function test_etag_revalidation_yields_304(string $point): void
    {
        $fixture = $this->fixture($point);
        $first = $this->get($fixture['url'].'?size=xs');
        $etag = (string) $first->headers->get('ETag');
        $this->assertNotSame('', $etag);

        $second = $this->withHeaders(['If-None-Match' => $etag])->get($fixture['url'].'?size=xs');

        $second->assertStatus(304);
        $this->assertSame('', $this->body($second));
        $this->assertSame($first->headers->get('Cache-Control'), $second->headers->get('Cache-Control'));
        $this->assertSame($etag, $second->headers->get('ETag'));
        $this->assertSame('hit', $second->headers->get(ThumbnailResponder::HEADER));
    }

    /**
     * 로컬 원본의 수정 시각이 파생본보다 늦으면 다시 만든다(D43) — 앱 밖에서 원본을 바꾼 경우.
     *
     * @scenario serving_point=core_attachment, serving_point=layout_attachment, request_param=size
     *
     * @effects stale_derivative_regenerated_when_local_source_newer
     *
     * @param  string  $point  서빙 지점
     * @return void 반환값 없음
     */
    #[DataProvider('pointProvider')]
    public function test_newer_local_source_regenerates(string $point): void
    {
        $fixture = $this->fixture($point);
        $this->get($fixture['url'].'?size=sm')->assertOk();
        $key = $this->key($fixture, 'sm');

        $this->assertSame('hit', $this->get($fixture['url'].'?size=sm')->headers->get(ThumbnailResponder::HEADER), '원본이 파생본보다 늦지 않으면 hit');

        // 원본을 앱 밖에서(드라이버를 거치지 않고) 새 그림으로 바꾼다 — 파생본은 2분 전, 원본은 1분 전 수정
        $sourcePath = Storage::disk($fixture['disk'])->path($fixture['path']);
        copy(TestImages::halves(800, 600, 'vertical', [0, 255, 0], [0, 255, 0], 'jpeg'), $sourcePath);
        touch(Storage::disk('thumbnails')->path($key), time() - 120);
        touch($sourcePath, time() - 60);
        clearstatcache();

        $regenerated = $this->get($fixture['url'].'?size=sm');

        $this->assertSame('miss', $regenerated->headers->get(ThumbnailResponder::HEADER));
        [$r, $g, $b] = $this->centerColor($this->body($regenerated));
        $this->assertGreaterThan(200, $g, '옛 원본(빨강·파랑)의 썸네일을 냈다');
        $this->assertLessThan(60, $r + $b);
        $this->assertCount(1, Storage::disk('thumbnails')->allFiles());
    }

    /**
     * 게이트를 지나지 못하면 썸네일 요청도 원본과 똑같이 거부되고 파생본을 만들지 않는다.
     *
     * @scenario serving_point=core_attachment, request_param=size
     *
     * @effects gate_runs_before_thumbnail
     *
     * @return void 반환값 없음
     */
    public function test_core_attachment_gate_denies_thumbnail_like_the_original(): void
    {
        $fixture = $this->fixture('core_attachment');
        $permission = Permission::create([
            'identifier' => 'g7test.attachment.download',
            'name' => ['ko' => '첨부 다운로드', 'en' => 'Download'],
            'type' => PermissionType::Admin,
        ]);
        PermissionHook::create(['permission_id' => $permission->id, 'hook_name' => 'core.attachment.download']);
        $user = User::factory()->create();

        $original = $this->actingAs($user)->get($fixture['url']);
        $thumbnail = $this->actingAs($user)->get($fixture['url'].'?size=sm');

        $original->assertStatus(403);
        $thumbnail->assertStatus(403);
        $this->assertSame($original->json(), $thumbnail->json());
        $this->assertNull($thumbnail->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([], Storage::disk('thumbnails')->allFiles());
    }

    /**
     * 레이아웃 첨부의 게이트(경로 템플릿 소속)도 썸네일 앞에서 막는다 — 다른 템플릿 경로는 원본과 같은 404.
     *
     * @scenario serving_point=layout_attachment, request_param=size
     *
     * @effects gate_runs_before_thumbnail
     *
     * @return void 반환값 없음
     */
    public function test_layout_attachment_gate_denies_thumbnail_like_the_original(): void
    {
        $fixture = $this->fixture('layout_attachment');
        $wrongUrl = str_replace('/'.self::TEMPLATE.'/', '/g7test-othertpl/', $fixture['url']);

        $original = $this->get($wrongUrl);
        $thumbnail = $this->get($wrongUrl.'?size=sm');

        $original->assertNotFound();
        $thumbnail->assertNotFound();
        $this->assertSame($original->json(), $thumbnail->json());
        $this->assertSame([], Storage::disk('thumbnails')->allFiles());
    }

    /**
     * 잘못된 파라미터 6종은 원본(200)에 `bypass:unknown-preset` 만 붙는다 — 헤더·본문은 무파라미터와 같다.
     *
     * @scenario serving_point=core_attachment, serving_point=layout_attachment, request_param=invalid
     *
     * @effects invalid_params_fall_back_to_original, original_response_unchanged_without_params
     *
     * @param  string  $point  서빙 지점
     * @return void 반환값 없음
     */
    #[DataProvider('pointProvider')]
    public function test_invalid_params_serve_the_original_with_bypass_header(string $point): void
    {
        $fixture = $this->fixture($point);
        $baseline = $this->get($fixture['url']);

        foreach (['size=unknown', 'size=..%2F..%2Fx', 'size[]=x', 'w=-1', 'w=abc', 'size=sm&size=md'] as $query) {
            $response = $this->get($fixture['url'].'?'.$query);

            $response->assertOk();
            $this->assertSame('bypass:unknown-preset', $response->headers->get(ThumbnailResponder::HEADER), $query);
            $this->assertSame($fixture['bytes'], $this->body($response), $query);
            $this->assertSame($this->stableHeaders($baseline), $this->stableHeaders($response), "{$query} 의 헤더가 원본과 다르다");
        }

        $this->assertSame([], Storage::disk('thumbnails')->allFiles());
    }

    /**
     * IP 당 생성 제한을 넘으면 새로 만들지 않고 원본(`bypass:rate-limited`) — 이미 만든 것(hit)은 제한과 무관.
     *
     * @scenario serving_point=core_attachment, serving_point=layout_attachment, request_param=size
     *
     * @effects generation_rate_limited
     *
     * @param  string  $point  서빙 지점
     * @return void 반환값 없음
     */
    #[DataProvider('pointProvider')]
    public function test_generation_rate_limit(string $point): void
    {
        Config::set('core.thumbnail.generate_per_minute', 1);
        $fixture = $this->fixture($point);

        $this->assertSame('miss', $this->get($fixture['url'].'?size=sm')->headers->get(ThumbnailResponder::HEADER));

        $limited = $this->get($fixture['url'].'?size=xs');
        $limited->assertOk();
        $this->assertSame('bypass:rate-limited', $limited->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame($fixture['bytes'], $this->body($limited));

        $this->assertSame('hit', $this->get($fixture['url'].'?size=sm')->headers->get(ThumbnailResponder::HEADER));
        $this->assertCount(1, Storage::disk('thumbnails')->allFiles());
    }

    /**
     * 같은 파생본을 다른 요청이 만드는 중(생성 잠금 보유)이면 기다리다 원본을 내고, 두 번째 파일을 쓰지 않는다.
     *
     * 잠금 대기(10초)를 실제로 지난다 — 같은 주소 동시 요청이 파생본을 둘 만들거나 반쯤 쓴 파일을 덮지 않음을 잰다.
     *
     * @scenario serving_point=core_attachment, request_param=size
     *
     * @effects concurrent_generation_single_file
     *
     * @return void 반환값 없음
     */
    public function test_concurrent_generation_writes_a_single_file(): void
    {
        $fixture = $this->fixture('core_attachment');
        $key = $this->key($fixture, 'sm');
        $lock = Cache::lock('thumb:'.md5('thumbnails|'.$key), 30);
        $this->assertTrue($lock->get(), '시험 잠금을 잡지 못했다');

        try {
            $waiting = $this->get($fixture['url'].'?size=sm');
        } finally {
            $lock->release();
        }

        $waiting->assertOk();
        $this->assertSame('bypass:error', $waiting->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame($fixture['bytes'], $this->body($waiting));
        $this->assertSame([], Storage::disk('thumbnails')->allFiles(), '잠금을 가진 요청이 있는데 파생본을 썼다');

        // 잠금이 풀리면 하나만 만든다
        $this->assertSame('miss', $this->get($fixture['url'].'?size=sm')->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame('hit', $this->get($fixture['url'].'?size=sm')->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([$key], Storage::disk('thumbnails')->allFiles());
    }

    /**
     * 게이트 원본(코어 첨부)의 썸네일은 `private` 캐시 — 운영 환경은 `private, max-age`, 그 외는 `private, no-cache`.
     * 공개 원본(레이아웃 첨부)의 썸네일은 `private` 이 아니다.
     *
     * @scenario serving_point=core_attachment, serving_point=layout_attachment, request_param=size
     *
     * @effects gated_thumbnail_cache_is_private
     *
     * @return void 반환값 없음
     */
    public function test_gated_thumbnail_cache_control_is_private(): void
    {
        $core = $this->fixture('core_attachment');
        $layout = $this->fixture('layout_attachment');

        // 운영 밖 환경은 원본·썸네일 모두 재검증 강제(no-cache)라 Symfony 가 private 을 덧붙인다 — 값만 확인
        $this->assertSame(['no-cache', 'private'], $this->directives($this->get($core['url'].'?size=sm')));

        // 운영 환경: 게이트 썸네일만 private, 공개 원본의 썸네일과 원본 응답은 public
        $this->app['env'] = 'production';

        $gated = $this->get($core['url'].'?size=sm');
        $this->assertSame('hit', $gated->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame(['max-age=86400', 'private'], $this->directives($gated));

        $public = $this->get($layout['url'].'?size=sm');
        $this->assertSame('miss', $public->headers->get(ThumbnailResponder::HEADER));
        $this->assertContains('public', $this->directives($public));
        $this->assertNotContains('private', $this->directives($public));

        $original = $this->get($core['url']);
        $this->assertContains('public', $this->directives($original), '원본 응답의 캐시 계약은 그대로다');
        $this->assertNotContains('private', $this->directives($original));
    }

    /**
     * Cache-Control 지시어 목록 (정렬)
     *
     * @param  TestResponse  $response  응답
     * @return list<string> 지시어
     */
    private function directives(TestResponse $response): array
    {
        $values = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
        sort($values);

        return $values;
    }

    /**
     * 공개 썸네일 디스크로 공개 자산 디스크(public)를 고르면 공개 원본의 썸네일은 302 로 직접 주소를 가리킨다.
     * 게이트 원본은 같은 설정이어도 302 하지 않는다(비공개 디스크 스트림). 302 는 첫 요청(miss)이든 적중(hit)이든 nosniff 를 단다.
     *
     * @scenario serving_point=layout_attachment, request_param=size
     *
     * @effects public_thumbnail_redirects_to_public_disk, public_thumbnail_redirect_hardened
     *
     * @return void 반환값 없음
     */
    public function test_public_source_on_public_thumbnail_disk_redirects(): void
    {
        Config::set('core.thumbnail.disk_public', 'public');
        Config::set('core.thumbnail.disk_gated', 'public');
        $layout = $this->fixture('layout_attachment');
        $core = $this->fixture('core_attachment');

        $redirect = $this->get($layout['url'].'?size=sm');

        $redirect->assertStatus(302);
        $this->assertSame('miss', $redirect->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame(['max-age=86400', 'public'], $this->directives($redirect));
        $this->assertStringEndsWith('/'.$this->key($layout, 'sm'), (string) $redirect->headers->get('Location'));
        Storage::disk('public')->assertExists($this->key($layout, 'sm'));
        // 302 도 스트림 응답과 같은 보안 헤더(`InlineMediaPolicy::harden`)를 단다 — 본문이 없어도 응답 헤더 계약은 같다
        $this->assertSame('nosniff', $redirect->headers->get('X-Content-Type-Options'), '302 응답에 nosniff 가 없다');

        $hit = $this->get($layout['url'].'?size=sm');
        $hit->assertStatus(302);
        $this->assertSame('hit', $hit->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame('nosniff', $hit->headers->get('X-Content-Type-Options'), '두 번째(적중) 302 응답에 nosniff 가 없다');

        $gated = $this->get($core['url'].'?size=sm');
        $gated->assertOk();
        $this->assertContains('private', $this->directives($gated));
        Storage::disk('thumbnails')->assertExists($this->key($core, 'sm'));
        Storage::disk('public')->assertMissing($this->key($core, 'sm'));
    }

    /**
     * SVG 첨부에 size 를 붙여도 다운로드 방식은 그대로이고 `bypass:not-raster` 만 붙는다.
     *
     * @scenario serving_point=core_attachment, request_param=size
     *
     * @effects non_raster_bypassed
     *
     * @return void 반환값 없음
     */
    public function test_svg_attachment_keeps_download_and_marks_not_raster(): void
    {
        $svg = TestImages::tempPath('svg');
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>');
        $fixture = $this->fixture('core_attachment', $svg, 'image/svg+xml');

        $plain = $this->get($fixture['url']);
        $response = $this->get($fixture['url'].'?size=sm');

        $response->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('bypass:not-raster', $response->headers->get(ThumbnailResponder::HEADER));
        $this->assertNull($plain->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame($this->stableHeaders($plain), $this->stableHeaders($response));
        $this->assertSame([], Storage::disk('thumbnails')->allFiles());
    }

    /**
     * 움직이는 GIF 는 원본 바이트 그대로(`bypass:animated`) — 첫 프레임 썸네일로 바꾸지 않는다(D19).
     *
     * @scenario serving_point=core_attachment, request_param=size
     *
     * @effects animated_gif_detected, original_bytes_on_bypass
     *
     * @return void 반환값 없음
     */
    public function test_animated_gif_serves_original_bytes(): void
    {
        $fixture = $this->fixture('core_attachment', TestImages::gif(3, screen: 400), 'image/gif');

        $response = $this->get($fixture['url'].'?size=sm');

        $response->assertOk();
        $this->assertSame('bypass:animated', $response->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame($fixture['bytes'], $this->body($response));
        $this->assertSame([], Storage::disk('thumbnails')->allFiles());

        // 판정을 기억해 두 번째 요청은 원본을 다시 내려받지 않고 같은 사유를 낸다
        $this->assertSame('bypass:animated', $this->get($fixture['url'].'?size=sm')->headers->get(ThumbnailResponder::HEADER));
    }

    /**
     * 움직이는 형식 표 — 형식 이름(gif·webp·png), MIME, 결과 확장자
     *
     * @return array<string, array{0:string, 1:string, 2:string}> 형식
     */
    public static function animatedProvider(): array
    {
        return [
            'gif' => ['gif', 'image/gif', 'gif'],
            'webp' => ['webp', 'image/webp', 'webp'],
            'apng' => ['png', 'image/png', 'png'],
        ];
    }

    /**
     * 움직이는 형식의 원본 파일 (400×300 이상 — sm 규격보다 커서 작은 원본 판정에 걸리지 않는다)
     *
     * @param  string  $format  움직이는 형식 이름 (gif·webp·png)
     * @return string 만든 원본 파일 경로
     */
    private function animatedFile(string $format): string
    {
        return match ($format) {
            'gif' => TestImages::fullFrameGif(3),
            'webp' => TestImages::animatedWebp(3),
            default => TestImages::apng(3),
        };
    }

    /**
     * 움직이는 WebP·APNG 도 GIF 처럼 원본 바이트 그대로(`bypass:animated`) — 기본 설정(`core.thumbnail.animated_original`).
     *
     * @scenario serving_point=core_attachment, serving_point=layout_attachment, request_param=size
     *
     * @effects animated_webp_apng_detected, original_bytes_on_bypass
     *
     * @param  string  $format  움직이는 형식 이름
     * @param  string  $mime  원본 파일의 미디어 형식
     * @param  string  $extension  결과 파일 확장자 (이 시험은 파생본을 만들지 않아 쓰지 않는다)
     * @return void 반환값 없음
     */
    #[DataProvider('animatedProvider')]
    public function test_animated_webp_and_apng_serve_original_bytes(string $format, string $mime, string $extension): void
    {
        foreach (['core_attachment', 'layout_attachment'] as $point) {
            $fixture = $this->fixture($point, $this->animatedFile($format), $mime);

            $response = $this->get($fixture['url'].'?size=sm');

            $response->assertOk();
            $this->assertSame('bypass:animated', $response->headers->get(ThumbnailResponder::HEADER), "{$point} 첫 요청");
            $this->assertSame($fixture['bytes'], $this->body($response), "{$point} 원본 바이트");
            $this->assertSame('bypass:animated', $this->get($fixture['url'].'?size=sm')->headers->get(ThumbnailResponder::HEADER), "{$point} 기억한 판정");
        }

        $this->assertSame([], Storage::disk('thumbnails')->allFiles());
    }

    /**
     * 설정을 끄면 첫 프레임 썸네일을 만든다(GD 는 움직이는 WebP 를 읽지 못해 원본). 다시 켜면 이미 만든 첫 프레임
     * 썸네일이 있어도 바로 원본으로 돌아간다 — 판정을 기억한 동안에도, 기억이 없어진 뒤에도.
     *
     * @scenario serving_point=core_attachment, request_param=size
     *
     * @effects animated_original_configurable
     *
     * @param  string  $format  움직이는 형식 이름
     * @param  string  $mime  원본 파일의 미디어 형식
     * @param  string  $extension  결과 파일 확장자
     * @return void 반환값 없음
     */
    #[DataProvider('animatedProvider')]
    public function test_animated_original_setting_off_makes_first_frame_thumbnail(string $format, string $mime, string $extension): void
    {
        $fixture = $this->fixture('core_attachment', $this->animatedFile($format), $mime);
        $this->assertSame('bypass:animated', $this->get($fixture['url'].'?size=sm')->headers->get(ThumbnailResponder::HEADER), '켠 상태에서 판정을 기억');

        Config::set('core.thumbnail.animated_original', false);
        $off = $this->get($fixture['url'].'?size=sm');

        if ($format === 'webp') {
            $this->assertSame('bypass:animated', $off->headers->get(ThumbnailResponder::HEADER), 'GD 는 움직이는 WebP 를 읽지 못한다');
            $this->assertSame($fixture['bytes'], $this->body($off));
            $this->assertSame([], Storage::disk('thumbnails')->allFiles());

            return;
        }

        $this->assertSame('miss', $off->headers->get(ThumbnailResponder::HEADER), '끄면 기억한 판정과 상관없이 바로 만든다');
        $this->assertSame([160, 160], array_slice(getimagesizefromstring($this->body($off)), 0, 2));
        $this->assertSame('hit', $this->get($fixture['url'].'?size=sm')->headers->get(ThumbnailResponder::HEADER));
        $this->assertCount(1, Storage::disk('thumbnails')->allFiles());
        $this->assertNotContains($this->key($fixture, 'sm', $extension), Storage::disk('thumbnails')->allFiles(), '첫 프레임 썸네일은 정지 이미지 키와 다른 이름');

        Config::set('core.thumbnail.animated_original', true);
        $on = $this->get($fixture['url'].'?size=sm');
        $this->assertSame('bypass:animated', $on->headers->get(ThumbnailResponder::HEADER), '다시 켜면 바로 원본');
        $this->assertSame($fixture['bytes'], $this->body($on));

        Cache::flush();
        $this->assertSame('bypass:animated', $this->get($fixture['url'].'?size=sm')->headers->get(ThumbnailResponder::HEADER), '판정 기억이 없어도 첫 프레임 썸네일을 내지 않는다');
    }

    /**
     * 확장은 `core.thumbnail.filter_animated_original` 로 형식·원본·규격별로 판정을 바꾼다 — 설정값이 기본값으로 온다.
     *
     * @scenario serving_point=core_attachment, request_param=size
     *
     * @effects animated_original_configurable
     *
     * @return void 반환값 없음
     */
    public function test_animated_original_filter_lets_extensions_override(): void
    {
        $received = [];
        HookManager::addFilter('core.thumbnail.filter_animated_original', function (bool $serveOriginal, string $format, $source, $spec) use (&$received) {
            $received[] = [$serveOriginal, $format, $source->disk, $spec->name];

            return $format === 'png' ? false : $serveOriginal;
        });

        $gif = $this->fixture('core_attachment', $this->animatedFile('gif'), 'image/gif');
        $apng = $this->fixture('core_attachment', $this->animatedFile('png'), 'image/png');

        $this->assertSame('bypass:animated', $this->get($gif['url'].'?size=sm')->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame('miss', $this->get($apng['url'].'?size=sm')->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([[true, 'gif', 'attachments', 'sm'], [true, 'png', 'attachments', 'sm']], $received);
    }

    /**
     * 확장 필터가 bool 이 아닌 값(반환을 빠뜨린 null, 문자열)을 돌려주면 설정값을 쓴다 — 형제 썸네일 필터와 같은 기본값 복귀.
     *
     * @scenario serving_point=core_attachment, request_param=size
     *
     * @effects animated_original_configurable
     *
     * @return void 반환값 없음
     */
    public function test_animated_original_filter_non_bool_falls_back_to_setting(): void
    {
        $returns = [null, 'false', 0];
        HookManager::addFilter('core.thumbnail.filter_animated_original', function () use (&$returns) {
            return array_shift($returns);
        });

        foreach (['null', '문자열 false', '정수 0'] as $label) {
            $fixture = $this->fixture('core_attachment', $this->animatedFile('png'), 'image/png');
            $this->assertSame('bypass:animated', $this->get($fixture['url'].'?size=sm')->headers->get(ThumbnailResponder::HEADER), "{$label} 이면 설정(켬)대로 원본");
        }

        $this->assertSame([], Storage::disk('thumbnails')->allFiles());
    }

    /**
     * 판정 기억이 없어도(캐시 비움) 이미 만든 첫 프레임 썸네일은 원본을 다시 받지 않고 낸다 — 생성 한도가 다 찬 IP 에도.
     *
     * @scenario serving_point=core_attachment, request_param=size
     *
     * @effects animated_original_configurable, generation_rate_limited
     *
     * @return void 반환값 없음
     */
    public function test_first_frame_thumbnail_hits_without_memo_even_when_rate_limited(): void
    {
        Config::set('core.thumbnail.animated_original', false);
        $fixture = $this->fixture('core_attachment', $this->animatedFile('png'), 'image/png');
        $this->assertSame('miss', $this->get($fixture['url'].'?size=sm')->headers->get(ThumbnailResponder::HEADER));

        // 기억을 비우고(생성 제한 카운터도 함께 비워진다) 한도 1회를 다른 원본 생성으로 다 쓴다
        Cache::flush();
        Config::set('core.thumbnail.generate_per_minute', 1);
        $other = $this->fixture('core_attachment');
        $this->assertSame('miss', $this->get($other['url'].'?size=sm')->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame('bypass:rate-limited', $this->get($other['url'].'?size=md')->headers->get(ThumbnailResponder::HEADER), '한도가 찼다');

        $response = $this->get($fixture['url'].'?size=sm');
        $this->assertSame('hit', $response->headers->get(ThumbnailResponder::HEADER), '기억이 없어도 첫 프레임 썸네일');
        $this->assertSame([160, 160], array_slice(getimagesizefromstring($this->body($response)), 0, 2));

        // 다시 켜면 기억이 없어도 원본(첫 프레임 썸네일을 내지 않는다)
        Cache::flush();
        Config::set('core.thumbnail.animated_original', true);
        $this->assertSame('bypass:animated', $this->get($fixture['url'].'?size=sm')->headers->get(ThumbnailResponder::HEADER));
    }

    /**
     * 규격보다 작은 원본은 확대하지 않고 원본(`bypass:smaller-than-preset`) — 메타가 있으면 원본을 읽지도 않는다.
     *
     * @scenario serving_point=core_attachment, serving_point=layout_attachment, request_param=size
     *
     * @effects smaller_source_bypassed, original_bytes_on_bypass
     *
     * @param  string  $point  서빙 지점
     * @return void 반환값 없음
     */
    #[DataProvider('pointProvider')]
    public function test_smaller_than_preset_serves_the_original(string $point): void
    {
        $fixture = $this->fixture($point, TestImages::solid(100, 100, 'png'), 'image/png', withMeta: $point === 'core_attachment');

        $response = $this->get($fixture['url'].'?size=sm');

        $response->assertOk();
        $this->assertSame('bypass:smaller-than-preset', $response->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame($fixture['bytes'], $this->body($response));
        $this->assertSame([], Storage::disk('thumbnails')->allFiles());

        // 확대를 켠 운영자 규격이면 만든다
        Config::set('core.thumbnail.presets', [['name' => 'big', 'source' => 'custom', 'width' => 300, 'height' => 300, 'fit' => 'cover', 'upscale' => true]]);
        $upscaled = $this->get($fixture['url'].'?size=big');
        $this->assertSame('miss', $upscaled->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame([300, 300], array_slice(getimagesizefromstring($this->body($upscaled)), 0, 2));
    }

    /**
     * og 규격은 원본이 작아도 SEO 탭 크기(선언한 `og:image:width/height`)로 낸다 — 메타는 원본 크기를 모른 채
     * SEO 탭 크기를 선언하므로, 한쪽만 작은 원본(800×800)도 통째로 작은 원본(800×600)도 1200×630 이어야 한다.
     *
     * @scenario serving_point=core_attachment, request_param=size
     *
     * @effects og_preset_follows_seo_size
     *
     * @return void 반환값 없음
     */
    public function test_og_preset_always_matches_the_declared_seo_size(): void
    {
        foreach ([[800, 800], [800, 600]] as [$w, $h]) {
            $fixture = $this->fixture('core_attachment', TestImages::solid($w, $h, 'jpeg'));

            $response = $this->get($fixture['url'].'?size=og');

            $response->assertOk();
            $this->assertSame('miss', $response->headers->get(ThumbnailResponder::HEADER), "{$w}×{$h} 원본");
            $this->assertSame([1200, 630], array_slice(getimagesizefromstring($this->body($response)), 0, 2), "{$w}×{$h} 원본");
        }
    }

    /**
     * 기능을 끄면 파라미터가 있어도 원본(`bypass:disabled`).
     *
     * @scenario serving_point=core_attachment, request_param=size
     *
     * @effects invalid_params_fall_back_to_original
     *
     * @return void 반환값 없음
     */
    public function test_disabled_feature_serves_the_original(): void
    {
        Config::set('core.thumbnail.enabled', false);
        $fixture = $this->fixture('core_attachment');

        $response = $this->get($fixture['url'].'?size=sm');

        $this->assertSame('bypass:disabled', $response->headers->get(ThumbnailResponder::HEADER));
        $this->assertSame($fixture['bytes'], $this->body($response));
    }

    /**
     * 썸네일 본문 가운데 점의 색
     *
     * @param  string  $bytes  이미지 바이트
     * @return array{0:int,1:int,2:int} 가운데 점의 [r, g, b]
     */
    private function centerColor(string $bytes): array
    {
        $image = imagecreatefromstring($bytes);
        $rgb = imagecolorat($image, intdiv(imagesx($image), 2), intdiv(imagesy($image), 2));

        return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }

    /**
     * 권한 게이트가 없는 코어 첨부 원본은 운영에서도 공유 캐시 대상 그대로다 — 공개 첨부의 CDN 적중을 해치지 않는다.
     *
     * @scenario serving_point=core_attachment, request_param=none
     *
     * @effects core_attachment_original_private_only_behind_gate
     *
     * @return void 반환값 없음
     */
    public function test_core_attachment_original_stays_public_without_a_gate(): void
    {
        $image = $this->fixture('core_attachment');
        $svg = TestImages::tempPath('svg');
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>');
        $document = $this->fixture('core_attachment', $svg, 'image/svg+xml');

        $this->app['env'] = 'production';

        foreach ([$image, $document] as $fixture) {
            $cacheControl = (string) $this->get($fixture['url'])->assertOk()->headers->get('Cache-Control');

            $this->assertStringContainsString('public', $cacheControl);
            $this->assertStringNotContainsString('private', $cacheControl);
        }
    }

    /**
     * 권한 훅이 걸린 코어 첨부 원본은 인라인·다운로드 모두 공유 캐시에 남기지 않는다.
     *
     * @scenario serving_point=core_attachment, request_param=none
     *
     * @effects core_attachment_original_private_only_behind_gate
     *
     * @return void 반환값 없음
     */
    public function test_core_attachment_original_is_private_behind_a_permission_gate(): void
    {
        $image = $this->fixture('core_attachment');
        $svg = TestImages::tempPath('svg');
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>');
        $document = $this->fixture('core_attachment', $svg, 'image/svg+xml');

        $permission = Permission::create([
            'identifier' => 'g7test.attachment.download',
            'name' => ['ko' => '첨부 다운로드', 'en' => 'Download'],
            'type' => PermissionType::Admin,
        ]);
        PermissionHook::create(['permission_id' => $permission->id, 'hook_name' => 'core.attachment.download']);
        $role = Role::create([
            'identifier' => 'g7test_attachment_'.uniqid(),
            'name' => ['ko' => '시험', 'en' => 'Test'],
            'description' => ['ko' => '시험', 'en' => 'Test'],
            'is_active' => true,
        ]);
        $role->permissions()->sync([$permission->id]);
        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        $this->app['env'] = 'production';

        foreach ([$image, $document] as $fixture) {
            $cacheControl = (string) $this->actingAs($user)->get($fixture['url'])->assertOk()->headers->get('Cache-Control');

            $this->assertStringContainsString('private', $cacheControl);
            $this->assertStringNotContainsString('public', $cacheControl);
        }
    }
}
