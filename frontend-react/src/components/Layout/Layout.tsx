import { Outlet } from 'react-router-dom';
import { Header } from './Header';
import { Sidebar } from './Sidebar';
import { useUIStore } from '../../stores/uiStore';
import './Layout.css';

export function Layout() {
  const { sidebarCollapsed } = useUIStore();

  return (
    <div className="app-layout">
      <Header />
      <Sidebar />
      <main className={`main-content ${sidebarCollapsed ? 'sidebar-collapsed' : ''}`}>
        <div className="container">
          <Outlet />
        </div>
      </main>
    </div>
  );
}
