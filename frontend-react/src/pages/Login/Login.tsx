import { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { TelegramLoginButton } from '../../components/Auth';
import { useAuthStore } from '../../stores/authStore';
import './Login.css';

export const Login: React.FC = () => {
  const navigate = useNavigate();
  const { isAuthenticated, error, clearError } = useAuthStore();

  // Если уже авторизован - редирект на главную
  useEffect(() => {
    if (isAuthenticated) {
      navigate('/', { replace: true });
    }
  }, [isAuthenticated, navigate]);

  // Очищаем ошибки при размонтировании
  useEffect(() => {
    return () => clearError();
  }, [clearError]);

  const handleSuccess = () => {
    navigate('/', { replace: true });
  };

  const handleError = (errorMessage: string) => {
    console.error('Login error:', errorMessage);
  };

  return (
    <div className="login-page">
      <div className="login-container">
        <div className="logo">
          <img src="/logo-icon.svg" alt="First Call" className="logo-icon" />
        </div>
        
        <h1 className="app-title">First Call CRM</h1>
        <p className="app-subtitle">Система управления недвижимостью</p>
        
        <div className="login-form">
          <TelegramLoginButton 
            onSuccess={handleSuccess}
            onError={handleError}
          />
          
          {error && (
            <div className="login-error">
              <span className="material-icons">error_outline</span>
              <span>{error}</span>
            </div>
          )}
        </div>
        
        <p className="info-text">
          Безопасная авторизация через Telegram.<br />
          Ваши данные защищены.
        </p>

        <div className="features">
          <div className="feature-item">
            <span className="material-icons">check_circle</span>
            <span>Быстрый доступ к объявлениям</span>
          </div>
          <div className="feature-item">
            <span className="material-icons">check_circle</span>
            <span>Мгновенные уведомления</span>
          </div>
          <div className="feature-item">
            <span className="material-icons">check_circle</span>
            <span>Умная аналитика</span>
          </div>
        </div>
      </div>
    </div>
  );
};
