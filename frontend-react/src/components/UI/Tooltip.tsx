import { useState, useRef, useEffect, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import './Tooltip.css';

interface TooltipProps {
  content: ReactNode;
  children: ReactNode;
  position?: 'top' | 'bottom' | 'left' | 'right';
  delay?: number;
  maxWidth?: number;
}

export function Tooltip({ 
  content, 
  children, 
  position = 'bottom', 
  delay = 200,
  maxWidth = 300 
}: TooltipProps) {
  const [isVisible, setIsVisible] = useState(false);
  const [coords, setCoords] = useState<{ top: number; left: number } | null>(null);
  const wrapperRef = useRef<HTMLSpanElement>(null);
  const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const calculatePosition = () => {
    if (!wrapperRef.current) return null;
    
    const rect = wrapperRef.current.getBoundingClientRect();
    const gap = 8;
    const padding = 16; // Отступ от края экрана
    
    let top = 0;
    let left = 0;

    switch (position) {
      case 'top':
        top = rect.top - gap;
        left = rect.left + rect.width / 2;
        break;
      case 'bottom':
        top = rect.bottom + gap;
        left = rect.left + rect.width / 2;
        break;
      case 'left':
        top = rect.top + rect.height / 2;
        left = rect.left - gap;
        break;
      case 'right':
        top = rect.top + rect.height / 2;
        left = rect.right + gap;
        break;
    }

    // Корректируем позицию, чтобы не выходить за границы экрана
    const tooltipWidth = maxWidth;
    const tooltipHeight = 150; // Примерная высота
    
    // Проверяем правую границу
    if (left + tooltipWidth / 2 > window.innerWidth - padding) {
      left = window.innerWidth - tooltipWidth / 2 - padding;
    }
    // Проверяем левую границу
    if (left - tooltipWidth / 2 < padding) {
      left = tooltipWidth / 2 + padding;
    }
    // Проверяем нижнюю границу
    if (top + tooltipHeight > window.innerHeight - padding && position === 'bottom') {
      top = rect.top - gap - tooltipHeight;
    }

    return { top, left };
  };

  const handleMouseEnter = () => {
    if (timeoutRef.current) clearTimeout(timeoutRef.current);
    
    // Вычисляем позицию сразу
    const pos = calculatePosition();
    if (pos) {
      setCoords(pos);
    }
    
    timeoutRef.current = setTimeout(() => {
      setIsVisible(true);
    }, delay);
  };

  const handleMouseLeave = () => {
    if (timeoutRef.current) clearTimeout(timeoutRef.current);
    setIsVisible(false);
    setCoords(null);
  };

  useEffect(() => {
    return () => {
      if (timeoutRef.current) clearTimeout(timeoutRef.current);
    };
  }, []);

  return (
    <>
      <span
        ref={wrapperRef}
        className="tooltip-wrapper"
        onMouseEnter={handleMouseEnter}
        onMouseLeave={handleMouseLeave}
      >
        {children}
      </span>
      {isVisible && coords && createPortal(
        <div
          className={`custom-tooltip custom-tooltip-${position}`}
          style={{
            top: coords.top,
            left: coords.left,
            maxWidth,
          }}
        >
          {content}
        </div>,
        document.body
      )}
    </>
  );
}
