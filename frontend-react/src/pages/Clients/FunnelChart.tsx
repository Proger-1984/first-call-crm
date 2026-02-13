import type { PipelineStage } from '../../types/client';
import './FunnelChart.css';

interface FunnelChartProps {
  stages: PipelineStage[];
}

export function FunnelChart({ stages }: FunnelChartProps) {
  // Сортируем по sort_order
  const sortedStages = [...stages].sort((a, b) => a.sort_order - b.sort_order);
  const maxCount = Math.max(...sortedStages.map(s => s.clients_count), 1);

  const svgWidth = 600;
  const svgHeight = sortedStages.length * 48 + 16;
  const minBarWidth = 240; // Минимальная ширина — вмещает длинные русские названия
  const maxBarWidth = svgWidth - 40; // Максимальная ширина
  const barHeight = 36;
  const barGap = 12;
  const startY = 8;

  return (
    <div className="funnel-chart">
      <svg
        viewBox={`0 0 ${svgWidth} ${svgHeight}`}
        className="funnel-svg"
        preserveAspectRatio="xMidYMid meet"
      >
        {sortedStages.map((stage, index) => {
          const ratio = stage.clients_count / maxCount;
          const barWidth = minBarWidth + (maxBarWidth - minBarWidth) * ratio;
          const xOffset = (svgWidth - barWidth) / 2;
          const yOffset = startY + index * (barHeight + barGap);

          // Трапецевидная форма: текущая полоска сверху, следующая снизу
          const nextStage = sortedStages[index + 1];
          const nextRatio = nextStage ? nextStage.clients_count / maxCount : ratio * 0.7;
          const nextBarWidth = nextStage
            ? minBarWidth + (maxBarWidth - minBarWidth) * nextRatio
            : barWidth * 0.7;

          const topLeft = xOffset;
          const topRight = xOffset + barWidth;
          const bottomLeft = (svgWidth - nextBarWidth) / 2;
          const bottomRight = (svgWidth + nextBarWidth) / 2;

          // Путь трапеции
          const trapezoidPath = `M ${topLeft} ${yOffset} L ${topRight} ${yOffset} L ${bottomRight} ${yOffset + barHeight} L ${bottomLeft} ${yOffset + barHeight} Z`;

          // Текст по центру
          const textX = svgWidth / 2;
          const textY = yOffset + barHeight / 2;

          return (
            <g key={stage.id}>
              <path
                d={trapezoidPath}
                fill={stage.color}
                opacity={0.85}
              />
              <text
                x={textX}
                y={textY}
                textAnchor="middle"
                dominantBaseline="central"
                fill="#fff"
                fontSize="13"
                fontWeight="600"
                stroke={stage.color}
                strokeWidth="3"
                paintOrder="stroke"
              >
                {stage.name} ({stage.clients_count})
              </text>
            </g>
          );
        })}
      </svg>
    </div>
  );
}

export default FunnelChart;
