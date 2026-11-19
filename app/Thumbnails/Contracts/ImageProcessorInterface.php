<?php

namespace App\Thumbnails\Contracts;

use App\Thumbnails\DTO\ThumbnailSpec;

/**
 * 이미지 처리 엔진 계약 (환경설정 > 드라이버 > 이미지 처리)
 *
 * 코어는 GD·Imagick 두 엔진을 내장하고, 플러그인은 `core.settings.available_image_drivers` 로
 * 드라이버를 등재한 뒤 컨테이너 태그 대신 필터 `core.thumbnail.filter_processors` 로 구현체를 넘긴다.
 * 업로드 축소(`ImageResizer`)와 썸네일 생성이 같은 엔진을 쓴다.
 */
interface ImageProcessorInterface
{
    /**
     * 엔진 식별자 (드라이버 id 와 같다 — 예: gd, imagick)
     *
     * @return string 엔진 id
     */
    public function id(): string;

    /**
     * 이 서버에서 쓸 수 있는지 (PHP 확장 로드 여부 등)
     *
     * @return bool 사용 가능하면 true
     */
    public function isAvailable(): bool;

    /**
     * 입력 MIME 을 읽을 수 있는지
     *
     * @param  string  $mime  원본 MIME
     * @return bool 읽을 수 있으면 true
     */
    public function supportsInput(string $mime): bool;

    /**
     * 출력 형식(webp·avif·jpeg·png·gif)으로 쓸 수 있는지
     *
     * @param  string  $format  형식 이름
     * @return bool 쓸 수 있으면 true
     */
    public function supportsOutput(string $format): bool;

    /**
     * 움직이는 이미지를 프레임째 처리할 수 있는지
     *
     * @return bool 처리할 수 있으면 true
     */
    public function supportsAnimation(): bool;

    /**
     * 원본 파일을 규격대로 줄여 대상 파일에 씁니다.
     *
     * @param  string  $sourcePath  원본 로컬 파일 경로
     * @param  string  $sourceMime  원본 MIME
     * @param  ThumbnailSpec  $spec  규격
     * @param  string  $targetPath  결과를 쓸 로컬 파일 경로
     * @return string|null 결과 MIME (실패하면 null)
     */
    public function thumbnail(string $sourcePath, string $sourceMime, ThumbnailSpec $spec, string $targetPath): ?string;

    /**
     * 이미지가 상한을 넘으면 비율을 유지한 채 제자리에서 줄입니다 (업로드 축소).
     *
     * @param  string  $path  이미지 로컬 파일 경로 (제자리 수정)
     * @param  string|null  $mime  MIME
     * @param  int|null  $maxW  가로 상한 (null 이면 제한 없음)
     * @param  int|null  $maxH  세로 상한 (null 이면 제한 없음)
     * @param  int  $quality  품질 1~100
     * @return bool 실제로 줄였으면 true
     */
    public function resizeInPlace(string $path, ?string $mime, ?int $maxW, ?int $maxH, int $quality): bool;
}
