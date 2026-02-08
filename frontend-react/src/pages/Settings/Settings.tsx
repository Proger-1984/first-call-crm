import { useState, useEffect, useCallback } from 'react';
import { settingsApi, polygonsApi, type LocationPolygon } from '../../services/api';
import type { UserSettingsResponse } from '../../types';
import { PolygonMap } from '../../components/Map';
import { SourceAuth } from './SourceAuth';
import './Settings.css';

type TabType = 'main' | 'locations' | 'sources';

// Центры городов для карты
const CITY_CENTERS: Record<string, [number, number]> = {
  'Москва': [55.7558, 37.6173],
  'Санкт-Петербург': [59.9343, 30.3351],
};

// Ключ для сохранения таба в localStorage
const TAB_STORAGE_KEY = 'settings_active_tab';

export const Settings = () => {
  // Восстанавливаем таб из localStorage или используем 'main' по умолчанию
  const [activeTab, setActiveTab] = useState<TabType>(() => {
    const saved = localStorage.getItem(TAB_STORAGE_KEY);
    return (saved === 'main' || saved === 'locations' || saved === 'sources') ? saved : 'main';
  });
  const [settings, setSettings] = useState<UserSettingsResponse | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  
  // Состояние для полигонов
  const [selectedSubscriptionId, setSelectedSubscriptionId] = useState<number | null>(null);
  const [polygons, setPolygons] = useState<LocationPolygon[]>([]);
  const [isLoadingPolygons, setIsLoadingPolygons] = useState(false);
  const [polygonError, setPolygonError] = useState<string | null>(null);

  useEffect(() => {
    loadSettings();
  }, []);

  // Сохраняем активный таб в localStorage
  useEffect(() => {
    localStorage.setItem(TAB_STORAGE_KEY, activeTab);
  }, [activeTab]);

  // Загрузка полигонов при выборе подписки
  useEffect(() => {
    if (selectedSubscriptionId) {
      loadPolygons(selectedSubscriptionId);
    }
  }, [selectedSubscriptionId]);

  const loadSettings = async () => {
    setIsLoading(true);
    setError(null);
    try {
      const response = await settingsApi.getSettings();
      setSettings(response.data.data);
      
      // Автоматически выбираем первую подписку
      if (response.data.data.active_subscriptions.length > 0) {
        setSelectedSubscriptionId(response.data.data.active_subscriptions[0].id);
      }
    } catch (err) {
      console.error('Ошибка загрузки настроек:', err);
      setError('Не удалось загрузить настройки');
    } finally {
      setIsLoading(false);
    }
  };

  const loadPolygons = async (subscriptionId: number) => {
    setIsLoadingPolygons(true);
    setPolygonError(null);
    try {
      const response = await polygonsApi.getBySubscription(subscriptionId);
      setPolygons(response.data.data.location_polygons);
    } catch (err) {
      console.error('Ошибка загрузки полигонов:', err);
      setPolygonError('Не удалось загрузить области');
      setPolygons([]);
    } finally {
      setIsLoadingPolygons(false);
    }
  };

  const handleSettingChange = async (key: keyof UserSettingsResponse['settings'], value: boolean) => {
    if (!settings) return;
    
    // Оптимистичное обновление UI
    const previousSettings = { ...settings };
    setSettings({
      ...settings,
      settings: { ...settings.settings, [key]: value }
    });
    setError(null);
    
    setIsSaving(key);
    try {
      const response = await settingsApi.updateSetting(key, value, previousSettings);
      // Обновляем из ответа сервера если есть data
      if (response.data.data) {
        setSettings(response.data.data);
      }
    } catch (err) {
      console.error('Ошибка сохранения:', err);
      // Откатываем изменения
      setSettings(previousSettings);
      setError('Не удалось сохранить настройку');
    } finally {
      setIsSaving(null);
    }
  };

  const handleSourceChange = async (sourceId: number, enabled: boolean) => {
    if (!settings) return;
    
    // Оптимистичное обновление UI
    const previousSettings = { ...settings };
    setSettings({
      ...settings,
      sources: settings.sources.map(s => s.id === sourceId ? { ...s, enabled } : s)
    });
    setError(null);
    
    setIsSaving(`source-${sourceId}`);
    try {
      const response = await settingsApi.updateSource(sourceId, enabled, previousSettings);
      if (response.data.data) {
        setSettings(response.data.data);
      }
    } catch (err) {
      console.error('Ошибка сохранения:', err);
      setSettings(previousSettings);
      setError('Не удалось сохранить настройку');
    } finally {
      setIsSaving(null);
    }
  };

  const handleSubscriptionChange = async (subscriptionId: number, enabled: boolean) => {
    if (!settings) return;
    
    // Оптимистичное обновление UI
    const previousSettings = { ...settings };
    setSettings({
      ...settings,
      active_subscriptions: settings.active_subscriptions.map(s => 
        s.id === subscriptionId ? { ...s, enabled } : s
      )
    });
    setError(null);
    
    setIsSaving(`subscription-${subscriptionId}`);
    try {
      const response = await settingsApi.updateSubscriptionStatus(subscriptionId, enabled, previousSettings);
      if (response.data.data) {
        setSettings(response.data.data);
      }
    } catch (err) {
      console.error('Ошибка сохранения:', err);
      setSettings(previousSettings);
      setError('Не удалось сохранить настройку');
    } finally {
      setIsSaving(null);
    }
  };

  // Обработчики полигонов
  const handlePolygonCreate = useCallback(async (coordinates: [number, number][], name: string) => {
    if (!selectedSubscriptionId) return;
    
    try {
      await polygonsApi.create({
        subscription_id: selectedSubscriptionId,
        name,
        polygon_coordinates: coordinates,
      });
      // Перезагружаем полигоны
      loadPolygons(selectedSubscriptionId);
    } catch (err) {
      console.error('Ошибка создания полигона:', err);
      setPolygonError('Не удалось создать область');
    }
  }, [selectedSubscriptionId]);

  const handlePolygonUpdate = useCallback(async (id: number, coordinates: [number, number][]) => {
    if (!selectedSubscriptionId) return;
    
    try {
      await polygonsApi.update(id, {
        subscription_id: selectedSubscriptionId,
        polygon_coordinates: coordinates,
      });
      loadPolygons(selectedSubscriptionId);
    } catch (err) {
      console.error('Ошибка обновления полигона:', err);
      setPolygonError('Не удалось обновить область');
    }
  }, [selectedSubscriptionId]);

  const handlePolygonDelete = useCallback(async (id: number) => {
    if (!selectedSubscriptionId) return;
    
    try {
      await polygonsApi.delete(id);
      loadPolygons(selectedSubscriptionId);
    } catch (err) {
      console.error('Ошибка удаления полигона:', err);
      setPolygonError('Не удалось удалить область');
    }
  }, [selectedSubscriptionId]);

  // Получить центр карты для выбранной подписки
  const getMapCenter = (): [number, number] => {
    if (!settings || !selectedSubscriptionId) return CITY_CENTERS['Москва'];
    
    const subscription = settings.active_subscriptions.find(s => s.id === selectedSubscriptionId);
    if (!subscription) return CITY_CENTERS['Москва'];
    
    // Извлекаем город из названия подписки (формат: "Категория | Город, Регион")
    const parts = subscription.name.split('|');
    if (parts.length > 1) {
      const locationPart = parts[1].trim();
      const city = locationPart.split(',')[0].trim();
      return CITY_CENTERS[city] || CITY_CENTERS['Москва'];
    }
    
    return CITY_CENTERS['Москва'];
  };

  // Маппинг названий настроек
  const settingsLabels: Record<keyof UserSettingsResponse['settings'], { title: string; description: string }> = {
    log_events: { 
      title: 'Логирование событий', 
      description: 'Записывать все действия в журнал' 
    },
    auto_call: { 
      title: 'Автоматический звонок', 
      description: 'Звонок при новом объявлении' 
    },
    auto_call_raised: { 
      title: 'Звонок на поднятые', 
      description: 'Звонок при обновлении объявления' 
    },
    telegram_notifications: { 
      title: 'Уведомления в Telegram', 
      description: 'Получать уведомления в Telegram' 
    },
  };

  if (isLoading) {
    return (
      <div className="settings-page">
        <h1 className="page-title">
          <span className="material-icons">tune</span>
          Настройки
        </h1>
        <div className="settings-loading">
          <span className="material-icons spinning">sync</span>
          Загрузка настроек...
        </div>
      </div>
    );
  }

  if (error && !settings) {
    return (
      <div className="settings-page">
        <h1 className="page-title">
          <span className="material-icons">tune</span>
          Настройки
        </h1>
        <div className="settings-error">
          <span className="material-icons">error</span>
          {error}
          <button onClick={loadSettings} className="btn btn-primary">
            Повторить
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="settings-page">
      <h1 className="page-title">
        <span className="material-icons">tune</span>
        Настройки
      </h1>

      {/* Табы */}
      <div className="settings-tabs">
        <button 
          className={`settings-tab ${activeTab === 'main' ? 'active' : ''}`}
          onClick={() => setActiveTab('main')}
        >
          Основные настройки
        </button>
        <button 
          className={`settings-tab ${activeTab === 'locations' ? 'active' : ''}`}
          onClick={() => setActiveTab('locations')}
        >
          Настройки локаций
        </button>
        <button 
          className={`settings-tab ${activeTab === 'sources' ? 'active' : ''}`}
          onClick={() => setActiveTab('sources')}
        >
          Авторизация источников
        </button>
      </div>

      {error && (
        <div className="settings-message error">
          <span className="material-icons">error</span>
          {error}
        </div>
      )}

      {activeTab === 'main' && settings && (
        <div className="settings-grid">
          {/* Настройки событий */}
          <div className="settings-card">
            <div className="settings-card-header">
              <span className="material-icons">notifications</span>
              <h2>Настройки событий</h2>
            </div>
            <div className="settings-card-body">
              {(Object.keys(settingsLabels) as Array<keyof UserSettingsResponse['settings']>).map((key) => (
                <div className="settings-row" key={key}>
                  <div className="settings-row-info">
                    <div className="settings-row-title">{settingsLabels[key].title}</div>
                    <div className="settings-row-description">{settingsLabels[key].description}</div>
                  </div>
                  <label className={`toggle ${isSaving === key ? 'saving' : ''}`}>
                    <input
                      type="checkbox"
                      checked={settings.settings[key]}
                      onChange={(e) => handleSettingChange(key, e.target.checked)}
                      disabled={isSaving === key}
                    />
                    <span className="toggle-slider"></span>
                  </label>
                </div>
              ))}
            </div>
          </div>

          {/* Источники */}
          <div className="settings-card">
            <div className="settings-card-header">
              <span className="material-icons">rss_feed</span>
              <h2>Источники</h2>
            </div>
            <div className="settings-card-body">
              {settings.sources.map((source) => (
                <div className="settings-row" key={source.id}>
                  <div className="settings-row-info">
                    <div className="settings-row-title">{source.name}</div>
                    <div className="settings-row-description">
                      Получать объявления с {source.name}
                    </div>
                  </div>
                  <label className={`toggle ${isSaving === `source-${source.id}` ? 'saving' : ''}`}>
                    <input
                      type="checkbox"
                      checked={source.enabled}
                      onChange={(e) => handleSourceChange(source.id, e.target.checked)}
                      disabled={isSaving === `source-${source.id}`}
                    />
                    <span className="toggle-slider"></span>
                  </label>
                </div>
              ))}
            </div>
          </div>

          {/* Активные подписки */}
          <div className="settings-card">
            <div className="settings-card-header">
              <span className="material-icons">subscriptions</span>
              <h2>Активные подписки</h2>
            </div>
            <div className="settings-card-body">
              {settings.active_subscriptions.length === 0 ? (
                <div className="settings-empty">
                  <span className="material-icons">inbox</span>
                  <p>Нет активных подписок</p>
                </div>
              ) : (
                settings.active_subscriptions.map((subscription) => (
                  <div className="settings-row" key={subscription.id}>
                    <div className="settings-row-info">
                      <div className="settings-row-title">{subscription.name}</div>
                    </div>
                    <label className={`toggle ${isSaving === `subscription-${subscription.id}` ? 'saving' : ''}`}>
                      <input
                        type="checkbox"
                        checked={subscription.enabled}
                        onChange={(e) => handleSubscriptionChange(subscription.id, e.target.checked)}
                        disabled={isSaving === `subscription-${subscription.id}`}
                      />
                      <span className="toggle-slider"></span>
                    </label>
                  </div>
                ))
              )}
            </div>
          </div>
        </div>
      )}

      {activeTab === 'locations' && settings && (
        <div className="settings-locations">
          {settings.active_subscriptions.length === 0 ? (
            <div className="settings-card">
              <div className="settings-card-body">
                <div className="settings-empty">
                  <span className="material-icons">location_off</span>
                  <p>Нет активных подписок для настройки локаций</p>
                  <p className="settings-empty-hint">Оформите подписку, чтобы настроить локации</p>
                </div>
              </div>
            </div>
          ) : (
            <>
              {/* Селектор подписки вверху */}
              <div className="subscription-selector">
                <label>Подписка:</label>
                <select
                  value={selectedSubscriptionId || ''}
                  onChange={(e) => setSelectedSubscriptionId(Number(e.target.value))}
                >
                  {settings.active_subscriptions.map((sub) => (
                    <option key={sub.id} value={sub.id}>{sub.name}</option>
                  ))}
                </select>
              </div>

              {/* Карта с полигонами */}
              <div className="settings-card map-card">
                <div className="settings-card-body map-body">
                  {polygonError && (
                    <div className="settings-message error">
                      <span className="material-icons">error</span>
                      {polygonError}
                    </div>
                  )}

                  {isLoadingPolygons ? (
                    <div className="map-loading">
                      <span className="material-icons spinning">sync</span>
                      Загрузка карты...
                    </div>
                  ) : (
                    <PolygonMap
                      polygons={polygons}
                      center={getMapCenter()}
                      zoom={10}
                      onPolygonCreate={handlePolygonCreate}
                      onPolygonUpdate={handlePolygonUpdate}
                      onPolygonDelete={handlePolygonDelete}
                      editable={true}
                    />
                  )}
                </div>
              </div>
            </>
          )}
        </div>
      )}

      {activeTab === 'sources' && (
        <SourceAuth onError={setError} />
      )}
    </div>
  );
};

export default Settings;
