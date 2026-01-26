import './Badge.css';

interface BadgeProps {
  children: React.ReactNode;
  variant?: 'success' | 'warning' | 'danger' | 'info' | 'agent' | 'not-first';
}

export function Badge({ children, variant = 'info' }: BadgeProps) {
  return (
    <span className={`badge badge-${variant}`}>
      {children}
    </span>
  );
}

interface SourceBadgeProps {
  source: 'avito' | 'cian' | 'yandex' | 'domofond' | 'ula';
}

const sourceLabels: Record<string, string> = {
  avito: 'Авито',
  cian: 'ЦИАН',
  yandex: 'Яндекс',
  domofond: 'ДомоФонд',
  ula: 'Юла',
};

export function SourceBadge({ source }: SourceBadgeProps) {
  return (
    <span className={`source-badge ${source}`}>
      {sourceLabels[source] || source}
    </span>
  );
}

export function DuplicateBadge({ source }: SourceBadgeProps) {
  const letter = source === 'cian' ? 'Ц' : 
                 source === 'yandex' ? 'Я' : 
                 source === 'avito' ? 'А' : 
                 source === 'domofond' ? 'Д' : 'Ю';
  
  return (
    <span className={`duplicate-source ${source}`} title={sourceLabels[source]}>
      {letter}
    </span>
  );
}
