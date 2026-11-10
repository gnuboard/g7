<?php

namespace App\Thumbnails\Engines;

use App\Support\PhpExtensionProbe;
use App\Thumbnails\Contracts\ImageProcessorInterface;
use App\Thumbnails\DTO\ThumbnailGeometry;
use App\Thumbnails\DTO\ThumbnailSpec;
use GdImage;

/**
 * GD 이미지 처리 엔진 (코어 기본)
 *
 * 업로드 축소(`ImageResizer`)가 쓰던 로더·세이버·투명도 보존·PNG 압축 환산을 이 엔진이 소유한다.
 * JPEG 은 EXIF 방향 8가지(`exif_read_data` 가 있을 때만)대로 돌리고 뒤집은 뒤 처리하고, 다시 인코딩하므로 메타데이터는 남지 않는다.
 * WebP·AVIF 출력 함수가 없는 GD 빌드는 원본 형식으로 쓴다.
 */
class GdImageProcessor implements ImageProcessorInterface
{
    /**
     * 읽을 수 있는 MIME → GD 형식
     */
    private const INPUT_FORMATS = [
        'image/jpeg' => 'jpeg',
        'image/jpg' => 'jpeg',
        'image/pjpeg' => 'jpeg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/bmp' => 'bmp',
        'image/x-ms-bmp' => 'bmp',
    ];

    /**
     * 형식 → 결과 MIME
     */
    private const OUTPUT_MIME = [
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
    ];

    /**
     * {@inheritDoc}
     */
    public function id(): string
    {
        return 'gd';
    }

    /**
     * {@inheritDoc}
     */
    public function isAvailable(): bool
    {
        return app(PhpExtensionProbe::class)->loaded('gd');
    }

    /**
     * {@inheritDoc}
     */
    public function supportsInput(string $mime): bool
    {
        $format = self::INPUT_FORMATS[strtolower($mime)] ?? null;

        return $format !== null && $this->isAvailable() && function_exists('imagecreatefrom'.$format);
    }

    /**
     * {@inheritDoc}
     */
    public function supportsOutput(string $format): bool
    {
        $format = $format === 'jpg' ? 'jpeg' : strtolower($format);

        return isset(self::OUTPUT_MIME[$format]) && $this->isAvailable() && function_exists('image'.$format);
    }

    /**
     * {@inheritDoc}
     */
    public function supportsAnimation(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function thumbnail(string $sourcePath, string $sourceMime, ThumbnailSpec $spec, string $targetPath): ?string
    {
        $inputFormat = self::INPUT_FORMATS[strtolower($sourceMime)] ?? null;

        if ($inputFormat === null || ! extension_loaded('gd') || ! is_file($sourcePath)) {
            return null;
        }

        $source = $this->load($sourcePath, $inputFormat);

        if (! $source) {
            return null;
        }

        if ($inputFormat === 'jpeg') {
            $source = $this->applyExifOrientation($source, $sourcePath);
        }

        $geometry = ThumbnailGeometry::compute(imagesx($source), imagesy($source), $spec);
        $outputFormat = $this->outputFormat($inputFormat, $spec->format);
        $target = $this->canvas($geometry->dstW, $geometry->dstH, $outputFormat);

        imagecopyresampled(
            $target, $source,
            0, 0, $geometry->srcX, $geometry->srcY,
            $geometry->dstW, $geometry->dstH,
            $geometry->srcW, $geometry->srcH
        );

        $written = $this->save($target, $targetPath, $outputFormat, $spec->effectiveQuality());

        imagedestroy($source);
        imagedestroy($target);

        return $written ? self::OUTPUT_MIME[$outputFormat] : null;
    }

    /**
     * {@inheritDoc}
     */
    public function resizeInPlace(string $path, ?string $mime, ?int $maxW, ?int $maxH, int $quality): bool
    {
        $format = self::INPUT_FORMATS[strtolower((string) $mime)] ?? null;

        // 업로드 축소는 종전 지원 형식(jpeg·png·gif·webp)만 다룬다 — 그 밖의 형식을 다시 인코딩하면
        // 축소 기능 도입 전과 다른 파일이 저장된다.
        if (! in_array($format, ['jpeg', 'png', 'gif', 'webp'], true) || ! extension_loaded('gd') || ! is_file($path)) {
            return false;
        }

        if ($maxW === null && $maxH === null) {
            return false;
        }

        $size = @getimagesize($path);

        if ($size === false) {
            return false;
        }

        [$width, $height] = [(int) $size[0], (int) $size[1]];

        if ($width <= 0 || $height <= 0) {
            return false;
        }

        $scale = min(
            $maxW !== null ? $maxW / $width : 1.0,
            $maxH !== null ? $maxH / $height : 1.0,
            1.0
        );

        if ($scale >= 1.0) {
            return false;
        }

        // 반올림으로 0 이 되는 극단 비율에서도 최소 1px 은 남긴다
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $source = $this->load($path, $format);

        if (! $source) {
            return false;
        }

        $target = $this->canvas($targetWidth, $targetHeight, $format);

        imagecopyresampled(
            $target, $source,
            0, 0, 0, 0,
            $targetWidth, $targetHeight,
            imagesx($source), imagesy($source)
        );

        $written = $this->save($target, $path, $format, max(1, min(100, $quality)));

        imagedestroy($source);
        imagedestroy($target);

        return $written;
    }

    /**
     * 파일을 GD 이미지로 읽습니다.
     *
     * @param  string  $path  파일 경로
     * @param  string  $format  GD 형식
     * @return GdImage|null 이미지 (실패하면 null)
     */
    private function load(string $path, string $format): ?GdImage
    {
        // php-ext-guard: thumbnail()·resizeInPlace() 가 extension_loaded('gd') 로 먼저 거른다
        $function = 'imagecreatefrom'.$format;

        if (! function_exists($function)) {
            return null;
        }

        $image = @$function($path);

        return $image instanceof GdImage ? $image : null;
    }

    /**
     * 결과 캔버스를 만듭니다 — PNG·GIF·WebP·AVIF 는 투명 배경으로 채운다(미처리 시 검은 배경).
     *
     * @param  int  $width  가로
     * @param  int  $height  세로
     * @param  string  $format  결과 형식
     * @return GdImage 캔버스
     */
    private function canvas(int $width, int $height, string $format): GdImage
    {
        // php-ext-guard: thumbnail()·resizeInPlace() 가 extension_loaded('gd') 로 먼저 거른다
        $target = imagecreatetruecolor($width, $height);

        if (in_array($format, ['png', 'gif', 'webp', 'avif'], true)) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        }

        return $target;
    }

    /**
     * 이미지를 형식에 맞게 씁니다.
     *
     * @param  GdImage  $image  이미지
     * @param  string  $path  대상 경로
     * @param  string  $format  형식
     * @param  int  $quality  품질 1~100
     * @return bool 성공 여부
     */
    private function save(GdImage $image, string $path, string $format, int $quality): bool
    {
        // php-ext-guard: thumbnail()·resizeInPlace() 가 extension_loaded('gd') 로 먼저 거른다
        return (bool) match ($format) {
            'jpeg' => imagejpeg($image, $path, $quality),
            // PNG 압축 레벨은 0~9 이고 값이 클수록 더 압축된다 — 품질(1~100)과 방향이 반대다
            'png' => imagepng($image, $path, (int) round((100 - $quality) / 100 * 9)),
            'gif' => imagegif($image, $path),
            'webp' => imagewebp($image, $path, $quality),
            'avif' => function_exists('imageavif') ? imageavif($image, $path, $quality) : false,
            default => false,
        };
    }

    /**
     * 결과 형식을 정합니다 — 규격이 WebP·AVIF 를 원해도 이 GD 빌드가 쓸 수 없으면 원본 형식.
     *
     * @param  string  $inputFormat  원본 형식
     * @param  string  $requested  규격 형식 (original·webp·avif)
     * @return string 결과 형식
     */
    private function outputFormat(string $inputFormat, string $requested): string
    {
        if ($requested !== 'original' && $this->supportsOutput($requested)) {
            return $requested;
        }

        // BMP 는 썸네일로 다시 쓰면 용량만 커진다 — PNG 로 쓴다.
        if ($inputFormat === 'bmp') {
            return 'png';
        }

        // AVIF 원본인데 출력 함수가 없으면 PNG 로 쓴다(투명도 보존).
        if (! $this->supportsOutput($inputFormat)) {
            return 'png';
        }

        return $inputFormat;
    }

    /**
     * JPEG 의 EXIF 방향대로 돌리고 뒤집습니다 — 휴대폰 사진이 옆으로 눕거나 거울상인 썸네일이 되지 않게.
     *
     * @param  GdImage  $image  이미지
     * @param  string  $path  원본 경로 (EXIF 읽기용)
     * @return GdImage 세운 이미지
     */
    private function applyExifOrientation(GdImage $image, string $path): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        // 방향 → [시계 방향 회전 각도, 회전 뒤 뒤집기] — EXIF 규격(TIFF Orientation) 8가지.
        // 2·4·5·7 은 거울상이라 회전만으로는 세울 수 없다(5 는 시계 방향 90도 뒤 좌우, 7 은 시계 방향 90도 뒤 상하).
        [$clockwise, $flip] = match ($orientation) {
            2 => [0, IMG_FLIP_HORIZONTAL],
            3 => [180, null],
            4 => [0, IMG_FLIP_VERTICAL],
            5 => [90, IMG_FLIP_HORIZONTAL],
            6 => [90, null],
            7 => [90, IMG_FLIP_VERTICAL],
            8 => [270, null],
            default => [0, null],
        };

        if ($clockwise !== 0) {
            // php-ext-guard: 이 메서드는 thumbnail() 안에서 extension_loaded('gd') 뒤에만 불린다
            // imagerotate 의 각도는 반시계 방향이다
            $rotated = imagerotate($image, -$clockwise, 0);

            if (! $rotated instanceof GdImage) {
                return $image;
            }

            imagedestroy($image);
            $image = $rotated;
        }

        if ($flip !== null) {
            // php-ext-guard: 이 메서드는 thumbnail() 안에서 extension_loaded('gd') 뒤에만 불린다
            imageflip($image, $flip);
        }

        return $image;
    }
}
