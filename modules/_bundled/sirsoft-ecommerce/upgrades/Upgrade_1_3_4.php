<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * Ecommerce 모듈 1.3.4 업그레이드 스텝
 *
 * 번호는 data/1.3.4/migrations/ 의 파일 접두 번호와 같다(파일 이름 순으로 실행된다):
 *
 *   01. 상품 후기 표가 회원·주문 없는 후기를 담을 수 있는지 확인하고 빠진 것만 채움
 *   02. 저장된 SEO 제목 틀이 옛 기본값(`{commerce_name} - …`)이면 쇼핑몰명을 뺀 새 기본값으로 바꿈
 *   03. 개발 중 전용 표(`ecommerce_content_media`)에 쌓인 본문 미디어를 해시째 이 모듈 범용 첨부 표(`ecommerce_attachments`)로
 *       옮기고 전용 표를 지움
 *
 * 03 번이 필요한 이유 — 상품 설명·공통 정보 본문 미디어 저장소를 출시 전에 게시판·페이지와 같은 모양의 범용 첨부 표
 * (`ecommerce_attachments`, collection = content_media)로 옮겼다(#887). 공개판에는 전용 표가 없어 no-op 이고, 이미 표를 만든 개발
 * 설치본만 옮긴다. 저장된 본문의 주소(해시)와 파일 위치(디스크·카테고리 기준 경로)가 바뀌지 않게 그대로 옮기고, 옮기지 못한 행이
 * 있으면 전용 표를 남긴다.
 *
 * 02 번이 필요한 이유 — 코어 7.1.5 는 검색엔진 제목과 방문자 탭 제목을 한 규칙으로 정하고 사이트 이름을 코어
 * 「타이틀 접미사」로만 붙인다. 옛 기본 틀이 남으면 「쇼핑몰명 - 상품명 - 사이트 이름」 이 된다. 운영자가 고친 틀은 그대로 둔다.
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
