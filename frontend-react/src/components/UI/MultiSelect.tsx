import { useState, useRef, useEffect, useMemo, useCallback } from 'react';
import './MultiSelect.css';

interface Option {
  value: string;
  label: string;
  color?: string; // Для метро — цвет линии
  sublabel?: string; // Дополнительная информация (например, линия метро)
}

interface MultiSelectProps {
  options: Option[];
  placeholder: string;
  value: string[];
  onChange: (value: string[]) => void;
  className?: string;
  searchable?: boolean; // Включить поиск
  maxDisplayItems?: number; // Максимум элементов для отображения (по умолчанию 1)
}

export function MultiSelect({ 
  options, 
  placeholder, 
  value, 
  onChange, 
  className = '',
  searchable = false,
  maxDisplayItems = 1
}: MultiSelectProps) {
  const [isOpen, setIsOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const ref = useRef<HTMLDivElement>(null);
  const searchInputRef = useRef<HTMLInputElement>(null);
  const optionsRef = useRef<HTMLDivElement>(null);
  const scrollPositionRef = useRef<number>(0);

  // Закрытие при клике вне компонента
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (ref.current && !ref.current.contains(event.target as Node)) {
        setIsOpen(false);
        setSearchQuery('');
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  // Фокус на поле поиска при открытии
  useEffect(() => {
    if (isOpen && searchable && searchInputRef.current) {
      searchInputRef.current.focus();
    }
  }, [isOpen, searchable]);

  // Сохраняем позицию скролла перед обновлением опций
  useEffect(() => {
    if (optionsRef.current && isOpen) {
      // Восстанавливаем позицию скролла после обновления
      const savedPosition = scrollPositionRef.current;
      requestAnimationFrame(() => {
        if (optionsRef.current) {
          optionsRef.current.scrollTop = savedPosition;
        }
      });
    }
  }, [options, isOpen]);

  const handleToggle = useCallback(() => {
    setIsOpen(prev => !prev);
    if (isOpen) {
      setSearchQuery('');
      scrollPositionRef.current = 0;
    }
  }, [isOpen]);

  // Сохраняем позицию скролла при скролле
  const handleScroll = useCallback(() => {
    if (optionsRef.current) {
      scrollPositionRef.current = optionsRef.current.scrollTop;
    }
  }, []);

  // Обработчик клика на опцию — останавливаем всплытие
  const handleOptionClick = useCallback((e: React.MouseEvent, optionValue: string) => {
    e.stopPropagation();
    e.preventDefault();
    
    // Сохраняем позицию скролла перед изменением
    if (optionsRef.current) {
      scrollPositionRef.current = optionsRef.current.scrollTop;
    }
    
    const newValue = value.includes(optionValue)
      ? value.filter(v => v !== optionValue)
      : [...value, optionValue];
    onChange(newValue);
  }, [value, onChange]);

  // Фильтрация опций по поиску
  const filteredOptions = useMemo(() => {
    if (!searchQuery.trim()) return options;
    const query = searchQuery.toLowerCase();
    return options.filter(o => 
      o.label.toLowerCase().includes(query) || 
      (o.sublabel && o.sublabel.toLowerCase().includes(query))
    );
  }, [options, searchQuery]);

  // Текст для отображения (сокращённый формат)
  const displayText = useMemo(() => {
    if (value.length === 0) return placeholder;
    
    const selectedOptions = options.filter(o => value.includes(o.value));
    
    if (selectedOptions.length <= maxDisplayItems) {
      return selectedOptions.map(o => o.label).join(', ');
    }
    
    // Показываем первые N элементов + счётчик остальных
    const visibleLabels = selectedOptions.slice(0, maxDisplayItems).map(o => o.label).join(', ');
    const remaining = selectedOptions.length - maxDisplayItems;
    return `${visibleLabels} +${remaining}`;
  }, [value, options, placeholder, maxDisplayItems]);

  return (
    <div
      ref={ref}
      className={`multiselect ${isOpen ? 'active' : ''} ${className}`}
    >
      <div className="multiselect-toggle" onClick={handleToggle}>
        <span className={value.length > 0 ? 'has-value' : ''}>{displayText}</span>
        <span className="material-icons">expand_more</span>
      </div>
      <div className="multiselect-dropdown" onClick={(e) => e.stopPropagation()}>
        {searchable && (
          <div className="multiselect-search">
            <span className="material-icons">search</span>
            <input
              ref={searchInputRef}
              type="text"
              placeholder="Поиск..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
            />
          </div>
        )}
        <div 
          ref={optionsRef}
          className="multiselect-options"
          onScroll={handleScroll}
        >
          {filteredOptions.length === 0 ? (
            <div className="multiselect-empty">Ничего не найдено</div>
          ) : (
            filteredOptions.map(option => (
              <div
                key={option.value}
                className={`multiselect-option ${value.includes(option.value) ? 'selected' : ''}`}
                onClick={(e) => handleOptionClick(e, option.value)}
              >
                <input
                  type="checkbox"
                  checked={value.includes(option.value)}
                  readOnly
                />
                <span className="option-label">
                  {option.color && (
                    <span 
                      className="option-color-dot" 
                      style={{ backgroundColor: `#${option.color.replace('#', '')}` }}
                    />
                  )}
                  <span className="option-text">
                    {option.label}
                    {option.sublabel && (
                      <span className="option-sublabel">{option.sublabel}</span>
                    )}
                  </span>
                </span>
              </div>
            ))
          )}
        </div>
      </div>
    </div>
  );
}
