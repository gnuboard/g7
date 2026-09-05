<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftBoard\V1_1_1\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 평문으로 남은 비회원 게시글 비밀번호를 해시로 복구합니다.
 *
 * 1.1.1 이전에는 게시글 수정 요청의 `password`(본인 확인용 자격증명)가 저장 데이터로
 * 그대로 흘러 기존 bcrypt 해시를 평문으로 덮었다. 그래서 한 번이라도 수정된 비회원
 * 게시글은 ① 비밀번호가 평문으로 DB 에 남고 ② 이후 본인 확인이 "bcrypt 가 아니다"
 * 예외로 끝나 작성자가 자기 글을 수정·삭제할 수 없다.
 *
 * 소스 교정은 새로 덮이는 것만 막는다 — 이미 평문이 된 행은 이 백필이 없으면 영구히
 * 그 상태로 남는다. 평문을 그대로 해싱하므로 작성자가 알던 비밀번호는 그대로 동작한다.
 *
 * 멱등: 이미 bcrypt 인 행은 건너뛴다. 재실행해도 결과가 변하지 않는다.
 *
 * V-1 안전: 판정은 PHP 내장 password_get_info, 해싱은 password_hash(PASSWORD_BCRYPT)
 * 로 수행한다 — 코어 Hash 파사드를 거치지 않으므로 업그레이드 시점의 hashing 드라이버
 * 설정이나 코어 클래스 변경에 영향을 받지 않는다(버전 스냅샷 규약).
 */
class RehashPlaintextPostPasswords implements DataMigration
{
    private const POSTS_TABLE = 'board_posts';

    /**
     * 마이그레이션 이름을 반환합니다.
     */
    public function name(): string
    {
        return 'RehashPlaintextPostPasswords';
    }

    /**
     * 평문 비밀번호를 bcrypt 해시로 교체합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::POSTS_TABLE) || ! Schema::hasColumn(self::POSTS_TABLE, 'password')) {
            $context->logger->warning('[board:1.1.1] board_posts.password 미존재 — 스킵');

            return;
        }

        $rehashed = 0;
        $alreadyHashed = 0;

        DB::table(self::POSTS_TABLE)
            ->whereNotNull('password')
            ->where('password', '!=', '')
            ->orderBy('id')
            ->select('id', 'password')
            ->chunkById(200, function ($posts) use (&$rehashed, &$alreadyHashed) {
                foreach ($posts as $post) {
                    $stored = (string) $post->password;

                    if ((password_get_info($stored)['algoName'] ?? 'unknown') === 'bcrypt') {
                        $alreadyHashed++;

                        continue;
                    }

                    DB::table(self::POSTS_TABLE)
                        ->where('id', $post->id)
                        ->update(['password' => password_hash($stored, PASSWORD_BCRYPT)]);

                    $rehashed++;
                }
            });

        $context->logger->info(
            "[board:1.1.1] 게시글 비밀번호 복구: 재해싱 {$rehashed} / 이미 해시 {$alreadyHashed}"
        );
    }
}
