import { useEffect, useRef } from 'react';
import flatpickr from 'flatpickr';
import { Russian } from 'flatpickr/dist/l10n/ru';
import 'flatpickr/dist/flatpickr.min.css';

interface DatePickerProps {
  placeholder?: string;
  value?: string;
  onChange?: (date: string) => void;
  className?: string;
  /** Включить выбор времени */
  enableTime?: boolean;
  /** Формат даты (по умолчанию d.m.Y или d.m.Y H:i при enableTime) */
  dateFormat?: string;
  /** 24-часовой формат времени (по умолчанию true) */
  time_24hr?: boolean;
}

export function DatePicker({
  placeholder = 'дд.мм.гггг',
  value,
  onChange,
  className = '',
  enableTime = false,
  dateFormat,
  time_24hr = true,
}: DatePickerProps) {
  const inputRef = useRef<HTMLInputElement>(null);
  const fpRef = useRef<flatpickr.Instance | null>(null);

  const resolvedFormat = dateFormat || (enableTime ? 'd.m.Y H:i' : 'd.m.Y');
  const resolvedPlaceholder = placeholder || (enableTime ? 'дд.мм.гггг чч:мм' : 'дд.мм.гггг');

  useEffect(() => {
    if (inputRef.current && !fpRef.current) {
      fpRef.current = flatpickr(inputRef.current, {
        locale: Russian,
        dateFormat: resolvedFormat,
        enableTime,
        time_24hr,
        allowInput: true,
        disableMobile: true,
        onChange: (selectedDates, dateStr) => {
          onChange?.(dateStr);
        },
      });
    }

    return () => {
      fpRef.current?.destroy();
      fpRef.current = null;
    };
  }, [onChange, enableTime, resolvedFormat, time_24hr]);

  useEffect(() => {
    if (fpRef.current) {
      if (value) {
        fpRef.current.setDate(value, false);
      } else {
        // Очищаем дату при пустом значении
        fpRef.current.clear();
      }
    }
  }, [value]);

  return (
    <input
      ref={inputRef}
      type="text"
      className={`form-control ${className}`}
      placeholder={resolvedPlaceholder}
      defaultValue={value}
    />
  );
}
