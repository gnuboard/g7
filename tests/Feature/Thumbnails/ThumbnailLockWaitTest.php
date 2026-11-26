<?php

namespace Tests\Feature\Thumbnails;

use App\Extension\HookManager;
use App\Thumbnails\DTO\ThumbnailSource;
use App\Thumbnails\ImageProcessorResolver;
use App\Thumbnails\ThumbnailGenerator;
use App\Thumbnails\ThumbnailPresetRegistry;
use App\Thumbnails\ThumbnailStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Thumbnails\Support\TestImages;

/**
 * 썸네일 생성 잠금 대기 시간 서버 설정 — `core.thumbnail.lock_wait_seconds`.
 *
 * 같은 원본·같은 규격을 다른 요청이 만들고 있으면 이 시간만큼 기다리고, 넘으면 원본을 낸다(`bypass:error`).
 * 대기는 잠금 유지 시간(30초)보다 길 수 없고 1초보다 짧을 수 없다.
 */
class ThumbnailLockWaitTest extends TestCase
{
    /**
     * 디스크를 가짜로 바꾸고 썸네일 설정을 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        HookManager::resetAll();
        Storage::fake('attachments');
        Storage::fake('thumbnails');

        Config::set('core.image.driver', 'gd');
        Config::set('core.thumbnail.enabled', true);
        Config::set('core.thumbnail.presets', null);
        Config::set('core.thumbnail.disk_public', 'thumbnails');
        Config::set('core.thumbnail.disk_gated', 'thumbnails');
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
     * 다른 요청이 잠금을 쥐고 있으면 설정한 초만큼만 기다린 뒤 원본으로 돌아간다 — 기본값 10초가 아니라 설정값이 대기에 쓰인다.
     *
     * @scenario serving_point=core_attachment, request_param=size
     *
     * @effects thumbnail_lock_wait_configurable
     *
     * @return void 반환값 없음
     */
    public function test_configured_wait_is_used_for_the_generation_lock(): void
    {
        Config::set('core.thumbnail.lock_wait_seconds', 1);

        $path = '2026/11/05/'.Str::uuid().'.jpg';
        Storage::disk('attachments')->put($path, (string) file_get_contents(TestImages::solid(800, 600, 'jpeg')));
        $source = new ThumbnailSource('attachments', $path, 'image/jpeg', true, 800, 600);
        $spec = app(ThumbnailPresetRegistry::class)->find('sm');
        $engine = app(ImageProcessorResolver::class)->resolve();
        $this->assertNotNull($spec);
        $this->assertNotNull($engine);

        $store = app(ThumbnailStore::class);
        $key = $store->key($source, $spec, $engine);
        $lock = Cache::lock('thumb:'.md5($store->diskFor(true).'|'.$key), 30);
        $this->assertTrue($lock->get(), '시험 잠금을 잡지 못했다');

        try {
            $started = microtime(true);
            $result = app(ThumbnailGenerator::class)->prepare($source, $spec);
            $elapsed = microtime(true) - $started;
        } finally {
            $lock->release();
        }

        $this->assertSame('bypass', $result->status);
        $this->assertSame('error', $result->reason);
        // 잠금 재시도 간격(250ms)만큼 일찍 끝날 수 있다 — 기본값 10초(실측 약 9.9초)와 가르는 데는 충분하다
        $this->assertGreaterThanOrEqual(0.5, $elapsed, '설정한 1초 동안 기다리지 않았다');
        $this->assertLessThan(3.0, $elapsed, "설정한 1초가 아니라 {$elapsed}초를 기다렸다");
    }

    /**
     * 설정값 표 — 유지 시간(30초) 위는 30, 1 아래는 1, 숫자가 아니면 기본 10.
     *
     * @return array<string, array{0:mixed, 1:int}> 설정값과 실제 대기
     */
    public static function waitProvider(): array
    {
        return [
            '기본값' => [null, 10],
            '정상값' => [5, 5],
            '문자열 숫자' => ['3', 3],
            '유지 시간과 같음' => [30, 30],
            '유지 시간 초과' => [999, 30],
            '영' => [0, 1],
            '음수' => [-5, 1],
            '숫자 아님' => ['abc', 10],
        ];
    }

    /**
     * 대기 시간은 1초 이상, 잠금 유지 시간 이하로 묶인다 — 유지 시간보다 오래 기다리면 앞 요청의 잠금이 풀린 뒤에도
     * 같은 파생본을 또 만든다.
     *
     * @scenario serving_point=core_attachment, request_param=size
     *
     * @effects thumbnail_lock_wait_configurable
     *
     * @param  mixed  $configured  설정값
     * @param  int  $expected  실제 대기(초)
     * @return void 반환값 없음
     */
    #[DataProvider('waitProvider')]
    public function test_wait_is_clamped_between_one_second_and_the_lock_hold(mixed $configured, int $expected): void
    {
        Config::set('core.thumbnail.lock_wait_seconds', $configured);

        $this->assertSame($expected, ThumbnailGenerator::lockWaitSeconds());
        $this->assertLessThanOrEqual(ThumbnailGenerator::LOCK_SECONDS, ThumbnailGenerator::lockWaitSeconds());
    }
}
