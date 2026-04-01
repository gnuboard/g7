import React, { useState, useRef, useEffect } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Input } from '../basic/Input';
import { Icon } from '../basic/Icon';
import { Span } from '../basic/Span';

export interface IconOption {
  value: string;
  label: string;
  faIcon: string;
}

export interface IconSelectProps {
  value?: string;
  onChange?: (value: string) => void;
  options?: IconOption[];
  placeholder?: string;
  searchPlaceholder?: string;
  noResultsText?: string;
  className?: string;
  disabled?: boolean;
  name?: string;
}

const defaultIconOptions: IconOption[] = [
  { value: 'LayoutDashboard', label: 'LayoutDashboard', faIcon: 'tachometer-alt' },
  { value: 'Settings', label: 'Settings', faIcon: 'cog' },
  { value: 'Menu', label: 'Menu', faIcon: 'bars' },
  { value: 'Users', label: 'Users', faIcon: 'users' },
  { value: 'Package', label: 'Package', faIcon: 'cube' },
  { value: 'Puzzle', label: 'Puzzle', faIcon: 'puzzle-piece' },
  { value: 'FileText', label: 'FileText', faIcon: 'file-alt' },
  { value: 'ShoppingCart', label: 'ShoppingCart', faIcon: 'shopping-cart' },
  { value: 'MessageSquare', label: 'MessageSquare', faIcon: 'comment' },
  { value: 'Home', label: 'Home', faIcon: 'home' },
  { value: 'User', label: 'User', faIcon: 'user' },
  { value: 'Search', label: 'Search', faIcon: 'search' },
  { value: 'Bell', label: 'Bell', faIcon: 'bell' },
  { value: 'Mail', label: 'Mail', faIcon: 'envelope' },
  { value: 'Calendar', label: 'Calendar', faIcon: 'calendar' },
  { value: 'Chart', label: 'Chart', faIcon: 'chart-bar' },
  { value: 'Database', label: 'Database', faIcon: 'database' },
  { value: 'Lock', label: 'Lock', faIcon: 'lock' },
  { value: 'Globe', label: 'Globe', faIcon: 'globe' },
  { value: 'Image', label: 'Image', faIcon: 'image' },
];

/**
 * IconSelect 컴포넌트
 * 아이콘 선택 드롭다운
 */
export const IconSelect: React.FC<IconSelectProps> = ({
  value,
  onChange,
  options = defaultIconOptions,
  placeholder,
  searchPlaceholder,
  noResultsText,
  className = '',
  disabled = false,
  name,
}) => {
  const [isOpen, setIsOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const dropdownRef = useRef<HTMLDivElement>(null);

  // value가 faIcon 형식(fas fa-xxx) 또는 Lucide 형식(IconName)일 수 있음
  const selectedOption = options.find((opt) => {
    if (!value) return false;
    // FontAwesome 형식 (fas fa-xxx, far fa-xxx 등)
    if (value.includes('fa-')) {
      const faIconName = value.replace(/^(fas|far|fab|fal|fad)\s+fa-/, '');
      return opt.faIcon === faIconName || opt.faIcon === value;
    }
    // Lucide 형식 또는 직접 매칭
    return opt.value === value || opt.faIcon === value;
  });

  const filteredOptions = options.filter((opt) =>
    opt.label.toLowerCase().includes(searchQuery.toLowerCase())
  );

  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target as Node)) {
        setIsOpen(false);
        setSearchQuery('');
      }
    };

    if (isOpen) {
      document.addEventListener('mousedown', handleClickOutside);
      return () => document.removeEventListener('mousedown', handleClickOutside);
    }
  }, [isOpen]);

  const handleSelect = (option: IconOption) => {
    // FontAwesome 형식으로 변환하여 반환 (fas fa-xxx)
    const faValue = `fas fa-${option.faIcon}`;
    onChange?.(faValue);
    setIsOpen(false);
    setSearchQuery('');
  };

  return (
    <Div ref={dropdownRef} className={`relative ${className}`}>
      <Button
        type="button"
        onClick={() => !disabled && setIsOpen(!isOpen)}
        disabled={disabled}
        className={`
          w-full flex items-center justify-between px-3 py-2
          bg-white dark:bg-gray-800
          border border-gray-300 dark:border-gray-600 rounded-lg
          text-left text-sm
          ${disabled ? 'opacity-50 cursor-not-allowed' : 'hover:border-gray-400 dark:hover:border-gray-500'}
          focus:outline-none focus:ring-2 focus:ring-blue-500
        `}
      >
        <Div className="flex items-center gap-2">
          {selectedOption ? (
            <>
              <Icon name={selectedOption.faIcon} className="w-4 h-4 text-gray-600 dark:text-gray-400" />
              <Span className="text-gray-900 dark:text-white">{selectedOption.label}</Span>
            </>
          ) : (
            <Span className="text-gray-500 dark:text-gray-400">{placeholder}</Span>
          )}
        </Div>
        <Icon
          name="chevron-down"
          className={`w-4 h-4 text-gray-400 dark:text-gray-500 transition-transform ${isOpen ? 'rotate-180' : ''}`}
        />
      </Button>

      {name && <input type="hidden" name={name} value={value || ''} />}

      {isOpen && (
        <Div className="absolute z-50 w-full mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg max-h-72 overflow-hidden">
          <Div className="p-2 border-b border-gray-200 dark:border-gray-700">
            <Input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder={searchPlaceholder}
              className="w-full px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-white"
              autoFocus
            />
          </Div>

          <Div className="max-h-56 overflow-y-auto">
            {filteredOptions.length > 0 ? (
              filteredOptions.map((option) => (
                <Button
                  key={option.value}
                  type="button"
                  onClick={() => handleSelect(option)}
                  className={`
                    w-full flex items-center gap-3 px-3 py-2 text-left text-sm
                    ${selectedOption?.faIcon === option.faIcon
                      ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300'
                      : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700'
                    }
                  `}
                >
                  <Icon name={option.faIcon} className="w-4 h-4" />
                  <Span>{option.label}</Span>
                  {selectedOption?.faIcon === option.faIcon && (
                    <Icon name="check" className="w-4 h-4 ml-auto text-blue-600" />
                  )}
                </Button>
              ))
            ) : (
              <Div className="px-3 py-4 text-sm text-gray-500 dark:text-gray-400 text-center">
                {noResultsText}
              </Div>
            )}
          </Div>
        </Div>
      )}
    </Div>
  );
};
