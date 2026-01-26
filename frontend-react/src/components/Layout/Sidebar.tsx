import { NavLink } from 'react-router-dom';
import { useUIStore } from '../../stores/uiStore';
import './Sidebar.css';

interface MenuItem {
  icon: string;
  label: string;
  path: string;
}

const menuItems: MenuItem[] = [
  { icon: 'dashboard', label: 'Объявления', path: '/' },
  { icon: 'tune', label: 'Настройки', path: '/settings' },
  { icon: 'workspace_premium', label: 'Тарифы', path: '/tariffs' },
  { icon: 'star', label: 'Избранное', path: '/favorites' },
  { icon: 'history', label: 'История', path: '/history' },
  { icon: 'analytics', label: 'Аналитика', path: '/analytics' },
];

export function Sidebar() {
  const { sidebarCollapsed, toggleSidebar } = useUIStore();

  return (
    <aside className={`sidebar ${sidebarCollapsed ? 'collapsed' : ''}`}>
      <div className="sidebar-toggle" onClick={toggleSidebar}>
        <span className="material-icons">menu_open</span>
      </div>
      
      <nav className="menu">
        {menuItems.map((item) => (
          <NavLink
            key={item.path}
            to={item.path}
            className={({ isActive }) => 
              `menu-item ${isActive ? 'active' : ''}`
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
