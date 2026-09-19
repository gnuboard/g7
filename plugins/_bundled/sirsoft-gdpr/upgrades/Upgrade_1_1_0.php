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
 * 모든 비즈니스 로직은 data/1.1.0/migrations/ 로 격리(AbstractUpgradeStep 규약).
 */
class Upgrade_1_1_0 extends AbstractUpgradeStep {}
