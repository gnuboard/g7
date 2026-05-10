import React, { useMemo } from 'react';
import { Div } from '../basic/Div';
import { StatCard, StatCardProps } from './StatCard';

/**
 * StatCardGrid — responsive grid of StatCards driven by a data array.
 *
 * The existing StatCard composite renders a single tile; admins
 * configuring a dashboard usually want a row of them (total / 4xx
 * /429 / latency, etc.). StatCardGrid wraps the responsive Tailwind
 * grid + iteration boilerplate so layouts can express the dashboard
 * declaratively.
 *
 * Used by:
 *   - gb7-restapi admin_gb7_request_logs_index.json (dashboard header
 *     with 4 stat cards: total / 2xx / 4xx / 429)
 *
 * @example
 * // Layout JSON usage:
 * {
 *   "name": "StatCardGrid",
 *   "props": {
 *     "stats": [
 *       { "value": "{{logs.data.stats.total}}", "label": "$t:gb7-restapi::admin.logs.stat_total", "iconName": "bar-chart" },
 *       { "value": "{{logs.data.stats.2xx}}",   "label": "$t:gb7-restapi::admin.logs.stat_2xx",   "iconName": "check",      "trend": "up" },
 *       { "value": "{{logs.data.stats.4xx}}",   "label": "$t:gb7-restapi::admin.logs.stat_4xx",   "iconName": "alert-circle" },
 *       { "value": "{{logs.data.stats.429}}",   "label": "$t:gb7-restapi::admin.logs.stat_429",   "iconName": "clock",      "trend": "down" }
 *     ],
 *     "columns": 4,
 *     "responsiveColumns": { "sm": 1, "md": 2, "lg": 4 }
 *   }
 * }
 */
export interface StatCardGridProps {
  /**
   * Array of StatCard props. Each entry renders one tile. The full
   * StatCardProps shape is accepted (value, label, change, trend,
   * iconName, etc.).
   */
  stats: StatCardProps[];

  /** Default column count (lg breakpoint). 4 fits 4 stat cards on desktop. */
  columns?: number;

  /** Tailwind gap value (translated to gap-{n}). */
  gap?: number;

  /**
   * Per-breakpoint overrides. Without this, the grid uses
   * `grid-cols-1` on mobile and `lg:grid-cols-{columns}` on desktop.
   */
  responsiveColumns?: {
    sm?: number;
    md?: number;
    lg?: number;
    xl?: number;
  };

  className?: string;
  style?: React.CSSProperties;
}

export const StatCardGrid: React.FC<StatCardGridProps> = ({
  stats,
  columns = 4,
  gap = 4,
  responsiveColumns,
  className = '',
  style,
}) => {
  // Build responsive grid classes. Same approach as CardGrid so the
  // visual rhythm matches.
  const gridClasses = useMemo(() => {
    const classes: string[] = [];

    const smCols = responsiveColumns?.sm ?? 1;
    classes.push(`grid-cols-${smCols}`);

    if (responsiveColumns?.sm) {
      classes.push(`sm:grid-cols-${responsiveColumns.sm}`);
    }

    const mdCols = responsiveColumns?.md ?? Math.min(columns, 2);
    classes.push(`md:grid-cols-${mdCols}`);

    const lgCols = responsiveColumns?.lg ?? columns;
    classes.push(`lg:grid-cols-${lgCols}`);

    if (responsiveColumns?.xl) {
      classes.push(`xl:grid-cols-${responsiveColumns.xl}`);
    }

    return classes.join(' ');
  }, [columns, responsiveColumns]);

  // Defensive — the layout engine may pass `undefined` while data is
  // still loading. Render an empty grid rather than crashing.
  if (!Array.isArray(stats) || stats.length === 0) {
    return (
      <Div className={className} style={style} aria-hidden="true" />
    );
  }

  return (
    <Div
      className={`grid ${gridClasses} gap-${gap} ${className}`}
      style={style}
    >
      {stats.map((stat, index) => (
        <StatCard
          key={`stat-${index}-${stat.label}`}
          {...stat}
        />
      ))}
    </Div>
  );
};

export default StatCardGrid;
