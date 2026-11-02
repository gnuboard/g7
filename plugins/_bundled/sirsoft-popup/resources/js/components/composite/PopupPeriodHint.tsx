import React, { useEffect, useState } from 'react';
import { basic, periodStatus, translate } from '../../support/editor';

/** 저장 후 상태 안내 props */
export interface PopupPeriodHintProps {
  /** 상시/기간 지정 */
  period?: string;

  /** 사용 여부 */
  active?: boolean;

  /** 사이트 시간대로 입력한 시작 */
  start?: string;

  /** 사이트 시간대로 입력한 종료 */
  end?: string;

  /** 서버가 알려준 사이트 시간대 */
  timezone?: string;

  /** 서버가 알려준 현재 시각 */
  serverNow?: string;
}

/**
 * 사이트 시간대·서버 시계로 「지금 저장하면 '예약'으로 표시됩니다」를 안내합니다(#810 과 같은 방식).
 *
 * @param props 안내 props
 * @returns 안내 문구
 */
export const PopupPeriodHint: React.FC<PopupPeriodHintProps> = ({ period = 'always', active = true, start = '', end = '', timezone, serverNow }) => {
  const [clock, setClock] = useState(() => ({ base: serverNow, elapsed: 0 }));

  useEffect(() => {
    const received = Date.now();
    setClock({ base: serverNow, elapsed: 0 });
    const timer = window.setInterval(() => setClock({ base: serverNow, elapsed: Date.now() - received }), 1000);

    return () => window.clearInterval(timer);
  }, [serverNow]);

  const P = basic('P', 'p');
  if (!timezone || !clock.base) {
    return <P className="g7pp-form-hint">{translate('sirsoft-popup.admin.form.period.clock_loading')}</P>;
  }

  const status = periodStatus(period, active, start, end, timezone, Date.parse(clock.base) + clock.elapsed);

  return (
    <P className="g7pp-form-hint" data-period-status={status}>
      {translate('sirsoft-popup.admin.form.period.timezone', { timezone })}{' · '}
      {status === 'incomplete' ? translate('sirsoft-popup.admin.form.period.incomplete') : translate('sirsoft-popup.admin.form.period.hint', { status: translate('sirsoft-popup.admin.status.' + status) })}
    </P>
  );
};

export default PopupPeriodHint;
