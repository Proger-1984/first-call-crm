import { useEffect } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Layout } from './components/Layout';
import { Dashboard } from './pages/Dashboard';
import { Login, LoginTest } from './pages/Login';
import { Profile } from './pages/Profile';
import { Settings } from './pages/Settings';
import { Tariffs } from './pages/Tariffs';
import { ProtectedRoute } from './components/Auth';
import { useAuthStore } from './stores/authStore';

function Favorites() {
  return (
    <div>
      <h1 style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '24px' }}>
        <span className="material-icons" style={{ color: 'var(--primary)' }}>star</span>
        Избранное
      </h1>
      <p style={{ color: 'var(--text-secondary)' }}>Страница в разработке...</p>
    </div>
  );
}

function History() {
  return (
    <div>
      <h1 style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '24px' }}>
        <span className="material-icons" style={{ color: 'var(--primary)' }}>history</span>
        История
      </h1>
      <p style={{ color: 'var(--text-secondary)' }}>Страница в разработке...</p>
    </div>
  );
}

function Analytics() {
  return (
    <div>
      <h1 style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '24px' }}>
        <span className="material-icons" style={{ color: 'var(--primary)' }}>analytics</span>
        Аналитика
      </h1>
      <p style={{ color: 'var(--text-secondary)' }}>Страница в разработке...</p>
    </div>
  );
}

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
        <Route index element={<Dashboard />} />
        <Route path="profile" element={<Profile />} />
        <Route path="settings" element={<Settings />} />
        <Route path="tariffs" element={<Tariffs />} />
        <Route path="favorites" element={<Favorites />} />
        <Route path="history" element={<History />} />
        <Route path="analytics" element={<Analytics />} />
      </Route>
      
      {/* Редирект на главную для несуществующих маршрутов */}
      <Route path="*" element={<Navigate to="/" replace />} />
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
