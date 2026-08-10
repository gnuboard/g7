/**
 * registerCoreWidgets.ts — 코어 기본 위젯 등록
 *
 * Phase 4 위젯(`segmented`/`slider`/`select`/`toggle`/`color`/`image`/`tag-input`)
 * 을 widgetRegistry 에 등록한다. PropertyEditorModal 마운트 전에 1회 호출하면
 * 되며, 멱등(이미 등록돼 있으면 덮어쓰기). 후속 Phase 위젯(`flex`/
 * `condition-builder`/`action-list` 등)은 그 Phase 에서 `registerWidget` 으로 추가.
 *
 * @since engine-v1.50.0
 */

import { registerWidget, getRegisteredWidgetNames } from './widgetRegistry';
import {
  SegmentedWidget,
  SliderWidget,
  SelectWidget,
  ToggleWidget,
  DimensionWidget,
  SpacingWidget,
} from '../components/property-controls/StyleControlWidgets';
import { ColorPickerControl } from '../components/property-controls/ColorPickerControl';
import { ImagePickerControl } from '../components/property-controls/ImagePickerControl';
import { TagInputControl } from '../components/property-controls/TagInputControl';
import {
  PagePickerControl,
  DataSourcePickerControl,
  BindingListControl,
  StateKeyPickerControl,
  ModalPickerControl,
  I18nTextControl,
  TextControl,
} from '../components/property-controls/RecipePickerControls';
import { OptionsListControl } from '../components/property-controls/OptionsListControl';
import { CoreIdControl } from '../components/property-controls/CoreIdControl';
import { CoreDataKeyControl } from '../components/property-controls/CoreDataKeyControl';
import { ComponentTargetPicker } from '../components/property-controls/ComponentTargetPicker';

let registered = false;

/**
 * 코어 기본 위젯을 1회 등록. 중복 호출은 no-op(이미 등록됨).
 */
export function registerCoreWidgets(): void {
  if (registered) return;
  registerWidget('segmented', SegmentedWidget);
  registerWidget('slider', SliderWidget);
  registerWidget('select', SelectWidget);
  registerWidget('dimension', DimensionWidget);
  registerWidget('spacing', SpacingWidget);
  registerWidget('toggle', ToggleWidget);
  registerWidget('color', ColorPickerControl);
  registerWidget('image', ImagePickerControl);
  registerWidget('tag-input', TagInputControl);
  // 액션/조건 레시피 파라미터 picker 위젯.
  // FlexEditor/ConditionBuilder/ActionRecipeEditor 는 node/spec/capability 가
  // 필요한 탭 본체라 WidgetProps 레지스트리 대상이 아니다(모달이 직접 마운트).
  registerWidget('page-picker', PagePickerControl);
  registerWidget('datasource-picker', DataSourcePickerControl);
  // binding-picker = 단일 데이터 바인딩 경로 picker. computedRecipes 의 source(options_from_api/
  // group_items)·candidates 등이 `widget:'binding-picker'` 로 선언됐는데 종전 미등록이라
  // getWidget(null) → ComputedForm.ParamField 가 plain text 로 폴백, 데이터 검색·선택 UI 가
  // 사라졌다. datasource-picker 와 동일 후보 select + 자유 입력 폴백을 재사용한다
  // (ComputedForm 이 binding-picker 에 dataSourceCandidates 를 흘려보냄).
  registerWidget('binding-picker', DataSourcePickerControl);
  // binding-list = 다중 데이터 바인딩 후보(2~N) picker — first_of(candidates) 등 가변 후보 param.
  // 쉼표 구분 단일 입력(직접 만들기 firstOf 와 동형 UX)으로 N개 경로를 받는다.
  registerWidget('binding-list', BindingListControl);
  registerWidget('state-key-picker', StateKeyPickerControl);
  // modal-picker = 레이아웃 modals 선택 picker(openModal 대상). [에러 처리] 탭/데이터소스 동작이
  // 레이아웃 modals 후보를 흘려보내면 후보 select + 자유 입력 폴백(동적 모달 id)을 그린다.
  registerWidget('modal-picker', ModalPickerControl);
  registerWidget('i18n-text', I18nTextControl);
  registerWidget('text', TextControl);
  // 비-스타일 prop(propValue) 편집 위젯. options-list 는 정적 옵션 배열
  // 편집(바인딩이면 디그레이드). **icon-picker 위젯은 코어 비제공** —
  // 아이콘 검색 그리드 UI 는 라이브러리 종속이라 템플릿이 `G7Core.layoutEditor.registerWidget
  // ('icon-picker', ...)` 로 직접 등록한다(미등록 시 ControlRenderer 폴백). 코어 토큰 0.
  registerWidget('options-list', OptionsListControl);
  // 1-b 후속 — 코어 일괄 "요소 ID" 위젯. 바인딩(`{{...}}`) 디그레이드 + HTML 안전
  // 문자 sanitize. coreProps.ts 의 id 컨트롤이 본 위젯을 가리킨다.
  registerWidget('core-id', CoreIdControl);
  // 코어 폼 데이터 연결점(dataKey) 위젯. 폼 컨테이너(Form/Div/
  // Container) capability 가 `coreProps:['id','dataKey']` opt-in 시 노출. dataKey 는 노드
  // 최상위 구조키라 coreProps 가 nodeKey apply 로 패치(props 오염 방지). 바인딩 디그레이드 +
  // 식별자 안전 문자 sanitize(점 경로 허용).
  registerWidget('core-datakey', CoreDataKeyControl);
  // 범용 컴포넌트 영역 picker. [로딩 화면] target/
  // fallback_target·navigate transition_overlay_target·향후 "요소 ID 참조" param 공용.
  // editor-spec param `widget:'component-target-picker'` 선언만으로 어느 폼에서나 동작.
  registerWidget('component-target-picker', ComponentTargetPicker);
  registered = true;
}

/** 등록 상태 리셋 (테스트 격리용) */
export function resetCoreWidgetRegistration(): void {
  registered = false;
}

/** 등록 여부 확인 (테스트/진단용) */
export function isCoreWidgetsRegistered(): boolean {
  return registered && getRegisteredWidgetNames().length > 0;
}
