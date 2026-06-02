import React from "react";
import { motion } from "motion/react";
import {
  BarChart3,
  TrendingUp,
  TrendingDown,
  Zap,
  Activity,
  Receipt,
  ArrowUpRight,
  ArrowDownRight,
} from "lucide-react";
import { useCountUp, fmt, deltaColor } from "./utils";

/* ─── Helpers ─── */
function pctChange(a: number, b: number) {
  return ((a - b) / Math.abs(b)) * 100;
}

function DeltaCell({
  today,
  yesterday,
  dir = "higher_better",
  isCost = false,
}: {
  today: number;
  yesterday: number;
  dir?: "higher_better" | "lower_better" | "neutral";
  isCost?: boolean;
}) {
  const raw = today - yesterday;
  const pct = pctChange(today, yesterday);
  const color = deltaColor(raw, dir);
  const Icon = raw >= 0 ? ArrowUpRight : ArrowDownRight;
  if (today === 0 && yesterday === 0) return null;
  return (
    <td
      className="text-right py-2.5 pr-5 tabular-nums"
      style={{ fontSize: 11 }}
    >
      <span
        className="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded"
        style={{ color, background: `${color}15`, fontWeight: 500 }}
      >
        <Icon size={9} />
        {raw >= 0 ? "+" : ""}${fmt(Math.abs(raw))}
        <span style={{ opacity: 0.75 }}>
          ({raw >= 0 ? "+" : ""}
          {pct.toFixed(1)}%)
        </span>
      </span>
    </td>
  );
}

/* ─── Accounting row ─── */
interface RowProps {
  label: string;
  indent?: boolean;
  todayVal: number | string;
  yestVal?: number | string;
  today?: number;
  yesterday?: number;
  dir?: "higher_better" | "lower_better" | "neutral";
  isParens?: boolean;
  isTotal?: boolean;
  isSuffix?: string;
  delay?: number;
  accentColor?: string;
}

function AccountingRow({
  label,
  indent,
  todayVal,
  yestVal,
  today,
  yesterday,
  dir = "higher_better",
  isParens,
  isTotal,
  isSuffix,
  delay = 0,
  accentColor,
}: RowProps) {
  const showDelta =
    typeof today === "number" && typeof yesterday === "number" && !isSuffix;

  return (
    <motion.tr
      initial={{ opacity: 0 }}
      animate={{ opacity: 1 }}
      transition={{ duration: 0.25, delay }}
      style={{
        borderTop: isTotal ? "1px solid #2e3039" : undefined,
      }}
    >
      <td
        className="py-2.5 pl-5"
        style={{
          paddingLeft: indent ? 28 : 20,
          color: isTotal ? "#e2e8f0" : "#9ca3af",
          fontSize: isTotal ? 12 : 11,
          fontWeight: isTotal ? 600 : 400,
          letterSpacing: isTotal ? "0.04em" : undefined,
        }}
      >
        {accentColor && (
          <span
            style={{
              display: "inline-block",
              width: 2,
              height: 10,
              borderRadius: 1,
              background: accentColor,
              marginRight: 7,
              verticalAlign: "middle",
            }}
          />
        )}
        {label}
      </td>

      {/* Today */}
      <td
        className="text-right py-2.5 pr-4 tabular-nums"
        style={{
          color: isTotal
            ? accentColor ?? "#f1f5f9"
            : isParens
            ? "#f87171"
            : "#d1d5db",
          fontSize: isTotal ? 13 : 12,
          fontWeight: isTotal ? 700 : 400,
        }}
      >
        {isParens
          ? `(${todayVal})`
          : isSuffix
          ? `${todayVal}${isSuffix}`
          : `$${todayVal}`}
      </td>

      {/* Yesterday */}
      <td
        className="text-right py-2.5 pr-4 tabular-nums"
        style={{ color: "#4b5563", fontSize: 11 }}
      >
        {yestVal !== undefined
          ? isParens
            ? `(${yestVal})`
            : isSuffix
            ? `${yestVal}${isSuffix}`
            : `$${yestVal}`
          : "—"}
      </td>

      {/* Delta */}
      {showDelta ? (
        <DeltaCell today={today!} yesterday={yesterday!} dir={dir} />
      ) : (
        <td />
      )}
    </motion.tr>
  );
}

function SectionLabel({ label, delay = 0 }: { label: string; delay?: number }) {
  return (
    <motion.tr
      initial={{ opacity: 0 }}
      animate={{ opacity: 1 }}
      transition={{ delay }}
    >
      <td
        colSpan={4}
        className="pt-4 pb-1 pl-5"
        style={{
          color: "#4b5563",
          fontSize: 9.5,
          fontWeight: 600,
          letterSpacing: "0.1em",
          textTransform: "uppercase",
        }}
      >
        {label}
      </td>
    </motion.tr>
  );
}

/* ─── Bottom metric card ─── */
function MetricPill({
  icon,
  label,
  value,
  delta,
  color,
  delay = 0,
}: {
  icon: React.ReactNode;
  label: string;
  value: string;
  delta?: string;
  color: string;
  delay?: number;
}) {
  return (
    <motion.div
      initial={{ opacity: 0, y: 6 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.28, delay }}
      className="flex-1 px-4 py-3 rounded-lg"
      style={{ background: "#252830", border: "1px solid #2e3039" }}
    >
      <div className="flex items-center gap-2 mb-1.5">
        <span style={{ color }}>{icon}</span>
        <span style={{ color: "#6b7280", fontSize: 10, fontWeight: 500, letterSpacing: "0.05em", textTransform: "uppercase" }}>
          {label}
        </span>
      </div>
      <div style={{ color: "#f1f5f9", fontSize: 16, fontWeight: 700, fontVariantNumeric: "tabular-nums", letterSpacing: "-0.01em" }}>
        {value}
      </div>
      {delta && (
        <div style={{ color: "#6b7280", fontSize: 10, marginTop: 2 }}>{delta}</div>
      )}
    </motion.div>
  );
}

/* ─── Velocity bar ─── */
function VelocityBar({ txns }: { txns: number }) {
  const max = 20;
  const pct = Math.min((txns / max) * 100, 100);
  const label = txns >= 15 ? "Alta" : txns >= 8 ? "Normal" : "Baja";
  const color = txns >= 15 ? "#22c55e" : txns >= 8 ? "#3b82f6" : "#f97316";
  return (
    <motion.div
      initial={{ opacity: 0, y: 6 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.28, delay: 0.55 }}
      className="flex-1 px-4 py-3 rounded-lg"
      style={{ background: "#252830", border: "1px solid #2e3039" }}
    >
      <div className="flex items-center gap-2 mb-1.5">
        <Activity size={13} style={{ color }} />
        <span style={{ color: "#6b7280", fontSize: 10, fontWeight: 500, letterSpacing: "0.05em", textTransform: "uppercase" }}>
          Velocidad de Ventas
        </span>
      </div>
      <div className="flex items-center gap-3">
        <div style={{ color, fontSize: 16, fontWeight: 700 }}>{label}</div>
        <div className="flex-1 h-1.5 rounded-full" style={{ background: "#1a1d27" }}>
          <motion.div
            initial={{ width: 0 }}
            animate={{ width: `${pct}%` }}
            transition={{ duration: 0.8, delay: 0.7, ease: "easeOut" }}
            className="h-full rounded-full"
            style={{ background: color }}
          />
        </div>
        <span style={{ color: "#6b7280", fontSize: 10, fontVariantNumeric: "tabular-nums" }}>
          {txns}/día
        </span>
      </div>
      <div style={{ color: "#6b7280", fontSize: 10, marginTop: 3 }}>+1 txn vs ayer</div>
    </motion.div>
  );
}

/* ─── Main component ─── */
export function DailyPnL() {
  const netSales = useCountUp(4035, 950, 150);
  const grossProfit = useCountUp(1585, 950, 250);

  return (
    <motion.div
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.38, delay: 0.1 }}
      className="rounded-xl border border-[#2e3039] overflow-hidden"
      style={{ background: "#1a1d27" }}
    >
      {/* Header */}
      <div className="flex items-center justify-between px-5 py-3.5 border-b border-[#2e3039]">
        <div className="flex items-center gap-2.5">
          <div
            className="w-7 h-7 rounded-lg flex items-center justify-center"
            style={{ background: "rgba(59,130,246,0.1)" }}
          >
            <BarChart3 size={14} style={{ color: "#3b82f6" }} />
          </div>
          <div>
            <span
              style={{
                color: "#e2e8f0",
                fontWeight: 600,
                fontSize: 12,
                letterSpacing: "0.05em",
              }}
            >
              ESTADO DE RESULTADOS DEL DÍA
            </span>
            <span
              className="ml-2"
              style={{ color: "#4b5563", fontSize: 11 }}
            >
              · Comparativa: 25/05/2026
            </span>
          </div>
        </div>
        <div className="flex items-center gap-3">
          <div className="text-right">
            <div style={{ color: "#6b7280", fontSize: 9.5, textTransform: "uppercase", letterSpacing: "0.08em" }}>Ventas Netas</div>
            <div style={{ color: "#22c55e", fontSize: 16, fontWeight: 700, fontVariantNumeric: "tabular-nums" }}>
              ${fmt(netSales)}
            </div>
          </div>
          <div
            className="w-px self-stretch"
            style={{ background: "#2e3039" }}
          />
          <div className="text-right">
            <div style={{ color: "#6b7280", fontSize: 9.5, textTransform: "uppercase", letterSpacing: "0.08em" }}>Utilidad Bruta</div>
            <div style={{ color: "#818cf8", fontSize: 16, fontWeight: 700, fontVariantNumeric: "tabular-nums" }}>
              ${fmt(grossProfit)}
            </div>
          </div>
        </div>
      </div>

      {/* Accounting table */}
      <div className="overflow-x-auto">
        <table className="w-full">
          <thead>
            <tr style={{ borderBottom: "1px solid #2e3039" }}>
              <th
                className="text-left pl-5 py-2.5"
                style={{ color: "#4b5563", fontSize: 9.5, fontWeight: 600, letterSpacing: "0.08em", textTransform: "uppercase" }}
              >
                Concepto
              </th>
              <th
                className="text-right pr-4 py-2.5"
                style={{ color: "#4b5563", fontSize: 9.5, fontWeight: 600, letterSpacing: "0.08em", textTransform: "uppercase" }}
              >
                Hoy
              </th>
              <th
                className="text-right pr-4 py-2.5"
                style={{ color: "#4b5563", fontSize: 9.5, fontWeight: 600, letterSpacing: "0.08em", textTransform: "uppercase" }}
              >
                Ayer
              </th>
              <th
                className="text-right pr-5 py-2.5"
                style={{ color: "#4b5563", fontSize: 9.5, fontWeight: 600, letterSpacing: "0.08em", textTransform: "uppercase" }}
              >
                Δ
              </th>
            </tr>
          </thead>
          <tbody>
            <SectionLabel label="Ingresos" delay={0.1} />
            <AccountingRow label="Ventas Brutas" indent todayVal={fmt(4200)} yestVal={fmt(3850)} today={4200} yesterday={3850} dir="higher_better" delay={0.12} accentColor="#3b82f6" />
            <AccountingRow label="(−) Devoluciones" indent todayVal={fmt(120)} yestVal={fmt(100)} today={120} yesterday={100} dir="lower_better" isParens delay={0.15} />
            <AccountingRow label="(−) Descuentos" indent todayVal={fmt(45)} yestVal={fmt(40)} today={45} yesterday={40} dir="lower_better" isParens delay={0.17} />
            <AccountingRow label="VENTAS NETAS" todayVal={fmt(4035)} yestVal={fmt(3710)} today={4035} yesterday={3710} dir="higher_better" isTotal delay={0.19} accentColor="#22c55e" />

            <SectionLabel label="Costo de Ventas" delay={0.21} />
            <AccountingRow label="(−) COGS" indent todayVal={fmt(2450)} yestVal={fmt(2290)} today={2450} yesterday={2290} dir="lower_better" isParens delay={0.23} />

            <AccountingRow label="UTILIDAD BRUTA" todayVal={fmt(1585)} yestVal={fmt(1420)} today={1585} yesterday={1420} dir="higher_better" isTotal delay={0.26} accentColor="#818cf8" />
            <AccountingRow label="Margen Bruto" indent todayVal="39.3" yestVal="38.3" today={39.3} yesterday={38.3} dir="higher_better" isSuffix="%" delay={0.28} />
          </tbody>
        </table>
      </div>

      {/* Bottom metrics */}
      <div className="px-5 py-4 border-t border-[#2e3039] flex gap-3">
        <MetricPill
          icon={<Receipt size={13} />}
          label="Transacciones"
          value="12"
          delta="+1 vs ayer"
          color="#3b82f6"
          delay={0.35}
        />
        <MetricPill
          icon={<BarChart3 size={13} />}
          label="Ticket Promedio"
          value={`$${fmt(336.25)}`}
          delta="−$13.75 vs ayer"
          color="#6366f1"
          delay={0.42}
        />
        <MetricPill
          icon={<TrendingUp size={13} />}
          label="Utilidad / Txn"
          value={`$${fmt(132.08)}`}
          delta="+$2.99 vs ayer"
          color="#22c55e"
          delay={0.49}
        />
        <VelocityBar txns={12} />
      </div>
    </motion.div>
  );
}
