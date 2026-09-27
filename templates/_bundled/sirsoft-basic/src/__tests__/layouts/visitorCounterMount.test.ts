/**
 * @file visitorCounterMount.test.ts
 * @description 홈 방문자 카운터가 실제로 그려질 조건으로 붙어 있는지 잠근다 (이슈 #682 S3)
 *
 * 카운터는 공개 설정 `public_summary_enabled` 가 켜져 있을 때만 요청하고 그린다. 그 설정을 읽는 경로가
 * 한 칸이라도 어긋나면 조건이 늘 거짓이라 **아무 일도 일어나지 않는다** — 요청도 없고 오류도 없다.
 * 브라우저 실측(M10-T1)에서 홈에 카운터가 아예 없고 `GET /api/analytics/summary` 도 찍히지 않았다.
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const LAYOUT_DIR = path.resolve(__dirname, '../../../layouts');

const read = (rel: string): string => fs.readFileSync(path.join(LAYOUT_DIR, rel), 'utf8');

describe('홈 방문자 카운터 — 공개 설정을 읽는 경로', () => {
  // @scenario screen=home_widget, state=normal, viewport=1440, locale=ko
  // @effects counter_reads_public_settings_from_global
  it('공개 설정은 _global.settings 로 읽는다 — 접두사가 빠지면 조건이 늘 거짓이다', () => {
    const files = ['home.json', 'partials/_visitor_counter.json'];

    for (const f of files) {
      const raw = read(f);
      const bare = raw.match(/(?<!_global\.)\bsettings\?\.analytics/g) ?? [];
      expect(bare, `${f} 에 접두사 없는 settings?.analytics 가 남아 있다`).toEqual([]);
      expect(raw, f).toContain('_global.settings?.analytics?.public_summary_enabled');
    }
  });
});
