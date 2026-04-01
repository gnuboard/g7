/**
 * DataSourceManager.test.tsx
 *
 * DataSourceManager 컴포넌트 테스트
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { DataSourceManager, DataSource } from '../components/PropertyPanel/DataSourceManager';

// ============================================================================
// 테스트 헬퍼
// ============================================================================

function createTestDataSources(): DataSource[] {
  return [
    {
      id: 'users',
      type: 'api',
      endpoint: '/api/admin/users',
      method: 'GET',
      auto_fetch: true,
      auth_required: true,
      loading_strategy: 'progressive',
    },
    {
      id: 'config',
      type: 'static',
      data: { theme: 'dark' },
    },
  ];
}

// ============================================================================
// 테스트
// ============================================================================

describe('DataSourceManager', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe('기본 렌더링', () => {
    it('헤더와 개수가 표시되어야 함', () => {
      const dataSources = createTestDataSources();
      const onChange = vi.fn();

      render(<DataSourceManager dataSources={dataSources} onChange={onChange} />);

      expect(screen.getByText('데이터 소스')).toBeInTheDocument();
      expect(screen.getByText('2개')).toBeInTheDocument();
    });

    it('데이터 소스 목록이 표시되어야 함', () => {
      const dataSources = createTestDataSources();
      const onChange = vi.fn();

      render(<DataSourceManager dataSources={dataSources} onChange={onChange} />);

      expect(screen.getByText('users')).toBeInTheDocument();
      expect(screen.getByText('config')).toBeInTheDocument();
    });

    it('빈 상태일 때 안내 메시지가 표시되어야 함', () => {
      const onChange = vi.fn();

      render(<DataSourceManager dataSources={[]} onChange={onChange} />);

      expect(screen.getByText(/데이터 소스가 없습니다/)).toBeInTheDocument();
    });

    it('추가 버튼들이 표시되어야 함', () => {
      const onChange = vi.fn();

      render(<DataSourceManager dataSources={[]} onChange={onChange} />);

      expect(screen.getByText('+ API')).toBeInTheDocument();
      expect(screen.getByText('+ 정적 데이터')).toBeInTheDocument();
      expect(screen.getByText('+ Route Params')).toBeInTheDocument();
      expect(screen.getByText('+ Query Params')).toBeInTheDocument();
    });

    it('도움말이 표시되어야 함', () => {
      const onChange = vi.fn();

      render(<DataSourceManager dataSources={[]} onChange={onChange} />);

      expect(screen.getByText('데이터 소스 규칙:')).toBeInTheDocument();
      expect(screen.getByText(/ID 고유성/)).toBeInTheDocument();
    });
  });

  describe('데이터 소스 추가', () => {
    it('API 데이터 소스 추가 시 기본값이 설정되어야 함', () => {
      const onChange = vi.fn();

      render(<DataSourceManager dataSources={[]} onChange={onChange} />);

      fireEvent.click(screen.getByText('+ API'));

      expect(onChange).toHaveBeenCalledWith([
        expect.objectContaining({
          id: 'dataSource1',
          type: 'api',
          endpoint: '/api/admin/',
          method: 'GET',
          auto_fetch: true,
          auth_required: true,
          loading_strategy: 'progressive',
        }),
      ]);
    });

    it('정적 데이터 소스 추가 시 기본값이 설정되어야 함', () => {
      const onChange = vi.fn();

      render(<DataSourceManager dataSources={[]} onChange={onChange} />);

      fireEvent.click(screen.getByText('+ 정적 데이터'));

      expect(onChange).toHaveBeenCalledWith([
        expect.objectContaining({
          id: 'dataSource1',
          type: 'static',
          data: {},
        }),
      ]);
    });

    it('Route Params 추가가 가능해야 함', () => {
      const onChange = vi.fn();

      render(<DataSourceManager dataSources={[]} onChange={onChange} />);

      fireEvent.click(screen.getByText('+ Route Params'));

      expect(onChange).toHaveBeenCalledWith([
        expect.objectContaining({
          id: 'dataSource1',
          type: 'route_params',
        }),
      ]);
    });

    it('Query Params 추가가 가능해야 함', () => {
      const onChange = vi.fn();

      render(<DataSourceManager dataSources={[]} onChange={onChange} />);

      fireEvent.click(screen.getByText('+ Query Params'));

      expect(onChange).toHaveBeenCalledWith([
        expect.objectContaining({
          id: 'dataSource1',
          type: 'query_params',
        }),
      ]);
    });
  });

  describe('데이터 소스 삭제', () => {
    it('삭제 버튼 클릭 시 해당 데이터 소스가 제거되어야 함', () => {
      const dataSources = createTestDataSources();
      const onChange = vi.fn();

      render(<DataSourceManager dataSources={dataSources} onChange={onChange} />);

      // 첫 번째 삭제 버튼 클릭
      const deleteButtons = screen.getAllByText('삭제');
      fireEvent.click(deleteButtons[0]);

      expect(onChange).toHaveBeenCalledWith([dataSources[1]]);
    });
  });

  describe('데이터 소스 편집', () => {
    it('데이터 소스 클릭 시 편집 폼이 펼쳐져야 함', () => {
      const dataSources = createTestDataSources();
      const onChange = vi.fn();

      render(<DataSourceManager dataSources={dataSources} onChange={onChange} />);

      // users 데이터 소스 클릭
      fireEvent.click(screen.getByText('users'));

      // 편집 필드들이 표시되어야 함
      expect(screen.getByDisplayValue('users')).toBeInTheDocument();
      expect(screen.getByDisplayValue('/api/admin/users')).toBeInTheDocument();
    });

    it('ID 변경 시 onChange가 호출되어야 함', () => {
      const dataSources = createTestDataSources();
      const onChange = vi.fn();

      render(<DataSourceManager dataSources={dataSources} onChange={onChange} />);

      // 첫 번째 데이터 소스 펼치기
      fireEvent.click(screen.getByText('users'));

      // ID 변경
      const idInput = screen.getByDisplayValue('users');
      fireEvent.change(idInput, { target: { value: 'userList' } });

      expect(onChange).toHaveBeenCalledWith([
        expect.objectContaining({ id: 'userList' }),
        dataSources[1],
      ]);
    });

    it('엔드포인트 변경 시 onChange가 호출되어야 함', () => {
      const dataSources = createTestDataSources();
      const onChange = vi.fn();

      render(<DataSourceManager dataSources={dataSources} onChange={onChange} />);

      // 첫 번째 데이터 소스 펼치기
      fireEvent.click(screen.getByText('users'));

      // 엔드포인트 변경
      const endpointInput = screen.getByDisplayValue('/api/admin/users');
      fireEvent.change(endpointInput, { target: { value: '/api/admin/users?active=true' } });

      expect(onChange).toHaveBeenCalledWith([
        expect.objectContaining({ endpoint: '/api/admin/users?active=true' }),
        dataSources[1],
      ]);
    });

    it('로딩 전략 변경이 가능해야 함', () => {
      const dataSources = createTestDataSources();
      const onChange = vi.fn();

      render(<DataSourceManager dataSources={dataSources} onChange={onChange} />);

      // 첫 번째 데이터 소스 펼치기
      fireEvent.click(screen.getByText('users'));

      // 로딩 전략 변경
      const strategySelect = screen.getByDisplayValue(/프로그레시브/);
      fireEvent.change(strategySelect, { target: { value: 'blocking' } });

      expect(onChange).toHaveBeenCalledWith([
        expect.objectContaining({ loading_strategy: 'blocking' }),
        dataSources[1],
      ]);
    });
  });

  describe('파라미터 관리', () => {
    it('파라미터 추가가 가능해야 함', () => {
      const dataSources: DataSource[] = [
        {
          id: 'users',
          type: 'api',
          endpoint: '/api/admin/users',
          method: 'GET',
        },
      ];
      const onChange = vi.fn();

      render(<DataSourceManager dataSources={dataSources} onChange={onChange} />);

      // 데이터 소스 펼치기
      fireEvent.click(screen.getByText('users'));

      // 파라미터 추가
      fireEvent.click(screen.getByText('+ 추가'));

      expect(onChange).toHaveBeenCalledWith([
        expect.objectContaining({
          params: { param1: '' },
        }),
      ]);
    });

    it('파라미터 값 수정이 가능해야 함', () => {
      const dataSources: DataSource[] = [
        {
          id: 'users',
          type: 'api',
          endpoint: '/api/admin/users',
          method: 'GET',
          params: { page: '{{query.page}}' },
        },
      ];
      const onChange = vi.fn();

      render(<DataSourceManager dataSources={dataSources} onChange={onChange} />);

      // 데이터 소스 펼치기
      fireEvent.click(screen.getByText('users'));

      // 파라미터 값 수정
      const valueInput = screen.getByDisplayValue('{{query.page}}');
      fireEvent.change(valueInput, { target: { value: '{{query.page || 1}}' } });

      expect(onChange).toHaveBeenCalledWith([
        expect.objectContaining({
          params: { page: '{{query.page || 1}}' },
        }),
      ]);
    });
  });

  describe('ID 유효성 검사', () => {
    it('중복 ID 입력 시 에러가 표시되어야 함', () => {
      const dataSources = createTestDataSources();
      const onChange = vi.fn();

      render(<DataSourceManager dataSources={dataSources} onChange={onChange} />);

      // 첫 번째 데이터 소스 펼치기
      fireEvent.click(screen.getByText('users'));

      // ID를 기존 ID와 동일하게 변경
      const idInput = screen.getByDisplayValue('users');
      fireEvent.change(idInput, { target: { value: 'config' } });

      expect(screen.getByText('이미 사용 중인 ID입니다')).toBeInTheDocument();
    });

    it('빈 ID 입력 시 에러가 표시되어야 함', () => {
      const dataSources = createTestDataSources();
      const onChange = vi.fn();

      render(<DataSourceManager dataSources={dataSources} onChange={onChange} />);

      // 첫 번째 데이터 소스 펼치기
      fireEvent.click(screen.getByText('users'));

      // ID 비우기
      const idInput = screen.getByDisplayValue('users');
      fireEvent.change(idInput, { target: { value: '' } });

      expect(screen.getByText('ID는 필수입니다')).toBeInTheDocument();
    });

    it('잘못된 형식의 ID 입력 시 에러가 표시되어야 함', () => {
      const dataSources = createTestDataSources();
      const onChange = vi.fn();

      render(<DataSourceManager dataSources={dataSources} onChange={onChange} />);

      // 첫 번째 데이터 소스 펼치기
      fireEvent.click(screen.getByText('users'));

      // 숫자로 시작하는 ID 입력
      const idInput = screen.getByDisplayValue('users');
      fireEvent.change(idInput, { target: { value: '123users' } });

      expect(screen.getByText(/ID는 영문자로 시작/)).toBeInTheDocument();
    });
  });

  describe('JSON 미리보기', () => {
    it('데이터 소스가 있으면 JSON 미리보기가 표시되어야 함', () => {
      const dataSources = createTestDataSources();
      const onChange = vi.fn();

      render(<DataSourceManager dataSources={dataSources} onChange={onChange} />);

      expect(screen.getByText('JSON 미리보기')).toBeInTheDocument();
      expect(screen.getByText(/"id": "users"/)).toBeInTheDocument();
    });

    it('데이터 소스가 없으면 JSON 미리보기가 표시되지 않아야 함', () => {
      const onChange = vi.fn();

      render(<DataSourceManager dataSources={[]} onChange={onChange} />);

      expect(screen.queryByText('JSON 미리보기')).not.toBeInTheDocument();
    });
  });

  describe('체크박스 옵션', () => {
    it('auto_fetch 체크박스가 동작해야 함', () => {
      const dataSources: DataSource[] = [
        {
          id: 'users',
          type: 'api',
          endpoint: '/api/admin/users',
          auto_fetch: true,
        },
      ];
      const onChange = vi.fn();

      render(<DataSourceManager dataSources={dataSources} onChange={onChange} />);

      // 데이터 소스 펼치기
      fireEvent.click(screen.getByText('users'));

      // 자동 Fetch 체크 해제
      const checkbox = screen.getByLabelText('자동 Fetch');
      fireEvent.click(checkbox);

      expect(onChange).toHaveBeenCalledWith([
        expect.objectContaining({ auto_fetch: false }),
      ]);
    });

    it('auth_required 체크박스가 동작해야 함', () => {
      const dataSources: DataSource[] = [
        {
          id: 'users',
          type: 'api',
          endpoint: '/api/admin/users',
        },
      ];
      const onChange = vi.fn();

      render(<DataSourceManager dataSources={dataSources} onChange={onChange} />);

      // 데이터 소스 펼치기
      fireEvent.click(screen.getByText('users'));

      // 인증 필요 체크
      const checkbox = screen.getByLabelText('인증 필요');
      fireEvent.click(checkbox);

      expect(onChange).toHaveBeenCalledWith([
        expect.objectContaining({ auth_required: true }),
      ]);
    });
  });

  describe('조건부 로딩', () => {
    it('if 필드 입력이 가능해야 함', () => {
      const dataSources: DataSource[] = [
        {
          id: 'users',
          type: 'api',
          endpoint: '/api/admin/users',
        },
      ];
      const onChange = vi.fn();

      render(<DataSourceManager dataSources={dataSources} onChange={onChange} />);

      // 데이터 소스 펼치기
      fireEvent.click(screen.getByText('users'));

      // if 필드 입력
      const ifInput = screen.getByPlaceholderText('{{route.id}}');
      fireEvent.change(ifInput, { target: { value: '{{route.id}}' } });

      expect(onChange).toHaveBeenCalledWith([
        expect.objectContaining({ if: '{{route.id}}' }),
      ]);
    });
  });

  describe('정적 데이터', () => {
    it('static 타입일 때 데이터 입력 필드가 표시되어야 함', () => {
      const dataSources: DataSource[] = [
        {
          id: 'config',
          type: 'static',
          data: { theme: 'dark' },
        },
      ];
      const onChange = vi.fn();

      render(<DataSourceManager dataSources={dataSources} onChange={onChange} />);

      // 데이터 소스 펼치기
      fireEvent.click(screen.getByText('config'));

      // 정적 데이터 입력 필드 (textarea) 확인
      const textarea = screen.getByPlaceholderText('{"key": "value"}');
      expect(textarea).toBeInTheDocument();
    });
  });
});
