<?php

namespace Plugins\Sirsoft\Koreaexim\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * sirsoft-koreaexim 플러그인 1.0.2 업그레이드 스텝
 *
 * 환율 정기 갱신 시각을 플러그인 설정(`update_time`, 한국시간 HH:mm)에서 코어 7.2.0 의 스케줄 관리 행
 * (`update-rates`, cron + Asia/Seoul)으로 옮긴다. 설정 화면의 갱신 시각 칸은 없어지고, 운영자는 스케줄 관리에서
 * 시각을 바꾼다. 기설치본이 기본값(11:30)이 아닌 시각을 저장해 두었다면 그 시각을 스케줄 행에 운영자 수정으로
 * 기록해 다음 갱신에서도 보존한다. 옛 설정 키는 지운다.
 *
 * 플러그인 갱신은 선언 동기화(스케줄 행 생성)를 업그레이드 스텝보다 먼저 하므로 이 스텝이 돌 때 행이 있다.
 *
 * 모든 비즈니스 로직은 data/1.0.2/migrations/ 로 격리(AbstractUpgradeStep 규약).
 */
class Upgrade_1_0_2 extends AbstractUpgradeStep {}
