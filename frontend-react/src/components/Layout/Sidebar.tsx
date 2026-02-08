import { NavLink } from 'react-router-dom';
import { useUIStore } from '../../stores/uiStore';
import { useAuthStore } from '../../stores/authStore';
import './Sidebar.css';

interface MenuItem {
  icon: string;
  label: string;
  path: string;
  adminOnly?: boolean;
}

const menuItems: MenuItem[] = [
  { icon: 'dashboard', label: 'Объявления', path: '/' },
  { icon: 'tune', label: 'Настройки', path: '/settings' },
  { icon: 'workspace_premium', label: 'Тарифы', path: '/tariffs' },
  { icon: 'receipt_long', label: 'Мои подписки', path: '/billing' },
  { icon: 'star', label: 'Избранное', path: '/favorites' },
  { icon: 'history', label: 'История', path: '/history' },
  { icon: 'admin_panel_settings', label: 'Управление подписками', path: '/admin/billing', adminOnly: true },
  { icon: 'analytics', label: 'Аналитика', path: '/admin/analytics', adminOnly: true },
];

export function Sidebar() {
  const { sidebarCollapsed, toggleSidebar } = useUIStore();
  const { user } = useAuthStore();
  const isAdmin = user?.role === 'admin';

  return (
    <aside className={`sidebar ${sidebarCollapsed ? 'collapsed' : ''}`}>
      <div className="sidebar-toggle" onClick={toggleSidebar}>
        <span className="material-icons">menu_open</span>
      </div>
      
      <nav className="menu">
        {menuItems
          .filter((item) => !item.adminOnly || isAdmin)
          .map((item) => (
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
