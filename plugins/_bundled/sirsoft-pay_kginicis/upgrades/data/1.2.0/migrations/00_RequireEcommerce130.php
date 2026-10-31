<?php

declare(strict_types=1);

namespace App\Upgrades\Data\Ext\Plugins\SirsoftPayKginicis\V1_2_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * 이커머스가 1.3.0 미만이면 이 버전으로의 갱신을 멈춘다.
 *
 * 배경:
 *
 * 이 버전의 결제 콜백·환불 경로는 이커머스 1.3.0 에서 새로 생긴 클래스(클레임 추가결제·
 * 가상계좌 발급 후속 처리 등)를 주입받는다. 그런데 플러그인 갱신 경로는 manifest 의
 * `dependencies` 버전을 다시 검사하지 않는다(설치 때만 검사). 그래서 이커머스 갱신이
 * 거절·실패한 채 이 플러그인만 올라가면, 결제 화면이 처음 열릴 때 없는 클래스를 찾다가
 * 500 으로 끝난다.
 *
 * 이 스텝은 다른 스텝보다 먼저 돌도록 파일명 앞에 `00_` 을 붙였다. 예외를 던지면 갱신
 * 경로가 플러그인 파일을 백업으로 되돌리고 버전 칸도 원래대로 둔다 — 이커머스를 먼저
 * 1.3.0 이상으로 올린 뒤 이 플러그인을 다시 갱신하면 된다.
 *
 * 이커머스가 설치돼 있지 않으면 이 갱신이 만드는 문제가 아니므로 막지 않는다.
 *
 * 멱등: 읽기만 한다.
 *
 * V-1 안전 격리 (docs/extension/upgrade-step-guide.md §13):
 *   - `Illuminate\Support\Facades\{DB,Schema}` 만 사용 (이전 버전에도 존재하던 표면)
 *   - Service / Manager / Repository 컨테이너 해석 없음
 */
final class RequireEcommerce130 implements DataMigration
{
    /**
     * 이 플러그인 식별자 (로그·메시지용).
     */
    private const SELF = 'sirsoft-pay_kginicis';

    /**
     * 의존 모듈 식별자.
     */
    private const ECOMMERCE = 'sirsoft-ecommerce';

    /**
     * 이 버전이 요구하는 이커머스 최소 버전.
     */
    private const MIN_VERSION = '1.3.0';

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 사람이 읽을 수 있는 짧은 식별자
     */
    public function name(): string
    {
        return 'RequireEcommerce130';
    }

    /**
     * 설치된 이커머스 버전을 확인하고 부족하면 예외를 던진다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (로거 등)
     * @return void 반환값 없음
     *
     * @throws RuntimeException 이커머스가 1.3.0 미만일 때
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable('modules')) {
            $context->logger->info(sprintf('[%s] 모듈 테이블 없음 — 이커머스 버전 확인 skip', self::SELF));

            return;
        }

        $installed = DB::table('modules')->where('identifier', self::ECOMMERCE)->value('version');

        if ($installed === null) {
            $context->logger->info(sprintf('[%s] 이커머스 미설치 — 이커머스 버전 확인 skip', self::SELF));

            return;
        }

        if (version_compare((string) $installed, self::MIN_VERSION, '<')) {
            throw new RuntimeException(sprintf(
                '[%s] 이커머스 %s 이상이 필요합니다(현재 %s). 이커머스를 먼저 업데이트한 뒤 이 플러그인을 다시 업데이트하세요.',
                self::SELF,
                self::MIN_VERSION,
                $installed,
            ));
        }

        $context->logger->info(sprintf('[%s] 이커머스 %s 확인 — 갱신 계속', self::SELF, $installed));
    }
}
