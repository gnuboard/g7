<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * Ecommerce 모듈 1.3.4 업그레이드 스텝
 *
 * 번호는 data/1.3.4/migrations/ 의 파일 접두 번호와 같다(파일 이름 순으로 실행된다):
 *
 *   01. 상품 후기 표가 회원·주문 없는 후기를 담을 수 있는지 확인하고 빠진 것만 채움
 *
 * 01 번이 필요한 이유 — 1.3.4 는 다른 쇼핑몰에서 이관한 후기처럼 회원·주문 연결이 없는 후기를 후기 표에 직접
 * 넣는다(`order_option_id`·`user_id` NULL 허용, 작성자 표시 이름 `author_name`). 스키마는 마이그레이션이 바꾸지만,
 * 업데이트 도중 마이그레이션이 부분 적용된 채 판만 1.3.4 로 기록된 설치본은 이관이 NOT NULL 제약에 막힌다.
 * 업그레이드 시점에 같은 결과를 한 번 더 보장한다(이미 반영된 항목은 건너뛴다).
 *
 * 모든 비즈니스 로직은 data/1.3.4/migrations/ 로 격리(AbstractUpgradeStep 규약).
 *
 * @upgrade-path A
 */
class Upgrade_1_3_4 extends AbstractUpgradeStep {}
