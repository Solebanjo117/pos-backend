import { motion } from "motion/react";
import {
  Wallet,
  ArrowUp,
  ArrowDown,
  TrendingUp,
  TrendingDown,
  CheckCircle2,
  AlertTriangle,
} from "lucide-react";
import { useCountUp, fmt } from "./utils";

type ReconciliationStatus = "balanced" | "discrepancy" | "warning";

const STATUS: ReconciliationStatus = "balanced";
const PHYSICAL_COUNT = 3480;
const EXPECTED = 3480;

const STATUS_CONFIG = {
  balanced: {
    bg: "rgba(5,46,22,0.55)",
    border: "#15803d",
    text: "#4ade80",
    label: "CAJA CUADRA",
    Icon: CheckCircle2,
  },
  discrepancy: {
    bg: "rgba(69,10,10,0.55)",
    border: "#991b1b",
    text: "#f87171",
    label: "DISCREPANCIA",
    Icon: AlertTriangle,
  },
  warning: {
    bg: "rgba(67,20,7,0.55)",
    border: "#9a3412",
    text: "#fb923c",
    label: "ADVERTENCIA",
    Icon: AlertTriangle,
  },
};

interface StatCol {
  label: string;
  display: () => string;
  description: string;
  meta: string;
  trendDir: "up_good" | "up_bad" | "none";
  trendLabel: string;
  accentColor: string;
  textColor: string;
}

export function CashDrawer() {
  const apertura = useCountUp(200, 950, 100);
  const ingresos = useCountUp(3450, 950, 200);
  const devoluciones = useCountUp(120, 950, 300);
  const total = useCountUp(3480, 950, 400);
  const ingresoExtra = useCountUp(50, 850, 250);
  const retiro = useCountUp(100, 850, 350);

  const diff = PHYSICAL_COUNT - EXPECTED;
  const sc = STATUS_CONFIG[STATUS];
  const { Icon } = sc;

  const cols: StatCol[] = [
    {
      label: "FONDO DE APERTURA",
      display: () => `$${fmt(apertura)}`,
      description: "Capital inicial del turno",
      meta: "Apertura: 08:00 AM",
      trendDir: "none",
      trendLabel: "Sin variación",
      accentColor: "#6b7280",
      textColor: "#d1d5db",
    },
    {
      label: "INGRESOS EFECTIVO",
      display: () => `+$${fmt(ingresos)}`,
      description: "12 ventas en efectivo",
      meta: "Último: 21:58",
      trendDir: "up_good",
      trendLabel: "+$350 vs ayer",
      accentColor: "#22c55e",
      textColor: "#22c55e",
    },
    {
      label: "DEVOLUCIONES",
      display: () => `−$${fmt(devoluciones)}`,
      description: "1 devolución procesada",
      meta: "Ref: FACT-221890",
      trendDir: "up_bad",
      trendLabel: "+$20 vs ayer",
      accentColor: "#ef4444",
      textColor: "#ef4444",
    },
    {
      label: "TOTAL ESPERADO",
      display: () => `$${fmt(total)}`,
      description: "Efectivo en caja esperado",
      meta: diff === 0 ? "Sin discrepancia" : `Δ $${fmt(Math.abs(diff))}`,
      trendDir: "none",
      trendLabel: diff === 0 ? "✓ Cuadrado" : "⚠ Diferencia",
      accentColor: "#6366f1",
      textColor: "#818cf8",
    },
  ];

  const equationTerms = [
    { v: "$200.00", c: "#9ca3af", bold: false },
    { v: "+", c: "#4b5563", bold: false },
    { v: "$3,450.00", c: "#22c55e", bold: false },
    { v: "−", c: "#4b5563", bold: false },
    { v: "$120.00", c: "#ef4444", bold: false },
    { v: "+", c: "#4b5563", bold: false },
    { v: "$50.00", c: "#22c55e", bold: false },
    { v: "−", c: "#4b5563", bold: false },
    { v: "$100.00", c: "#f97316", bold: false },
    { v: "=", c: "#4b5563", bold: false },
    { v: "$3,480.00", c: "#818cf8", bold: true },
  ];

  return (
    <motion.div
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.38, ease: "easeOut" }}
      className="rounded-xl border border-[#2e3039] overflow-hidden"
      style={{ background: "#1a1d27" }}
    >
      {/* ─ Header ─ */}
      <div className="flex items-center justify-between px-6 py-4 border-b border-[#2e3039]">
        <div className="flex items-center gap-3">
          <div
            className="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0"
            style={{ background: "rgba(34,197,94,0.1)" }}
          >
            <Wallet size={14} style={{ color: "#22c55e" }} />
          </div>
          <div>
            <div className="flex items-center gap-2">
              <span
                style={{
                  color: "#e2e8f0",
                  fontWeight: 600,
                  fontSize: 12,
                  letterSpacing: "0.07em",
                }}
              >
                ARQUEO DE EFECTIVO
              </span>
              <span
                style={{
                  color: "#6b7280",
                  fontSize: 10,
                  background: "#252830",
                  padding: "2px 7px",
                  borderRadius: 4,
                  border: "1px solid #374151",
                  letterSpacing: "0.03em",
                }}
              >
                Z-00042
              </span>
            </div>
            <div style={{ color: "#6b7280", fontSize: 11, marginTop: 2 }}>
              26 de mayo, 2026 &middot; Juan Martínez &middot; Sucursal Central
              &middot; 22:34
            </div>
          </div>
        </div>

        <motion.div
          initial={{ opacity: 0, scale: 0.82 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{
            duration: 0.4,
            delay: 1.0,
            type: "spring",
            stiffness: 180,
            damping: 14,
          }}
          className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg"
          style={{
            background: sc.bg,
            border: `1px solid ${sc.border}`,
            color: sc.text,
            fontSize: 11,
            fontWeight: 700,
            letterSpacing: "0.07em",
          }}
        >
          <Icon size={11} />
          {sc.label}
        </motion.div>
      </div>

      {/* ─ Stat columns ─ */}
      <div
        className="grid grid-cols-4"
        style={{
          borderBottom: "1px solid #252830",
        }}
      >
        {cols.map((col, i) => (
          <motion.div
            key={col.label}
            initial={{ opacity: 0, y: 6 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.3, delay: 0.08 + i * 0.07 }}
            className="px-5 py-5"
            style={{
              borderRight: i < 3 ? "1px solid #252830" : undefined,
            }}
          >
            <div className="flex items-center gap-1.5 mb-3">
              <div
                style={{
                  width: 2,
                  height: 11,
                  borderRadius: 1,
                  background: col.accentColor,
                  flexShrink: 0,
                }}
              />
              <span
                style={{
                  color: "#6b7280",
                  fontSize: 9.5,
                  fontWeight: 600,
                  letterSpacing: "0.09em",
                  textTransform: "uppercase",
                }}
              >
                {col.label}
              </span>
            </div>

            <div
              style={{
                color: col.textColor,
                fontWeight: 700,
                fontSize: 20,
                letterSpacing: "-0.02em",
                fontVariantNumeric: "tabular-nums",
                lineHeight: 1,
                marginBottom: 5,
              }}
            >
              {col.display()}
            </div>

            <div style={{ color: "#6b7280", fontSize: 11, marginBottom: 8 }}>
              {col.description}
            </div>

            <div className="flex items-center justify-between">
              <span style={{ color: "#4b5563", fontSize: 10 }}>{col.meta}</span>
              {col.trendDir === "up_good" && (
                <span
                  className="flex items-center gap-0.5"
                  style={{ fontSize: 10, color: "#22c55e" }}
                >
                  <TrendingUp size={9} />
                  {col.trendLabel}
                </span>
              )}
              {col.trendDir === "up_bad" && (
                <span
                  className="flex items-center gap-0.5"
                  style={{ fontSize: 10, color: "#f97316" }}
                >
                  <TrendingUp size={9} />
                  {col.trendLabel}
                </span>
              )}
              {col.trendDir === "none" && (
                <span
                  style={{ fontSize: 10, color: col.accentColor }}
                >
                  {col.trendLabel}
                </span>
              )}
            </div>
          </motion.div>
        ))}
      </div>

      {/* ─ Extra movements ─ */}
      <div
        className="px-5 py-3 flex items-center gap-4 flex-wrap border-b border-[#252830]"
        style={{ background: "rgba(37,40,48,0.45)" }}
      >
        <span
          style={{
            color: "#4b5563",
            fontSize: 9.5,
            fontWeight: 600,
            letterSpacing: "0.09em",
            textTransform: "uppercase",
          }}
        >
          Movimientos de Caja
        </span>

        <motion.div
          initial={{ opacity: 0, x: -6 }}
          animate={{ opacity: 1, x: 0 }}
          transition={{ duration: 0.25, delay: 0.55 }}
          className="flex items-center gap-1.5 px-2.5 py-1 rounded-md"
          style={{
            background: "rgba(34,197,94,0.08)",
            border: "1px solid rgba(34,197,94,0.15)",
          }}
        >
          <ArrowUp size={9} style={{ color: "#22c55e" }} />
          <span
            style={{
              color: "#22c55e",
              fontSize: 11,
              fontVariantNumeric: "tabular-nums",
              fontWeight: 600,
            }}
          >
            +${fmt(ingresoExtra)}
          </span>
          <span style={{ color: "#6b7280", fontSize: 11 }}>Ingreso extra</span>
        </motion.div>

        <motion.div
          initial={{ opacity: 0, x: -6 }}
          animate={{ opacity: 1, x: 0 }}
          transition={{ duration: 0.25, delay: 0.65 }}
          className="flex items-center gap-1.5 px-2.5 py-1 rounded-md"
          style={{
            background: "rgba(249,115,22,0.08)",
            border: "1px solid rgba(249,115,22,0.15)",
          }}
        >
          <ArrowDown size={9} style={{ color: "#f97316" }} />
          <span
            style={{
              color: "#f97316",
              fontSize: 11,
              fontVariantNumeric: "tabular-nums",
              fontWeight: 600,
            }}
          >
            −${fmt(retiro)}
          </span>
          <span style={{ color: "#6b7280", fontSize: 11 }}>Retiro autorizado</span>
        </motion.div>
      </div>

      {/* ─ Reconciliation equation ─ */}
      <div className="px-5 py-4">
        <div className="flex items-start justify-between gap-6">
          <div>
            <div
              style={{
                color: "#4b5563",
                fontSize: 9.5,
                fontWeight: 600,
                letterSpacing: "0.09em",
                textTransform: "uppercase",
                marginBottom: 8,
              }}
            >
              Verificación de Balance
            </div>
            <div className="flex items-center gap-1.5 flex-wrap">
              {equationTerms.map((t, i) => (
                <motion.span
                  key={i}
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                  transition={{ duration: 0.12, delay: 0.85 + i * 0.05 }}
                  style={{
                    color: t.c,
                    fontWeight: t.bold ? 700 : 400,
                    fontSize: 12,
                    fontVariantNumeric: "tabular-nums",
                    lineHeight: 1,
                  }}
                >
                  {t.v}
                </motion.span>
              ))}
            </div>
          </div>

          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ delay: 1.5, duration: 0.3 }}
            className="flex-shrink-0"
          >
            <div
              className="flex items-center justify-between gap-4 mb-1.5 pb-1.5"
              style={{ borderBottom: "1px solid #252830" }}
            >
              <span style={{ color: "#6b7280", fontSize: 11 }}>
                Conteo físico
              </span>
              <span
                style={{
                  color: "#e2e8f0",
                  fontSize: 12,
                  fontWeight: 600,
                  fontVariantNumeric: "tabular-nums",
                }}
              >
                ${fmt(PHYSICAL_COUNT)}
              </span>
            </div>
            <div className="flex items-center justify-between gap-4">
              <span style={{ color: "#6b7280", fontSize: 11 }}>Diferencia</span>
              <span
                style={{
                  color: diff === 0 ? "#22c55e" : "#ef4444",
                  fontSize: 13,
                  fontWeight: 700,
                  fontVariantNumeric: "tabular-nums",
                }}
              >
                {diff === 0
                  ? "$0.00"
                  : `${diff > 0 ? "+" : ""}$${fmt(Math.abs(diff))}`}
              </span>
            </div>
          </motion.div>
        </div>
      </div>
    </motion.div>
  );
}
