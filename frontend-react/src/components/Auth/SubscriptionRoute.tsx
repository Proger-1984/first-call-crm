import { Navigate } from 'react-router-dom';
import { useAuthStore } from '../../stores/authStore';

interface SubscriptionRouteProps {
  children: React.ReactNode;
}

/**
 * Компонент защиты роутов, требующих активную подписку.
 * 
 * Логика:
 * - Админы имеют доступ всегда
 * - Пользователи с активной подпиской (включая extend_pending) имеют доступ
 * - Пользователи без подписки перенаправляются на страницу тарифов
 */
export const SubscriptionRoute: React.FC<SubscriptionRouteProps> = ({ children }) => {
  const { user } = useAuthStore();
  
  const isAdmin = user?.role === 'admin';
  const hasActiveSubscription = user?.has_active_subscription;
  
  // Админы и пользователи с подпиской имеют доступ
  if (isAdmin || hasActiveSubscription) {
    return <>{children}</>;
  }
  
  // Редирект на тарифы если нет активной подписки
  return <Navigate to="/tariffs" replace />;
};
