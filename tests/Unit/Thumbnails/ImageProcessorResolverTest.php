<?php

namespace Tests\Unit\Thumbnails;

use App\Extension\HookManager;
use App\Thumbnails\Contracts\ImageProcessorInterface;
use App\Thumbnails\DTO\ThumbnailSpec;
use App\Thumbnails\Engines\GdImageProcessor;
use App\Thumbnails\Engines\ImagickImageProcessor;
use App\Thumbnails\ImageProcessorResolver;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * 이미지 처리 엔진 선택기와 확장 필터 `core.thumbnail.filter_processors` 테스트
 *
 * 확장은 이 필터로 `[엔진 id => ImageProcessorInterface]` 를 넘겨 엔진을 더하거나 바꾼다. 구독한 엔진은 설정
 * (`core.image.driver`)이 고르면 쓰이고, 필터가 잘못된 값을 돌려주면 코어 엔진으로 돌아가야 한다 — 그렇지 않으면
 * 확장 하나의 실수로 썸네일과 업로드 축소가 모두 꺼진다.
 */
class ImageProcessorResolverTest extends TestCase
{
    /** 필터 이름 */
    private const FILTER = 'core.thumbnail.filter_processors';

    /**
     * 필터 구독을 비우고 GD 를 기본 엔진으로 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        HookManager::clearFilter(self::FILTER);
        Config::set('core.image.driver', 'gd');

        if (! (new GdImageProcessor)->isAvailable()) {
            $this->markTestSkipped('GD 확장이 없는 서버 — 기본 엔진 복귀를 잴 수 없다');
        }
    }

    /**
     * 시험이 남긴 필터 구독을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(self::FILTER);

        parent::tearDown();
    }

    /**
     * 확장 엔진 하나를 만듭니다 (픽셀은 다루지 않고 선택 여부만 잰다).
     *
     * @param  string  $id  엔진 id
     * @param  bool  $available  이 서버에서 쓸 수 있는지
     * @return ImageProcessorInterface 엔진
     */
    private function extensionEngine(string $id, bool $available = true): ImageProcessorInterface
    {
        return new class($id, $available) implements ImageProcessorInterface
        {
            /**
             * 엔진 id 와 사용 가능 여부를 받습니다.
             *
             * @param  string  $engineId  엔진 id
             * @param  bool  $available  사용 가능 여부
             */
            public function __construct(private string $engineId, private bool $available) {}

            /** {@inheritDoc} */
            public function id(): string
            {
                return $this->engineId;
            }

            /** {@inheritDoc} */
            public function isAvailable(): bool
            {
                return $this->available;
            }

            /** {@inheritDoc} */
            public function supportsInput(string $mime): bool
            {
                return true;
            }

            /** {@inheritDoc} */
            public function supportsOutput(string $format): bool
            {
                return true;
            }

            /** {@inheritDoc} */
            public function supportsAnimation(): bool
            {
                return false;
            }

            /** {@inheritDoc} */
            public function thumbnail(string $sourcePath, string $sourceMime, ThumbnailSpec $spec, string $targetPath): ?string
            {
                return null;
            }

            /** {@inheritDoc} */
            public function resizeInPlace(string $path, ?string $mime, ?int $maxW, ?int $maxH, int $quality): bool
            {
                return false;
            }
        };
    }

    /**
     * 필터로 넘긴 확장 엔진을 설정이 고르면 그 엔진을 쓰고, 코어 엔진 둘도 목록에 남는다.
     *
     * @effects extension_processor_used_via_filter
     *
     * @return void 반환값 없음
     */
    public function test_subscribed_engine_is_used_when_selected(): void
    {
        $engine = $this->extensionEngine('vips');
        HookManager::addFilter(self::FILTER, fn (array $processors) => $processors + ['vips' => $engine]);
        Config::set('core.image.driver', 'vips');

        $resolver = app(ImageProcessorResolver::class);

        $this->assertSame($engine, $resolver->resolve());
        $this->assertSame(['gd', 'imagick', 'vips'], array_keys($resolver->processors()));
    }

    /**
     * 구독자가 코어 id(`gd`)를 자기 엔진으로 바꾸면 그 엔진이 기본 엔진이 된다.
     *
     * @effects extension_processor_used_via_filter
     *
     * @return void 반환값 없음
     */
    public function test_subscriber_can_replace_a_core_engine(): void
    {
        $engine = $this->extensionEngine('gd');
        HookManager::addFilter(self::FILTER, fn (array $processors) => ['gd' => $engine] + $processors);

        $this->assertSame($engine, app(ImageProcessorResolver::class)->resolve());
    }

    /**
     * 고른 확장 엔진을 이 서버에서 쓸 수 없으면 GD 로 물러선다.
     *
     * @effects invalid_processor_filter_falls_back_to_core
     *
     * @return void 반환값 없음
     */
    public function test_unavailable_subscribed_engine_falls_back_to_gd(): void
    {
        HookManager::addFilter(self::FILTER, fn (array $processors) => $processors + ['vips' => $this->extensionEngine('vips', false)]);
        Config::set('core.image.driver', 'vips');

        $this->assertInstanceOf(GdImageProcessor::class, app(ImageProcessorResolver::class)->resolve());
    }

    /**
     * 필터가 배열이 아닌 값을 돌려주면 코어 엔진 목록을 그대로 쓴다.
     *
     * @effects invalid_processor_filter_falls_back_to_core
     *
     * @return void 반환값 없음
     */
    public function test_non_array_filter_result_keeps_core_engines(): void
    {
        HookManager::addFilter(self::FILTER, fn () => 'broken');

        $resolver = app(ImageProcessorResolver::class);
        $processors = $resolver->processors();

        $this->assertSame(['gd', 'imagick'], array_keys($processors));
        $this->assertInstanceOf(GdImageProcessor::class, $processors['gd']);
        $this->assertInstanceOf(ImagickImageProcessor::class, $processors['imagick']);
        $this->assertInstanceOf(GdImageProcessor::class, $resolver->resolve());
    }

    /**
     * 구독자가 코어 id 자리에 엔진이 아닌 값을 넣거나 코어 엔진을 빠뜨리면 그 자리는 코어 엔진으로 돌아간다 —
     * 종전에는 잘못된 항목을 걸러낸 뒤 `gd` 가 비어 기본 엔진 복귀 자체가 사라졌고, 썸네일과 업로드 축소가 모두 꺼졌다.
     * 엔진이 아닌 확장 항목은 버린다.
     *
     * @effects invalid_processor_filter_falls_back_to_core
     *
     * @return void 반환값 없음
     */
    public function test_invalid_entries_fall_back_to_core_engines(): void
    {
        HookManager::addFilter(self::FILTER, fn () => ['gd' => 'not-an-engine', 'vips' => new \stdClass]);
        Config::set('core.image.driver', 'vips');

        $resolver = app(ImageProcessorResolver::class);
        $processors = $resolver->processors();

        $this->assertInstanceOf(GdImageProcessor::class, $processors['gd'] ?? null);
        $this->assertInstanceOf(ImagickImageProcessor::class, $processors['imagick'] ?? null);
        $this->assertArrayNotHasKey('vips', $processors);
        $this->assertInstanceOf(GdImageProcessor::class, $resolver->resolve());
    }
}
