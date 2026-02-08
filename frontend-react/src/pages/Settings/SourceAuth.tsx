import { useState, useEffect, useCallback } from 'react';
import { sourceAuthApi, type SourceAuthStatus } from '../../services/api';
import { Tooltip } from '../../components/UI';
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
      'Откройте cian.ru и авторизуйтесь',
      'Нажмите F12 → вкладка "Сеть" (Network)',
      'Обновите страницу (F5)',
      'Кликните на любой запрос → "Заголовки"',
      'Скопируйте значение "Cookie:"',
    ],
  },
  avito: {
    name: 'Avito',
    domain: 'avito.ru',
    icon: 'store',
    color: '#00aaff',
    instructions: [
      'Откройте avito.ru и авторизуйтесь',
      'Нажмите F12 → вкладка "Сеть" (Network)',
      'Обновите страницу (F5)',
      'Кликните на любой запрос → "Заголовки"',
      'Скопируйте значение "Cookie:"',
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
        await loadStatuses();
        setManualCookies(prev => ({ ...prev, [source]: '' }));
        setExpandedSource(null);
      } else {
        onError(response.data.data.message || 'Не удалось сохранить куки');
      }
    } catch (err: unknown) {
      console.error('Ошибка сохранения кук:', err);
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

  const revalidateAuth = useCallback(async (source: SourceType) => {
    setIsRevalidating(source);
    onError(null);

    try {
      const response = await sourceAuthApi.revalidate(source);
      
      if (response.data.data.success) {
        await loadStatuses();
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
    const isAuthorized = status?.is_authorized;
    const info = status?.subscription_info;

    return (
      <div className={`source-card ${isAuthorized ? 'authorized' : ''}`} key={source}>
        {/* Заголовок с названием и статусом */}
        <div className="source-card-header">
          <div className="source-card-title">
            <span className="material-icons" style={{ color: config.color }}>{config.icon}</span>
            <span className="source-name">{config.name}</span>
          </div>
          <div className={`source-badge ${isAuthorized ? 'success' : 'inactive'}`}>
            <span className="material-icons">{isAuthorized ? 'check_circle' : 'cancel'}</span>
            {isAuthorized ? 'Авторизован' : 'Не авторизован'}
          </div>
        </div>

        {/* Информация о подписке — компактная сетка */}
        {isAuthorized && info && (
          <div className="source-info-grid">
            {/* CIAN поля */}
            {info.tariff && (
              <div className="info-item">
                <span className="info-label">Тариф</span>
                <span className="info-value">{info.tariff}</span>
              </div>
            )}
            {info.expire_text && (
              <div className="info-item">
                <span className="info-label">Действует</span>
                <span className="info-value">{info.expire_text}</span>
              </div>
            )}
            {info.limit_info && (
              <div className="info-item">
                <span className="info-label">Лимит</span>
                <span className="info-value">{info.limit_info}</span>
              </div>
            )}
            {info.phone && (
              <div className="info-item">
                <span className="info-label">Телефон</span>
                <span className="info-value">{info.phone}</span>
              </div>
            )}
            {/* Avito поля */}
            {info.name && (
              <div className="info-item">
                <span className="info-label">Аккаунт</span>
                <span className="info-value">{info.name}</span>
              </div>
            )}
            {info.balance && (
              <div className="info-item">
                <span className="info-label">Баланс</span>
                <span className="info-value">{info.balance}</span>
              </div>
            )}
            {info.listings_remaining && (
              <div className="info-item">
                <span className="info-label">Размещений</span>
                <span className="info-value">{info.listings_remaining}</span>
              </div>
            )}
            {info.bonuses && (
              <div className="info-item">
                <span className="info-label">Бонусы</span>
                <span className="info-value">{info.bonuses}</span>
              </div>
            )}
          </div>
        )}

        {/* Кнопки действий */}
        <div className="source-card-actions">
          {isAuthorized ? (
            <>
              <Tooltip content="Перепроверить авторизацию" position="top">
                <button 
                  className="btn-icon" 
                  onClick={() => revalidateAuth(source)}
                  disabled={isRevalidating === source}
                >
                  <span className={`material-icons ${isRevalidating === source ? 'spinning' : ''}`}>
                    refresh
                  </span>
                </button>
              </Tooltip>
              <Tooltip content="Удалить авторизацию" position="top">
                <button 
                  className="btn-icon danger" 
                  onClick={() => deleteAuth(source)}
                >
                  <span className="material-icons">delete</span>
                </button>
              </Tooltip>
            </>
          ) : null}
          <Tooltip content={isAuthorized ? 'Обновить куки авторизации' : 'Добавить куки для авторизации'} position="top">
            <button 
              className={`btn-expand ${isExpanded ? 'expanded' : ''}`}
              onClick={() => setExpandedSource(isExpanded ? null : source)}
            >
              <span className="material-icons">
                {isExpanded ? 'expand_less' : 'expand_more'}
              </span>
              {isAuthorized ? 'Обновить куки' : 'Добавить куки'}
            </button>
          </Tooltip>
        </div>

        {/* Развёрнутая форма ввода кук */}
        {isExpanded && (
          <div className="source-card-expanded">
            <div className="cookie-instructions">
              <span className="material-icons">help_outline</span>
              <ol>
                {config.instructions.map((step, i) => (
                  <li key={i}>{step}</li>
                ))}
              </ol>
            </div>
            <div className="cookie-input-wrapper">
              <textarea
                placeholder={`Вставьте куки с ${config.domain}...`}
                value={manualCookies[source]}
                onChange={(e) => setManualCookies(prev => ({ ...prev, [source]: e.target.value }))}
                rows={3}
              />
              <button 
                className="btn-save"
                onClick={() => saveCookiesManually(source)}
                disabled={isSaving === source || !manualCookies[source].trim()}
              >
                {isSaving === source ? (
                  <span className="material-icons spinning">sync</span>
                ) : (
                  <span className="material-icons">save</span>
                )}
                {isSaving === source ? 'Сохранение...' : 'Сохранить'}
              </button>
            </div>
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
    <div className="source-auth-page">
      <div className="source-auth-hint">
        <span className="material-icons">info</span>
        <p>Авторизация необходима для автоматического выкупа контактов собственников.</p>
      </div>
      
      <div className="source-cards-grid">
        {renderSourceCard('cian')}
        {renderSourceCard('avito')}
      </div>
    </div>
  );
};

export default SourceAuth;
