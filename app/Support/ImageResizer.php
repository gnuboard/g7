<?php

namespace App\Support;

use App\Thumbnails\ImageProcessorResolver;
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
 * 실제 처리는 드라이버 카테고리 `image` 의 엔진(GD·Imagick·플러그인)이 맡는다 — 썸네일 생성과 같은 엔진이다.
 *
 * 한계값이 없으면(설정 미지정) 아무 것도 하지 않습니다 — 기존 동작을 유지해,
 * 이 기능이 도입됐다는 이유만으로 기존 사이트의 이미지가 갑자기 줄어들지 않도록 합니다.
 */
class ImageResizer
{
    /**
     * 기본 품질 (설정 미지정 시)
     */
    private const DEFAULT_QUALITY = 85;

    /**
     * ImageResizer 생성자
     *
     * @param  ImageProcessorResolver  $processors  이미지 처리 엔진 해석기
     */
    public function __construct(
        private readonly ImageProcessorResolver $processors
    ) {}

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
        if (! is_file($path)) {
            return false;
        }

        $maxWidth = $this->limit('image_max_width');
        $maxHeight = $this->limit('image_max_height');

        if ($maxWidth === null && $maxHeight === null) {
            return false;
        }

        $engine = $this->processors->resolve();

        if ($engine === null) {
            return false;
        }

        try {
            return $engine->resizeInPlace($path, $mimeType, $maxWidth, $maxHeight, $this->quality());
        } catch (\Throwable $e) {
            // 축소 실패가 업로드 자체를 막지는 않는다 — 원본이 그대로 저장된다
            Log::warning('업로드 이미지 축소 실패 (원본을 그대로 저장합니다)', [
                'path' => $path,
                'mime_type' => $mimeType,
                'engine' => $engine->id(),
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
