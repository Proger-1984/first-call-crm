import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuthStore } from '../../stores/authStore';
import type { TelegramUser } from '../../types';
import './Login.css';

/**
 * Тестовая страница авторизации для локальной разработки
 * В production используется реальный Telegram виджет
 */
export const LoginTest: React.FC = () => {
  const navigate = useNavigate();
  const { login, error, isLoading } = useAuthStore();
  const [testUserId] = useState(Math.floor(Math.random() * 1000000));

  const handleTestLogin = async () => {
    // Создаём тестовые данные Telegram пользователя
    const testTelegramData: TelegramUser = {
      id: testUserId,
      first_name: 'Test',
      last_name: 'User',
      username: 'testuser',
      photo_url: '',
      auth_date: Math.floor(Date.now() / 1000),
      hash: 'test_hash_' + Math.random().toString(36).substring(7),
    };

    try {
      await login(testTelegramData);
      navigate('/', { replace: true });
    } catch (error) {
      console.error('Test login failed:', error);
    }
  };

  return (
    <div className="login-page">
      <div className="login-container">
        <div className="logo">
          <span className="material-icons">home_work</span>
        </div>
        
        <h1 className="app-title">First Call CRM</h1>
        <p className="app-subtitle">Система управления недвижимостью</p>
        
        <div className="login-form">
          <div style={{
            background: 'linear-gradient(135deg, #fff3cd, #ffeaa7)',
            padding: '16px',
            borderRadius: '12px',
            marginBottom: '20px',
            border: '2px dashed #f39c12',
          }}>
            <div style={{
              display: 'flex',
              alignItems: 'center',
              gap: '12px',
              marginBottom: '8px',
            }}>
              <span className="material-icons" style={{ color: '#f39c12' }}>
                warning
              </span>
              <strong style={{ color: '#856404' }}>Режим тестирования</strong>
            </div>
            <p style={{
              margin: '0',
              fontSize: '13px',
              color: '#856404',
              lineHeight: '1.5',
            }}>
              Это тестовая версия авторизации для локальной разработки.
              <br />
              <small>Реальный Telegram виджет требует настройки домена в BotFather.</small>
            </p>
          </div>

          <button
            onClick={handleTestLogin}
            disabled={isLoading}
            style={{
              width: '100%',
              padding: '16px 32px',
              background: 'linear-gradient(135deg, #0088cc, #006699)',
              color: 'white',
              border: 'none',
              borderRadius: '12px',
              fontSize: '16px',
              fontWeight: '600',
              cursor: isLoading ? 'not-allowed' : 'pointer',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              gap: '12px',
              transition: 'all 0.3s ease',
              opacity: isLoading ? 0.6 : 1,
            }}
            onMouseEnter={(e) => {
              if (!isLoading) {
                e.currentTarget.style.transform = 'translateY(-2px)';
                e.currentTarget.style.boxShadow = '0 8px 16px rgba(0, 136, 204, 0.3)';
              }
            }}
            onMouseLeave={(e) => {
              e.currentTarget.style.transform = 'translateY(0)';
              e.currentTarget.style.boxShadow = 'none';
            }}
          >
            <span className="material-icons" style={{ fontSize: '24px' }}>
              {isLoading ? 'hourglass_empty' : 'telegram'}
            </span>
            <span>
              {isLoading ? 'Авторизация...' : 'Войти (Тестовый режим)'}
            </span>
          </button>

          {error && (
            <div className="login-error">
              <span className="material-icons">error_outline</span>
              <span>{error}</span>
            </div>
          )}

          <div style={{
            marginTop: '20px',
            padding: '12px',
            background: 'var(--bg-main)',
            borderRadius: '8px',
            fontSize: '12px',
            color: 'var(--text-secondary)',
          }}>
            <strong>ID тестового пользователя:</strong> {testUserId}
            <br />
            <small>Этот ID будет использован для авторизации</small>
          </div>
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
