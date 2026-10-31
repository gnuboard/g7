<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Upgrade;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use Modules\Sirsoft\Board\Upgrades\Upgrade_1_2_0;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * 1.2.0 게시글 작성 IP 자리표시값 정리 업그레이드 스텝 테스트 (이슈 #636 S5).
 *
 * 배경: 1.2.0 이전 board_posts.ip_address 는 NOT NULL 이라 IP 를 알 수 없는 글에 '0.0.0.0' 을
 * 채웠다. 1.2.0 마이그레이션이 컬럼을 nullable 로 바꾼 뒤, 이 스텝이 기존 행의 자리표시값을
 * NULL(「모름」)로 되돌린다 — 남겨 두면 보유기간 처리가 그 행을 IP 가 남은 행으로 센다.
 *
 * 검증 목적:
 * - 자리표시값 행은 NULL 이 된다
 * - 실제 IP 행과 이미 NULL 인 행은 그대로다
 * - 재실행해도 결과가 같다 (멱등)
 */
#[Group('board')]
#[Group('upgrade')]
class PlaceholderPostIpNormalizeTest extends BoardTestCase
{
    /**
     * 테스트 게시판 슬러그를 반환합니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'placeholder-ip-normalize';
    }

    /**
     * 1.2.0 정리 스텝을 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runNormalize(): void
    {
        (new Upgrade_1_2_0)->run(new UpgradeContext('1.1.1', '1.2.0', '1.2.0', 'extension-upgrade'));
    }

    /**
     * 게시글의 작성 IP 를 읽습니다.
     *
     * @param  int  $postId  게시글 ID
     * @return string|null 작성 IP
     */
    private function ipOf(int $postId): ?string
    {
        return DB::table('board_posts')->where('id', $postId)->value('ip_address');
    }

    /**
     * 자리표시값만 NULL 로 바뀌고 나머지 행은 그대로이며, 재실행해도 결과가 같다.
     *
     * @return void 반환값 없음
     *
     * @scenario axis=ip, toggle=on, guest_mode=anonymize
     *
     * @effects board_placeholder_post_ip_is_normalized
     */
    #[Test]
    public function placeholder_ip_becomes_null_and_other_rows_are_untouched(): void
    {
        $placeholder = $this->createTestPost(['ip_address' => '0.0.0.0']);
        $real = $this->createTestPost(['ip_address' => '203.0.113.7']);
        $erased = $this->createTestPost(['ip_address' => null]);

        $this->runNormalize();

        $this->assertNull($this->ipOf($placeholder), '자리표시값 0.0.0.0 은 「모름」이라 NULL 로 되돌린다');
        $this->assertSame('203.0.113.7', $this->ipOf($real), '실제 IP 는 건드리지 않는다');
        $this->assertNull($this->ipOf($erased));

        $this->runNormalize();

        $this->assertNull($this->ipOf($placeholder), '재실행해도 결과가 같다');
        $this->assertSame('203.0.113.7', $this->ipOf($real));
    }
}
