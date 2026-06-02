import { motion } from "motion/react";
import { TrendingUp, TrendingDown, DollarSign } from "lucide-react";
import {
  ComposedChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Line,
} from "recharts";

const monthlyData = [
  { mes: "Ene", ingresos: 38200, gastos: 24100, utilidad: 14100 },
  { mes: "Feb", ingresos: 41500, gastos: 26300, utilidad: 15200 },
  { mes: "Mar", ingresos: 39800, gastos: 25800, utilidad: 14000 },
  { mes: "Abr", ingresos: 44200, gastos: 27500, utilidad: 16700 },
  { mes: "May", ingresos: 48350, gastos: 29630, utilidad: 18720 },
];

const rows = [
  { label: "Ingresos por Ventas", value: "$48,350.00", pct: "100%", positive: true, bold: true },
  { label: "Costo de Mercancía Vendida (COGS)", value: "-$24,180.00", pct: "50.0%", positive: false },
  { label: "Utilidad Bruta", value: "$24,170.00", pct: "50.0%", positive: true, bold: true },
  { label: "Gastos Operativos", value: "-$3,650.00", pct: "7.5%", positive: false },
  { label: "Salarios y Comisiones", value: "-$1,800.00", pct: "3.7%", positive: false },
  { label: "Gastos Varios", value: "-$400.00", pct: "0.8%", positive: false },
  { label: "Utilidad Operativa (EBIT)", value: "$18,320.00", pct: "37.9%", positive: true, bold: true },
  { label: "Impuesto sobre la Renta (25%)", value: "-$4,580.00", pct: "9.5%", positive: false },
  { label: "Utilidad Neta", value: "$18,720.00", pct: "38.7%", positive: true, bold: true, highlight: true },
];

const CustomTooltip = ({ active, payload, label }: any) => {
  if (!active || !payload?.length) return null;
  return (
    <div className="rounded-lg p-3" style={{ backgroundColor: "#252830", border: "1px solid #3e4250", boxShadow: "0 8px 24px rgba(0,0,0,0.4)" }}>
      <p style={{ color: "#64748b", fontSize: 11, marginBottom: 6 }}>{label}</p>
      {payload.map((p: any) => (
        <div key={p.name} className="flex items-center gap-2">
          <span className="inline-block rounded-full" style={{ width: 8, height: 8, backgroundColor: p.color, flexShrink: 0 }} />
          <span style={{ color: "#94a3b8", fontSize: 12 }}>{p.name}:</span>
          <span style={{ color: "#f1f5f9", fontSize: 12, fontWeight: 600 }}>${p.value?.toLocaleString()}</span>
        </div>
      ))}
    </div>
  );
};

export function PageEstadoResultados() {
  return (
    <motion.div
      initial={{ opacity: 0, x: 24 }}
      animate={{ opacity: 1, x: 0 }}
      transition={{ duration: 0.3, ease: "easeOut" }}
      className="flex flex-col gap-6"
      style={{ padding: 24, paddingTop: 88 }}
    >
      {/* Header */}
      <div className="flex items-center gap-3">
        <div className="flex items-center justify-center rounded-xl" style={{ width: 44, height: 44, background: "rgba(99,102,241,0.12)", border: "1px solid rgba(99,102,241,0.2)" }}>
          <DollarSign size={22} color="#6366f1" />
        </div>
        <div>
          <h1 style={{ color: "#f1f5f9", fontWeight: 700, letterSpacing: "-0.5px", lineHeight: 1.2 }}>Estado de Resultados</h1>
          <p style={{ color: "#475569", fontSize: 13, marginTop: 3 }}>Período: 01 May 2026 — 26 May 2026</p>
        </div>
      </div>

      {/* Summary KPIs */}
      <div className="grid gap-4" style={{ gridTemplateColumns: "repeat(3, 1fr)" }}>
        {[
          { label: "Ingresos Totales", value: "$48,350.00", delta: "+12.4%", positive: true, borderColor: "#3b82f6" },
          { label: "Gastos Totales", value: "$29,630.00", delta: "+8.1%", positive: false, borderColor: "#ef4444" },
          { label: "Utilidad Neta", value: "$18,720.00", delta: "+18.7%", positive: true, borderColor: "#22c55e" },
        ].map((k) => (
          <motion.div
            key={k.label}
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.35 }}
            className="rounded-xl p-5"
            style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039", borderLeft: `4px solid ${k.borderColor}` }}
          >
            <p style={{ color: "#64748b", fontSize: 11, fontWeight: 500, textTransform: "uppercase", letterSpacing: "0.05em", marginBottom: 6 }}>{k.label}</p>
            <p style={{ color: "#f1f5f9", fontSize: 22, fontWeight: 700, letterSpacing: "-0.5px", lineHeight: 1 }}>{k.value}</p>
            <div className="flex items-center gap-1 mt-2">
              {k.positive ? <TrendingUp size={12} color="#22c55e" /> : <TrendingDown size={12} color="#ef4444" />}
              <span style={{ color: k.positive ? "#22c55e" : "#ef4444", fontSize: 11, fontWeight: 600 }}>{k.delta} vs mes anterior</span>
            </div>
          </motion.div>
        ))}
      </div>

      {/* Chart + Table */}
      <div className="grid gap-6" style={{ gridTemplateColumns: "1fr 1fr" }}>
        {/* Monthly trend */}
        <div className="rounded-xl p-5" style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039" }}>
          <h3 style={{ color: "#f1f5f9", fontSize: 15, fontWeight: 600, marginBottom: 4 }}>Evolución Mensual</h3>
          <p style={{ color: "#475569", fontSize: 12, marginBottom: 16 }}>Ene — May 2026</p>
          <ResponsiveContainer width="100%" height={240}>
            <ComposedChart data={monthlyData} margin={{ top: 4, right: 4, left: -20, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="#2e3039" vertical={false} />
              <XAxis dataKey="mes" tick={{ fill: "#475569", fontSize: 11 }} axisLine={false} tickLine={false} />
              <YAxis tick={{ fill: "#475569", fontSize: 11 }} axisLine={false} tickLine={false} tickFormatter={(v) => `$${(v / 1000).toFixed(0)}k`} />
              <Tooltip content={<CustomTooltip />} cursor={{ fill: "rgba(99,102,241,0.04)" }} />
              <Bar name="Ingresos" dataKey="ingresos" fill="#3b82f6" fillOpacity={0.7} radius={[4, 4, 0, 0]} />
              <Bar name="Gastos" dataKey="gastos" fill="#ef4444" fillOpacity={0.7} radius={[4, 4, 0, 0]} />
              <Line name="Utilidad" type="monotone" dataKey="utilidad" stroke="#22c55e" strokeWidth={2} dot={{ fill: "#22c55e", r: 3 }} />
            </ComposedChart>
          </ResponsiveContainer>
        </div>

        {/* Income statement table */}
        <div className="rounded-xl overflow-hidden" style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039" }}>
          <div className="px-5 py-4" style={{ borderBottom: "1px solid #2e3039" }}>
            <h3 style={{ color: "#f1f5f9", fontSize: 15, fontWeight: 600 }}>Desglose</h3>
          </div>
          <div>
            {rows.map((row, i) => (
              <div
                key={i}
                className="flex items-center justify-between px-5 py-3"
                style={{
                  borderBottom: i < rows.length - 1 ? "1px solid #2e3039" : "none",
                  backgroundColor: row.highlight ? "rgba(34,197,94,0.06)" : "transparent",
                }}
              >
                <span style={{ color: row.bold ? "#e2e8f0" : "#64748b", fontSize: 12, fontWeight: row.bold ? 600 : 400 }}>
                  {row.label}
                </span>
                <div className="flex items-center gap-3">
                  <span style={{ color: "#475569", fontSize: 11 }}>{row.pct}</span>
                  <span style={{ color: row.positive ? "#22c55e" : "#ef4444", fontSize: 12, fontWeight: row.bold ? 700 : 500, minWidth: 90, textAlign: "right" }}>
                    {row.value}
                  </span>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    </motion.div>
  );
}
