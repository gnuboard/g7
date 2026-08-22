<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Upgrade;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use Modules\Sirsoft\Board\Upgrades\Upgrade_1_1_1;
use PHPUnit\Framework\Attributes\Test;

/**
 * 1.1.1 평문 게시글 비밀번호 복구 업그레이드 스텝 테스트.
 *
 * 배경: 1.1.1 이전 게시글 수정 경로가 본인 확인용 `password` 를 저장 데이터로 흘려
 * 기존 bcrypt 해시를 평문으로 덮었다. 소스 교정은 새로 덮이는 것만 막으므로,
 * 이미 평문이 된 행은 이 백필이 없으면 작성자가 영구히 자기 글을 다룰 수 없다.
 *
 * 검증 목적:
 * - 평문으로 남은 행이 bcrypt 해시로 교체된다
 * - 교체 후에도 작성자가 알던 원래 비밀번호로 검증된다
 * - 이미 해시인 행은 값이 그대로다 (재해싱하지 않는다)
 * - 재실행해도 결과가 동일하다 (멱등)
 *
 * @group board
 * @group upgrade
 */
class PlaintextPostPasswordRehashTest extends BoardTestCase
{
    protected function getTestBoardSlug(): string
    {
        return 'plaintext-password-rehash';
    }

    /**
     * 1.1.1 복구 스텝을 실행합니다.
     */
    private function runRehash(): void
    {
        (new Upgrade_1_1_1)->run(new UpgradeContext('1.1.0', '1.1.1', '1.1.1', 'extension-upgrade'));
    }

    private function storedPassword(int $postId): string
    {
        return (string) DB::table('board_posts')->where('id', $postId)->value('password');
    }

    /**
     * 평문으로 남은 행이 해시로 복구되고, 원래 비밀번호로 검증된다.
     *
     * @scenario stored=plaintext
     *
     * @effects plaintext_password_rehashed_to_bcrypt
     */
    #[Test]
    public function plaintext_rows_are_rehashed_and_still_verify(): void
    {
        $plain = 'legacyPlain123';
        $postId = $this->createTestPost(['password' => $plain]);

        $this->runRehash();

        $stored = $this->storedPassword($postId);

        $this->assertNotSame($plain, $stored, '평문이 그대로 남았다');
        $this->assertSame('bcrypt', password_get_info($stored)['algoName'] ?? 'unknown');
        $this->assertTrue(Hash::check($plain, $stored), '복구 후 원래 비밀번호로 검증되지 않는다');
    }

    /**
     * 이미 해시인 행은 건드리지 않는다 (재해싱 금지).
     *
     * @scenario stored=bcrypt
     *
     * @effects already_hashed_rows_untouched
     */
    #[Test]
    public function already_hashed_rows_are_left_untouched(): void
    {
        $hashed = Hash::make('alreadyHashed123');
        $postId = $this->createTestPost(['password' => $hashed]);

        $this->runRehash();

        $this->assertSame($hashed, $this->storedPassword($postId), '이미 해시인 행이 재해싱되었다');
    }

    /**
     * 비밀번호가 없는 회원 게시글은 대상이 아니다.
     *
     * @scenario stored=null
     *
     * @effects rows_without_password_untouched
     */
    #[Test]
    public function rows_without_password_are_untouched(): void
    {
        $postId = $this->createTestPost(['password' => null]);

        $this->runRehash();

        $this->assertNull(DB::table('board_posts')->where('id', $postId)->value('password'));
    }

    /**
     * 재실행해도 결과가 변하지 않는다 (멱등).
     *
     * @scenario run=twice
     *
     * @effects rehash_is_idempotent
     */
    #[Test]
    public function rerunning_does_not_change_the_result(): void
    {
        $plain = 'idempotent123';
        $postId = $this->createTestPost(['password' => $plain]);

        $this->runRehash();
        $afterFirst = $this->storedPassword($postId);

        $this->runRehash();

        $this->assertSame($afterFirst, $this->storedPassword($postId), '재실행이 값을 바꿨다');
        $this->assertTrue(Hash::check($plain, $this->storedPassword($postId)));
    }
}
