<?php

namespace Plugins\Sirsoft\Gdpr\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * sirsoft-gdpr 플러그인 1.1.0 업그레이드 스텝
 *
 * 필수 저장 항목 허용목록에 세 항목을 보강한다 — 방문자가 고른 표시 통화·배송 국가와
 * 관리자 화면의 쿠폰 목록 필터다. 앞의 둘은 언어·테마와 같은 「사용자가 화면에서 직접
 * 고른 표시 설정」(WP29 §3.6)이고, 마지막은 형제 필터 접두사와 같은 부류인데 명명만
 * 갈려 목록에서 빠져 있었다.
 *
 * 빠져 있는 동안에는 그 값들이 매 방문마다 회수되어 기본값으로 되돌아갔다. 쓰기는
 * user-initiated 면제로 통과하지만 회수 경로는 이 목록만 보므로, 「저장은 되는데 다음
 * 방문에 사라지는」 형태였고 오류도 안내도 남지 않았다.
 *
 * 모든 비즈니스 로직은 data/1.1.0/migrations/ 로 격리(AbstractUpgradeStep 규약).
 */
class Upgrade_1_1_0 extends AbstractUpgradeStep {}
