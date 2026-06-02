import { PieChart, Pie, Cell, Tooltip, ResponsiveContainer } from "recharts";
import { motion } from "motion/react";

const data = [
  { name: "Efectivo", value: 45, color: "#22c55e" },
  { name: "Tarjeta", value: 25, color: "#3b82f6" },
  { name: "Yappy/Transfer", value: 18, color: "#8b5cf6" },
  { name: "Crédito", value: 12, color: "#f97316" },
];

const CustomTooltip = ({ active, payload }: any) => {
  if (active && payload && payload.length) {
    return (
      <div
        className="rounded-lg px-3 py-2"
        style={{ backgroundColor: "#252830", border: "1px solid #3e4250", boxShadow: "0 8px 24px rgba(0,0,0,0.4)" }}
      >
        <p style={{ color: "#f1f5f9", fontSize: 12, fontWeight: 600 }}>
          {payload[0].name}: <span style={{ color: payload[0].payload.color }}>{payload[0].value}%</span>
        </p>
      </div>
    );
  }
  return null;
};

export function PaymentMethodsChart() {
  return (
    <motion.div
      initial={{ opacity: 0, y: 20 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.5, delay: 0.42 }}
      className="rounded-xl p-5"
      style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039" }}
    >
      <div className="mb-4">
        <h3 style={{ color: "#f1f5f9", fontSize: 15, fontWeight: 600, marginBottom: 2 }}>
          Métodos de Pago
        </h3>
        <p style={{ color: "#475569", fontSize: 12 }}>Distribución del periodo</p>
      </div>

      <div className="flex justify-center">
        <ResponsiveContainer width="100%" height={220}>
          <PieChart>
            <Pie
              data={data}
              cx="50%"
              cy="50%"
              innerRadius={68}
              outerRadius={95}
              paddingAngle={3}
              dataKey="value"
              animationBegin={0}
              animationDuration={800}
            >
              {data.map((entry, index) => (
                <Cell key={`cell-${index}`} fill={entry.color} stroke="transparent" />
              ))}
            </Pie>
            <Tooltip content={<CustomTooltip />} />
            {/* Center label */}
          </PieChart>
        </ResponsiveContainer>
      </div>

      {/* Legend */}
      <div className="flex flex-col gap-2 mt-2">
        {data.map((item) => (
          <div key={item.name} className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <span
                className="inline-block rounded-full flex-shrink-0"
                style={{ width: 10, height: 10, backgroundColor: item.color }}
              />
              <span style={{ color: "#94a3b8", fontSize: 12 }}>{item.name}</span>
            </div>
            <div className="flex items-center gap-2">
              <div
                className="rounded-full"
                style={{ height: 4, width: 60, backgroundColor: "#252830", overflow: "hidden" }}
              >
                <div
                  className="h-full rounded-full"
                  style={{ width: `${item.value}%`, backgroundColor: item.color }}
                />
              </div>
              <span style={{ color: "#64748b", fontSize: 12, fontWeight: 600, minWidth: 28, textAlign: "right" }}>
                {item.value}%
              </span>
            </div>
          </div>
        ))}
      </div>
    </motion.div>
  );
}
