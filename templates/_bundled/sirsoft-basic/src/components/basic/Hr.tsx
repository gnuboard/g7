import React from 'react';

export interface HrProps extends React.HTMLAttributes<HTMLHRElement> {
  ref?: React.Ref<HTMLHRElement>;
}

/**
 * 기본 hr (수평선) 컴포넌트
 */
export const Hr = React.forwardRef<HTMLHRElement, HrProps>(({
  className = '',
  ...props
}, ref) => {
  return (
    <hr
      ref={ref}
      className={className}
      {...props}
    />
  );
});
