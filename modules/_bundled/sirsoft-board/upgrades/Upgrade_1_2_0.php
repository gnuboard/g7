<?php

namespace Modules\Sirsoft\Board\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * Board 모듈 1.2.0 업그레이드 스텝
 *
 * 1.2.0 은 board_posts.ip_address 를 nullable 로 바꾼다(마이그레이션). 그 전에는 IP 를 알 수 없는
 * 글(이커머스 상품문의 연동이 만든 글)에 NOT NULL 을 채우려고 자리표시값 '0.0.0.0' 을 넣었는데,
 * 실제 접속 IP 가 될 수 없는 값이라 뜻은 "모름" 이다. 남겨 두면 개인정보 보유기간 처리가 그 행을
 * IP 가 남은 행으로 세므로, 기존 행의 자리표시값을 NULL 로 되돌린다(01).
 *
 * 첨부 임시 키가 UUID 만 받게 되면서(KVE-2026-2349), 레이아웃 편집기로 고쳐 둔 화면이나 「편집본 유지」
 * 업데이트 화면에 남은 예전 임시 키 생성식을 `$uuid()` 로 바꾼다(02). 같은 이유로 키 없이 올린 예전
 * 첨부의 저장 경로에 남은 빈 조각(`//`)을 걷어낸다(03).
 *
 * 1.2.0 은 게시판 사용자 권한에 공지 등록(posts.write-notice)을 신설한다. 기존 게시판에는 그
 * 권한 행이 없고 저장된 기본 권한 설정에도 키가 없으므로, 둘 다 기본값(admin + 게시판
 * 관리자·스텝)으로 채운다(04·05).
 *
 * 반응 기능이 새로 생기면서 빈 반응 유형 표에 기본 유형(추천·비추천)을 채우고(06), 기존 게시판의
 * 「사용할 반응 유형」을 새 게시판 기본값과 같은 추천으로 채운다(07).
 *
 * 모든 비즈니스 로직은 data/1.2.0/migrations/ 로 격리(AbstractUpgradeStep 규약).
 *
 * @upgrade-path A
 */
class Upgrade_1_2_0 extends AbstractUpgradeStep {}
