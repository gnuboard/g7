<?php

namespace Modules\Sirsoft\Board\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * Board 모듈 1.3.1 업그레이드 스텝
 *
 * 번호는 data/1.3.1/migrations/ 의 파일 접두 번호와 같다(파일 이름 순으로 실행된다):
 *
 *   01. 신고 정책 저장값에서 쓰이지 않는 알림 채널 키 2개(`notify_admin_on_report_channels`·
 *       `notify_author_on_report_action_channels`)를 지운다
 *
 * 01 번이 필요한 이유 — 신고 알림 채널은 코어 알림 설정이 정한 뒤로 두 키는 화면도 읽는 코드도 없이 저장만 됐다.
 * 1.3.1 은 기본값과 저장 검증에서 뺐으므로 이미 저장된 사이트의 설정 파일에도 남기지 않는다.
 *
 * 모든 비즈니스 로직은 data/1.3.1/migrations/ 로 격리(AbstractUpgradeStep 규약).
 *
 * @upgrade-path A
 */
class Upgrade_1_3_1 extends AbstractUpgradeStep {}
