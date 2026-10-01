<?php

namespace Plugins\Sirsoft\Gdpr\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * sirsoft-gdpr 플러그인 1.1.0 업그레이드 스텝
 *
 * 관리자 확장 관리 화면에 갤러리/목록 보기 전환이 생기면서 그 선택을 브라우저에
 * 기억하는 키(`g7_view_mode_*`)가 추가됐다. 이 키가 필수 저장 허용목록에 없으면
 * functional 미동의 상태에서 부팅 때마다 파기되어, 보기를 바꿔도 새로고침하면
 * 되돌아간다(#640 화면 테마와 같은 함정).
 *
 * 소스 카탈로그는 교정했으나 기설치본은 설치 시점에 시드된 설정 파일을 그대로 쓰므로
 * 저장된 목록에 이 항목만 추가한다. 운영자가 편집한 나머지 목록은 건드리지 않는다.
 *
 * 또한 1.0.4 가 목록에 못박은 확장 소유 키(결제·본인인증·쇼핑)를 걷어낸다. 1.1.0 부터 그 키는
 * 각 확장이 계약 훅으로 제공하므로, 설정에 남은 사본은 확장이 빠져도 허용된 채로 남는다.
 *
 * 처리방침 링크 설정을 슬러그(`privacy_policy_slug`, 페이지 모듈 주소 규칙 `/page/{slug}` 로 조합)에서
 * 운영자가 직접 적는 주소(`privacy_policy_url`)로 옮긴다. 기설치본이 보던 링크는 같은 경로로 이관한다.
 *
 * 모든 비즈니스 로직은 data/1.1.0/migrations/ 로 격리(AbstractUpgradeStep 규약).
 */
class Upgrade_1_1_0 extends AbstractUpgradeStep {}
