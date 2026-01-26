import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuthStore } from '../../stores/authStore';
import { userApi } from '../../services/api';
import './Header.css';

export function Header() {
  const { user, logout, updateUser } = useAuthStore();
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

        <button className="header-btn primary">
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

        <div className="header-icon" title={user?.app_connected ? 'Приложение подключено' : 'Приложение не подключено'}>
          <span className="material-icons">phonelink</span>
          <span className={`connection-indicator ${user?.app_connected ? 'connected' : 'disconnected'}`}></span>
        </div>

        <div className="phone-status-wrapper">
          <div
            className="header-icon"
            title={autoCallStatus === null ? 'Статус автозвонка' : autoCallStatus ? 'Готов' : 'Не беспокоить'}
            onClick={() => setIsPhoneDropdownOpen(!isPhoneDropdownOpen)}
          >
            <span className="material-icons">phone</span>
            <span className={`phone-status-indicator ${autoCallStatus === true ? 'ready' : autoCallStatus === false ? 'busy' : ''}`}></span>
          </div>

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
          <div
            className="user-avatar-button"
            onClick={() => setIsDropdownOpen(!isDropdownOpen)}
            title={user?.name || 'Профиль'}
          >
            <div className="user-avatar">
              {getInitials(user?.name)}
            </div>
          </div>

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
