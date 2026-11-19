<?php

namespace Modules\Sirsoft\Ecommerce\Exports;

/**
 * 내보내기에서 제외하는 컬럼의 공용 사유
 *
 * 선언 클래스의 `excluded()` 는 컬럼 → 사유 문장이다. 같은 이유가 여러 번 나오므로 문장을 여기에
 * 모아 둔다. 스키마 동기화 테스트는 사유가 비었으면 실패한다 — 「왜 안 내보내는가」가 남아야 다음
 * 사람이 판단을 되짚을 수 있다.
 */
final class ExportExclusion
{
    public const PRIMARY_KEY = '내부 행 번호 — 업무 식별자(주문번호·상품코드 등)로 대신 제공';

    public const FOREIGN_KEY = '연결 ID — 연결 대상의 이름·번호 필드로 대신 제공';

    public const RECORD_META = '레코드 기록 시각 — 업무 일시 필드로 대신 제공';

    public const SOFT_DELETE = '삭제 표시 — 삭제된 행은 내보내지 않음';

    public const MULTI_CURRENCY = '통화별 원천 금액 배열 — 「통화」와 기준 통화 금액 필드로 대신 제공';

    public const SNAPSHOT = '계산·정책 스냅샷 원천 JSON — 운영자에게 필요한 값은 개별 필드로 제공';

    public const INTERNAL = '내부 처리용 값 — 운영자에게 의미 없음';

    public const SECRET = '비밀값(해시·암호화 값·민감 식별번호) — 반출 금지';

    public const SEO = '검색엔진·표시 설정 — 판매·정산 자료가 아님';

    public const LONG_CONTENT = '본문 HTML — 표 형식 자료에 맞지 않음';

    public const CODE_WITH_NAME = '기관·은행 코드 — 이름 필드로 대신 제공';
}
