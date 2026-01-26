import { useEffect, useRef } from 'react';
import flatpickr from 'flatpickr';
import { Russian } from 'flatpickr/dist/l10n/ru';
import 'flatpickr/dist/flatpickr.min.css';

interface DatePickerProps {
  placeholder?: string;
  value?: string;
  onChange?: (date: string) => void;
  className?: string;
}

export function DatePicker({ placeholder = 'дд.мм.гггг', value, onChange, className = '' }: DatePickerProps) {
  const inputRef = useRef<HTMLInputElement>(null);
  const fpRef = useRef<flatpickr.Instance | null>(null);

  useEffect(() => {
    if (inputRef.current && !fpRef.current) {
      fpRef.current = flatpickr(inputRef.current, {
        locale: Russian,
        dateFormat: 'd.m.Y',
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
  }, [onChange]);

  useEffect(() => {
    if (fpRef.current && value) {
      fpRef.current.setDate(value, false);
    }
  }, [value]);

  return (
    <input
      ref={inputRef}
      type="text"
      className={`form-control ${className}`}
      placeholder={placeholder}
      defaultValue={value}
    />
  );
}
