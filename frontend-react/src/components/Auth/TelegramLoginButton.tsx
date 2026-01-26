import { useEffect, useRef, useState } from 'react';
import { authApi } from '../../services/api';
import { useAuthStore } from '../../stores/authStore';
import type { TelegramUser } from '../../types';
import './TelegramLoginButton.css';

// Расширяем Window для Telegram callback
declare global {
  interface Window {
    onTelegramAuth?: (user: TelegramUser) => void;
  }
}

interface TelegramLoginButtonProps {
  onSuccess?: () => void;
  onError?: (error: string) => void;
}

export const TelegramLoginButton: React.FC<TelegramLoginButtonProps> = ({ 
  onSuccess, 
  onError 
}) => {
  const containerRef = useRef<HTMLDivElement>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const login = useAuthStore((state) => state.login);

  useEffect(() => {
    let isMounted = true;
    
    // Функция обработки авторизации Telegram
    const handleTelegramAuth = async (user: TelegramUser) => {
      console.log('[TelegramWidget] Auth data received:', user);
      
      try {
        await login(user);
        onSuccess?.();
      } catch (err: any) {
        const errorMessage = err.response?.data?.message || 'Ошибка авторизации через Telegram';
        if (isMounted) {
          setError(errorMessage);
        }
        onError?.(errorMessage);
      }
    };

    // Устанавливаем глобальный callback для Telegram Widget
    window.onTelegramAuth = handleTelegramAuth;

    // Загружаем Telegram Widget
    const loadTelegramWidget = async () => {
      try {
        // Получаем имя бота
        const response = await authApi.getTelegramBotUsername();
        const botUsername = response.data;

        if (!botUsername) {
          throw new Error('Имя Telegram бота не настроено');
        }

        if (!isMounted || !containerRef.current) return;

        // Очищаем контейнер перед добавлением нового виджета
        containerRef.current.innerHTML = '';

        // Создаём скрипт Telegram Widget
        const script = document.createElement('script');
        script.src = 'https://telegram.org/js/telegram-widget.js?22';
        script.setAttribute('data-telegram-login', botUsername);
        script.setAttribute('data-size', 'large');
        script.setAttribute('data-radius', '12');
        script.setAttribute('data-onauth', 'onTelegramAuth(user)');
        script.setAttribute('data-request-access', 'write');
        script.async = true;

        script.onload = () => {
          if (isMounted) setIsLoading(false);
        };
        script.onerror = () => {
          if (isMounted) {
            setError('Не удалось загрузить Telegram виджет');
            setIsLoading(false);
          }
        };

        containerRef.current.appendChild(script);
      } catch (err: any) {
        console.error('[TelegramWidget] Error loading:', err);
        if (isMounted) {
          setError(err.message || 'Ошибка загрузки Telegram виджета');
          setIsLoading(false);
        }
      }
    };

    loadTelegramWidget();

    // Cleanup - очищаем виджет при размонтировании
    return () => {
      isMounted = false;
      delete window.onTelegramAuth;
      
      // Очищаем контейнер
      if (containerRef.current) {
        containerRef.current.innerHTML = '';
      }
    };
  }, []); // Пустой массив зависимостей - загружаем только при монтировании

  if (error) {
    return (
      <div className="telegram-login-error">
        <span className="material-icons">error_outline</span>
        <p>{error}</p>
      </div>
    );
  }

  return (
    <div className="telegram-login-wrapper">
      {isLoading && (
        <div className="telegram-login-skeleton">
          <div className="skeleton-button">
            <span className="material-icons">telegram</span>
            <span>Загрузка...</span>
          </div>
        </div>
      )}
      <div ref={containerRef} className="telegram-login-container" />
    </div>
  );
};
