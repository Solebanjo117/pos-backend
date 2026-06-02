import { useId } from "react";
import { AreaChart, Area } from "recharts";

interface SparkLineProps {
  data: number[];
  color: string;
  width?: number;
  height?: number;
}

export function SparkLine({ data, color, width = 72, height = 28 }: SparkLineProps) {
  const uid = useId().replace(/[^a-zA-Z0-9]/g, "");
  const gradId = `sk-${uid}`;
  const chartData = data.map((v, i) => ({ i, v }));

  return (
    <AreaChart
      width={width}
      height={height}
      data={chartData}
      margin={{ top: 2, right: 2, bottom: 2, left: 2 }}
    >
      <defs>
        <linearGradient id={gradId} x1="0" y1="0" x2="0" y2="1">
          <stop offset="5%" stopColor={color} stopOpacity={0.35} />
          <stop offset="95%" stopColor={color} stopOpacity={0.02} />
        </linearGradient>
      </defs>
      <Area
        type="monotone"
        dataKey="v"
        stroke={color}
        strokeWidth={1.5}
        fill={`url(#${gradId})`}
        dot={false}
        isAnimationActive
        animationDuration={900}
        animationEasing="ease-out"
      />
    </AreaChart>
  );
}
