<?php

namespace App\Support;

use App\Helpers\InlineMediaPolicy;
use Symfony\Component\Mime\MimeTypes;

/**
 * 공개 디스크 직접 주소(`/storage`)의 웹서버 보호 규칙 파일
 *
 * 공개 디스크의 파일은 서버(PHP)를 거치지 않고 웹서버가 직접 내려보낸다. 그 응답에는 애플리케이션이
 * 헤더를 붙일 수 없으므로, 공개 디스크 루트(`storage/app/public`)에 웹서버 규칙 파일(`.htaccess`)을 두어
 * 서버를 거치는 주소와 같은 보호를 건다.
 *
 * - 모든 응답에 `X-Content-Type-Options: nosniff`
 * - 인라인으로 두는 래스터 이미지 외의 파일은 내려받기 강제(`Content-Disposition: attachment`) + CSP `sandbox`
 * - 헤더 모듈이 없으면 그 파일들은 직접 접근 차단(403)
 * - PHP 파일은 언제나 직접 접근 차단(403)
 *
 * 차단은 주소 재작성 규칙으로 낸다. 이 파일이 쓰는 지시어(`Header`·`RewriteRule`)는 모두 `AllowOverride FileInfo`
 * 범위라 사이트가 동작하는 데 이미 필요한 범위를 넘지 않는다. 인증 계열(`Require`)·PHP 설정(`php_flag`) 지시어는
 * 쓰지 않는다 — 그 범위를 열지 않은 서버에서는 허용되지 않은 지시어 하나가 이 디렉터리의 모든 요청(이미지 포함)을
 * 500 으로 만든다.
 *
 * 인라인으로 두는 형식은 「공개 디스크로 내보낼 확장자」 중 래스터 이미지다 — 운영자가 목록에 넣은 그 밖의
 * 형식은 직접 주소에서도 내려받기로만 열린다. 설정이 바뀌면 `ensureHtaccess()` 가 파일을 다시 만든다.
 *
 * 이 규칙은 Apache(2.4+, `AllowOverride` 허용)에서만 읽힌다. 다른 웹서버는 같은 규칙을 서버 설정에 직접
 * 넣어야 하며, 적용 여부는 `PublicDiskHardeningProbe` 가 확인한다.
 *
 * @since 7.1.6
 */
final class PublicDiskHardening
{
    /**
     * 자동 생성 파일 표지 (첫 줄). 규칙의 모양이 바뀌면 번호를 올린다.
     */
    public const MARKER = '# g7-public-disk-hardening v2';

    /**
     * 내려받기 강제 응답에 붙이는 CSP — 서버를 거치는 주소의 정책과 같다.
     */
    private const SANDBOX_CSP = "default-src 'none'; style-src 'unsafe-inline'; sandbox";

    /**
     * 직접 주소에서 인라인으로 두는 확장자 — 「공개 디스크로 내보낼 확장자」 중 래스터 이미지.
     *
     * @return array<int, string> 확장자 목록 (소문자)
     */
    public static function inlineExtensions(): array
    {
        $mimeTypes = MimeTypes::getDefault();
        $inline = [];

        foreach (PublicAssetDisk::exportableExtensions() as $extension) {
            // 규칙 파일의 정규식에 그대로 들어가므로 영숫자만 받는다.
            if (preg_match('/^[a-z0-9]+$/', $extension) !== 1) {
                continue;
            }

            foreach ($mimeTypes->getMimeTypes($extension) as $mimeType) {
                if (InlineMediaPolicy::isInlineImage($mimeType)) {
                    $inline[] = $extension;

                    break;
                }
            }
        }

        return $inline;
    }

    /**
     * 규칙 파일 내용을 만듭니다.
     *
     * @return string `.htaccess` 내용 (LF 줄바꿈)
     */
    public static function htaccess(): string
    {
        $inline = self::inlineExtensions();

        // 인라인 형식이 아닌 모든 파일. 인라인 형식이 하나도 없으면 전부.
        $nonInline = $inline === []
            ? '.+'
            : '(?i)^(?!.*\.('.implode('|', $inline).')$).+$';

        $lines = [
            self::MARKER.' — 자동 생성 파일입니다. 직접 고치지 마세요(설정을 저장하면 다시 만들어집니다).',
            '<IfModule mod_headers.c>',
            '    Header always set X-Content-Type-Options "nosniff"',
            '    <FilesMatch "'.$nonInline.'">',
            '        Header always set Content-Disposition "attachment"',
            '        Header always set Content-Security-Policy "'.self::SANDBOX_CSP.'"',
            '    </FilesMatch>',
            '</IfModule>',
            '<IfModule mod_rewrite.c>',
            '    RewriteEngine On',
            '    RewriteRule "(?i)\.(php\d?|phtml|phar|pht)$" - [F]',
            '    <IfModule !mod_headers.c>',
            '        RewriteRule "'.$nonInline.'" - [F]',
            '    </IfModule>',
            '</IfModule>',
            '',
        ];

        return implode("\n", $lines);
    }

    /**
     * 공개 디스크 루트에 규칙 파일이 최신 내용으로 놓여 있게 합니다(멱등).
     *
     * 내용이 같으면 다시 쓰지 않는다. 표지가 없는 기존 파일(손으로 만든 파일)은 `.htaccess.g7-backup` 으로
     * 사본을 남기고 바꾼다. 공개 디스크 디렉터리가 없으면 만들지 않는다.
     *
     * 소유권: root 로 실행된 코어 업데이트(sudo)가 만든 파일은 웹 계정이 고쳐 쓸 수 없다. 이 파일은 업데이트
     * 시작 시점에 없어 업데이트의 소유권 스냅샷·복원이 닿지 않으므로, 쓴 뒤(또는 내용이 같아도) 공개 디스크
     * 디렉터리의 소유자·그룹으로 맞춘다 — root 가 아니면 바꿀 수 없으므로 아무 일도 없다. 그래도 파일을 고쳐 쓸
     * 수 없으면(이미 root 소유로 남은 사이트) 같은 디렉터리의 임시 파일로 쓴 뒤 바꿔 넣는다. 디렉터리에도 쓸 수
     * 없으면 false 다.
     *
     * @param  string|null  $directory  공개 디스크 루트 (null 이면 `storage/app/public`)
     * @return bool 규칙 파일이 최신 내용으로 놓여 있으면 true
     */
    public static function ensureHtaccess(?string $directory = null): bool
    {
        $directory ??= storage_path('app/public');

        if (! is_dir($directory)) {
            return false;
        }

        $path = $directory.DIRECTORY_SEPARATOR.'.htaccess';
        $content = self::htaccess();
        $existing = is_file($path) ? @file_get_contents($path) : false;

        if ($existing === $content) {
            self::matchDirectoryOwnership($path, $directory);

            return true;
        }

        if (is_string($existing) && $existing !== '' && ! str_starts_with($existing, '# g7-public-disk-hardening')) {
            @copy($path, $path.'.g7-backup');
        }

        if (! self::write($path, $content)) {
            return false;
        }

        self::matchDirectoryOwnership($path, $directory);

        return true;
    }

    /**
     * 규칙 파일을 씁니다 — 제자리에 쓸 수 없으면 같은 디렉터리의 임시 파일로 쓴 뒤 바꿔 넣습니다.
     *
     * 바꿔 넣기는 파일이 아니라 디렉터리의 쓰기 권한만 요구한다. 바꿔 넣은 파일은 원래 파일의 권한을 이어받는다.
     * 규칙 파일 자리가 파일이 아니면(디렉터리 등) 쓰지 않는다.
     *
     * @param  string  $path  규칙 파일 경로
     * @param  string  $content  쓸 내용
     * @return bool 썼으면 true
     */
    private static function write(string $path, string $content): bool
    {
        if (file_exists($path) && ! is_file($path)) {
            return false;
        }

        if ((! file_exists($path) || is_writable($path)) && @file_put_contents($path, $content, LOCK_EX) !== false) {
            self::ensureWebReadable($path);

            return true;
        }

        $temporary = $path.'.tmp-'.bin2hex(random_bytes(6));

        if (@file_put_contents($temporary, $content) === false) {
            return false;
        }

        $perms = is_file($path) ? (@fileperms($path) & 0777) : false;
        if ($perms !== false && $perms !== 0) {
            @chmod($temporary, $perms);
        }

        if (! @rename($temporary, $path)) {
            @unlink($temporary);

            return false;
        }

        self::ensureWebReadable($path);

        return true;
    }

    /**
     * 규칙 파일에 모든 계정의 읽기 권한을 더합니다.
     *
     * 파일 퍼미션은 프로세스 umask 를 따른다 — umask 0027·0077 인 실행에서 0640·0600 이 되면, PHP 와 다른
     * 계정으로 도는 웹서버가 규칙 파일을 읽지 못하고 Apache 가 공개 디스크(`/storage/**`) 전체를 403
     * (`Server unable to read htaccess file`)으로 막는다. 다른 비트는 건드리지 않는다.
     *
     * @param  string  $path  규칙 파일 경로
     * @return void 반환값 없음
     */
    private static function ensureWebReadable(string $path): void
    {
        clearstatcache(true, $path);
        $perms = @fileperms($path);

        if ($perms !== false && ($perms & 0044) !== 0044) {
            @chmod($path, ($perms & 0777) | 0044);
        }
    }

    /**
     * 규칙 파일의 소유자·그룹을 공개 디스크 디렉터리와 같게 맞춥니다(root 로 실행 중일 때만 실제로 바뀐다).
     *
     * @param  string  $path  규칙 파일 경로
     * @param  string  $directory  공개 디스크 루트
     * @return void 반환값 없음
     */
    private static function matchDirectoryOwnership(string $path, string $directory): void
    {
        if (! function_exists('chown') || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return;
        }

        clearstatcache(true, $path);
        $owner = @fileowner($directory);
        $group = @filegroup($directory);

        if ($owner !== false && @fileowner($path) !== $owner) {
            @chown($path, $owner);
        }
        if ($group !== false && @filegroup($path) !== $group) {
            @chgrp($path, $group);
        }
    }
}
