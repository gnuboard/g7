<?php

namespace Tests\Unit\Thumbnails;

use App\Thumbnails\DTO\ThumbnailSpec;
use App\Thumbnails\Engines\GdImageProcessor;
use App\Thumbnails\ThumbnailGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Thumbnails\Support\TestImages;

/**
 * GD 썸네일 엔진 테스트
 *
 * 맞춤 3종(자르기·맞추기·가로만)과 자르기 기준점 5종이 결과 크기·잘린 영역을 규격대로 내는지,
 * 투명 PNG 의 투명 모서리가 검게 바뀌지 않는지, EXIF 방향을 따라 세우는지, WebP·AVIF 를 못 쓰는
 * 서버에서 원본 형식으로 물러서는지, 움직이는 GIF 판정과 업로드 축소(`resizeInPlace`)를 잰다.
 */
class GdImageProcessorTest extends TestCase
{
    private GdImageProcessor $engine;

    /**
     * 엔진을 준비하고 품질 기본값을 고정합니다 (운영자 저장값이 스며들지 않게).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['attachment.image_quality' => 85]);
        $this->engine = new GdImageProcessor;
    }

    /**
     * 테스트가 만든 임시 이미지를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        TestImages::cleanup();

        parent::tearDown();
    }

    /**
     * 규격 하나를 만듭니다.
     *
     * @param  array<string, mixed>  $row  규격 행 (name·width 필수)
     * @return ThumbnailSpec 규격
     */
    private function spec(array $row): ThumbnailSpec
    {
        return ThumbnailSpec::fromArray($row + ['name' => 'test', 'source' => 'custom']);
    }

    /**
     * 엔진으로 썸네일을 만들어 결과 파일 경로와 MIME 을 돌려줍니다.
     *
     * @param  string  $source  원본 경로
     * @param  string  $mime  원본 MIME
     * @param  ThumbnailSpec  $spec  규격
     * @param  string  $extension  결과 파일 확장자
     * @return array{0:string,1:?string} [결과 경로, 결과 MIME]
     */
    private function make(string $source, string $mime, ThumbnailSpec $spec, string $extension = 'png'): array
    {
        $target = TestImages::tempPath($extension);

        return [$target, $this->engine->thumbnail($source, $mime, $spec, $target)];
    }

    /**
     * 자르기 맞춤은 규격 크기를 꽉 채운다 — 가로로 긴 원본도 정사각형 결과.
     *
     * @effects fit_produces_declared_geometry
     *
     * @return void 반환값 없음
     */
    public function test_cover_fills_the_exact_box(): void
    {
        $source = TestImages::solid(400, 200);

        [$target, $mime] = $this->make($source, 'image/png', $this->spec(['width' => 100, 'height' => 100, 'fit' => 'cover']));

        $this->assertSame('image/png', $mime);
        $this->assertSame([100, 100], TestImages::size($target));
    }

    /**
     * 맞추기는 상자 안에 전체가 들어가게 줄인다 — 비율을 지킨 채 긴 쪽이 상자에 닿는다.
     *
     * @effects fit_produces_declared_geometry
     *
     * @return void 반환값 없음
     */
    public function test_contain_keeps_ratio_inside_the_box(): void
    {
        $source = TestImages::solid(400, 200);

        [$target] = $this->make($source, 'image/png', $this->spec(['width' => 100, 'height' => 100, 'fit' => 'contain']));

        $this->assertSame([100, 50], TestImages::size($target));
    }

    /**
     * 가로만 맞춤은 세로를 비율대로 둔다.
     *
     * @effects fit_produces_declared_geometry
     *
     * @return void 반환값 없음
     */
    public function test_width_fit_scales_height_by_ratio(): void
    {
        $source = TestImages::solid(1280, 720, 'jpeg');

        [$target, $mime] = $this->make($source, 'image/jpeg', $this->spec(['width' => 640, 'fit' => 'width']), 'jpg');

        $this->assertSame('image/jpeg', $mime);
        $this->assertSame([640, 360], TestImages::size($target));
    }

    /**
     * 확대를 끈 규격은 작은 원본을 키우지 않는다(엔진 단계에서도 배율 1 상한).
     *
     * @effects fit_produces_declared_geometry
     *
     * @return void 반환값 없음
     */
    public function test_small_source_is_not_upscaled_when_upscale_is_off(): void
    {
        $source = TestImages::solid(100, 50);

        [$target] = $this->make($source, 'image/png', $this->spec(['width' => 640, 'fit' => 'width']));

        $this->assertSame([100, 50], TestImages::size($target));
    }

    /**
     * 기준점 표 — 원본을 두 색으로 반씩 칠하고 잘린 결과의 색으로 어느 쪽을 남겼는지 잰다.
     *
     * @return array<string, array{0:string,1:string,2:array{0:int,1:int},3:array{0:int,1:int,2:int},4:array{0:int,1:int},5:array{0:int,1:int,2:int}}> 기준점, 나눔, 점1, 기대색1, 점2, 기대색2
     */
    public static function anchorProvider(): array
    {
        $red = [255, 0, 0];
        $blue = [0, 0, 255];

        return [
            // 400×200 왼쪽 빨강/오른쪽 파랑 → 100×100: 200×200 을 잘라낸다
            'left' => ['left', 'vertical', [50, 50], $red, [95, 50], $red],
            'right' => ['right', 'vertical', [5, 50], $blue, [50, 50], $blue],
            'center' => ['center', 'vertical', [10, 50], $red, [90, 50], $blue],
            // 200×400 위 빨강/아래 파랑
            'top' => ['top', 'horizontal', [50, 50], $red, [50, 95], $red],
            'bottom' => ['bottom', 'horizontal', [50, 5], $blue, [50, 50], $blue],
        ];
    }

    /**
     * 자르기 기준점 5종이 원본의 해당 쪽을 남긴다.
     *
     * @effects anchor_keeps_declared_side
     *
     * @param  string  $anchor  기준점
     * @param  string  $split  원본 나눔 방향
     * @param  array{0:int,1:int}  $p1  잴 점 1
     * @param  array{0:int,1:int,2:int}  $c1  점 1 기대색
     * @param  array{0:int,1:int}  $p2  잴 점 2
     * @param  array{0:int,1:int,2:int}  $c2  점 2 기대색
     * @return void 반환값 없음
     */
    #[DataProvider('anchorProvider')]
    public function test_anchor_keeps_the_declared_side(string $anchor, string $split, array $p1, array $c1, array $p2, array $c2): void
    {
        [$w, $h] = $split === 'vertical' ? [400, 200] : [200, 400];
        $source = TestImages::halves($w, $h, $split, [255, 0, 0], [0, 0, 255]);

        [$target] = $this->make($source, 'image/png', $this->spec(['width' => 100, 'height' => 100, 'fit' => 'cover', 'anchor' => $anchor]));

        $this->assertSame([100, 100], TestImages::size($target));
        $this->assertSame($c1, array_slice(TestImages::pixel($target, ...$p1), 0, 3), "{$anchor} 점1");
        $this->assertSame($c2, array_slice(TestImages::pixel($target, ...$p2), 0, 3), "{$anchor} 점2");
    }

    /**
     * 투명 PNG 의 투명 모서리는 결과에서도 완전 투명(알파 127)이다 — 검은 배경으로 채워지지 않는다.
     *
     * @effects transparency_preserved
     *
     * @return void 반환값 없음
     */
    public function test_transparent_png_keeps_fully_transparent_corners(): void
    {
        $source = TestImages::transparentPng(200, 200);

        [$target, $mime] = $this->make($source, 'image/png', $this->spec(['width' => 50, 'height' => 50, 'fit' => 'cover']));

        $this->assertSame('image/png', $mime);

        foreach ([[0, 0], [49, 0], [0, 49], [49, 49]] as [$x, $y]) {
            $this->assertSame(127, TestImages::pixel($target, $x, $y)[3], "모서리 ({$x},{$y}) 가 투명하지 않다");
        }

        $this->assertSame(0, TestImages::pixel($target, 25, 25)[3], '가운데 불투명 영역이 사라졌다');
    }

    /**
     * EXIF 방향 6(시계 방향 90도)을 단 JPEG 은 세워서 줄인다 — 저장 픽셀 그대로면 가로로 누운 썸네일이 된다.
     *
     * @effects exif_orientation_applied
     *
     * @return void 반환값 없음
     */
    public function test_exif_orientation_is_applied_before_resizing(): void
    {
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('exif 확장이 없는 서버 — 엔진은 회전 없이 처리한다');
        }

        $source = TestImages::jpegWithOrientation(300, 100, 6);
        $this->assertSame(6, (int) (@exif_read_data($source)['Orientation'] ?? 0), '시험 원본에 방향 태그가 실리지 않았다');

        [$target] = $this->make($source, 'image/jpeg', $this->spec(['width' => 50, 'fit' => 'width']), 'jpg');

        $this->assertSame([50, 150], TestImages::size($target));
        // 재인코딩은 메타데이터를 남기지 않는다 — 방향 태그가 남으면 브라우저가 한 번 더 돌린다.
        $this->assertSame(0, (int) (@exif_read_data($target)['Orientation'] ?? 0));
    }

    /**
     * EXIF 방향 8가지 모두 세운 뒤의 사분면 배치가 규격대로다 — 뒤집기가 든 2·4·5·7 을 회전만으로 처리하면
     * 좌우나 상하가 거울상인 썸네일이 된다.
     *
     * @return array<string, array{0:int,1:list<string>,2:bool}> 방향 사례
     */
    public static function exifOrientationProvider(): array
    {
        return TestImages::exifOrientationCases();
    }

    /**
     * 방향 하나를 단 사분면 JPEG 을 줄여 크기와 네 사분면 색 위치를 잰다.
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
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('exif 확장이 없는 서버 — 엔진은 회전 없이 처리한다');
        }

        $source = TestImages::jpegQuadrantsWithOrientation(120, 80, $orientation);
        $this->assertSame($orientation, (int) (@exif_read_data($source)['Orientation'] ?? 0), '시험 원본에 방향 태그가 실리지 않았다');

        [$target] = $this->make($source, 'image/jpeg', $this->spec(['width' => 60, 'fit' => 'width']), 'jpg');

        $this->assertSame($swapsAxes ? [60, 90] : [60, 40], TestImages::size($target));
        $this->assertSame($layout, TestImages::quadrantLayout($target), "방향 {$orientation} 의 사분면 배치가 어긋났다");
    }

    /**
     * WebP·AVIF 형식 규격은 그 형식으로 쓴다(함수가 있는 서버).
     *
     * @effects output_format_honoured_or_falls_back
     *
     * @return void 반환값 없음
     */
    public function test_webp_and_avif_formats_are_written_when_supported(): void
    {
        $source = TestImages::solid(200, 200);

        [$webp, $webpMime] = $this->make($source, 'image/png', $this->spec(['width' => 64, 'height' => 64, 'fit' => 'cover', 'format' => 'webp']), 'webp');
        $this->assertSame('image/webp', $webpMime);
        $this->assertSame('image/webp', TestImages::mime($webp));

        if (! function_exists('imageavif')) {
            return;
        }

        [$avif, $avifMime] = $this->make($source, 'image/png', $this->spec(['width' => 64, 'height' => 64, 'fit' => 'cover', 'format' => 'avif']), 'avif');
        $this->assertSame('image/avif', $avifMime);
        $this->assertGreaterThan(0, filesize($avif));
    }

    /**
     * 출력 함수가 없는 서버(WebP·AVIF 미지원)는 원본 형식으로 물러선다 — 빈 파일이나 실패가 아니다.
     *
     * @effects output_format_honoured_or_falls_back
     *
     * @return void 반환값 없음
     */
    public function test_unsupported_output_format_falls_back_to_the_original_format(): void
    {
        $engine = new class extends GdImageProcessor
        {
            /**
             * WebP·AVIF 출력 함수가 없는 GD 빌드를 흉내 냅니다.
             *
             * @param  string  $format  출력 형식
             * @return bool 그 형식으로 쓸 수 있으면 true
             */
            public function supportsOutput(string $format): bool
            {
                return ! in_array(strtolower($format), ['webp', 'avif'], true) && parent::supportsOutput($format);
            }
        };

        $source = TestImages::solid(200, 200, 'jpeg');

        foreach (['webp', 'avif'] as $format) {
            $target = TestImages::tempPath('jpg');
            $mime = $engine->thumbnail($source, 'image/jpeg', $this->spec(['width' => 64, 'height' => 64, 'fit' => 'cover', 'format' => $format]), $target);

            $this->assertSame('image/jpeg', $mime, "{$format} 미지원이면 원본 형식");
            $this->assertSame('image/jpeg', TestImages::mime($target));
        }
    }

    /**
     * 움직이는 GIF 판정 — 프레임 둘 이상이면 움직인다. 반복 블록이 없는 2프레임 GIF 와 청크 경계 뒤 프레임도 잡는다.
     *
     * @effects animated_gif_detected
     *
     * @return void 반환값 없음
     */
    public function test_animated_gif_detection(): void
    {
        $this->assertTrue(ThumbnailGenerator::isAnimatedGif(TestImages::gif(3)), '3프레임');
        $this->assertTrue(ThumbnailGenerator::isAnimatedGif(TestImages::gif(2)), '반복 블록 있는 2프레임');
        $this->assertTrue(ThumbnailGenerator::isAnimatedGif(TestImages::gif(2, loopExtension: false)), '반복 블록 없는 2프레임(한 번 재생)');
        $this->assertTrue(ThumbnailGenerator::isAnimatedGif(TestImages::gif(2, padTo: 300 * 1024)), '청크 경계 뒤 2프레임');

        $this->assertFalse(ThumbnailGenerator::isAnimatedGif(TestImages::gif(1)), '1프레임');
        $this->assertFalse(ThumbnailGenerator::isAnimatedGif(TestImages::gif(1, loopExtension: false)), '반복 블록 없는 1프레임');
        $this->assertFalse(ThumbnailGenerator::isAnimatedGif(TestImages::solid(50, 50, 'gif')), 'GD 가 쓴 정지 GIF');
        $this->assertFalse(ThumbnailGenerator::isAnimatedGif(TestImages::tempPath('gif')), '없는 파일');
    }

    /**
     * 움직이는 WebP·PNG(APNG) 판정 — 프레임 둘 이상이면 움직인다. 형식은 MIME 이 아니라 파일 머리로 가린다.
     *
     * @effects animated_webp_apng_detected
     *
     * @return void 반환값 없음
     */
    public function test_animated_webp_and_apng_detection(): void
    {
        $this->assertTrue(ThumbnailGenerator::isAnimatedWebp(TestImages::animatedWebp(2)), 'WebP 2프레임');
        $this->assertFalse(ThumbnailGenerator::isAnimatedWebp(TestImages::animatedWebp(1)), '애니메이션 표시만 있는 WebP 1프레임');
        $this->assertFalse(ThumbnailGenerator::isAnimatedWebp(TestImages::solid(50, 50, 'webp')), 'GD 가 쓴 정지 WebP');
        $this->assertTrue(ThumbnailGenerator::isAnimatedPng(TestImages::apng(2)), 'APNG 2프레임');
        $this->assertFalse(ThumbnailGenerator::isAnimatedPng(TestImages::apng(1)), 'APNG 1프레임');
        $this->assertFalse(ThumbnailGenerator::isAnimatedPng(TestImages::solid(50, 50, 'png')), '정지 PNG');
        $this->assertFalse(ThumbnailGenerator::isAnimatedPng(TestImages::tempPath('png')), '없는 파일');

        $this->assertSame('gif', ThumbnailGenerator::animatedFormat(TestImages::gif(3)));
        $this->assertTrue(ThumbnailGenerator::isAnimatedGif(TestImages::fullFrameGif(2)), '실제 크기 프레임 GIF');
        $this->assertSame('webp', ThumbnailGenerator::animatedFormat(TestImages::animatedWebp(3)));
        $this->assertSame('png', ThumbnailGenerator::animatedFormat(TestImages::apng(3)));
        $this->assertNull(ThumbnailGenerator::animatedFormat(TestImages::solid(50, 50, 'jpeg')));
        $this->assertNull(ThumbnailGenerator::animatedFormat(TestImages::solid(50, 50, 'webp')));
    }

    /**
     * 프레임 하나가 읽기 청크 경계에 걸쳐도 두 번 세지 않는다 — 정지 GIF 가 움직이는 것으로 판정되면 썸네일을 잃는다.
     *
     * @effects animated_gif_detected
     *
     * @return void 반환값 없음
     */
    public function test_single_frame_near_the_chunk_boundary_is_not_counted_twice(): void
    {
        $chunk = 1024 * 100;

        // 프레임 GCE 가 첫 청크의 마지막 20바이트 안에 통째로 들어가게 앞을 0 으로 채운다
        // (판정은 바이트 패턴만 보므로 채움이 유효한 GIF 블록일 필요는 없다)
        foreach ([12, 15, 18] as $tail) {
            $header = 'GIF89a'."\x01\x00\x01\x00"."\x00\x00\x00";
            $frame = "\x21\xF9\x04\x00\x0A\x00\x00\x00"."\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00"."\x02\x02\x44\x01\x00";
            $bytes = $header.str_repeat("\x00", $chunk - $tail - strlen($header)).$frame."\x3B";
            $path = TestImages::tempPath('gif');
            file_put_contents($path, $bytes);

            $this->assertFalse(ThumbnailGenerator::isAnimatedGif($path), "프레임 시작이 청크 끝 {$tail}바이트 앞");
        }
    }

    /**
     * 업로드 축소 — 상한을 넘으면 비율대로 줄이고, 상한이 없거나 이미 작으면 손대지 않는다.
     *
     * @effects upload_resize_shares_engine
     *
     * @return void 반환값 없음
     */
    public function test_resize_in_place_shrinks_only_when_over_bounds(): void
    {
        $large = TestImages::solid(1600, 1200);
        $this->assertTrue($this->engine->resizeInPlace($large, 'image/png', 800, 600, 80));
        $this->assertSame([800, 600], TestImages::size($large));

        $wide = TestImages::solid(2000, 500, 'jpeg');
        $this->assertTrue($this->engine->resizeInPlace($wide, 'image/jpeg', 1000, null, 80));
        $this->assertSame([1000, 250], TestImages::size($wide));

        $small = TestImages::solid(100, 100);
        $before = md5_file($small);
        $this->assertFalse($this->engine->resizeInPlace($small, 'image/png', 800, 600, 80));
        $this->assertFalse($this->engine->resizeInPlace($large, 'image/png', null, null, 80));
        $this->assertSame($before, md5_file($small), '작은 원본을 다시 인코딩했다');

        // 종전 지원 형식 밖(BMP·AVIF)은 다시 쓰지 않는다
        $this->assertFalse($this->engine->resizeInPlace($large, 'image/bmp', 10, 10, 80));
        $this->assertFalse($this->engine->resizeInPlace($large, 'image/svg+xml', 10, 10, 80));
    }

    /**
     * 투명 PNG 업로드 축소도 투명도를 지킨다.
     *
     * @effects transparency_preserved, upload_resize_shares_engine
     *
     * @return void 반환값 없음
     */
    public function test_resize_in_place_keeps_png_transparency(): void
    {
        $path = TestImages::transparentPng(400, 400);

        $this->assertTrue($this->engine->resizeInPlace($path, 'image/png', 100, 100, 90));
        $this->assertSame([100, 100], TestImages::size($path));
        $this->assertSame(127, TestImages::pixel($path, 0, 0)[3]);
    }

    /**
     * 입력·출력 지원 판정 — SVG 는 입력이 아니고, jpg 별칭은 jpeg 출력으로 본다.
     *
     * @effects non_raster_bypassed
     *
     * @return void 반환값 없음
     */
    public function test_input_and_output_support_matrix(): void
    {
        $this->assertSame('gd', $this->engine->id());
        $this->assertTrue($this->engine->isAvailable());
        $this->assertFalse($this->engine->supportsAnimation());

        foreach (['image/jpeg', 'image/png', 'image/gif', 'image/webp'] as $mime) {
            $this->assertTrue($this->engine->supportsInput($mime), $mime);
        }

        $this->assertFalse($this->engine->supportsInput('image/svg+xml'));
        $this->assertFalse($this->engine->supportsInput('application/pdf'));
        $this->assertTrue($this->engine->supportsOutput('jpg'));
        $this->assertTrue($this->engine->supportsOutput('png'));
        $this->assertFalse($this->engine->supportsOutput('bmp'));
        $this->assertNull($this->engine->thumbnail(TestImages::solid(10, 10), 'image/svg+xml', $this->spec(['width' => 5]), TestImages::tempPath('png')));
    }
}
