import {
  ComposedChart,
  Area,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
} from "recharts";
import { motion } from "motion/react";

const data = [
  { date: "01 May", ingresos: 1200, gastos: 800 },
  { date: "03 May", ingresos: 1800, gastos: 1100 },
  { date: "05 May", ingresos: 1500, gastos: 900 },
  { date: "07 May", ingresos: 2200, gastos: 1300 },
  { date: "09 May", ingresos: 1900, gastos: 1200 },
  { date: "11 May", ingresos: 2600, gastos: 1500 },
  { date: "13 May", ingresos: 2100, gastos: 1400 },
  { date: "15 May", ingresos: 2800, gastos: 1600 },
  { date: "17 May", ingresos: 2400, gastos: 1350 },
  { date: "19 May", ingresos: 3200, gastos: 1800 },
  { date: "21 May", ingresos: 2900, gastos: 1700 },
  { date: "23 May", ingresos: 3600, gastos: 2000 },
  { date: "25 May", ingresos: 3100, gastos: 1900 },
  { date: "26 May", ingresos: 3850, gastos: 2100 },
];

const CustomTooltip = ({ active, payload, label }: any) => {
  if (active && payload && payload.length) {
    return (
      <div
        className="rounded-lg p-3"
        style={{ backgroundColor: "#252830", border: "1px solid #3e4250", boxShadow: "0 8px 24px rgba(0,0,0,0.4)" }}
      >
        <p style={{ color: "#64748b", fontSize: 11, marginBottom: 6 }}>{label}</p>
        {payload.map((p: any) => (
          <div key={p.name} className="flex items-center gap-2">
            <span
              className="inline-block rounded-full"
              style={{ width: 8, height: 8, backgroundColor: p.color, flexShrink: 0 }}
            />
            <span style={{ color: "#94a3b8", fontSize: 12 }}>
              {p.name === "ingresos" ? "Ingresos" : "Gastos"}:
            </span>
            <span style={{ color: "#f1f5f9", fontSize: 12, fontWeight: 600 }}>
              ${p.value.toLocaleString()}
            </span>
          </div>
        ))}
      </div>
    );
  }
  return null;
};

export function RevenueChart() {
  return (
    <motion.div
      initial={{ opacity: 0, y: 20 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.5, delay: 0.35 }}
      className="rounded-xl p-5"
      style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039" }}
    >
      <div className="flex items-center justify-between mb-5">
        <div>
          <h3 style={{ color: "#f1f5f9", fontSize: 15, fontWeight: 600, marginBottom: 2 }}>
            Ingresos vs Gastos
          </h3>
          <p style={{ color: "#475569", fontSize: 12 }}>01 May — 26 May 2026</p>
        </div>
        <div className="flex items-center gap-4">
          <div className="flex items-center gap-1.5">
            <span className="inline-block rounded-full" style={{ width: 8, height: 8, backgroundColor: "#3b82f6" }} />
            <span style={{ color: "#64748b", fontSize: 12 }}>Ingresos</span>
          </div>
          <div className="flex items-center gap-1.5">
            <span className="inline-block rounded-full" style={{ width: 8, height: 8, backgroundColor: "#ef4444" }} />
            <span style={{ color: "#64748b", fontSize: 12 }}>Gastos</span>
          </div>
        </div>
      </div>

      <ResponsiveContainer width="100%" height={300}>
        <ComposedChart data={data} margin={{ top: 4, right: 4, left: -20, bottom: 0 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="#2e3039" vertical={false} />
          <XAxis
            dataKey="date"
            tick={{ fill: "#475569", fontSize: 11 }}
            axisLine={false}
            tickLine={false}
          />
          <YAxis
            tick={{ fill: "#475569", fontSize: 11 }}
            axisLine={false}
            tickLine={false}
            tickFormatter={(v) => `$${(v / 1000).toFixed(0)}k`}
          />
          <Tooltip content={<CustomTooltip />} cursor={{ stroke: "#3e4250", strokeWidth: 1 }} />
          <Area
            name="Ingresos"
            type="monotone"
            dataKey="ingresos"
            stroke="#3b82f6"
            strokeWidth={2}
            fill="#3b82f6"
            fillOpacity={0.12}
            animationDuration={800}
            animationEasing="ease-out"
          />
          <Area
            name="Gastos"
            type="monotone"
            dataKey="gastos"
            stroke="#ef4444"
            strokeWidth={2}
            fill="#ef4444"
            fillOpacity={0.1}
            animationDuration={800}
            animationEasing="ease-out"
          />
        </ComposedChart>
      </ResponsiveContainer>
    </motion.div>
  );
}
