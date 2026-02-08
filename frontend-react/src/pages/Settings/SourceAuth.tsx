import { useState, useEffect, useCallback } from 'react';
import { sourceAuthApi, type SourceAuthStatus } from '../../services/api';
import './SourceAuth.css';

interface SourceAuthProps {
  onError: (error: string | null) => void;
}

type SourceType = 'cian' | 'avito';

interface SourceConfig {
  name: string;
  domain: string;
  icon: string;
  color: string;
  instructions: string[];
}

const SOURCE_CONFIGS: Record<SourceType, SourceConfig> = {
  cian: {
    name: 'CIAN',
    domain: 'cian.ru',
    icon: 'apartment',
    color: '#0468ff',
    instructions: [
      'Откройте cian.ru в браузере и авторизуйтесь в своём аккаунте',
      'Нажмите F12 → вкладка "Сеть" (Network) → обновите страницу (F5)',
      'Кликните на любой запрос к cian.ru → вкладка "Заголовки" (Headers)',
      'Найдите строку "Cookie:" → выделите и скопируйте всё значение после двоеточия',
    ],
  },
  avito: {
    name: 'Avito',
    domain: 'avito.ru',
    icon: 'store',
    color: '#00aaff',
    instructions: [
      'Откройте avito.ru в браузере и авторизуйтесь в своём аккаунте',
      'Нажмите F12 → вкладка "Сеть" (Network) → обновите страницу (F5)',
      'Кликните на любой запрос к avito.ru → вкладка "Заголовки" (Headers)',
      'Найдите строку "Cookie:" → выделите и скопируйте всё значение после двоеточия',
    ],
  },
};

export const SourceAuth = ({ onError }: SourceAuthProps) => {
  const [statuses, setStatuses] = useState<Record<SourceType, SourceAuthStatus | null>>({
    cian: null,
    avito: null,
  });
  const [isLoading, setIsLoading] = useState(true);
  const [expandedSource, setExpandedSource] = useState<SourceType | null>(null);
  const [manualCookies, setManualCookies] = useState<Record<SourceType, string>>({
    cian: '',
    avito: '',
  });
  const [isSaving, setIsSaving] = useState<SourceType | null>(null);
  const [isRevalidating, setIsRevalidating] = useState<SourceType | null>(null);

  // Загрузка статусов при монтировании
  useEffect(() => {
    loadStatuses();
  }, []);

  const loadStatuses = async () => {
    setIsLoading(true);
    try {
      const response = await sourceAuthApi.getStatus();
      setStatuses({
        cian: response.data.data.cian,
        avito: response.data.data.avito,
      });
    } catch (err) {
      console.error('Ошибка загрузки статусов авторизации:', err);
      onError('Не удалось загрузить статусы авторизации');
    } finally {
      setIsLoading(false);
    }
  };

  // Сохранение кук вручную
  const saveCookiesManually = useCallback(async (source: SourceType) => {
    const cookies = manualCookies[source].trim();
    if (!cookies) {
      onError('Введите куки');
      return;
    }

    setIsSaving(source);
    onError(null);
    
    try {
      const response = await sourceAuthApi.saveCookies(source, cookies);
      
      if (response.data.data.success) {
        // Обновляем статус
        await loadStatuses();
        setManualCookies(prev => ({ ...prev, [source]: '' }));
        setExpandedSource(null);
      } else {
        onError(response.data.data.message || 'Не удалось сохранить куки');
      }
    } catch (err: unknown) {
      console.error('Ошибка сохранения кук:', err);
      // Извлекаем сообщение об ошибке из ответа бэкенда
      let errorMessage = 'Не удалось сохранить куки';
      if (err && typeof err === 'object' && 'response' in err) {
        const axiosError = err as { response?: { data?: { message?: string; data?: { message?: string } } } };
        errorMessage = axiosError.response?.data?.message 
          || axiosError.response?.data?.data?.message 
          || errorMessage;
      } else if (err instanceof Error) {
        errorMessage = err.message;
      }
      onError(errorMessage);
    } finally {
      setIsSaving(null);
    }
  }, [manualCookies, onError]);

  // Удаление авторизации
  const deleteAuth = useCallback(async (source: SourceType) => {
    if (!confirm(`Удалить авторизацию ${SOURCE_CONFIGS[source].name}?`)) {
      return;
    }

    try {
      await sourceAuthApi.deleteCookies(source);
      await loadStatuses();
    } catch (err) {
      console.error('Ошибка удаления авторизации:', err);
      onError('Не удалось удалить авторизацию');
    }
  }, [onError]);

  // Перепроверка авторизации
  const revalidateAuth = useCallback(async (source: SourceType) => {
    setIsRevalidating(source);
    onError(null);

    try {
      const response = await sourceAuthApi.revalidate(source);
      
      if (response.data.data.success) {
        await loadStatuses();
        // Можно показать уведомление об успехе
      } else {
        onError(response.data.data.message || 'Не удалось перепроверить авторизацию');
      }
    } catch (err: unknown) {
      console.error('Ошибка перепроверки авторизации:', err);
      let errorMessage = 'Не удалось перепроверить авторизацию';
      if (err && typeof err === 'object' && 'response' in err) {
        const axiosError = err as { response?: { data?: { message?: string; data?: { message?: string } } } };
        errorMessage = axiosError.response?.data?.message 
          || axiosError.response?.data?.data?.message 
          || errorMessage;
      }
      onError(errorMessage);
    } finally {
      setIsRevalidating(null);
    }
  }, [onError]);

  const renderSourceCard = (source: SourceType) => {
    const config = SOURCE_CONFIGS[source];
    const status = statuses[source];
    const isExpanded = expandedSource === source;

    return (
      <div className={`source-auth-card ${status?.is_authorized ? 'authorized' : ''}`} key={source}>
        <div 
          className="source-auth-header"
          onClick={() => setExpandedSource(isExpanded ? null : source)}
        >
          <div className="source-auth-info">
            <span 
              className="material-icons source-icon" 
              style={{ color: config.color }}
            >
              {config.icon}
            </span>
            <div className="source-auth-title">
              <h3>{config.name}</h3>
              <span className={`source-auth-status ${status?.is_authorized ? 'active' : 'inactive'}`}>
                {status?.is_authorized ? 'Авторизован' : 'Не авторизован'}
              </span>
            </div>
          </div>
          <span className={`material-icons expand-icon ${isExpanded ? 'expanded' : ''}`}>
            expand_more
          </span>
        </div>

        {/* Информация о подписке (если авторизован) */}
        {status?.is_authorized && status.subscription_info && (
          <div className="source-subscription-info">
            {/* Поля CIAN */}
            {status.subscription_info.tariff && (
              <div className="subscription-item">
                <span className="material-icons">card_membership</span>
                <span>Тариф: {status.subscription_info.tariff}</span>
              </div>
            )}
            {status.subscription_info.limit_info && (
              <div className="subscription-item">
                <span className="material-icons">format_list_numbered</span>
                <span>{status.subscription_info.limit_info}</span>
              </div>
            )}
            {status.subscription_info.expire_text && (
              <div className="subscription-item">
                <span className="material-icons">event</span>
                <span>{status.subscription_info.expire_text}</span>
              </div>
            )}
            {status.subscription_info.phone && (
              <div className="subscription-item">
                <span className="material-icons">phone</span>
                <span>{status.subscription_info.phone}</span>
              </div>
            )}
            {/* Поля Avito */}
            {status.subscription_info.name && (
              <div className="subscription-item">
                <span className="material-icons">person</span>
                <span>{status.subscription_info.name}</span>
              </div>
            )}
            {status.subscription_info.balance && (
              <div className="subscription-item">
                <span className="material-icons">account_balance_wallet</span>
                <span>Баланс: {status.subscription_info.balance}</span>
              </div>
            )}
            {status.subscription_info.listings_remaining && (
              <div className="subscription-item">
                <span className="material-icons">format_list_numbered</span>
                <span>Остаток размещений: {status.subscription_info.listings_remaining}</span>
              </div>
            )}
            {status.subscription_info.bonuses && (
              <div className="subscription-item">
                <span className="material-icons">stars</span>
                <span>{status.subscription_info.bonuses}</span>
              </div>
            )}
          </div>
        )}

        {/* Развёрнутый контент */}
        {isExpanded && (
          <div className="source-auth-content">
            {/* Инструкции */}
            <div className="source-auth-instructions">
              <h4>Инструкция:</h4>
              <ol>
                {config.instructions.map((instruction, index) => (
                  <li key={index}>{instruction}</li>
                ))}
              </ol>
            </div>

            {/* Ввод куки */}
            <div className="source-auth-method">
              <h4>
                <span className="material-icons">edit</span>
                Ввод куки
              </h4>
              <p className="method-description">
                Скопируйте значение Cookie из заголовков запроса (см. инструкцию выше)
              </p>
              
              <div className="manual-input-group">
                <textarea
                  placeholder={`Вставьте куки с ${config.domain} в формате: name=value; name2=value2...`}
                  value={manualCookies[source]}
                  onChange={(e) => setManualCookies(prev => ({ ...prev, [source]: e.target.value }))}
                  rows={4}
                />
                <button 
                  className="btn btn-primary"
                  onClick={() => saveCookiesManually(source)}
                  disabled={isSaving === source || !manualCookies[source].trim()}
                >
                  {isSaving === source ? (
                    <>
                      <span className="material-icons spinning">sync</span>
                      Сохранение...
                    </>
                  ) : (
                    <>
                      <span className="material-icons">save</span>
                      Сохранить куки
                    </>
                  )}
                </button>
              </div>
            </div>

            {/* Кнопки управления авторизацией */}
            {status?.is_authorized && (
              <div className="source-auth-actions">
                <button 
                  className="btn btn-secondary"
                  onClick={() => revalidateAuth(source)}
                  disabled={isRevalidating === source}
                >
                  {isRevalidating === source ? (
                    <>
                      <span className="material-icons spinning">sync</span>
                      Проверка...
                    </>
                  ) : (
                    <>
                      <span className="material-icons">refresh</span>
                      Перепроверить
                    </>
                  )}
                </button>
                <button 
                  className="btn btn-danger"
                  onClick={() => deleteAuth(source)}
                >
                  <span className="material-icons">logout</span>
                  Удалить авторизацию
                </button>
              </div>
            )}
          </div>
        )}
      </div>
    );
  };

  if (isLoading) {
    return (
      <div className="source-auth-loading">
        <span className="material-icons spinning">sync</span>
        Загрузка...
      </div>
    );
  }

  return (
    <div className="source-auth-container">
      <div className="source-auth-description">
        <span className="material-icons">info</span>
        <p>
          Авторизация на источниках необходима для автоматического выкупа контактов 
          собственников по новым объявлениям.
        </p>
      </div>

      {renderSourceCard('cian')}
      {renderSourceCard('avito')}
    </div>
  );
};

export default SourceAuth;
