<?php

namespace Tests\Unit\Thumbnails;

use App\Support\PhpExtensionProbe;
use App\Thumbnails\DTO\ThumbnailSpec;
use App\Thumbnails\Engines\ImagickImageProcessor;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Thumbnails\Support\TestImages;

/**
 * Imagick 썸네일 엔진 테스트 — imagick 확장이 있는 PHP 에서만 돈다(개발 PC 는 WSL php8.3-imagick).
 *
 * 앱을 띄우지 않는 순수 PHPUnit 테스트다. Windows PHPUnit(테스트 DB 사용)과 겹쳐 돌려도 DB 를 건드리지 않게
 * 컨테이너에 엔진이 쓰는 설정·확장 판정·로그만 넣는다. 결과 크기 계산은 GD 와 같은 `ThumbnailGeometry` 라
 * 엔진을 바꿔도 화면 배치가 같아야 한다 — 같은 표를 GD 시험과 나란히 잰다.
 */
#[RequiresPhpExtension('imagick')]
class ImagickImageProcessorTest extends TestCase
{
    private ImagickImageProcessor $engine;

    private ?Container $previous = null;

    /**
     * 최소 컨테이너(설정·확장 판정·로그)를 세우고 엔진을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['attachment' => ['image_quality' => 85]]));
        $container->instance(PhpExtensionProbe::class, new PhpExtensionProbe);
        $container->instance('log', new NullLogger);
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        $this->engine = new ImagickImageProcessor;
    }

    /**
     * 임시 이미지를 지우고 컨테이너를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        TestImages::cleanup();
        Facade::clearResolvedInstances();
        Container::setInstance($this->previous);

        parent::tearDown();
    }

    /**
     * 규격 하나를 만듭니다.
     *
     * @param  array<string, mixed>  $row  규격 행
     * @return ThumbnailSpec 규격
     */
    private function spec(array $row): ThumbnailSpec
    {
        return ThumbnailSpec::fromArray($row + ['name' => 'test', 'source' => 'custom']);
    }

    /**
     * 썸네일을 만들어 [결과 경로, 결과 MIME] 을 돌려줍니다.
     *
     * @param  string  $source  원본 경로
     * @param  string  $mime  원본 MIME
     * @param  ThumbnailSpec  $spec  규격
     * @param  string  $extension  결과 확장자
     * @return array{0:string,1:?string} 결과
     */
    private function make(string $source, string $mime, ThumbnailSpec $spec, string $extension = 'png'): array
    {
        $target = TestImages::tempPath($extension);

        return [$target, $this->engine->thumbnail($source, $mime, $spec, $target)];
    }

    /**
     * 맞춤 3종의 결과 크기가 GD 와 같은 계산을 따른다.
     *
     * @effects fit_produces_declared_geometry
     *
     * @return void 반환값 없음
     */
    public function test_fit_geometry_matches_the_shared_calculation(): void
    {
        $this->assertSame('imagick', $this->engine->id());
        $this->assertTrue($this->engine->isAvailable());

        $source = TestImages::solid(400, 200);

        [$cover, $mime] = $this->make($source, 'image/png', $this->spec(['width' => 100, 'height' => 100, 'fit' => 'cover']));
        $this->assertSame('image/png', $mime);
        $this->assertSame([100, 100], TestImages::size($cover));

        [$contain] = $this->make($source, 'image/png', $this->spec(['width' => 100, 'height' => 100, 'fit' => 'contain']));
        $this->assertSame([100, 50], TestImages::size($contain));

        $jpeg = TestImages::solid(1280, 720, 'jpeg');
        [$width, $jpegMime] = $this->make($jpeg, 'image/jpeg', $this->spec(['width' => 640, 'fit' => 'width']), 'jpg');
        $this->assertSame('image/jpeg', $jpegMime);
        $this->assertSame([640, 360], TestImages::size($width));
    }

    /**
     * 자르기 기준점 — 왼쪽·오른쪽·위·아래가 원본의 그 쪽을 남긴다.
     *
     * @effects anchor_keeps_declared_side
     *
     * @return void 반환값 없음
     */
    public function test_anchor_keeps_the_declared_side(): void
    {
        $red = [255, 0, 0];
        $blue = [0, 0, 255];
        $vertical = TestImages::halves(400, 200, 'vertical', $red, $blue);
        $horizontal = TestImages::halves(200, 400, 'horizontal', $red, $blue);

        $cases = [
            ['left', $vertical, [50, 50], $red],
            ['right', $vertical, [50, 50], $blue],
            ['center', $vertical, [10, 50], $red],
            ['center', $vertical, [90, 50], $blue],
            ['top', $horizontal, [50, 50], $red],
            ['bottom', $horizontal, [50, 50], $blue],
        ];

        foreach ($cases as [$anchor, $source, [$x, $y], $expected]) {
            [$target] = $this->make($source, 'image/png', $this->spec(['width' => 100, 'height' => 100, 'fit' => 'cover', 'anchor' => $anchor]));

            $this->assertSame([100, 100], TestImages::size($target), $anchor);
            $this->assertSame($expected, array_slice(TestImages::pixel($target, $x, $y), 0, 3), "{$anchor} ({$x},{$y})");
        }
    }

    /**
     * 투명 PNG 모서리는 결과에서도 투명하다.
     *
     * @effects transparency_preserved
     *
     * @return void 반환값 없음
     */
    public function test_transparent_png_keeps_transparent_corners(): void
    {
        $source = TestImages::transparentPng(200, 200);

        [$target] = $this->make($source, 'image/png', $this->spec(['width' => 50, 'height' => 50, 'fit' => 'cover']));

        $this->assertSame(127, TestImages::pixel($target, 0, 0)[3]);
        $this->assertSame(127, TestImages::pixel($target, 49, 49)[3]);
        $this->assertSame(0, TestImages::pixel($target, 25, 25)[3]);
    }

    /**
     * EXIF 방향을 따라 세운 뒤 메타데이터를 지운다(`autoOrient`·`stripImage`).
     *
     * @effects exif_orientation_applied
     *
     * @return void 반환값 없음
     */
    public function test_exif_orientation_is_applied_and_stripped(): void
    {
        $source = TestImages::jpegWithOrientation(300, 100, 6);

        [$target] = $this->make($source, 'image/jpeg', $this->spec(['width' => 50, 'fit' => 'width']), 'jpg');

        $this->assertSame([50, 150], TestImages::size($target));

        if (function_exists('exif_read_data')) {
            $this->assertSame(0, (int) (@exif_read_data($target)['Orientation'] ?? 0), '방향 태그가 남아 브라우저가 한 번 더 돌린다');
        }
    }

    /**
     * EXIF 방향 8가지 사례 (GD 시험과 같은 표)
     *
     * @return array<string, array{0:int,1:list<string>,2:bool}> 방향 사례
     */
    public static function exifOrientationProvider(): array
    {
        return TestImages::exifOrientationCases();
    }

    /**
     * 방향 8가지 모두 세운 뒤의 크기와 네 사분면 색 위치가 GD 엔진과 같다 — 엔진을 바꿔도 사진 방향이 같아야 한다.
     *
     * @effects exif_orientation_all_eight_placed
     *
     * @param  int  $orientation  EXIF 방향 값
     * @param  list<string>  $layout  세운 뒤 [왼쪽 위, 오른쪽 위, 왼쪽 아래, 오른쪽 아래] 색
     * @param  bool  $swapsAxes  가로세로가 바뀌는지
     * @return void 반환값 없음
     */
    #[DataProvider('exifOrientationProvider')]
    public function test_every_exif_orientation_lands_quadrants_in_place(int $orientation, array $layout, bool $swapsAxes): void
    {
        $source = TestImages::jpegQuadrantsWithOrientation(120, 80, $orientation);

        [$target] = $this->make($source, 'image/jpeg', $this->spec(['width' => 60, 'fit' => 'width']), 'jpg');

        $this->assertSame($swapsAxes ? [60, 90] : [60, 40], TestImages::size($target));
        $this->assertSame($layout, TestImages::quadrantLayout($target), "방향 {$orientation} 의 사분면 배치가 어긋났다");
    }

    /**
     * WebP 형식 규격은 WebP 로 쓴다. 움직이는 GIF 는 첫 프레임만 쓴다(서빙 단계가 먼저 원본으로 거르지만 엔진도 안전).
     *
     * @effects output_format_honoured_or_falls_back, animated_gif_detected
     *
     * @return void 반환값 없음
     */
    public function test_webp_output_and_first_frame_of_multi_frame_gif(): void
    {
        if ($this->engine->supportsOutput('webp')) {
            [$webp, $mime] = $this->make(TestImages::solid(200, 200), 'image/png', $this->spec(['width' => 64, 'height' => 64, 'fit' => 'cover', 'format' => 'webp']), 'webp');
            $this->assertSame('image/webp', $mime);
            $this->assertSame('image/webp', TestImages::mime($webp));
        }

        $this->assertTrue($this->engine->supportsAnimation());

        [$gif, $gifMime] = $this->make(TestImages::gif(3), 'image/gif', $this->spec(['width' => 1, 'height' => 1, 'fit' => 'cover', 'upscale' => true]), 'gif');
        $this->assertSame('image/gif', $gifMime);
        $this->assertSame(1, (new \Imagick($gif))->getNumberImages(), '결과에 프레임이 여럿 남았다');
    }

    /**
     * 「움직이는 이미지는 원본」을 끈 경우 — 움직이는 GIF·WebP·APNG 모두 첫 프레임으로 규격 크기의 한 장짜리 썸네일을 만든다
     * (GD 는 움직이는 WebP 를 읽지 못하지만 Imagick 은 읽는다).
     *
     * @effects animated_original_configurable
     *
     * @return void 반환값 없음
     */
    public function test_first_frame_thumbnail_of_animated_gif_webp_and_apng(): void
    {
        $spec = $this->spec(['width' => 160, 'height' => 160, 'fit' => 'cover']);
        $sources = [
            'gif' => [TestImages::fullFrameGif(3), 'image/gif'],
            'png' => [TestImages::apng(3), 'image/png'],
        ];

        if ($this->engine->supportsInput('image/webp')) {
            $sources['webp'] = [TestImages::animatedWebp(3), 'image/webp'];
        }

        foreach ($sources as $format => [$path, $mime]) {
            [$target, $resultMime] = $this->make($path, $mime, $spec, $format);
            $this->assertSame($mime, $resultMime, $format);
            $this->assertSame([160, 160], TestImages::size($target), $format);
            $this->assertSame(1, (new \Imagick($target))->getNumberImages(), "{$format} 결과에 프레임이 여럿 남았다");
        }
    }

    /**
     * 업로드 축소 — 상한을 넘으면 비율대로, 아니면 손대지 않는다.
     *
     * @effects upload_resize_shares_engine
     *
     * @return void 반환값 없음
     */
    public function test_resize_in_place(): void
    {
        $large = TestImages::solid(1600, 1200);
        $this->assertTrue($this->engine->resizeInPlace($large, 'image/png', 800, 600, 80));
        $this->assertSame([800, 600], TestImages::size($large));

        $small = TestImages::solid(100, 100);
        $this->assertFalse($this->engine->resizeInPlace($small, 'image/png', 800, 600, 80));
        $this->assertFalse($this->engine->resizeInPlace($large, 'image/png', null, null, 80));
        $this->assertFalse($this->engine->resizeInPlace($large, 'image/bmp', 10, 10, 80));
        $this->assertSame([800, 600], TestImages::size($large));
    }
}
