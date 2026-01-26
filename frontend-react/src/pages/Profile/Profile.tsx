import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { profileApi, authApi, subscriptionsApi } from '../../services/api';
import type { DownloadInfo } from '../../services/api';
import type { UserSubscriptionFull } from '../../types';
import { useAuthStore } from '../../stores/authStore';
import './Profile.css';

declare global {
  interface Window {
    Telegram?: {
      Login: {
        auth: (options: {
          bot_id: string;
          request_access?: boolean;
          lang?: string;
        }, callback: (data: any) => void) => void;
      };
    };
  }
}

export const Profile = () => {
  const navigate = useNavigate();
  const { user, isAuthenticated, updateUser } = useAuthStore();
  const [appLogin, setAppLogin] = useState<string>('');
  const [downloadInfo, setDownloadInfo] = useState<DownloadInfo | null>(null);
  const [subscriptions, setSubscriptions] = useState<UserSubscriptionFull[]>([]);
  const [isGeneratingPassword, setIsGeneratingPassword] = useState(false);
  const [isDownloading, setIsDownloading] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
  const [telegramBotId, setTelegramBotId] = useState<string>('');

  useEffect(() => {
    // Загружаем данные только если пользователь авторизован
    if (isAuthenticated) {
      loadProfileData();
      loadTelegramBotId();
    }
  }, [isAuthenticated]);

  const loadProfileData = async () => {
    setIsLoading(true);
    try {
      // Загружаем последовательно, чтобы избежать race condition при refresh токена
      const loginResponse = await profileApi.getAppLogin();
      setAppLogin(loginResponse.data.data.login);
      
      const downloadResponse = await profileApi.getDownloadInfo();
      setDownloadInfo(downloadResponse.data.data);
      
      const subscriptionsResponse = await subscriptionsApi.getAll();
      setSubscriptions(subscriptionsResponse.data.data.subscriptions);
    } catch (error) {
      console.error('Ошибка загрузки данных профиля:', error);
      // Не редиректим — пусть интерцептор сам разберётся
    } finally {
      setIsLoading(false);
    }
  };

  const loadTelegramBotId = async () => {
    try {
      const response = await fetch('/api/v1/config/telegram-bot-username');
      const botUsername = await response.text();
      // Для Telegram Login Widget нужен bot_id, но мы используем username
      setTelegramBotId(botUsername);
    } catch (error) {
      console.error('Ошибка загрузки Telegram bot ID:', error);
    }
  };

  const handleGeneratePassword = async () => {
    setIsGeneratingPassword(true);
    setMessage(null);
    
    try {
      const response = await profileApi.generatePassword();
      setMessage({ 
        type: 'success', 
        text: response.data.message || 'Новый пароль отправлен в Telegram' 
      });
    } catch (error: any) {
      setMessage({ 
        type: 'error', 
        text: error.response?.data?.message || 'Ошибка генерации пароля' 
      });
    } finally {
      setIsGeneratingPassword(false);
    }
  };

  const handleDownloadApp = async () => {
    setIsDownloading(true);
    setMessage(null);
    
    try {
      await profileApi.downloadAndroidApp();
      setMessage({ type: 'success', text: 'Скачивание началось' });
    } catch (error: any) {
      setMessage({ 
        type: 'error', 
        text: 'Ошибка скачивания. Попробуйте позже.' 
      });
    } finally {
      setIsDownloading(false);
    }
  };

  const handleTelegramRebind = async () => {
    // Получаем имя бота
    try {
      const response = await fetch('/api/v1/config/telegram-bot-username');
      const botUsername = await response.text();
      
      if (!botUsername) {
        setMessage({ type: 'error', text: 'Не удалось получить имя бота Telegram' });
        return;
      }
      
      // Открываем окно перепривязки с виджетом Telegram
      const width = 450;
      const height = 350;
      const left = (window.innerWidth - width) / 2;
      const top = (window.innerHeight - height) / 2;
      
      window.open(
        `/telegram-widget.html?bot=${encodeURIComponent(botUsername)}&rebind=true`,
        'telegram_rebind',
        `width=${width},height=${height},left=${left},top=${top}`
      );
    } catch (error) {
      setMessage({ type: 'error', text: 'Ошибка при открытии окна перепривязки' });
    }
  };

  // Глобальная функция для обработки callback от Telegram виджета
  useEffect(() => {
    // Функция вызывается из popup окна с виджетом Telegram
    (window as any).onTelegramAuth = async (user: any) => {
      console.log('Telegram rebind callback:', user);
      
      try {
        await profileApi.rebindTelegram(user);
        
        // Обновляем данные пользователя в store
        const meResponse = await authApi.me();
        updateUser(meResponse.data.data.user);
        
        setMessage({ type: 'success', text: 'Telegram успешно перепривязан' });
      } catch (error: any) {
        console.error('Rebind error:', error);
        setMessage({ 
          type: 'error', 
          text: error.response?.data?.message || 'Ошибка перепривязки Telegram' 
        });
      }
    };

    return () => {
      delete (window as any).onTelegramAuth;
    };
  }, [updateUser]);

  if (isLoading && !appLogin) {
    return (
      <div className="profile-page">
        <h1 className="page-title">
          <span className="material-icons">person</span>
          Профиль пользователя
        </h1>
        <div className="profile-loading">
          <span className="material-icons spinning">sync</span>
          Загрузка...
        </div>
      </div>
    );
  }

  return (
    <div className="profile-page">
      <h1 className="page-title">
        <span className="material-icons">person</span>
        Профиль пользователя
      </h1>

      {message && (
        <div className={`profile-message ${message.type}`}>
          <span className="material-icons">
            {message.type === 'success' ? 'check_circle' : 'error'}
          </span>
          {message.text}
        </div>
      )}

      <div className="profile-grid">
        {/* Данные для приложения */}
        <div className="profile-card">
          <div className="profile-card-header">
            <span className="material-icons">smartphone</span>
            <h2>Данные для приложения</h2>
          </div>
          
          <div className="profile-card-body">
            <div className="profile-field">
              <label>Логин для входа</label>
              <div className="profile-value">
                <input 
                  type="text" 
                  value={appLogin} 
                  readOnly 
                  className="profile-input"
                />
                <button 
                  className="btn-icon" 
                  onClick={() => navigator.clipboard.writeText(appLogin)}
                  title="Копировать"
                >
                  <span className="material-icons">content_copy</span>
                </button>
              </div>
            </div>

            <div className="profile-field">
              <label>Пароль</label>
              <div className="profile-value">
                <button 
                  className="btn btn-warning"
                  onClick={handleGeneratePassword}
                  disabled={isGeneratingPassword}
                >
                  <span className="material-icons">
                    {isGeneratingPassword ? 'hourglass_empty' : 'refresh'}
                  </span>
                  {isGeneratingPassword ? 'Генерация...' : 'Сгенерировать новый пароль'}
                </button>
              </div>
              <p className="profile-hint">
                Новый пароль будет отправлен в Telegram
              </p>
            </div>

            <div className="profile-field">
              <label>Скачать приложение</label>
              <div className="profile-value">
                {downloadInfo?.android.available ? (
                  <button 
                    className="btn btn-download"
                    onClick={handleDownloadApp}
                    disabled={isDownloading}
                  >
                    <span className="material-icons">android</span>
                    <span>
                      {isDownloading ? 'Скачивание...' : 'Скачать для Android'}
                      {downloadInfo.android.size_formatted && (
                        <small> ({downloadInfo.android.size_formatted})</small>
                      )}
                    </span>
                  </button>
                ) : (
                  <div className="download-unavailable">
                    <span className="material-icons">info</span>
                    Приложение временно недоступно
                  </div>
                )}
              </div>
            </div>
          </div>
        </div>

        {/* Telegram */}
        <div className="profile-card">
          <div className="profile-card-header">
            <span className="material-icons">send</span>
            <h2>Telegram</h2>
          </div>
          
          <div className="profile-card-body">
            <div className="telegram-status connected">
              <span className="material-icons">check_circle</span>
              <div>
                <strong>Telegram подключен</strong>
                {user?.name && <p>{user.name}</p>}
              </div>
              {user?.telegram_photo_url && (
                <img 
                  src={user.telegram_photo_url} 
                  alt="Telegram avatar" 
                  className="telegram-avatar"
                />
              )}
            </div>

            <div className="profile-field">
              <button 
                className="btn btn-secondary"
                onClick={handleTelegramRebind}
              >
                <span className="material-icons">link</span>
                Перепривязать Telegram
              </button>
            </div>

            <div className="telegram-hint-box">
              <span className="material-icons">info</span>
              <div>
                <strong>Как сменить аккаунт Telegram?</strong>
                <p>Telegram запоминает вашу авторизацию в браузере. Чтобы войти другим аккаунтом, откройте страницу в режиме инкогнито или выйдите из Telegram Web.</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Мои подписки */}
      <div className="profile-subscriptions">
        <h2 className="profile-section-title">
          <span className="material-icons">subscriptions</span>
          Мои подписки
        </h2>
        
        {subscriptions.length === 0 ? (
          <div className="profile-card">
            <div className="profile-card-body">
              <div className="subscriptions-empty">
                <span className="material-icons">inbox</span>
                <p>У вас пока нет подписок</p>
                <button 
                  className="btn btn-primary" 
                  onClick={() => navigate('/tariffs')}
                >
                  <span className="material-icons">add</span>
                  Выбрать тариф
                </button>
              </div>
            </div>
          </div>
        ) : (
          <div className="subscriptions-list">
            {subscriptions.map((sub) => (
              <div className={`subscription-card ${sub.status}`} key={sub.id}>
                <div className="subscription-header">
                  <div className="subscription-location">
                    <span className="material-icons">location_on</span>
                    {sub.location_name}
                  </div>
                  <span className={`subscription-badge ${sub.status}`}>
                    {sub.status === 'active' && 'Активна'}
                    {sub.status === 'pending' && 'Ожидает'}
                    {sub.status === 'expired' && 'Истекла'}
                    {sub.status === 'cancelled' && 'Отменена'}
                  </span>
                </div>
                <div className="subscription-details">
                  <div className="subscription-detail">
                    <span className="detail-label">Категория:</span>
                    <span className="detail-value">{sub.category_name}</span>
                  </div>
                  <div className="subscription-detail">
                    <span className="detail-label">Тариф:</span>
                    <span className="detail-value">{sub.tariff_name}</span>
                  </div>
                  {sub.end_date && (
                    <div className="subscription-detail">
                      <span className="detail-label">
                        {sub.status === 'active' ? 'Действует до:' : 'Истекла:'}
                      </span>
                      <span className="detail-value">
                        {new Date(sub.end_date).toLocaleDateString('ru-RU', {
                          day: '2-digit',
                          month: '2-digit',
                          year: 'numeric',
                          hour: '2-digit',
                          minute: '2-digit'
                        })}
                      </span>
                    </div>
                  )}
                </div>
                {sub.status === 'expired' && (
                  <div className="subscription-actions">
                    <button className="btn btn-primary btn-sm">
                      <span className="material-icons">refresh</span>
                      Продлить
                    </button>
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
};

export default Profile;
