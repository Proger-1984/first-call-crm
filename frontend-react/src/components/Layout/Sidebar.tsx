import { NavLink } from 'react-router-dom';
import { useUIStore } from '../../stores/uiStore';
import { useAuthStore } from '../../stores/authStore';
import './Sidebar.css';

interface MenuItem {
  icon: string;
  label: string;
  path: string;
  adminOnly?: boolean;
  requiresSubscription?: boolean; // Требует активную подписку
  alwaysVisible?: boolean; // Всегда видим (даже без подписки)
}

const menuItems: MenuItem[] = [
  { icon: 'dashboard', label: 'Объявления', path: '/', requiresSubscription: true },
  { icon: 'tune', label: 'Настройки', path: '/settings', requiresSubscription: true },
  { icon: 'workspace_premium', label: 'Тарифы', path: '/tariffs', alwaysVisible: true },
  { icon: 'receipt_long', label: 'Мои подписки', path: '/billing', alwaysVisible: true },
  { icon: 'star', label: 'Избранное', path: '/favorites', requiresSubscription: true },
  { icon: 'admin_panel_settings', label: 'Управление подписками', path: '/admin/billing', adminOnly: true },
  { icon: 'analytics', label: 'Аналитика', path: '/admin/analytics', adminOnly: true },
];

export function Sidebar() {
  const { sidebarCollapsed, toggleSidebar } = useUIStore();
  const { user } = useAuthStore();
  const isAdmin = user?.role === 'admin';
  const hasActiveSubscription = user?.has_active_subscription || isAdmin;

  // Фильтруем пункты меню:
  // 1. Админские пункты - только для админов
  // 2. Пункты требующие подписку - только при активной подписке или для админов
  // 3. alwaysVisible - всегда показываем
  const filteredMenuItems = menuItems.filter((item) => {
    // Админские пункты - только для админов
    if (item.adminOnly && !isAdmin) {
      return false;
    }
    
    // Пункты требующие подписку - проверяем наличие подписки
    if (item.requiresSubscription && !hasActiveSubscription) {
      return false;
    }
    
    return true;
  });

  return (
    <aside className={`sidebar ${sidebarCollapsed ? 'collapsed' : ''}`}>
      <div className="sidebar-toggle" onClick={toggleSidebar}>
        <span className="material-icons">menu_open</span>
      </div>
      
      <nav className="menu">
        {filteredMenuItems.map((item) => (
          <NavLink
            key={item.path}
            to={item.path}
            className={({ isActive }) => 
              `menu-item ${isActive ? 'active' : ''}${item.adminOnly ? ' admin-item' : ''}`
            }
          >
            <span className="material-icons">{item.icon}</span>
            <span>{item.label}</span>
            {sidebarCollapsed && (
              <span className="tooltip">{item.label}</span>
            )}
          </NavLink>
        ))}
      </nav>
    </aside>
  );
}
