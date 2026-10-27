import React, { useEffect, useState } from 'react';
import { basic, periodStatus, translate } from '../../support/editor';

export interface HeroPeriodHintProps {
  /** 상시/기간 지정 */
  period?: string;
  /** 사용 여부 */
  active?: boolean;
  /** 사이트 시간대로 입력한 시작·종료 */
  start?: string;
  end?: string;
  /** 서버가 알려준 사이트 시간대·현재 시각 */
  timezone?: string;
  serverNow?: string;
}

/** 사이트 시간대·서버 시계로 저장 후 상태를 안내합니다. */
export const HeroPeriodHint: React.FC<HeroPeriodHintProps> = ({ period = 'always', active = true, start = '', end = '', timezone, serverNow }) => {
  const [clock, setClock] = useState(() => ({ base: serverNow, received: Date.now(), elapsed: 0 }));
  useEffect(() => {
    const received = Date.now();
    setClock({ base: serverNow, received, elapsed: 0 });
    const timer = window.setInterval(() => setClock({ base: serverNow, received, elapsed: Date.now() - received }), 1000);
    return () => window.clearInterval(timer);
  }, [serverNow]);
  const P = basic('P', 'p');
  if (!timezone || !clock.base) return <P className="form-hint">{translate('admin.form.period.clock_loading')}</P>;
  const status = periodStatus(period, active, start, end, timezone, Date.parse(clock.base) + clock.elapsed);
  return <P className="form-hint" data-period-status={status}>
    {translate('admin.form.period.timezone', { timezone })}{' · '}
    {status === 'incomplete' ? translate('admin.form.period.incomplete') : translate('admin.form.period.hint', { status: translate(`admin.status.${status}`) })}
  </P>;
};
