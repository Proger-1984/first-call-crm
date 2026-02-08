import { useEffect } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Layout } from './components/Layout';
import { Dashboard } from './pages/Dashboard';
import { Login, LoginTest } from './pages/Login';
import { Profile } from './pages/Profile';
import { Settings } from './pages/Settings';
import { Tariffs } from './pages/Tariffs';
import { Favorites } from './pages/Favorites';
import { Billing } from './pages/Billing';
import { AdminBilling } from './pages/AdminBilling';
import { Analytics } from './pages/Analytics';
import { ProtectedRoute, SubscriptionRoute } from './components/Auth';
import { useAuthStore } from './stores/authStore';

// Создаём клиент React Query
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 1000 * 60 * 5, // 5 минут
      retry: 1,
    },
  },
});

function AppContent() {
  const { checkAuth } = useAuthStore();

  // Проверяем авторизацию при загрузке приложения
  useEffect(() => {
    checkAuth();
  }, [checkAuth]);

  return (
    <Routes>
      {/* Публичные маршруты - авторизация */}
      <Route path="/login" element={<Login />} />
      <Route path="/login-test" element={<LoginTest />} />
      
      {/* Защищённые маршруты */}
      <Route
        path="/"
        element={
          <ProtectedRoute>
            <Layout />
          </ProtectedRoute>
        }
      >
        {/* Маршруты требующие активную подписку */}
        <Route index element={<SubscriptionRoute><Dashboard /></SubscriptionRoute>} />
        <Route path="profile" element={<SubscriptionRoute><Profile /></SubscriptionRoute>} />
        <Route path="settings" element={<SubscriptionRoute><Settings /></SubscriptionRoute>} />
        <Route path="favorites" element={<SubscriptionRoute><Favorites /></SubscriptionRoute>} />
        
        {/* Маршруты доступные всегда (без подписки) */}
        <Route path="tariffs" element={<Tariffs />} />
        <Route path="billing" element={<Billing />} />
        
        {/* Админские маршруты */}
        <Route path="admin/billing" element={<AdminBilling />} />
        <Route path="admin/analytics" element={<Analytics />} />
      </Route>
      
      {/* Редирект на тарифы для несуществующих маршрутов */}
      <Route path="*" element={<Navigate to="/tariffs" replace />} />
    </Routes>
  );
}

function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <AppContent />
      </BrowserRouter>
    </QueryClientProvider>
  );
}

export default App;
