import { useState, useRef, useEffect } from 'react';
import './MultiSelect.css';

interface Option {
  value: string;
  label: string;
}

interface MultiSelectProps {
  options: Option[];
  placeholder: string;
  value: string[];
  onChange: (value: string[]) => void;
  className?: string;
}

export function MultiSelect({ options, placeholder, value, onChange, className = '' }: MultiSelectProps) {
  const [isOpen, setIsOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  // Закрытие при клике вне компонента
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (ref.current && !ref.current.contains(event.target as Node)) {
        setIsOpen(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const handleToggle = () => {
    setIsOpen(!isOpen);
  };

  const handleOptionClick = (optionValue: string) => {
    const newValue = value.includes(optionValue)
      ? value.filter(v => v !== optionValue)
      : [...value, optionValue];
    onChange(newValue);
  };

  // Текст для отображения
  const displayText = value.length > 0
    ? options.filter(o => value.includes(o.value)).map(o => o.label).join(', ')
    : placeholder;

  return (
    <div
      ref={ref}
      className={`multiselect ${isOpen ? 'active' : ''} ${className}`}
    >
      <div className="multiselect-toggle" onClick={handleToggle}>
        <span className={value.length > 0 ? 'has-value' : ''}>{displayText}</span>
        <span className="material-icons">expand_more</span>
      </div>
      <div className="multiselect-dropdown">
        {options.map(option => (
          <div
            key={option.value}
            className="multiselect-option"
            onClick={() => handleOptionClick(option.value)}
          >
            <input
              type="checkbox"
              checked={value.includes(option.value)}
              onChange={() => { }} // Handled by div click
              id={`option-${option.value}`}
            />
            <label htmlFor={`option-${option.value}`}>{option.label}</label>
          </div>
        ))}
      </div>
    </div>
  );
}
