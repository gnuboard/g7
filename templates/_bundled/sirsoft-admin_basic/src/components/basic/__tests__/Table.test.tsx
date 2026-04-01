import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { Table } from '../Table';
import { Thead } from '../Thead';
import { Tbody } from '../Tbody';
import { Tr } from '../Tr';
import { Th } from '../Th';
import { Td } from '../Td';

describe('Table 컴포넌트', () => {
  describe('기본 렌더링', () => {
    it('table 요소가 렌더링된다', () => {
      const { container } = render(
        <Table>
          <tbody>
            <tr>
              <td>데이터</td>
            </tr>
          </tbody>
        </Table>
      );
      const table = container.querySelector('table');
      expect(table).toBeTruthy();
    });

    it('자식 요소를 렌더링한다', () => {
      render(
        <Table>
          <tbody>
            <tr>
              <td>테스트 데이터</td>
            </tr>
          </tbody>
        </Table>
      );
      expect(screen.getByText('테스트 데이터')).toBeTruthy();
    });

    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(
        <Table className="custom-table">
          <tbody>
            <tr>
              <td>데이터</td>
            </tr>
          </tbody>
        </Table>
      );
      const table = container.querySelector('table');
      expect(table?.className).toContain('custom-table');
    });
  });

  describe('Thead 컴포넌트', () => {
    it('thead 요소가 렌더링된다', () => {
      const { container } = render(
        <table>
          <Thead>
            <tr>
              <th>헤더</th>
            </tr>
          </Thead>
        </table>
      );
      const thead = container.querySelector('thead');
      expect(thead).toBeTruthy();
    });

    it('자식 요소를 렌더링한다', () => {
      render(
        <table>
          <Thead>
            <tr>
              <th>컬럼명</th>
            </tr>
          </Thead>
        </table>
      );
      expect(screen.getByText('컬럼명')).toBeTruthy();
    });

    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(
        <table>
          <Thead className="custom-thead">
            <tr>
              <th>헤더</th>
            </tr>
          </Thead>
        </table>
      );
      const thead = container.querySelector('thead');
      expect(thead?.className).toContain('custom-thead');
    });
  });

  describe('Tbody 컴포넌트', () => {
    it('tbody 요소가 렌더링된다', () => {
      const { container } = render(
        <table>
          <Tbody>
            <tr>
              <td>데이터</td>
            </tr>
          </Tbody>
        </table>
      );
      const tbody = container.querySelector('tbody');
      expect(tbody).toBeTruthy();
    });

    it('자식 요소를 렌더링한다', () => {
      render(
        <table>
          <Tbody>
            <tr>
              <td>테스트 데이터</td>
            </tr>
          </Tbody>
        </table>
      );
      expect(screen.getByText('테스트 데이터')).toBeTruthy();
    });

    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(
        <table>
          <Tbody className="custom-tbody">
            <tr>
              <td>데이터</td>
            </tr>
          </Tbody>
        </table>
      );
      const tbody = container.querySelector('tbody');
      expect(tbody?.className).toContain('custom-tbody');
    });
  });

  describe('Tr 컴포넌트', () => {
    it('tr 요소가 렌더링된다', () => {
      const { container } = render(
        <table>
          <tbody>
            <Tr>
              <td>셀</td>
            </Tr>
          </tbody>
        </table>
      );
      const tr = container.querySelector('tr');
      expect(tr).toBeTruthy();
    });

    it('자식 요소를 렌더링한다', () => {
      render(
        <table>
          <tbody>
            <Tr>
              <td>테스트 셀</td>
            </Tr>
          </tbody>
        </table>
      );
      expect(screen.getByText('테스트 셀')).toBeTruthy();
    });

    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(
        <table>
          <tbody>
            <Tr className="custom-tr">
              <td>셀</td>
            </Tr>
          </tbody>
        </table>
      );
      const tr = container.querySelector('tr');
      expect(tr?.className).toContain('custom-tr');
    });

    it('onClick 이벤트가 작동한다', () => {
      const handleClick = vi.fn();
      render(
        <table>
          <tbody>
            <Tr onClick={handleClick}>
              <td>클릭 가능한 행</td>
            </Tr>
          </tbody>
        </table>
      );
      const tr = screen.getByText('클릭 가능한 행').closest('tr')!;
      fireEvent.click(tr);
      expect(handleClick).toHaveBeenCalledTimes(1);
    });
  });

  describe('Th 컴포넌트', () => {
    it('th 요소가 렌더링된다', () => {
      const { container } = render(
        <table>
          <thead>
            <tr>
              <Th>헤더 셀</Th>
            </tr>
          </thead>
        </table>
      );
      const th = container.querySelector('th');
      expect(th).toBeTruthy();
    });

    it('자식 요소를 렌더링한다', () => {
      render(
        <table>
          <thead>
            <tr>
              <Th>이름</Th>
            </tr>
          </thead>
        </table>
      );
      expect(screen.getByText('이름')).toBeTruthy();
    });

    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(
        <table>
          <thead>
            <tr>
              <Th className="custom-th">헤더</Th>
            </tr>
          </thead>
        </table>
      );
      const th = container.querySelector('th');
      expect(th?.className).toContain('custom-th');
    });

    it('scope 속성이 적용된다', () => {
      const { container } = render(
        <table>
          <thead>
            <tr>
              <Th scope="col">컬럼</Th>
            </tr>
          </thead>
        </table>
      );
      const th = container.querySelector('th');
      expect(th?.scope).toBe('col');
    });

    it('onClick 이벤트가 작동한다 (정렬 가능한 헤더)', () => {
      const handleClick = vi.fn();
      render(
        <table>
          <thead>
            <tr>
              <Th onClick={handleClick}>정렬 가능한 컬럼</Th>
            </tr>
          </thead>
        </table>
      );
      const th = screen.getByText('정렬 가능한 컬럼');
      fireEvent.click(th);
      expect(handleClick).toHaveBeenCalledTimes(1);
    });
  });

  describe('Td 컴포넌트', () => {
    it('td 요소가 렌더링된다', () => {
      const { container } = render(
        <table>
          <tbody>
            <tr>
              <Td>데이터 셀</Td>
            </tr>
          </tbody>
        </table>
      );
      const td = container.querySelector('td');
      expect(td).toBeTruthy();
    });

    it('자식 요소를 렌더링한다', () => {
      render(
        <table>
          <tbody>
            <tr>
              <Td>홍길동</Td>
            </tr>
          </tbody>
        </table>
      );
      expect(screen.getByText('홍길동')).toBeTruthy();
    });

    it('사용자 정의 클래스가 추가된다', () => {
      const { container } = render(
        <table>
          <tbody>
            <tr>
              <Td className="custom-td">데이터</Td>
            </tr>
          </tbody>
        </table>
      );
      const td = container.querySelector('td');
      expect(td?.className).toContain('custom-td');
    });

    it('colSpan 속성이 적용된다', () => {
      const { container } = render(
        <table>
          <tbody>
            <tr>
              <Td colSpan={2}>병합된 셀</Td>
            </tr>
          </tbody>
        </table>
      );
      const td = container.querySelector('td');
      expect(td?.colSpan).toBe(2);
    });

    it('rowSpan 속성이 적용된다', () => {
      const { container } = render(
        <table>
          <tbody>
            <tr>
              <Td rowSpan={2}>세로 병합</Td>
            </tr>
            <tr></tr>
          </tbody>
        </table>
      );
      const td = container.querySelector('td');
      expect(td?.rowSpan).toBe(2);
    });
  });

  describe('완전한 테이블 구조', () => {
    it('thead와 tbody를 포함한 완전한 테이블이 렌더링된다', () => {
      const { container } = render(
        <Table>
          <Thead>
            <Tr>
              <Th>이름</Th>
              <Th>나이</Th>
              <Th>이메일</Th>
            </Tr>
          </Thead>
          <Tbody>
            <Tr>
              <Td>홍길동</Td>
              <Td>30</Td>
              <Td>hong@example.com</Td>
            </Tr>
            <Tr>
              <Td>김철수</Td>
              <Td>25</Td>
              <Td>kim@example.com</Td>
            </Tr>
          </Tbody>
        </Table>
      );

      const table = container.querySelector('table');
      const thead = container.querySelector('thead');
      const tbody = container.querySelector('tbody');
      const ths = container.querySelectorAll('th');
      const trs = tbody?.querySelectorAll('tr');

      expect(table).toBeTruthy();
      expect(thead).toBeTruthy();
      expect(tbody).toBeTruthy();
      expect(ths.length).toBe(3);
      expect(trs?.length).toBe(2);

      expect(screen.getByText('홍길동')).toBeTruthy();
      expect(screen.getByText('김철수')).toBeTruthy();
    });

    it('여러 행과 열을 렌더링한다', () => {
      render(
        <Table>
          <Tbody>
            <Tr>
              <Td>1</Td>
              <Td>A</Td>
              <Td>Alpha</Td>
            </Tr>
            <Tr>
              <Td>2</Td>
              <Td>B</Td>
              <Td>Beta</Td>
            </Tr>
            <Tr>
              <Td>3</Td>
              <Td>C</Td>
              <Td>Gamma</Td>
            </Tr>
          </Tbody>
        </Table>
      );

      expect(screen.getByText('Alpha')).toBeTruthy();
      expect(screen.getByText('Beta')).toBeTruthy();
      expect(screen.getByText('Gamma')).toBeTruthy();
    });
  });

  describe('HTML 속성', () => {
    it('cellPadding이 적용된다', () => {
      const { container } = render(
        <Table cellPadding={10}>
          <tbody>
            <tr>
              <td>데이터</td>
            </tr>
          </tbody>
        </Table>
      );
      const table = container.querySelector('table');
      expect(table?.cellPadding).toBe('10');
    });

    it('cellSpacing이 적용된다', () => {
      const { container } = render(
        <Table cellSpacing={5}>
          <tbody>
            <tr>
              <td>데이터</td>
            </tr>
          </tbody>
        </Table>
      );
      const table = container.querySelector('table');
      expect(table?.cellSpacing).toBe('5');
    });
  });

  describe('접근성', () => {
    it('caption을 포함할 수 있다', () => {
      render(
        <Table>
          <caption>사용자 목록</caption>
          <Tbody>
            <Tr>
              <Td>데이터</Td>
            </Tr>
          </Tbody>
        </Table>
      );

      expect(screen.getByText('사용자 목록')).toBeTruthy();
    });

    it('th의 scope 속성이 접근성을 향상시킨다', () => {
      const { container } = render(
        <Table>
          <Thead>
            <Tr>
              <Th scope="col">이름</Th>
              <Th scope="col">나이</Th>
            </Tr>
          </Thead>
          <Tbody>
            <Tr>
              <Th scope="row">홍길동</Th>
              <Td>30</Td>
            </Tr>
          </Tbody>
        </Table>
      );

      const ths = container.querySelectorAll('th');
      const colHeaders = Array.from(ths).filter((th) => th.scope === 'col');
      const rowHeaders = Array.from(ths).filter((th) => th.scope === 'row');

      expect(colHeaders.length).toBe(2);
      expect(rowHeaders.length).toBe(1);
    });
  });

  describe('복합 Props', () => {
    it('여러 props와 스타일이 함께 적용된다', () => {
      const { container } = render(
        <Table className="border-collapse w-full" cellPadding={8}>
          <Thead className="bg-gray-100">
            <Tr>
              <Th className="text-left" scope="col">
                컬럼 1
              </Th>
              <Th className="text-right" scope="col">
                컬럼 2
              </Th>
            </Tr>
          </Thead>
          <Tbody>
            <Tr className="hover:bg-gray-50">
              <Td className="py-2">데이터 1</Td>
              <Td className="py-2 text-right">데이터 2</Td>
            </Tr>
          </Tbody>
        </Table>
      );

      const table = container.querySelector('table');
      const thead = container.querySelector('thead');
      const tr = container.querySelector('tbody tr');

      expect(table?.className).toContain('border-collapse');
      expect(table?.className).toContain('w-full');
      expect(thead?.className).toContain('bg-gray-100');
      expect(tr?.className).toContain('hover:bg-gray-50');
    });
  });
});
