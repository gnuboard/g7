<?php

namespace App\Upgrades\Data\V7_1_6\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;

/**
 * 공개 디스크 루트에 웹서버 보호 규칙 파일(`.htaccess`)을 만듭니다.
 *
 * 공개 디스크(`storage/app/public`)의 파일은 서버를 거치지 않고 `/storage/…` 직접 주소로 나간다. 7.1.6 이전에
 * 설치된 사이트에는 그 주소의 응답 헤더를 고정하는 규칙 파일이 없다. 업데이트 한 번으로 규칙이 놓이게 한다.
 *
 * 규칙 내용은 7.1.6 시점의 기본값(인라인 = jpg·jpeg·png·gif·webp·avif)으로 고정한 리터럴이다. 운영자가
 * 환경설정 > 드라이버에서 「공개 디스크로 내보낼 확장자」 를 바꿔 저장하면 그때 설정에 맞춰 다시 만들어진다.
 *
 * V-1 판단: 경로 리터럴과 PHP 파일 함수만 쓴다 — 모델·서비스·신규 클래스를 부르지 않는다.
 *
 * idempotent: 자동 생성 표지가 있는 파일은 건드리지 않는다. 표지가 없는 기존 파일은 `.htaccess.g7-backup` 으로
 * 사본을 남기고 바꾼다. 공개 디스크 디렉터리가 없으면 건너뛴다.
 */
class WritePublicDiskHtaccess implements DataMigration
{
    /**
     * 자동 생성 파일 표지 접두 (동결 리터럴).
     */
    private const MARKER_PREFIX = '# g7-public-disk-hardening';

    /**
     * 7.1.6 시점의 규칙 내용 (동결 리터럴, LF 줄바꿈).
     */
    private const RULES = <<<'HTACCESS'
# g7-public-disk-hardening v2 — 자동 생성 파일입니다. 직접 고치지 마세요(설정을 저장하면 다시 만들어집니다).
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    <FilesMatch "(?i)^(?!.*\.(jpg|jpeg|png|gif|webp|avif)$).+$">
        Header always set Content-Disposition "attachment"
        Header always set Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; sandbox"
    </FilesMatch>
</IfModule>
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule "(?i)\.(php\d?|phtml|phar|pht)$" - [F]
    <IfModule !mod_headers.c>
        RewriteRule "(?i)^(?!.*\.(jpg|jpeg|png|gif|webp|avif)$).+$" - [F]
    </IfModule>
</IfModule>

HTACCESS;

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'WritePublicDiskHtaccess';
    }

    /**
     * 규칙 파일이 없으면 만듭니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        $directory = storage_path('app/public');

        if (! is_dir($directory)) {
            $context->logger->info('[7.1.6] 공개 디스크 디렉터리 없음 — 보호 규칙 파일 생성을 건너뜀');

            return;
        }

        $path = $directory.DIRECTORY_SEPARATOR.'.htaccess';
        $existing = is_file($path) ? @file_get_contents($path) : false;

        if (is_string($existing) && str_starts_with($existing, self::MARKER_PREFIX)) {
            $context->logger->info('[7.1.6] 공개 디스크 보호 규칙 파일이 이미 있음 — 건너뜀');

            return;
        }

        if (is_string($existing) && $existing !== '') {
            if (@copy($path, $path.'.g7-backup')) {
                $context->logger->info('[7.1.6] 기존 .htaccess 를 .htaccess.g7-backup 으로 보관');
            } else {
                $context->logger->warning('[7.1.6] 기존 .htaccess 사본을 만들지 못했습니다(권한) — 그대로 바꿉니다');
            }
        }

        // 줄바꿈은 LF 로 고정한다 (소스 파일의 줄바꿈과 무관하게).
        $rules = str_replace("\r\n", "\n", self::RULES);

        if (@file_put_contents($path, $rules, LOCK_EX) === false) {
            $context->logger->warning('[7.1.6] 공개 디스크 보호 규칙 파일을 쓰지 못했습니다(권한) — 환경설정 > 드라이버를 저장하면 다시 시도합니다');

            return;
        }

        $context->logger->info('[7.1.6] 공개 디스크 보호 규칙 파일 생성: storage/app/public/.htaccess');
    }
}
