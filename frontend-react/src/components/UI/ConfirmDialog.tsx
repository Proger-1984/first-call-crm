import { useEffect, useCallback } from 'react';
import './ConfirmDialog.css';

interface ConfirmDialogProps {
  /** Заголовок диалога */
  title: string;
  /** Текст сообщения */
  message: string;
  /** Текст кнопки подтверждения */
  confirmText?: string;
  /** Текст кнопки отмены (если не задан — показывается только кнопка подтверждения) */
  cancelText?: string;
  /** Визуальный вариант: danger (красный), warning (жёлтый), info (стандартный) */
  variant?: 'danger' | 'warning' | 'info';
  /** Обработчик подтверждения */
  onConfirm: () => void;
  /** Обработчик отмены / закрытия */
  onCancel: () => void;
}

/** Иконка в зависимости от варианта */
function getIcon(variant: string): string {
  switch (variant) {
    case 'danger': return 'warning';
    case 'warning': return 'info';
    default: return 'check_circle';
  }
}

/**
 * Кастомный диалог подтверждения — замена нативным confirm()/alert()
 */
export function ConfirmDialog({
  title,
  message,
  confirmText = 'Ок',
  cancelText,
  variant = 'info',
  onConfirm,
  onCancel,
}: ConfirmDialogProps) {
  /** Закрытие по Escape */
  const handleKeyDown = useCallback((e: KeyboardEvent) => {
    if (e.key === 'Escape') {
      onCancel();
    }
  }, [onCancel]);

  useEffect(() => {
    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [handleKeyDown]);

  /** Закрытие по клику на overlay */
  const handleOverlayClick = (e: React.MouseEvent) => {
    if (e.target === e.currentTarget) {
      onCancel();
    }
  };

  return (
    <div className="confirm-dialog-overlay" onClick={handleOverlayClick}>
      <div className="confirm-dialog">
        <div className="confirm-dialog-header">
          <div className={`confirm-dialog-icon ${variant}`}>
            <span className="material-icons">{getIcon(variant)}</span>
          </div>
          <h3 className="confirm-dialog-title">{title}</h3>
        </div>
        <div className="confirm-dialog-body">
          <p className="confirm-dialog-message">{message}</p>
        </div>
        <div className="confirm-dialog-actions">
          {cancelText && (
            <button className="confirm-dialog-btn cancel" onClick={onCancel}>
              {cancelText}
            </button>
          )}
          <button className={`confirm-dialog-btn confirm ${variant}`} onClick={onConfirm}>
            {confirmText}
          </button>
        </div>
      </div>
    </div>
  );
}

export default ConfirmDialog;
