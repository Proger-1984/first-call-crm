import './StatsCard.css';

interface StatsCardProps {
  icon: string;
  title: string;
  value: string | number;
  trend?: {
    value: number;
    isPositive: boolean;
  };
  variant?: 'default' | 'featured' | 'primary' | 'success' | 'warning' | 'danger' | 'info';
}

export function StatsCard({ icon, title, value, trend, variant = 'default' }: StatsCardProps) {
  return (
    <div className={`stats-card ${variant}`}>
      <span className="material-icons stats-card-icon">{icon}</span>
      <div className="stats-title">{title}</div>
      <div className="stats-value">{value}</div>
      {trend && (
        <div className={`stats-trend ${trend.isPositive ? 'up' : 'down'}`}>
          <span className="material-icons">
            {trend.isPositive ? 'trending_up' : 'trending_down'}
          </span>
          {trend.isPositive ? '+' : ''}{trend.value}% за неделю
        </div>
      )}
    </div>
  );
}
