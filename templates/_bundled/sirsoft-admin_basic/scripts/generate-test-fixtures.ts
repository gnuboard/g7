/**
 * 성능 테스트용 픽스처 데이터 생성 스크립트
 *
 * 사용법: tsx scripts/generate-test-fixtures.ts
 */

import * as fs from 'fs';
import * as path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

/**
 * 대용량 JSON 데이터 생성 (O(n) 복잡도)
 */
const generateLargeJSON = (sizeInMB: number): string => {
  console.log(`\n[생성 시작] ${sizeInMB}MB 목표`);
  const startTime = Date.now();

  const targetSize = sizeInMB * 1024 * 1024;
  const baseObject = {
    id: 1,
    name: 'test-layout',
    version: '1.0.0',
    components: [] as any[],
  };

  // 샘플 컴포넌트로 크기 추정
  const sampleComponent = {
    id: 'component-0',
    type: 'div',
    className: 'container flex flex-col items-center justify-center p-4 m-2',
    children: [
      {
        id: 'child-0-1',
        type: 'h1',
        className: 'text-2xl font-bold',
        content: 'Title Component',
      },
      {
        id: 'child-0-2',
        type: 'p',
        className: 'text-base',
        content: 'Description text for the component',
      },
    ],
    metadata: {
      createdAt: new Date().toISOString(),
      updatedAt: new Date().toISOString(),
      author: 'system',
      tags: ['component', 'layout', 'container', 'responsive'],
    },
  };

  // 샘플 컴포넌트 크기 측정 (포맷팅 포함)
  const sampleJSON = JSON.stringify({ components: [sampleComponent] }, null, 2);
  const componentSize = sampleJSON.length - JSON.stringify({ components: [] }, null, 2).length;

  console.log(`[추정] 컴포넌트 1개 크기: ${componentSize} bytes`);

  // 필요한 컴포넌트 개수 계산 (10% 마진)
  const baseSize = JSON.stringify(baseObject).length;
  const componentsNeeded = Math.ceil((targetSize - baseSize) / componentSize * 1.1);

  console.log(`[계획] ${componentsNeeded}개 컴포넌트 생성 예정`);

  // 컴포넌트 생성 (stringify 없이 - O(n) 복잡도)
  for (let i = 0; i < componentsNeeded; i++) {
    baseObject.components.push({
      id: `component-${i}`,
      type: 'div',
      className: 'container flex flex-col items-center justify-center p-4 m-2',
      children: [
        {
          id: `child-${i}-1`,
          type: 'h1',
          className: 'text-2xl font-bold',
          content: `Title Component ${i}`,
        },
        {
          id: `child-${i}-2`,
          type: 'p',
          className: 'text-base',
          content: `Description text for the component ${i}`,
        },
      ],
      metadata: {
        createdAt: new Date().toISOString(),
        updatedAt: new Date().toISOString(),
        author: 'system',
        tags: ['component', 'layout', 'container', 'responsive'],
      },
    });

    if ((i + 1) % 100 === 0) {
      const progress = ((i + 1) / componentsNeeded * 100).toFixed(1);
      console.log(`[진행] ${progress}% (${i + 1}/${componentsNeeded})`);
    }
  }

  // 최종 JSON 생성 (1회만 stringify)
  const result = JSON.stringify(baseObject, null, 2);
  const actualSize = result.length;
  const actualSizeMB = (actualSize / 1024 / 1024).toFixed(2);

  const endTime = Date.now();
  const duration = ((endTime - startTime) / 1000).toFixed(2);

  console.log(`[완료] ${baseObject.components.length}개 컴포넌트, ${actualSizeMB}MB`);
  console.log(`[소요시간] ${duration}초\n`);

  return result;
};

/**
 * 픽스처 파일 생성
 */
const generateFixtures = () => {
  const fixturesDir = path.join(__dirname, '../src/components/__tests__/__fixtures__');

  // 디렉토리 확인
  if (!fs.existsSync(fixturesDir)) {
    console.error(`❌ 디렉토리가 존재하지 않습니다: ${fixturesDir}`);
    process.exit(1);
  }

  console.log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
  console.log('  성능 테스트 픽스처 데이터 생성');
  console.log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

  const sizes = [
    { size: 1, filename: 'test-1mb.json' },
    { size: 5, filename: 'test-5mb.json' },
    { size: 10, filename: 'test-10mb.json' },
  ];

  for (const { size, filename } of sizes) {
    const filePath = path.join(fixturesDir, filename);
    console.log(`\n📝 ${filename} 생성 중...`);

    const data = generateLargeJSON(size);
    fs.writeFileSync(filePath, data, 'utf-8');

    const fileStats = fs.statSync(filePath);
    const fileSizeMB = (fileStats.size / 1024 / 1024).toFixed(2);
    console.log(`✅ 저장 완료: ${filePath} (${fileSizeMB}MB)`);
  }

  console.log('\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
  console.log('✅ 모든 픽스처 파일 생성 완료!');
  console.log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n');
};

// 스크립트 실행
generateFixtures();
