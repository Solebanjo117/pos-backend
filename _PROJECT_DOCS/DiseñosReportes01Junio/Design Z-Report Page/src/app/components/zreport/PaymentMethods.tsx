import React, { useState, useEffect, useRef } from "react";
import { motion } from "motion/react";
import { CreditCard, Banknote, FileText, Smartphone } from "lucide-react";
import { fmt } from "./utils";

interface PaymentMethod {
  id: string;
  icon: React.ReactNode;
  label: string;
  sub: string;
  txns: number;
  amount: number;
  pct: number;
  color: string;
  bgColor: string;
}

const METHODS: PaymentMethod[] = [
  {
    id: "cash",
    icon: <Banknote size={15} />,
    label: "Efectivo",
    sub: "Total recibido en caja",
    txns: 8,
    amount: 2450,
    pct: 60.7,
    color: "#22c55e",
    bgColor: "rgba(34,197,94,0.1)",
  },
  {
    id: "card",
    icon: <CreditCard size={15} />,
    label: "Tarjeta / Transferencia",
    sub: "Pagos electrónicos",
    txns: 3,
    amount: 850,
    pct: 21.1,
    color: "#3b82f6",
    bgColor: "rgba(59,130,246,0.1)",
  },
  {
    id: "yappy",
    icon: <Smartphone size={15} />,
    label: "Yappy",
    sub: "Pagos móviles",
    txns: 1,
    amount: 600,
    pct: 14.9,
    color: "#06b6d4",
    bgColor: "rgba(6,182,212,0.1)",
  },
  {
    id: "credit",
    icon: <FileText size={15} />,
    label: "Crédito",
    sub: "Cuentas por cobrar",
    txns: 1,
    amount: 135,
    pct: 3.3,
    color: "#f97316",
    bgColor: "rgba(249,115,22,0.1)",
  },
];

/* ─── Stacked bar with hover tooltips ─── */
function StackedBar({ methods }: { methods: PaymentMethod[] }) {
  const [hovered, setHovered] = useState<string | null>(null);
  const [mounted, setMounted] = useState(false);
  const [tooltipPos, setTooltipPos] = useState({ x: 0 });
  const barRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const t = setTimeout(() => setMounted(true), 300);
    return () => clearTimeout(t);
  }, []);

  return (
    <div className="pt-4 border-t border-[#252830]">
      {/* Bar */}
      <div
        ref={barRef}
        className="flex h-2 rounded-full overflow-hidden relative"
        style={{ background: "#252830" }}
      >
        {methods.map((m) => (
          <div
            key={m.id}
            className="h-full relative cursor-pointer transition-all duration-200"
            style={{
              width: mounted ? `${m.pct}%` : "0%",
              background: m.color,
              opacity: hovered && hovered !== m.id ? 0.35 : 1,
              transition: "width 0.7s ease-out, opacity 0.15s ease",
            }}
            onMouseEnter={(e) => {
              setHovered(m.id);
              if (barRef.current) {
                const rect = barRef.current.getBoundingClientRect();
                const targetRect = (e.target as HTMLElement).getBoundingClientRect();
                setTooltipPos({ x: targetRect.left - rect.left + targetRect.width / 2 });
              }
            }}
            onMouseLeave={() => setHovered(null)}
          />
        ))}
      </div>

      {/* Tooltip */}
      {hovered && (() => {
        const m = methods.find((x) => x.id === hovered);
        if (!m) return null;
        return (
          <div
            className="absolute pointer-events-none z-20 px-3 py-2 rounded-lg text-xs shadow-xl"
            style={{
              left: tooltipPos.x,
              transform: "translateX(-50%) translateY(-100%)",
              marginTop: -8,
              background: "#1a1d27",
              border: `1px solid ${m.color}40`,
              color: "#f1f5f9",
              minWidth: 120,
            }}
          >
            <div style={{ color: m.color, fontWeight: 600 }}>{m.label}</div>
            <div>${fmt(m.amount)} · {m.pct}%</div>
            <div style={{ color: "#6b7280" }}>{m.txns} transac.</div>
          </div>
        );
      })()}

      {/* Legend */}
      <div className="flex flex-wrap gap-x-5 gap-y-1 mt-3">
        {methods.map((m) => (
          <div key={m.id} className="flex items-center gap-1.5">
            <div
              className="w-2 h-2 rounded-sm flex-shrink-0"
              style={{ background: m.color }}
            />
            <span style={{ color: "#9ca3af", fontSize: 11 }}>
              {m.label}
            </span>
            <span style={{ color: "#6b7280", fontSize: 11 }}>
              {m.pct}%
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}

export function PaymentMethods() {
  const total = METHODS.reduce((s, m) => s + m.amount, 0);
  const totalTxns = METHODS.reduce((s, m) => s + m.txns, 0);

  return (
    <motion.div
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.38, delay: 0.12 }}
      className="rounded-xl border border-[#2e3039] overflow-hidden relative"
      style={{ background: "#1a1d27" }}
    >
      {/* Header */}
      <div className="flex items-center justify-between px-5 py-3.5 border-b border-[#2e3039]">
        <div className="flex items-center gap-2.5">
          <div
            className="w-7 h-7 rounded-lg flex items-center justify-center"
            style={{ background: "rgba(139,92,246,0.1)" }}
          >
            <CreditCard size={14} style={{ color: "#8b5cf6" }} />
          </div>
          <span
            style={{
              color: "#e2e8f0",
              fontWeight: 600,
              fontSize: 12,
              letterSpacing: "0.05em",
            }}
          >
            DESGLOSE POR MÉTODO DE PAGO
          </span>
        </div>
        <div
          style={{
            color: "#6b7280",
            fontSize: 11,
          }}
        >
          {totalTxns} transacciones &middot;{" "}
          <span style={{ color: "#d1d5db", fontVariantNumeric: "tabular-nums" }}>
            ${fmt(total)}
          </span>
        </div>
      </div>

      {/* Table header */}
      <div
        className="grid px-5 py-2 border-b border-[#252830]"
        style={{ gridTemplateColumns: "1fr auto auto auto" }}
      >
        <span style={{ color: "#4b5563", fontSize: 9.5, fontWeight: 600, letterSpacing: "0.08em", textTransform: "uppercase" }}>
          Método
        </span>
        <span style={{ color: "#4b5563", fontSize: 9.5, fontWeight: 600, letterSpacing: "0.08em", textTransform: "uppercase", textAlign: "right", marginRight: 32 }}>
          Txns
        </span>
        <span style={{ color: "#4b5563", fontSize: 9.5, fontWeight: 600, letterSpacing: "0.08em", textTransform: "uppercase", textAlign: "right", marginRight: 32 }}>
          Importe
        </span>
        <span style={{ color: "#4b5563", fontSize: 9.5, fontWeight: 600, letterSpacing: "0.08em", textTransform: "uppercase", textAlign: "right" }}>
          Participación
        </span>
      </div>

      {/* Method rows */}
      <div>
        {METHODS.map((m, i) => (
          <motion.div
            key={m.id}
            initial={{ opacity: 0, x: -8 }}
            animate={{ opacity: 1, x: 0 }}
            transition={{ duration: 0.28, delay: 0.18 + i * 0.07 }}
            className="group flex items-center gap-4 px-5 py-3.5 transition-colors"
            style={{
              borderBottom: i < METHODS.length - 1 ? "1px solid #252830" : undefined,
            }}
            onMouseEnter={(e) => (e.currentTarget.style.background = "#252830")}
            onMouseLeave={(e) => (e.currentTarget.style.background = "transparent")}
          >
            {/* Icon + name */}
            <div className="flex items-center gap-3 flex-1 min-w-0">
              <div
                className="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
                style={{ background: m.bgColor, color: m.color }}
              >
                {m.icon}
              </div>
              <div>
                <div style={{ color: "#d1d5db", fontSize: 13, fontWeight: 500 }}>
                  {m.label}
                </div>
                <div style={{ color: "#6b7280", fontSize: 10 }}>{m.sub}</div>
              </div>
            </div>

            {/* Txns */}
            <div
              className="w-10 text-right"
              style={{
                color: "#9ca3af",
                fontSize: 12,
                fontVariantNumeric: "tabular-nums",
                fontWeight: 500,
              }}
            >
              {m.txns}
            </div>

            {/* Amount */}
            <div
              className="w-24 text-right"
              style={{
                color: "#f1f5f9",
                fontSize: 13,
                fontVariantNumeric: "tabular-nums",
                fontWeight: 600,
              }}
            >
              ${fmt(m.amount)}
            </div>

            {/* Inline bar + pct */}
            <div className="w-32 flex items-center gap-2">
              <div
                className="flex-1 h-1 rounded-full overflow-hidden"
                style={{ background: "#252830" }}
              >
                <motion.div
                  initial={{ width: 0 }}
                  animate={{ width: `${m.pct}%` }}
                  transition={{
                    duration: 0.65,
                    delay: 0.3 + i * 0.07,
                    ease: "easeOut",
                  }}
                  className="h-full rounded-full"
                  style={{ background: m.color }}
                />
              </div>
              <span
                style={{
                  color: m.color,
                  fontSize: 11,
                  fontVariantNumeric: "tabular-nums",
                  fontWeight: 600,
                  minWidth: 36,
                  textAlign: "right",
                }}
              >
                {m.pct}%
              </span>
            </div>
          </motion.div>
        ))}
      </div>

      {/* Totals row */}
      <div
        className="flex items-center px-5 py-3 border-t border-[#2e3039]"
        style={{ background: "rgba(37,40,48,0.4)" }}
      >
        <div className="flex items-center gap-3 flex-1">
          <div className="w-8 flex-shrink-0" />
          <span
            style={{
              color: "#9ca3af",
              fontSize: 11,
              fontWeight: 600,
              letterSpacing: "0.06em",
              textTransform: "uppercase",
            }}
          >
            Total
          </span>
        </div>
        <div className="w-10 text-right" style={{ color: "#9ca3af", fontSize: 12, fontVariantNumeric: "tabular-nums" }}>
          {totalTxns}
        </div>
        <div className="w-24 text-right" style={{ color: "#22c55e", fontSize: 13, fontVariantNumeric: "tabular-nums", fontWeight: 700 }}>
          ${fmt(total)}
        </div>
        <div className="w-32 text-right pr-0.5" style={{ color: "#9ca3af", fontSize: 11 }}>
          100.0%
        </div>
      </div>

      {/* Stacked bar */}
      <div className="px-5 pb-4">
        <StackedBar methods={METHODS} />
      </div>
    </motion.div>
  );
}
