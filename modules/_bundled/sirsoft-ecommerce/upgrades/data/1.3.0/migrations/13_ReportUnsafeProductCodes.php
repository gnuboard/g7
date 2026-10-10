<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_3_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 이미지 저장 폴더로 쓸 수 없는 기존 상품 코드를 찾아 알립니다 (값은 바꾸지 않음).
 *
 * 1.3.0 부터 새로 등록하거나 바꾸는 상품 코드는 영문·숫자·하이픈·밑줄만 받는다. 상품 코드는 이미지 저장 폴더
 * (`images/products/{코드}`)에 그대로 쓰이는데, 경로 구분자(`/`·`\`)·인코딩된 구분자·`.`/`..` 조각이 든 코드는
 * 저장소 경로 검사(KVE-2026-2349)에 걸리거나 다른 상품 폴더와 겹쳐 이미지 올리기·복사가 실패한다
 * (삭제는 이미지 폴더를 건너뛰고 진행). 그런 상품만 하나씩 경고한다.
 *
 * 한글·공백처럼 새 규칙 밖이지만 경로에는 문제가 없는 코드는 그대로 동작한다 — 수정 요청은 코드를 바꿀 때만
 * 새 규칙을 적용하므로(UpdateProductRequest) 다른 항목 수정도 종전대로 된다. 건수만 남긴다.
 *
 * 코드 값은 외부 연동·주문 기록·상품 주소에 쓰였을 수 있어 자동으로 바꾸지 않는다(설계 결정 2026-09-26
 * 「입력 규칙 + 기존 코드 점검」). 관리자 상품 수정 화면은 코드 입력칸이 비활성이라, 경고된 코드는 운영자가
 * 따로 정리해야 한다.
 *
 * 읽기 전용이며 `chunkById` 로 순회한다(멱등).
 *
 * @upgrade-path A
 */
class ReportUnsafeProductCodes implements DataMigration
{
    /** 대상 테이블 */
    private const TABLE = 'ecommerce_products';

    /** 허용 문자 — StoreProductRequest::PRODUCT_CODE_PATTERN 과 같은 규칙(경로 A 라 로컬 사본) */
    private const PATTERN = '/^[A-Za-z0-9_-]+$/';

    /** 한 청크의 행 수 */
    private const CHUNK_SIZE = 1000;

    /**
     * 마이그레이션 이름을 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'ReportUnsafeProductCodes';
    }

    /**
     * 이미지 폴더로 쓸 수 없는 상품 코드를 경고하고, 새 규칙 밖 코드의 건수를 남깁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'product_code')) {
            $context->logger->info('[ecommerce:1.3.0] '.self::TABLE.'.product_code 부재 — 상품 코드 점검 스킵');

            return;
        }

        $pathHazards = 0;
        $outsideRule = 0;

        DB::table(self::TABLE)
            ->select(['id', 'product_code'])
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($rows) use ($context, &$pathHazards, &$outsideRule) {
                foreach ($rows as $row) {
                    $code = (string) $row->product_code;

                    if (preg_match(self::PATTERN, $code) === 1) {
                        continue;
                    }

                    if (! $this->isPathHazard($code)) {
                        $outsideRule++;

                        continue;
                    }

                    $pathHazards++;
                    $context->logger->warning('[ecommerce:1.3.0] 상품 #'.$row->id.' 의 상품코드 '.json_encode($code, JSON_UNESCAPED_UNICODE)
                        .' 에 경로 구분자·인코딩된 구분자·`.`/`..` 조각이 있어 이미지 올리기·복사가 실패합니다 — 코드 정리가 필요합니다(관리자 상품 수정 화면에서는 상품코드를 바꿀 수 없습니다).');
                }
            });

        $context->logger->info('[ecommerce:1.3.0] 상품 코드 점검: 이미지 폴더로 쓸 수 없는 코드 '.$pathHazards.'건, '
            .'새 규칙(영문·숫자·-·_) 밖이지만 그대로 동작하는 코드 '.$outsideRule.'건');
    }

    /**
     * 상품 코드를 이미지 폴더 이름으로 쓰면 경로가 어긋나는지 판정합니다.
     *
     * @param  string  $code  상품 코드
     * @return bool 경로 구분자·NUL·인코딩된 구분자가 있거나 `.`/`..` 조각이면 true
     */
    private function isPathHazard(string $code): bool
    {
        if (str_contains($code, '/') || str_contains($code, '\\') || str_contains($code, "\0")) {
            return true;
        }

        return preg_match('/%(2e|2f|5c|00)/i', $code) === 1 || $code === '.' || $code === '..';
    }
}
