<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * sirsoft-verification_nhnkcp 플러그인 1.1.4 업그레이드 스텝
 *
 * 1.1.4 에서 생긴 동일인 선점표(`nhnkcp_identity_claims`)를 기존 회원의 본인확인 record 로 채운다 — 비어 있으면
 * 기존 회원과 같은 명의의 신규 가입이 선점표 판정을 지나쳐 가입 트랜잭션까지 들어간다(KVE-2026-3192).
 *
 * 모든 비즈니스 로직은 data/1.1.4/migrations/ 로 격리(AbstractUpgradeStep 규약).
 */
class Upgrade_1_1_4 extends AbstractUpgradeStep {}
