import { useState } from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { useUIStore } from '../../stores/uiStore';
import { useAuthStore } from '../../stores/authStore';
import './Sidebar.css';

interface MenuItem {
  icon: string;
  label: string;
  path: string;
  requiresSubscription?: boolean;
  alwaysVisible?: boolean;
}

interface MenuSection {
  title?: string;
  icon?: string;
  items: MenuItem[];
  adminOnly?: boolean;
  collapsible?: boolean;
}

const menuSections: MenuSection[] = [
  {
    // Основное меню (без заголовка)
    items: [
      { icon: 'dashboard', label: 'Объявления', path: '/', requiresSubscription: true },
      { icon: 'tune', label: 'Настройки', path: '/settings', requiresSubscription: true },
      { icon: 'workspace_premium', label: 'Тарифы', path: '/tariffs', alwaysVisible: true },
      { icon: 'receipt_long', label: 'Мои подписки', path: '/billing', alwaysVisible: true },
      { icon: 'star', label: 'Избранное', path: '/favorites', requiresSubscription: true },
      { icon: 'apartment', label: 'Объекты', path: '/properties', requiresSubscription: true },
      { icon: 'contacts', label: 'Контакты', path: '/contacts', requiresSubscription: true },
    ],
  },
  {
    // Админский раздел (раскрывающийся)
    title: 'Администрирование',
    icon: 'admin_panel_settings',
    adminOnly: true,
    collapsible: true,
    items: [
      { icon: 'credit_card', label: 'Подписки', path: '/admin/billing' },
      { icon: 'analytics', label: 'Аналитика', path: '/admin/analytics' },
      { icon: 'people', label: 'Пользователи', path: '/admin/users' },
    ],
  },
];

export function Sidebar() {
  const { sidebarCollapsed, toggleSidebar } = useUIStore();
  const { user } = useAuthStore();
  const location = useLocation();
  const isAdmin = user?.role === 'admin';
  const hasActiveSubscription = user?.has_active_subscription || isAdmin;

  // Состояние раскрытия секций (по умолчанию раскрыто, если мы на странице из этой секции)
  const [expandedSections, setExpandedSections] = useState<Record<number, boolean>>(() => {
    const initial: Record<number, boolean> = {};
    menuSections.forEach((section, index) => {
      if (section.collapsible) {
        // Раскрываем, если текущий путь принадлежит этой секции
        initial[index] = section.items.some(item => location.pathname.startsWith(item.path));
      }
    });
    return initial;
  });

  const toggleSection = (index: number) => {
    setExpandedSections(prev => ({
      ...prev,
      [index]: !prev[index],
    }));
  };

  // Фильтруем секции и пункты меню
  const filteredSections = menuSections
    .filter(section => !section.adminOnly || isAdmin)
    .map(section => ({
      ...section,
      items: section.items.filter(item => {
        if (item.requiresSubscription && !hasActiveSubscription) {
          return false;
        }
        return true;
      }),
    }))
    .filter(section => section.items.length > 0);

  return (
    <aside className={`sidebar ${sidebarCollapsed ? 'collapsed' : ''}`}>
      <div className="sidebar-toggle" onClick={toggleSidebar}>
        <span className="material-icons">menu_open</span>
      </div>
      
      <nav className="menu">
        {filteredSections.map((section, sectionIndex) => {
          const originalIndex = menuSections.findIndex(s => s.title === section.title && s.icon === section.icon);
          const isExpanded = section.collapsible ? expandedSections[originalIndex] : true;
          const hasActiveChild = section.items.some(item => location.pathname === item.path || 
            (item.path !== '/' && location.pathname.startsWith(item.path)));
          
          return (
            <div key={sectionIndex} className={`menu-section ${section.collapsible ? 'collapsible' : ''}`}>
              {section.title && (
                <div 
                  className={`menu-section-header ${section.collapsible ? 'clickable' : ''} ${hasActiveChild ? 'has-active' : ''}`}
                  onClick={() => section.collapsible && toggleSection(originalIndex)}
                >
                  {section.icon && <span className="material-icons section-icon">{section.icon}</span>}
                  <span className="menu-section-title">{section.title}</span>
                  {section.collapsible && (
                    <span className={`material-icons expand-icon ${isExpanded ? 'expanded' : ''}`}>
                      expand_more
                    </span>
                  )}
                </div>
              )}
              <div className={`menu-section-items ${section.collapsible && !isExpanded ? 'collapsed' : ''}`}>
                {section.items.map((item) => (
                  <NavLink
                    key={item.path}
                    to={item.path}
                    className={({ isActive }) => 
                      `menu-item ${isActive ? 'active' : ''}${section.adminOnly ? ' admin-item' : ''}`
                    }
                  >
                    <span className="material-icons">{item.icon}</span>
                    <span>{item.label}</span>
                    {sidebarCollapsed && (
                      <span className="tooltip">{item.label}</span>
                    )}
                  </NavLink>
                ))}
              </div>
            </div>
          );
        })}
      </nav>
    </aside>
  );
}
