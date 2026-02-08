import { useState, useEffect } from 'react';
import { tariffsApi, subscriptionsApi } from '../../services/api';
import type { Tariff, Category, Location, TariffPrice } from '../../types';
import { useAuthStore } from '../../stores/authStore';
import './Tariffs.css';

export const Tariffs = () => {
  const { user } = useAuthStore();
  const [tariffs, setTariffs] = useState<Tariff[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);
  const [locations, setLocations] = useState<Location[]>([]);
  const [tariffPrices, setTariffPrices] = useState<TariffPrice[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  
  // Выбранные значения (общие для всех тарифов)
  const [selectedCategory, setSelectedCategory] = useState<number | null>(null);
  const [selectedLocation, setSelectedLocation] = useState<number | null>(null);
  const [isSubmitting, setIsSubmitting] = useState<number | null>(null);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  useEffect(() => {
    loadTariffInfo();
  }, []);

  const loadTariffInfo = async () => {
    setIsLoading(true);
    setError(null);
    try {
      const response = await tariffsApi.getTariffInfo();
      const data = response.data.data;
      
      setTariffs(data.tariffs);
      setCategories(data.categories);
      setLocations(data.locations);
      setTariffPrices(data.tariff_prices);
      
      // Установить значения по умолчанию
      if (data.categories.length > 0) {
        setSelectedCategory(data.categories[0].id);
      }
      if (data.locations.length > 0) {
        setSelectedLocation(data.locations[0].id);
      }
    } catch (err) {
      console.error('Ошибка загрузки тарифов:', err);
      setError('Не удалось загрузить информацию о тарифах');
    } finally {
      setIsLoading(false);
    }
  };

  const getPrice = (tariffId: number): number => {
    if (!selectedLocation || !selectedCategory) return 0;
    const priceInfo = tariffPrices.find(
      p => p.tariff_id === tariffId && 
           p.location_id === selectedLocation && 
           p.category_id === selectedCategory
    );
    return priceInfo?.price ?? 0;
  };

  const formatPrice = (price: number): string => {
    if (price === 0) return 'Бесплатно';
    return new Intl.NumberFormat('ru-RU', {
      style: 'currency',
      currency: 'RUB',
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    }).format(price);
  };

  const getDurationText = (tariff: Tariff): string => {
    if (!tariff.duration_hours) return '';
    const hours = tariff.duration_hours;
    if (hours <= 3) return `${hours} часа`;
    if (hours <= 24) return `${hours} часов`;
    const days = Math.round(hours / 24);
    return `${days} день`;
  };

  const isDemo = (tariff: Tariff): boolean => {
    return tariff.code === 'demo' || tariff.name.toLowerCase().includes('demo');
  };

  const handleSubscribe = async (tariffId: number) => {
    if (!selectedCategory || !selectedLocation) {
      setMessage({ type: 'error', text: 'Выберите категорию и регион' });
      return;
    }

    const tariff = tariffs.find(t => t.id === tariffId);
    if (tariff && isDemo(tariff) && user?.is_trial_used) {
      setMessage({ type: 'error', text: 'Вы уже использовали демо-тариф' });
      return;
    }

    setIsSubmitting(tariffId);
    setMessage(null);
    
    try {
      await subscriptionsApi.create({
        tariff_id: tariffId,
        category_id: selectedCategory,
        location_id: selectedLocation,
      });
      
      setMessage({ 
        type: 'success', 
        text: isDemo(tariff!) 
          ? 'Демо-подписка активирована!' 
          : 'Заявка отправлена!' 
      });
    } catch (err: any) {
      console.error('Ошибка создания подписки:', err);
      setMessage({ 
        type: 'error', 
        text: err.response?.data?.message || 'Ошибка создания заявки' 
      });
    } finally {
      setIsSubmitting(null);
    }
  };

  if (isLoading) {
    return (
      <div className="tariffs-page">
        <div className="tariffs-loading">
          <span className="material-icons spinning">sync</span>
          Загрузка...
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="tariffs-page">
        <div className="tariffs-error">
          <span className="material-icons">error</span>
          {error}
          <button onClick={loadTariffInfo} className="btn btn-primary">Повторить</button>
        </div>
      </div>
    );
  }

  const demoTariff = tariffs.find(t => isDemo(t));
  const premiumTariff = tariffs.find(t => !isDemo(t));

  return (
    <div className="tariffs-page">
      {/* Заголовок + селекторы в одну строку */}
      <div className="tariffs-top">
        <h1 className="tariffs-title">Тарифы</h1>
        <div className="tariffs-selectors">
          <select
            value={selectedCategory || ''}
            onChange={(e) => setSelectedCategory(Number(e.target.value))}
          >
            {categories.map(cat => (
              <option key={cat.id} value={cat.id}>{cat.name}</option>
            ))}
          </select>
          <select
            value={selectedLocation || ''}
            onChange={(e) => setSelectedLocation(Number(e.target.value))}
          >
            {locations.map(loc => (
              <option key={loc.id} value={loc.id}>{loc.name}</option>
            ))}
          </select>
        </div>
      </div>

      {message && (
        <div className={`tariffs-message ${message.type}`}>
          <span className="material-icons">
            {message.type === 'success' ? 'check_circle' : 'error'}
          </span>
          {message.text}
        </div>
      )}

      {/* Карточки тарифов */}
      <div className="tariffs-grid">
        {/* Премиум */}
        {premiumTariff && (
          <div className="tariff-card premium">
            <div className="tariff-badge">
              <span className="material-icons">star</span>
              Рекомендуем
            </div>
            <div className="tariff-header">
              <span className="material-icons tariff-icon">workspace_premium</span>
              <div>
                <h2>Премиум</h2>
                <span className="tariff-period">{getDurationText(premiumTariff)}</span>
              </div>
            </div>
            <div className="tariff-price">{formatPrice(getPrice(premiumTariff.id))}</div>
            <ul className="tariff-features">
              <li><span className="material-icons">info</span>Стоимость одной учётной записи</li>
              <li><span className="material-icons">check</span>Мгновенные уведомления</li>
              <li><span className="material-icons">check</span>Автозвонок по объектам</li>
              <li><span className="material-icons">check</span>Настройка полигонов</li>
              <li><span className="material-icons">check</span>История объявлений</li>
            </ul>
            <button
              className="btn btn-premium"
              onClick={() => handleSubscribe(premiumTariff.id)}
              disabled={isSubmitting === premiumTariff.id}
            >
              {isSubmitting === premiumTariff.id ? 'Обработка...' : 'Оформить подписку'}
            </button>
          </div>
        )}

        {/* Демо */}
        {demoTariff && (
          <div className={`tariff-card demo ${user?.is_trial_used ? 'disabled' : ''}`}>
            <div className="tariff-header">
              <span className="material-icons tariff-icon demo-icon">play_circle</span>
              <div>
                <h2>Демо</h2>
                <span className="tariff-period">{getDurationText(demoTariff)}</span>
              </div>
            </div>
            <div className="tariff-price free">Бесплатно</div>
            <ul className="tariff-features">
              <li><span className="material-icons">check</span>Полный функционал</li>
              <li><span className="material-icons">check</span>Одна категория</li>
              <li><span className="material-icons">info</span>Доступно один раз</li>
            </ul>
            <button
              className="btn btn-demo"
              onClick={() => handleSubscribe(demoTariff.id)}
              disabled={isSubmitting === demoTariff.id || user?.is_trial_used}
            >
              {isSubmitting === demoTariff.id 
                ? 'Обработка...' 
                : user?.is_trial_used 
                  ? 'Уже использовано' 
                  : 'Попробовать'}
            </button>
          </div>
        )}
      </div>

      {/* Краткая информация */}
      <div className="tariffs-info">
        <span><span className="material-icons">credit_card</span>Оплата после заявки</span>
        <span><span className="material-icons">autorenew</span>Без автопродления</span>
        <span><span className="material-icons">support</span>Поддержка 24/7</span>
      </div>

      {/* Информация о поддержке */}
      <div className="tariffs-support-note">
        <span className="material-icons">help_outline</span>
        <span>
          Нет нужной категории или локации? Напишите в <a href="https://t.me/firstcall_support" target="_blank" rel="noopener noreferrer">поддержку</a> — добавим!
        </span>
      </div>
    </div>
  );
};

export default Tariffs;
