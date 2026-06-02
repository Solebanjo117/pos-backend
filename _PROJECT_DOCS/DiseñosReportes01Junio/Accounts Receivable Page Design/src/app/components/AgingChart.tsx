import { useState } from "react";
import { motion } from "motion/react";
import { TrendingUp, Shield, Clock, AlertOctagon, Info } from "lucide-react";
import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  ComposedChart,
  Line,
  Cell,
  LabelList,
  Legend,
} from "recharts";

// ── data ────────────────────────────────────────────────────────────────────

const segments = [
  { label: "0–30 días",  amount: 6200, percentage: 49.7, count: 12, recovery: 99, color: "#22c55e", risk: "Bajo",    riskIcon: <Shield size={12} /> },
  { label: "31–60 días", amount: 3800, percentage: 30.4, count: 7,  recovery: 95, color: "#f59e0b", risk: "Medio",   riskIcon: <Clock size={12} /> },
  { label: "61–90 días", amount: 1680, percentage: 13.5, count: 2,  recovery: 85, color: "#f97316", risk: "Alto",    riskIcon: <TrendingUp size={12} /> },
  { label: "+90 días",   amount: 800,  percentage: 6.4,  count: 2,  recovery: 55, color: "#ef4444", risk: "Crítico", riskIcon: <AlertOctagon size={12} /> },
];

// reversed for horizontal bar chart (best at top)
const agingBarData = [...segments].reverse();

const trendData = [
  { month: "Dic", credits: 6200, collections: 5800 },
  { month: "Ene", credits: 6800, collections: 6100 },
  { month: "Feb", credits: 7100, collections: 6400 },
  { month: "Mar", credits: 7600, collections: 6800 },
  { month: "Abr", credits: 7900, collections: 5200 },
  { month: "May", credits: 8450, collections: 5200 },
];

const totalAmount    = segments.reduce((s, x) => s + x.amount, 0);
const expectedRecovery = segments.reduce((s, x) => s + x.amount * (x.recovery / 100), 0);
const recoveryPct    = ((expectedRecovery / totalAmount) * 100).toFixed(1);
const healthScore    = (((totalAmount - 800) / totalAmount) * 100).toFixed(1);
const dso            = Math.round((totalAmount / 8450) * 30);

const fmtK = (n: number) =>
  n >= 1000 ? `$${(n / 1000).toFixed(1)}K` : `$${n}`;

const fmtFull = (n: number) =>
  new Intl.NumberFormat("en-US", { minimumFractionDigits: 0 }).format(n);

// ── tooltips ─────────────────────────────────────────────────────────────────

function AgingTooltip({ active, payload }: any) {
  if (!active || !payload?.length) return null;
  const seg = segments.find((s) => s.label === payload[0]?.payload?.label);
  if (!seg) return null;
  return (
    <div
      style={{
        background: "#1c1f30",
        border: "1px solid #252840",
        borderRadius: 8,
        padding: "10px 14px",
        fontSize: 12,
        boxShadow: "0 8px 24px rgba(0,0,0,0.4)",
        minWidth: 160,
      }}
    >
      <div className="flex items-center gap-2 mb-2">
        <div style={{ width: 8, height: 8, borderRadius: 2, background: seg.color }} />
        <span style={{ color: "#dde0ee", fontWeight: 600 }}>{seg.label}</span>
        <span
          style={{
            marginLeft: "auto",
            background: `${seg.color}18`,
            color: seg.color,
            fontSize: 10,
            fontWeight: 600,
            padding: "1px 6px",
            borderRadius: 4,
          }}
        >
          {seg.risk}
        </span>
      </div>
      {[
        ["Monto",        `$${fmtFull(seg.amount)}`],
        ["% del total",  `${seg.percentage}%`],
        ["Facturas",     `${seg.count}`],
        ["Rec. esperada",`${seg.recovery}%`],
      ].map(([k, v]) => (
        <div key={k} className="flex justify-between gap-4" style={{ marginBottom: 2 }}>
          <span style={{ color: "#4a506a" }}>{k}</span>
          <span style={{ color: "#dde0ee", fontWeight: 600 }}>{v}</span>
        </div>
      ))}
    </div>
  );
}

function TrendTooltip({ active, payload, label }: any) {
  if (!active || !payload?.length) return null;
  return (
    <div
      style={{
        background: "#1c1f30",
        border: "1px solid #252840",
        borderRadius: 8,
        padding: "10px 14px",
        fontSize: 12,
        boxShadow: "0 8px 24px rgba(0,0,0,0.4)",
      }}
    >
      <div style={{ color: "#7880a0", marginBottom: 6, fontWeight: 600 }}>{label} 2025–26</div>
      {payload.map((p: any) => (
        <div key={p.dataKey} className="flex justify-between gap-5" style={{ marginBottom: 2 }}>
          <span style={{ color: p.color }}>{p.name}</span>
          <span style={{ color: "#dde0ee", fontWeight: 600 }}>${fmtFull(p.value)}</span>
        </div>
      ))}
      {payload.length === 2 && (
        <div
          style={{
            marginTop: 6,
            paddingTop: 6,
            borderTop: "1px solid #1a1d2e",
            color: payload[1].value >= payload[0].value ? "#4ade80" : "#f87171",
            fontSize: 11,
          }}
        >
          {payload[1].value >= payload[0].value ? "▲" : "▼"} Eficiencia:{" "}
          {((payload[1].value / payload[0].value) * 100).toFixed(0)}%
        </div>
      )}
    </div>
  );
}

// ── component ────────────────────────────────────────────────────────────────

export function AgingChart() {
  return (
    <div className="rounded-lg" style={{ background: "#111420", border: "1px solid #1e2132" }}>
      {/* Header */}
      <div
        className="flex items-center justify-between px-5 py-4"
        style={{ borderBottom: "1px solid #1a1d2e" }}
      >
        <div>
          <div style={{ color: "#dde0ee", fontSize: 14, fontWeight: 600 }}>
            Antigüedad de Saldos
          </div>
          <div style={{ color: "#4a506a", fontSize: 11, marginTop: 2 }}>
            Análisis de concentración y riesgo de cartera
          </div>
        </div>
        <div className="flex items-center gap-5">
          <div className="text-right">
            <div style={{ color: "#7880a0", fontSize: 10, fontWeight: 600, textTransform: "uppercase", letterSpacing: "0.05em" }}>DSO</div>
            <div style={{ color: "#dde0ee", fontSize: 18, fontWeight: 700, fontVariantNumeric: "tabular-nums" }}>
              {dso} <span style={{ fontSize: 11, color: "#7880a0", fontWeight: 400 }}>días</span>
            </div>
          </div>
          <div style={{ width: 1, height: 32, background: "#1e2132" }} />
          <div className="text-right">
            <div style={{ color: "#7880a0", fontSize: 10, fontWeight: 600, textTransform: "uppercase", letterSpacing: "0.05em" }}>Rec. Esperada</div>
            <div style={{ color: "#22c55e", fontSize: 18, fontWeight: 700, fontVariantNumeric: "tabular-nums" }}>
              ${fmtFull(expectedRecovery)}{" "}
              <span style={{ fontSize: 11, color: "#22c55e70", fontWeight: 400 }}>({recoveryPct}%)</span>
            </div>
          </div>
          <div style={{ width: 1, height: 32, background: "#1e2132" }} />
          <div className="text-right">
            <div style={{ color: "#7880a0", fontSize: 10, fontWeight: 600, textTransform: "uppercase", letterSpacing: "0.05em" }}>Salud Cartera</div>
            <div style={{ color: "#3b82f6", fontSize: 18, fontWeight: 700, fontVariantNumeric: "tabular-nums" }}>
              {healthScore}%
            </div>
          </div>
        </div>
      </div>

      {/* ── two-chart row ── */}
      <div className="grid grid-cols-2 gap-0" style={{ borderBottom: "1px solid #1a1d2e" }}>
        {/* Left: aging distribution bar chart */}
        <div className="px-5 py-4" style={{ borderRight: "1px solid #1a1d2e" }}>
          <div style={{ color: "#7880a0", fontSize: 11, fontWeight: 600, marginBottom: 12, textTransform: "uppercase", letterSpacing: "0.04em" }}>
            Distribución por Período
          </div>
          <ResponsiveContainer width="100%" height={160}>
            <BarChart
              data={agingBarData}
              layout="vertical"
              margin={{ top: 0, right: 56, bottom: 0, left: 8 }}
              barCategoryGap="28%"
            >
              <CartesianGrid horizontal={false} strokeDasharray="3 3" stroke="#1a1d2e" />
              <XAxis
                type="number"
                tickFormatter={fmtK}
                tick={{ fill: "#434865", fontSize: 10 }}
                axisLine={{ stroke: "#1a1d2e" }}
                tickLine={false}
                domain={[0, 7000]}
              />
              <YAxis
                type="category"
                dataKey="label"
                width={64}
                tick={{ fill: "#7880a0", fontSize: 11 }}
                axisLine={false}
                tickLine={false}
              />
              <Tooltip content={<AgingTooltip />} cursor={{ fill: "rgba(255,255,255,0.03)" }} />
              <Bar dataKey="amount" radius={[0, 3, 3, 0]} isAnimationActive animationDuration={700}>
                {agingBarData.map((entry, i) => (
                  <Cell key={i} fill={entry.color} fillOpacity={0.85} />
                ))}
                <LabelList
                  dataKey="amount"
                  position="right"
                  formatter={(v: number) => `$${fmtFull(v)}`}
                  style={{ fill: "#7880a0", fontSize: 11, fontVariantNumeric: "tabular-nums" }}
                />
              </Bar>
            </BarChart>
          </ResponsiveContainer>
        </div>

        {/* Right: 6-month collections vs credits trend */}
        <div className="px-5 py-4">
          <div style={{ color: "#7880a0", fontSize: 11, fontWeight: 600, marginBottom: 12, textTransform: "uppercase", letterSpacing: "0.04em" }}>
            Tendencia — Créditos vs Cobros (6m)
          </div>
          <ResponsiveContainer width="100%" height={160}>
            <ComposedChart
              data={trendData}
              margin={{ top: 4, right: 8, bottom: 0, left: 0 }}
            >
              <defs>
                <linearGradient id="creditsGrad" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor="#3b82f6" stopOpacity={0.2} />
                  <stop offset="100%" stopColor="#3b82f6" stopOpacity={0.03} />
                </linearGradient>
              </defs>
              <CartesianGrid strokeDasharray="3 3" stroke="#1a1d2e" vertical={false} />
              <XAxis
                dataKey="month"
                tick={{ fill: "#434865", fontSize: 10 }}
                axisLine={{ stroke: "#1a1d2e" }}
                tickLine={false}
              />
              <YAxis
                tickFormatter={fmtK}
                tick={{ fill: "#434865", fontSize: 10 }}
                axisLine={false}
                tickLine={false}
                width={40}
              />
              <Tooltip content={<TrendTooltip />} cursor={{ stroke: "#252840", strokeWidth: 1 }} />
              <Bar
                dataKey="credits"
                name="Créditos"
                fill="url(#creditsGrad)"
                stroke="#3b82f640"
                strokeWidth={1}
                radius={[2, 2, 0, 0]}
                isAnimationActive
                animationDuration={700}
              />
              <Line
                type="monotone"
                dataKey="collections"
                name="Cobros"
                stroke="#22c55e"
                strokeWidth={2}
                dot={{ r: 3, fill: "#22c55e", strokeWidth: 0 }}
                activeDot={{ r: 4, fill: "#22c55e" }}
                isAnimationActive
                animationDuration={900}
              />
              <Legend
                wrapperStyle={{ fontSize: 11, paddingTop: 8 }}
                formatter={(value) => (
                  <span style={{ color: "#7880a0" }}>{value}</span>
                )}
              />
            </ComposedChart>
          </ResponsiveContainer>
        </div>
      </div>

      {/* ── breakdown table ── */}
      <div className="px-5 py-4">
        <div className="rounded-lg overflow-hidden" style={{ border: "1px solid #1a1d2e" }}>
          <table style={{ width: "100%", borderCollapse: "collapse" }}>
            <thead>
              <tr style={{ background: "#0e1019" }}>
                {["Período", "Monto", "% Total", "Facturas", "Rec. Esperada", "Riesgo"].map((h) => (
                  <th
                    key={h}
                    style={{
                      color: "#434865",
                      fontSize: 10,
                      fontWeight: 600,
                      textTransform: "uppercase",
                      letterSpacing: "0.05em",
                      padding: "8px 14px",
                      textAlign: h === "Período" || h === "Riesgo" ? "left" : "right",
                      borderBottom: "1px solid #1a1d2e",
                    }}
                  >
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {segments.map((seg, i) => (
                <motion.tr
                  key={seg.label}
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                  transition={{ delay: 0.3 + i * 0.06 }}
                  className="hover:bg-white/[0.015] transition-colors"
                  style={{ borderBottom: i < segments.length - 1 ? "1px solid #0e101988" : undefined }}
                >
                  <td style={{ padding: "9px 14px" }}>
                    <div className="flex items-center gap-2">
                      <div style={{ width: 8, height: 8, borderRadius: 2, background: seg.color, flexShrink: 0 }} />
                      <span style={{ color: "#dde0ee", fontSize: 12, fontWeight: 500 }}>{seg.label}</span>
                    </div>
                  </td>
                  <td style={{ padding: "9px 14px", color: seg.color, fontSize: 12, fontWeight: 600, textAlign: "right", fontVariantNumeric: "tabular-nums" }}>
                    ${fmtFull(seg.amount)}
                  </td>
                  <td style={{ padding: "9px 14px", color: "#7880a0", fontSize: 12, textAlign: "right", fontVariantNumeric: "tabular-nums" }}>
                    {seg.percentage}%
                  </td>
                  <td style={{ padding: "9px 14px", color: "#7880a0", fontSize: 12, textAlign: "right", fontVariantNumeric: "tabular-nums" }}>
                    {seg.count}
                  </td>
                  <td style={{ padding: "9px 14px", textAlign: "right" }}>
                    <span
                      style={{
                        background: seg.recovery >= 90 ? "#22c55e12" : seg.recovery >= 80 ? "#f59e0b12" : "#ef444412",
                        color: seg.recovery >= 90 ? "#4ade80" : seg.recovery >= 80 ? "#fbbf24" : "#f87171",
                        fontSize: 11,
                        fontWeight: 600,
                        padding: "2px 7px",
                        borderRadius: 4,
                        fontVariantNumeric: "tabular-nums",
                      }}
                    >
                      {seg.recovery}%
                    </span>
                  </td>
                  <td style={{ padding: "9px 14px" }}>
                    <div className="flex items-center gap-1.5">
                      <span style={{ color: seg.color }}>{seg.riskIcon}</span>
                      <span style={{ color: seg.color, fontSize: 11, fontWeight: 600 }}>{seg.risk}</span>
                    </div>
                  </td>
                </motion.tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Insights */}
        <div className="flex gap-3 mt-3">
          {[
            { icon: <Info size={11} />,          color: "#3b82f6", text: "80.1% del saldo en estado saludable (0–60 días)" },
            { icon: <AlertOctagon size={11} />,   color: "#ef4444", text: "2 clientes concentran el 100% del vencido +90d" },
            { icon: <TrendingUp size={11} />,     color: "#22c55e", text: `Eficiencia de cobro: ${((5200 / 12480) * 100).toFixed(1)}% mensual` },
          ].map((ins, i) => (
            <div
              key={i}
              className="flex items-start gap-2 flex-1 rounded px-3 py-2"
              style={{ background: `${ins.color}08`, border: `1px solid ${ins.color}18` }}
            >
              <span style={{ color: ins.color, marginTop: 1, flexShrink: 0 }}>{ins.icon}</span>
              <span style={{ color: "#7880a0", fontSize: 11, lineHeight: 1.4 }}>{ins.text}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
