<?php

namespace App\Enums;

/**
 * 본문 미디어 종류 — 편집기 본문에 넣는 이미지·동영상·오디오 (#883 4단계)
 *
 * 종류마다 받을 수 있는 확장자·MIME 은 코드가 정한 닫힌 집합이다. 확장(게시판·페이지·이커머스)은 이 안에서만 고를 수 있다 —
 * 운영자가 임의 형식(svg·html 등)을 본문 미디어로 열지 못하게 한다.
 *
 * @since 7.1.5
 */
enum ContentMediaKind: string
{
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';

    /**
     * 이 종류의 후보 확장자(소문자).
     *
     * @return array<int, string> 확장자 목록
     */
    public function candidateExtensions(): array
    {
        return match ($this) {
            self::Image => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'],
            self::Video => ['mp4', 'webm', 'ogv'],
            self::Audio => ['mp3', 'm4a', 'ogg', 'oga', 'wav'],
        };
    }

    /**
     * 확장자별 MIME.
     *
     * @return array<string, array<int, string>> 확장자 => MIME 목록
     */
    public function mimeMap(): array
    {
        return match ($this) {
            self::Image => [
                'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
                'gif' => ['image/gif'], 'webp' => ['image/webp'], 'avif' => ['image/avif'],
            ],
            self::Video => [
                'mp4' => ['video/mp4'], 'webm' => ['video/webm'], 'ogv' => ['video/ogg'],
            ],
            self::Audio => [
                'mp3' => ['audio/mpeg'], 'm4a' => ['audio/mp4', 'audio/x-m4a'], 'ogg' => ['audio/ogg'],
                'oga' => ['audio/ogg'], 'wav' => ['audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave'],
            ],
        };
    }

    /**
     * 확장자 목록에 해당하는 MIME 목록.
     *
     * @param  array<int, string>  $extensions  확장자 목록
     * @return array<int, string> MIME 목록 (중복 제거)
     */
    public function mimeTypesFor(array $extensions): array
    {
        $map = $this->mimeMap();
        $mimes = [];
        foreach ($extensions as $extension) {
            foreach ($map[strtolower($extension)] ?? [] as $mime) {
                $mimes[$mime] = true;
            }
        }

        return array_keys($mimes);
    }

    /**
     * MIME 으로 종류를 찾습니다.
     *
     * @param  string|null  $mime  MIME
     * @return self|null 종류 (후보 밖이면 null)
     */
    public static function fromMime(?string $mime): ?self
    {
        $mime = strtolower(trim((string) $mime));
        foreach (self::cases() as $kind) {
            foreach ($kind->mimeMap() as $mimes) {
                if (in_array($mime, $mimes, true)) {
                    return $kind;
                }
            }
        }

        return null;
    }

    /**
     * 확장자로 종류를 찾습니다.
     *
     * @param  string|null  $extension  확장자
     * @return self|null 종류 (후보 밖이면 null)
     */
    public static function fromExtension(?string $extension): ?self
    {
        $extension = strtolower(trim((string) $extension));
        foreach (self::cases() as $kind) {
            if (in_array($extension, $kind->candidateExtensions(), true)) {
                return $kind;
            }
        }

        return null;
    }
}
