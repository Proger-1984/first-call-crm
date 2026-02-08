import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuthStore } from '../../stores/authStore';
import { useUIStore } from '../../stores/uiStore';
import { userApi } from '../../services/api';
import { Tooltip } from '../UI/Tooltip';
import './Header.css';

export function Header() {
  const { user, logout, updateUser } = useAuthStore();
  const { soundEnabled, toggleSound, playNotificationSound } = useUIStore();
  const navigate = useNavigate();
  const [isDropdownOpen, setIsDropdownOpen] = useState(false);
  const [isPhoneDropdownOpen, setIsPhoneDropdownOpen] = useState(false);
  const [isLoadingStatus, setIsLoadingStatus] = useState(false);

  // Статус автозвонка берём из user (из /me/info)
  const autoCallStatus = user?.auto_call ?? null;

  // Обновление статуса автозвонка
  const handleSetAutoCallStatus = async (status: boolean) => {
    setIsLoadingStatus(true);
    try {
      await userApi.setAutoCallStatus(status);
      // Обновляем user в store
      if (user) {
        updateUser({ ...user, auto_call: status });
      }
      setIsPhoneDropdownOpen(false);
    } catch (error) {
      console.error('Ошибка изменения статуса автозвонка:', error);
    } finally {
      setIsLoadingStatus(false);
    }
  };

  // Парсим текст подписки (может содержать \n или \\n)
  const parseSubscriptionText = () => {
    if (!user?.subscription_status_text) return { date: '', remaining: '' };
    // Поддержка как реального \n так и текстового \\n
    const text = user.subscription_status_text.replace(/\\n/g, '\n');
    const parts = text.split('\n');
    return {
      date: parts[0]?.trim() || '',
      remaining: parts[1]?.trim() || ''
    };
  };

  const subscription = parseSubscriptionText();

  // Получаем инициалы пользователя
  const getInitials = (name?: string) => {
    if (!name) return 'ИИ';
    return name
      .split(' ')
      .map(n => n[0])
      .join('')
      .toUpperCase()
      .slice(0, 2);
  };

  return (
    <header className="header">
      <div className="header-left">
        <img
          src="/logo.svg"
          alt="First Call"
          className="header-logo"
          onError={(e) => {
            e.currentTarget.style.display = 'none';
          }}
        />
      </div>

      <div className="header-right">
        <a
          href="https://t.me/firstcall_support"
          target="_blank"
          rel="noopener noreferrer"
          className="header-btn"
        >
          <span className="material-icons">support_agent</span>
          <span>Поддержка</span>
        </a>

        <button className="header-btn primary" onClick={() => navigate('/billing')}>
          <span className="material-icons">account_balance_wallet</span>
          <span>Биллинг</span>
        </button>

        {subscription.date && (
          <div className="access-info">
            <div className="access-date">{subscription.date}</div>
            {subscription.remaining && (
              <div className="access-remaining">{subscription.remaining}</div>
            )}
          </div>
        )}

        <Tooltip 
          content={
            <div className="header-tooltip">
              <div className="header-tooltip-title">Мобильное приложение</div>
              <div className="header-tooltip-status">
                <span className={`status-dot ${user?.app_connected ? 'online' : 'offline'}`}></span>
                <span>{user?.app_connected ? 'Подключено' : 'Не подключено'}</span>
              </div>
            </div>
          }
          position="bottom"
        >
          <div className="header-icon">
            <span className="material-icons">phonelink</span>
            <span className={`connection-indicator ${user?.app_connected ? 'connected' : 'disconnected'}`}></span>
          </div>
        </Tooltip>

        <Tooltip 
          content={
            <div className="header-tooltip">
              <div className="header-tooltip-title">Звуковые уведомления</div>
              <div className="header-tooltip-status">
                <span className={`status-dot ${soundEnabled ? 'online' : 'offline'}`}></span>
                <span>{soundEnabled ? 'Включены' : 'Выключены'}</span>
              </div>
            </div>
          }
          position="bottom"
        >
          <div 
            className={`header-icon sound-toggle ${soundEnabled ? 'enabled' : 'disabled'}`}
            onClick={() => {
              toggleSound();
              // Проиграть звук при включении для демонстрации
              if (!soundEnabled) {
                setTimeout(() => playNotificationSound(), 100);
              }
            }}
          >
            <span className="material-icons">
              {soundEnabled ? 'notifications_active' : 'notifications_off'}
            </span>
          </div>
        </Tooltip>

        <div className="phone-status-wrapper">
          <Tooltip 
            content={
              <div className="header-tooltip">
                <div className="header-tooltip-title">Автозвонок</div>
                <div className="header-tooltip-status">
                  <span className={`status-dot ${autoCallStatus === true ? 'online' : 'offline'}`}></span>
                  <span>{autoCallStatus === null ? 'Не настроен' : autoCallStatus ? 'Готов к звонкам' : 'Не беспокоить'}</span>
                </div>
              </div>
            }
            position="bottom"
          >
            <div
              className="header-icon"
              onClick={() => setIsPhoneDropdownOpen(!isPhoneDropdownOpen)}
            >
              <span className="material-icons">phone</span>
              <span className={`phone-status-indicator ${autoCallStatus === true ? 'ready' : autoCallStatus === false ? 'busy' : ''}`}></span>
            </div>
          </Tooltip>

          {isPhoneDropdownOpen && (
            <>
              <div
                className="dropdown-overlay"
                onClick={() => setIsPhoneDropdownOpen(false)}
              />
              <div className="phone-dropdown">
                <button
                  className={`dropdown-item ${autoCallStatus === false ? 'active' : ''}`}
                  onClick={() => handleSetAutoCallStatus(false)}
                  disabled={isLoadingStatus}
                >
                  <span className="material-icons icon-danger">block</span>
                  <span>Не беспокоить</span>
                  {autoCallStatus === false && <span className="material-icons check">check</span>}
                </button>
                <button
                  className={`dropdown-item ${autoCallStatus === true ? 'active' : ''}`}
                  onClick={() => handleSetAutoCallStatus(true)}
                  disabled={isLoadingStatus}
                >
                  <span className="material-icons icon-success">check_circle</span>
                  <span>Готов к звонкам</span>
                  {autoCallStatus === true && <span className="material-icons check">check</span>}
                </button>
              </div>
            </>
          )}
        </div>

        <div className="user-profile">
          <Tooltip 
            content={
              <div className="header-tooltip">
                <div className="header-tooltip-title">{user?.name || 'Пользователь'}</div>
                {user?.email && <div style={{ fontSize: '11px', color: 'var(--text-secondary)' }}>{user.email}</div>}
              </div>
            }
            position="bottom"
          >
            <div
              className="user-avatar-button"
              onClick={() => setIsDropdownOpen(!isDropdownOpen)}
            >
              <div className="user-avatar">
                {getInitials(user?.name)}
              </div>
            </div>
          </Tooltip>

          {isDropdownOpen && (
            <>
              <div
                className="dropdown-overlay"
                onClick={() => setIsDropdownOpen(false)}
              />
              <div className="user-dropdown">
                <button
                  className="dropdown-item"
                  onClick={() => {
                    setIsDropdownOpen(false);
                    navigate('/profile');
                  }}
                >
                  <span className="material-icons">person</span>
                  <span>Профиль</span>
                </button>

                <button
                  className="dropdown-item"
                  onClick={() => {
                    setIsDropdownOpen(false);
                    navigate('/settings');
                  }}
                >
                  <span className="material-icons">settings</span>
                  <span>Настройки</span>
                </button>

                <div className="dropdown-divider" />

                <button
                  className="dropdown-item"
                  onClick={async () => {
                    setIsDropdownOpen(false);
                    await logout();
                    navigate('/login');
                  }}
                >
                  <span className="material-icons">exit_to_app</span>
                  <span>Выход</span>
                </button>
              </div>
            </>
          )}
        </div>
      </div>
    </header>
  );
}
