<?php

namespace App\Thumbnails\Engines;

use App\Support\PhpExtensionProbe;
use App\Thumbnails\Contracts\ImageProcessorInterface;
use App\Thumbnails\DTO\ThumbnailGeometry;
use App\Thumbnails\DTO\ThumbnailSpec;
use Illuminate\Support\Facades\Log;

/**
 * Imagick 이미지 처리 엔진 (imagick PHP 확장이 있는 서버에서만 선택지로 보인다)
 *
 * GD 엔진과 같은 맞춤 규칙(`ThumbnailGeometry`)을 쓴다. `autoOrient` 로 EXIF 방향을 반영하고
 * `stripImage` 로 메타데이터를 지운다. 움직이는 GIF·WebP·APNG 는 설정(`core.thumbnail.animated_original`)이 켜져 있으면
 * 생성기가 먼저 원본으로 거르고, 끄면 이 엔진이 첫 프레임으로 썸네일을 만든다.
 */
class ImagickImageProcessor implements ImageProcessorInterface
{
    /**
     * 읽을 수 있는 MIME → Imagick 형식
     */
    private const INPUT_FORMATS = [
        'image/jpeg' => 'JPEG',
        'image/jpg' => 'JPEG',
        'image/pjpeg' => 'JPEG',
        'image/png' => 'PNG',
        'image/gif' => 'GIF',
        'image/webp' => 'WEBP',
        'image/avif' => 'AVIF',
        'image/bmp' => 'BMP',
        'image/x-ms-bmp' => 'BMP',
    ];

    /**
     * 결과 형식 → MIME
     */
    private const OUTPUT_MIME = [
        'JPEG' => 'image/jpeg',
        'PNG' => 'image/png',
        'GIF' => 'image/gif',
        'WEBP' => 'image/webp',
        'AVIF' => 'image/avif',
    ];

    /**
     * {@inheritDoc}
     */
    public function id(): string
    {
        return 'imagick';
    }

    /**
     * {@inheritDoc}
     */
    public function isAvailable(): bool
    {
        return app(PhpExtensionProbe::class)->loaded('imagick');
    }

    /**
     * {@inheritDoc}
     */
    public function supportsInput(string $mime): bool
    {
        $format = self::INPUT_FORMATS[strtolower($mime)] ?? null;

        return $format !== null && $this->formatSupported($format);
    }

    /**
     * {@inheritDoc}
     */
    public function supportsOutput(string $format): bool
    {
        $format = strtoupper($format === 'jpg' ? 'jpeg' : $format);

        return isset(self::OUTPUT_MIME[$format]) && $this->formatSupported($format);
    }

    /**
     * {@inheritDoc}
     */
    public function supportsAnimation(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function thumbnail(string $sourcePath, string $sourceMime, ThumbnailSpec $spec, string $targetPath): ?string
    {
        $inputFormat = self::INPUT_FORMATS[strtolower($sourceMime)] ?? null;

        if ($inputFormat === null || ! extension_loaded('imagick') || ! is_file($sourcePath)) {
            return null;
        }

        $original = null;
        $image = null;

        try {
            $original = new \Imagick($sourcePath);
            // 여러 프레임이면 첫 프레임만 쓴다(움직이는 GIF·WebP·APNG 는 설정이 켜져 있으면 생성기가 먼저 원본으로 거른다)
            $original->setIteratorIndex(0);
            $image = $original->getImage();
            // 전 프레임을 든 원래 객체는 바로 놓는다 — 오래 도는 큐 워커에 메모리가 쌓이지 않게
            $original->clear();
            $original = null;
            $image->autoOrient();

            $geometry = ThumbnailGeometry::compute($image->getImageWidth(), $image->getImageHeight(), $spec);

            if ($geometry->srcW !== $image->getImageWidth() || $geometry->srcH !== $image->getImageHeight()) {
                $image->cropImage($geometry->srcW, $geometry->srcH, $geometry->srcX, $geometry->srcY);
                $image->setImagePage(0, 0, 0, 0);
            }

            $image->resizeImage($geometry->dstW, $geometry->dstH, \Imagick::FILTER_LANCZOS, 1);
            $image->stripImage();

            $outputFormat = $this->outputFormat($inputFormat, $spec->format);
            $image->setImageFormat($outputFormat);
            $image->setImageCompressionQuality($spec->effectiveQuality());

            $written = $image->writeImage($targetPath);

            return $written ? self::OUTPUT_MIME[$outputFormat] : null;
        } catch (\Throwable $e) {
            Log::warning('Imagick 썸네일 생성 실패', ['path' => $sourcePath, 'error' => $e->getMessage()]);

            return null;
        } finally {
            $original?->clear();
            $image?->clear();
        }
    }

    /**
     * {@inheritDoc}
     */
    public function resizeInPlace(string $path, ?string $mime, ?int $maxW, ?int $maxH, int $quality): bool
    {
        $format = self::INPUT_FORMATS[strtolower((string) $mime)] ?? null;

        if (! in_array($format, ['JPEG', 'PNG', 'GIF', 'WEBP'], true) || ! extension_loaded('imagick') || ! is_file($path)) {
            return false;
        }

        if ($maxW === null && $maxH === null) {
            return false;
        }

        $image = null;

        try {
            $image = new \Imagick($path);
            [$width, $height] = [$image->getImageWidth(), $image->getImageHeight()];

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

            $image->resizeImage(
                max(1, (int) round($width * $scale)),
                max(1, (int) round($height * $scale)),
                \Imagick::FILTER_LANCZOS,
                1
            );
            $image->setImageCompressionQuality(max(1, min(100, $quality)));

            return (bool) $image->writeImage($path);
        } catch (\Throwable $e) {
            Log::warning('Imagick 업로드 축소 실패', ['path' => $path, 'error' => $e->getMessage()]);

            return false;
        } finally {
            $image?->clear();
        }
    }

    /**
     * Imagick 이 그 형식을 다룰 수 있는지 (빌드마다 지원 코덱이 다르다)
     *
     * @param  string  $format  Imagick 형식 이름
     * @return bool 다룰 수 있으면 true
     */
    private function formatSupported(string $format): bool
    {
        if (! $this->isAvailable() || ! extension_loaded('imagick')) {
            return false;
        }

        return \Imagick::queryFormats($format) !== [];
    }

    /**
     * 결과 형식을 정합니다 (GD 엔진과 같은 규칙).
     *
     * @param  string  $inputFormat  원본 형식
     * @param  string  $requested  규격 형식 (original·webp·avif)
     * @return string Imagick 형식 이름
     */
    private function outputFormat(string $inputFormat, string $requested): string
    {
        if ($requested !== 'original' && $this->supportsOutput($requested)) {
            return strtoupper($requested);
        }

        if ($inputFormat === 'BMP' || ! $this->supportsOutput(strtolower($inputFormat))) {
            return 'PNG';
        }

        return $inputFormat;
    }
}
