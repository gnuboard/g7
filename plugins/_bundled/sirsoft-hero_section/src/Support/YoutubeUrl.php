<?php

namespace Plugins\Sirsoft\HeroSection\Support;

/**
 * 유튜브 주소 → 영상 ID(11자) 변환.
 *
 * 운영자가 브라우저 주소창·공유 단추에서 복사한 모양을 그대로 받는다 — `youtube.com/watch?v=…`, `youtu.be/…`,
 * `youtube.com/shorts/…`·`/embed/…`·`/live/…`, `youtube-nocookie.com/embed/…`, 영상 ID 11자만. 그 밖의 호스트는 받지 않는다.
 * 저장은 ID 만 한다 — 사이트는 ID 로 `youtube-nocookie.com/embed/<ID>` 주소를 직접 만들므로 운영자 입력이 iframe 주소에 들어가지 않는다.
 */
final class YoutubeUrl
{
    /**
     * 영상 ID 모양
     */
    private const ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';

    /**
     * 받는 호스트(앞의 www.·m. 는 떼고 비교)
     *
     * @var array<int, string>
     */
    private const HOSTS = ['youtube.com', 'youtu.be', 'youtube-nocookie.com', 'music.youtube.com'];

    /**
     * 주소에서 영상 ID 를 꺼냅니다.
     *
     * @param  string|null  $input  운영자가 넣은 주소 또는 영상 ID
     * @return string|null 영상 ID (알아볼 수 없으면 null)
     */
    public static function videoId(?string $input): ?string
    {
        $input = trim((string) $input);
        if ($input === '' || preg_match('/[\s\x00-\x1F\x7F]/u', $input)) {
            return null;
        }

        if (preg_match(self::ID_PATTERN, $input)) {
            return $input;
        }

        if (! preg_match('#^https?://#i', $input)) {
            $input = 'https://'.$input;
        }

        $parts = parse_url($input);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $host = (string) preg_replace('/^(www|m)\./', '', $host);
        if (! in_array($host, self::HOSTS, true)) {
            return null;
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        $segments = $path === '' ? [] : explode('/', $path);

        $candidate = null;
        if ($host === 'youtu.be') {
            $candidate = $segments[0] ?? null;
        } elseif (in_array($segments[0] ?? '', ['embed', 'shorts', 'live', 'v'], true)) {
            $candidate = $segments[1] ?? null;
        } elseif (($segments[0] ?? '') === 'watch') {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $candidate = is_string($query['v'] ?? null) ? $query['v'] : null;
        }

        return is_string($candidate) && preg_match(self::ID_PATTERN, $candidate) ? $candidate : null;
    }

    /**
     * 영상 ID 로 관리자 화면에 다시 보여 줄 보기 주소를 만듭니다.
     *
     * @param  string|null  $id  영상 ID
     * @return string|null 보기 주소 (ID 가 없으면 null)
     */
    public static function watchUrl(?string $id): ?string
    {
        return $id !== null && preg_match(self::ID_PATTERN, $id) ? 'https://www.youtube.com/watch?v='.$id : null;
    }
}
