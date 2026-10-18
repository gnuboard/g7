<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * 업로드 이미지 축소 (환경설정 > 업로드)
 *
 * `upload.image_max_width` / `image_max_height` / `image_quality` 는 저장·검증만 되고
 * 실제로 이미지를 줄이는 코드가 없었습니다. 관리자는 값을 바꾸면 적용된다고 인지하지만
 * 원본이 그대로 저장돼, 설정 화면과 실제 동작이 어긋나 있었습니다.
 *
 * 단위 규약: config/attachment.image_* 는 픽셀, `image_quality` 는 1~100 입니다.
 * MB↔KB 처럼 변환이 필요한 값이 아니므로 `SettingsServiceProvider` 가 그대로 옮깁니다.
 *
 * 한계값이 없으면(설정 미지정) 아무 것도 하지 않습니다 — 기존 동작을 유지해,
 * 이 기능이 도입됐다는 이유만으로 기존 사이트의 이미지가 갑자기 줄어들지 않도록 합니다.
 */
class ImageResizer
{
    /**
     * 축소를 지원하는 MIME 타입 → GD 로더/세이버 매핑
     */
    private const SUPPORTED = [
        'image/jpeg' => 'jpeg',
        'image/jpg' => 'jpeg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /**
     * 기본 품질 (설정 미지정 시)
     */
    private const DEFAULT_QUALITY = 85;

    /**
     * 이미지가 설정 상한을 넘으면 비율을 유지한 채 파일을 제자리에서 축소합니다.
     *
     * 상한 이내이거나 지원하지 않는 형식이면 파일을 건드리지 않습니다 — 상한 이내인데도
     * 다시 인코딩하면 아무 이득 없이 화질만 떨어집니다.
     *
     * @param  string  $path  이미지 파일 절대 경로 (제자리 수정)
     * @param  string|null  $mimeType  파일 MIME 타입
     * @return bool 실제로 축소했으면 true
     */
    public function resizeInPlace(string $path, ?string $mimeType): bool
    {
        $format = self::SUPPORTED[strtolower((string) $mimeType)] ?? null;

        if ($format === null || ! extension_loaded('gd') || ! is_file($path)) {
            return false;
        }

        $maxWidth = $this->limit('image_max_width');
        $maxHeight = $this->limit('image_max_height');

        if ($maxWidth === null && $maxHeight === null) {
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
            $maxWidth !== null ? $maxWidth / $width : 1.0,
            $maxHeight !== null ? $maxHeight / $height : 1.0,
            1.0
        );

        if ($scale >= 1.0) {
            return false;
        }

        // 반올림으로 0 이 되는 극단 비율에서도 최소 1px 은 남긴다
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        try {
            return $this->write($path, $format, $targetWidth, $targetHeight);
        } catch (\Throwable $e) {
            // 축소 실패가 업로드 자체를 막지는 않는다 — 원본이 그대로 저장된다
            Log::warning('업로드 이미지 축소 실패 (원본을 그대로 저장합니다)', [
                'path' => $path,
                'mime_type' => $mimeType,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 설정된 픽셀 한계값을 반환합니다.
     *
     * @param  string  $key  설정 키 (image_max_width / image_max_height)
     * @return int|null 한계값 (미설정이면 null)
     */
    private function limit(string $key): ?int
    {
        $value = config("attachment.{$key}");

        if ($value === null || $value === '') {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    /**
     * 축소한 이미지를 원본 경로에 다시 씁니다.
     *
     * @param  string  $path  대상 경로
     * @param  string  $format  GD 포맷 (jpeg/png/gif/webp)
     * @param  int  $targetWidth  결과 가로
     * @param  int  $targetHeight  결과 세로
     * @return bool 성공 여부
     */
    private function write(string $path, string $format, int $targetWidth, int $targetHeight): bool
    {
        $source = match ($format) {
            'jpeg' => @imagecreatefromjpeg($path),
            'png' => @imagecreatefrompng($path),
            'gif' => @imagecreatefromgif($path),
            'webp' => @imagecreatefromwebp($path),
        };

        if (! $source) {
            return false;
        }

        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        // PNG·GIF·WebP 의 투명도를 보존한다 (미처리 시 검은 배경으로 채워진다)
        if (in_array($format, ['png', 'gif', 'webp'], true)) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        }

        imagecopyresampled(
            $target, $source,
            0, 0, 0, 0,
            $targetWidth, $targetHeight,
            imagesx($source), imagesy($source)
        );

        $quality = $this->quality();

        $written = match ($format) {
            'jpeg' => imagejpeg($target, $path, $quality),
            // PNG 압축 레벨은 0~9 이고 값이 클수록 더 압축된다 — 품질(1~100)과 방향이 반대다
            'png' => imagepng($target, $path, (int) round((100 - $quality) / 100 * 9)),
            'gif' => imagegif($target, $path),
            'webp' => imagewebp($target, $path, $quality),
        };

        imagedestroy($source);
        imagedestroy($target);

        return (bool) $written;
    }

    /**
     * 설정된 이미지 품질을 반환합니다.
     *
     * @return int 1~100 범위의 품질
     */
    private function quality(): int
    {
        $quality = (int) config('attachment.image_quality', self::DEFAULT_QUALITY);

        return max(1, min(100, $quality > 0 ? $quality : self::DEFAULT_QUALITY));
    }
}
