import { useState, useEffect, useRef, useCallback } from "react";
import { motion, AnimatePresence } from "motion/react";
import { toast } from "sonner";
import {
  FileText,
  Sheet,
  Printer,
  ChevronDown,
  ChevronRight,
  TrendingUp,
  TrendingDown,
  ShoppingCart,
  DollarSign,
  BarChart3,
  RefreshCw,
  ArrowUpRight,
  X,
  Loader2,
  CheckCircle2,
  GitCompare,
  ChevronUp,
  ChevronsUpDown,
} from "lucide-react";
import { AccountingTable } from "./income/AccountingTable";
import { ProfitabilitySection } from "./income/ProfitabilitySection";
import { PaymentBreakdown } from "./income/PaymentBreakdown";

// ── Shimmer keyframes injected once ───────────────────────────────────────
const SHIMMER_STYLE = `
  @keyframes shimmer {
    0%   { background-position: 200% 0; }
    100% { background-position: -200% 0; }
  }
  .shimmer {
    background: linear-gradient(90deg, #171a26 25%, #1e2130 50%, #171a26 75%);
    background-size: 200% 100%;
    animation: shimmer 1.4s infinite;
    border-radius: 4px;
  }
`;

// ── Helpers ────────────────────────────────────────────────────────────────

function fmtAmt(v: number) {
  return "$" + Math.abs(v).toLocaleString("en-US", { minimumFractionDigits: 2 });
}

// ── Date presets ──────────────────────────────────────────────────────────

const TODAY = "2026-05-27";
type Preset = "Hoy" | "Semana" | "Mes" | "Trimestre" | "Año" | "Personalizado";

const PRESETS: Preset[] = ["Hoy", "Semana", "Mes", "Trimestre", "Año", "Personalizado"];

function presetDates(p: Preset): [string, string] {
  switch (p) {
    case "Hoy":       return ["2026-05-27", "2026-05-27"];
    case "Semana":    return ["2026-05-20", "2026-05-27"];
    case "Mes":       return ["2026-05-01", "2026-05-27"];
    case "Trimestre": return ["2026-04-01", "2026-05-27"];
    case "Año":       return ["2026-01-01", "2026-05-27"];
    default:          return ["2026-05-01", "2026-05-27"];
  }
}

function isFuture(from: string, to: string) {
  return from > TODAY && to > TODAY;
}

// ── Count-up hook ─────────────────────────────────────────────────────────

function useCountUp(target: number, trigger: string, delay = 0) {
  const [val, setVal] = useState(0);
  const rafRef = useRef<number>();
  useEffect(() => {
    setVal(0);
    let startTime: number | null = null;
    const startAt = performance.now() + delay;
    const duration = 1200;
    const step = (ts: number) => {
      if (ts < startAt) { rafRef.current = requestAnimationFrame(step); return; }
      if (!startTime) startTime = ts;
      const pct = Math.min((ts - startTime) / duration, 1);
      const eased = 1 - Math.pow(1 - pct, 3);
      setVal(Math.round(target * eased));
      if (pct < 1) rafRef.current = requestAnimationFrame(step);
      else setVal(target);
    };
    rafRef.current = requestAnimationFrame(step);
    return () => { if (rafRef.current) cancelAnimationFrame(rafRef.current); };
  }, [target, trigger, delay]);
  return val;
}

// ── Skeleton card ─────────────────────────────────────────────────────────

function SkeletonCard() {
  return (
    <div style={{ background: "#0f1117", border: "1px solid #1e2130", borderRadius: 10, padding: "16px 18px" }}>
      <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 14 }}>
        <div className="shimmer" style={{ width: 32, height: 32, borderRadius: 8 }} />
        <div className="shimmer" style={{ width: 56, height: 20 }} />
      </div>
      <div className="shimmer" style={{ width: 80, height: 11, marginBottom: 8 }} />
      <div className="shimmer" style={{ width: 130, height: 22 }} />
    </div>
  );
}

// ── Summary card ──────────────────────────────────────────────────────────

interface SummaryCardProps {
  icon: React.ElementType;
  label: string;
  target: number;
  prevTarget?: number;
  change: string;
  changeUp: boolean;
  accent: string;
  delay?: number;
  filterKey: string;
  comparisonMode: boolean;
}

function SummaryCard({
  icon: Icon, label, target, prevTarget = 0,
  change, changeUp, accent, delay = 0, filterKey, comparisonMode,
}: SummaryCardProps) {
  const val = useCountUp(target, filterKey, delay);
  const prevVal = useCountUp(prevTarget, filterKey, delay + 200);

  return (
    <motion.div
      whileHover={{ y: -2, transition: { duration: 0.15 } }}
      style={{
        background: "#0f1117",
        border: "1px solid #1e2130",
        borderRadius: 10,
        padding: "16px 18px",
        cursor: "default",
        position: "relative",
        overflow: "hidden",
      }}
    >
      <div style={{ position: "absolute", top: 0, left: 0, right: 0, height: 2, background: accent, opacity: 0.45, borderRadius: "10px 10px 0 0" }} />
      <div style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", marginBottom: 12 }}>
        <div style={{ width: 32, height: 32, borderRadius: 8, background: `${accent}16`, display: "flex", alignItems: "center", justifyContent: "center" }}>
          <Icon size={15} style={{ color: accent }} />
        </div>
        <span style={{
          display: "flex", alignItems: "center", gap: 3, padding: "2px 7px", borderRadius: 4,
          background: changeUp ? "rgba(52,211,153,0.1)" : "rgba(248,113,113,0.1)",
          color: changeUp ? "#34d399" : "#f87171", fontSize: 11, fontWeight: 500,
        }}>
          {changeUp ? <TrendingUp size={11} /> : <TrendingDown size={11} />}
          {change}
        </span>
      </div>
      <p style={{ color: "#475569", fontSize: 11.5, marginBottom: 4, fontFamily: "inherit" }}>{label}</p>
      <p style={{ color: "#e2e8f0", fontSize: 19, fontWeight: 600, fontVariantNumeric: "tabular-nums", letterSpacing: "-0.02em" }}>
        {fmtAmt(val)}
      </p>
      <AnimatePresence>
        {comparisonMode && (
          <motion.div
            initial={{ opacity: 0, height: 0 }}
            animate={{ opacity: 1, height: "auto" }}
            exit={{ opacity: 0, height: 0 }}
            style={{ marginTop: 6, overflow: "hidden" }}
          >
            <p style={{ color: "#334155", fontSize: 11, fontVariantNumeric: "tabular-nums" }}>
              Período ant.: {fmtAmt(prevVal)}
            </p>
          </motion.div>
        )}
      </AnimatePresence>
    </motion.div>
  );
}

// ── Export progress overlay ───────────────────────────────────────────────

function ExportOverlay({ type, onDone }: { type: "pdf" | "excel"; onDone: () => void }) {
  const [pct, setPct] = useState(0);
  const label = type === "pdf" ? "PDF" : "Excel";
  const icon = type === "pdf" ? "📄" : "📊";

  useEffect(() => {
    const start = Date.now();
    const id = setInterval(() => {
      const elapsed = Date.now() - start;
      const p = Math.min((elapsed / 2000) * 100, 100);
      setPct(p);
      if (p >= 100) {
        clearInterval(id);
        setTimeout(() => {
          onDone();
          toast.success(`${icon} ${label} listo`, {
            description: "El archivo ha sido generado y está listo para descargar.",
          });
        }, 300);
      }
    }, 30);
    return () => clearInterval(id);
  }, []);

  return (
    <motion.div
      initial={{ opacity: 0 }}
      animate={{ opacity: 1 }}
      exit={{ opacity: 0 }}
      style={{
        position: "fixed", inset: 0, background: "rgba(0,0,0,0.55)",
        display: "flex", alignItems: "center", justifyContent: "center", zIndex: 100,
      }}
    >
      <motion.div
        initial={{ scale: 0.92, opacity: 0 }}
        animate={{ scale: 1, opacity: 1 }}
        exit={{ scale: 0.95, opacity: 0 }}
        style={{
          background: "#171a26", border: "1px solid #252830", borderRadius: 14,
          padding: "28px 32px", width: 380, fontFamily: "inherit",
        }}
      >
        <div style={{ display: "flex", alignItems: "center", gap: 12, marginBottom: 20 }}>
          <Loader2 size={18} style={{ color: "#6366f1", animation: "spin 1s linear infinite" }} />
          <span style={{ color: "#e2e8f0", fontSize: 14, fontWeight: 600 }}>
            Generando {label}…
          </span>
        </div>
        <div style={{ height: 5, background: "#1e2130", borderRadius: 3, overflow: "hidden" }}>
          <motion.div
            animate={{ width: `${pct}%` }}
            transition={{ ease: "linear" }}
            style={{ height: "100%", background: type === "pdf" ? "#f87171" : "#34d399", borderRadius: 3 }}
          />
        </div>
        <p style={{ color: "#334155", fontSize: 12, marginTop: 10, textAlign: "right" }}>
          {Math.round(pct)}%
        </p>
      </motion.div>
      <style>{`@keyframes spin { to { transform: rotate(360deg); } }`}</style>
    </motion.div>
  );
}

// ── Print preview modal ────────────────────────────────────────────────────

function PrintModal({ fromDate, toDate, onClose }: { fromDate: string; toDate: string; onClose: () => void }) {
  const rows = [
    ["Ventas Brutas", "$48,350.00", "100.0%"],
    ["(-) Devoluciones", "-$1,250.00", "-2.6%"],
    ["(-) Descuentos", "-$680.00", "-1.4%"],
    ["Ventas Netas", "$46,420.00", "96.0%"],
    ["(-) Costo de Mercancía", "-$29,630.00", "-61.3%"],
    ["(-) Mano de Obra", "-$2,840.00", "-5.9%"],
    ["Utilidad Bruta", "$13,950.00", "28.8%"],
    ["(-) Gastos Operativos", "-$5,240.00", "-10.8%"],
    ["Utilidad Operativa", "$8,710.00", "18.0%"],
    ["Resultado Financiero", "-$180.00", "-0.4%"],
    ["Utilidad Antes de Imptos.", "$8,530.00", "17.6%"],
    ["(-) Impuestos", "-$3,412.00", "-7.1%"],
    ["UTILIDAD NETA", "$5,118.00", "10.6%"],
  ];
  const totals = new Set(["Ventas Netas", "Utilidad Bruta", "Utilidad Operativa", "Utilidad Antes de Imptos.", "UTILIDAD NETA"]);

  return (
    <motion.div
      initial={{ opacity: 0 }}
      animate={{ opacity: 1 }}
      exit={{ opacity: 0 }}
      style={{
        position: "fixed", inset: 0, background: "rgba(0,0,0,0.65)",
        display: "flex", alignItems: "center", justifyContent: "center", zIndex: 100,
      }}
      onClick={onClose}
    >
      <motion.div
        initial={{ scale: 0.9, opacity: 0 }}
        animate={{ scale: 1, opacity: 1 }}
        exit={{ scale: 0.95, opacity: 0 }}
        transition={{ duration: 0.25, ease: "easeOut" }}
        onClick={(e) => e.stopPropagation()}
        style={{
          width: 560, maxHeight: "86vh", background: "#fff", borderRadius: 12,
          overflow: "hidden", display: "flex", flexDirection: "column", fontFamily: "inherit",
        }}
      >
        {/* Print header bar */}
        <div style={{ background: "#1a1d27", padding: "12px 20px", display: "flex", alignItems: "center", justifyContent: "space-between" }}>
          <span style={{ color: "#e2e8f0", fontSize: 13, fontWeight: 600 }}>Vista Previa de Impresión</span>
          <button onClick={onClose} style={{ background: "none", border: "none", color: "#475569", cursor: "pointer", padding: 4 }}>
            <X size={16} />
          </button>
        </div>

        {/* A4 page */}
        <div style={{ flex: 1, overflowY: "auto", padding: "24px", background: "#f4f4f5" }}>
          <div style={{
            background: "#fff", maxWidth: 480, margin: "0 auto", padding: "40px 44px",
            boxShadow: "0 2px 16px rgba(0,0,0,0.12)", borderRadius: 2,
          }}>
            <div style={{ textAlign: "center", marginBottom: 28 }}>
              <p style={{ color: "#111", fontSize: 15, fontWeight: 700, margin: 0 }}>HermeX POS — Estado de Resultados</p>
              <p style={{ color: "#666", fontSize: 12, marginTop: 4 }}>Período: {fromDate} al {toDate}</p>
            </div>
            <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 12 }}>
              <thead>
                <tr style={{ borderBottom: "2px solid #e5e7eb" }}>
                  <th style={{ textAlign: "left", padding: "6px 0", color: "#374151", fontWeight: 700, fontSize: 11, letterSpacing: "0.06em", textTransform: "uppercase" }}>Concepto</th>
                  <th style={{ textAlign: "right", padding: "6px 0", color: "#374151", fontWeight: 700, fontSize: 11, letterSpacing: "0.06em", textTransform: "uppercase" }}>Monto</th>
                  <th style={{ textAlign: "right", padding: "6px 0", color: "#374151", fontWeight: 700, fontSize: 11, letterSpacing: "0.06em", textTransform: "uppercase" }}>%</th>
                </tr>
              </thead>
              <tbody>
                {rows.map(([label, amount, pct]) => {
                  const isTotal = totals.has(label);
                  const isGrand = label === "UTILIDAD NETA";
                  const isNeg = amount.startsWith("-");
                  return (
                    <tr key={label} style={{ borderTop: isTotal ? "1px solid #e5e7eb" : "none", background: isGrand ? "#f0fdf4" : "transparent" }}>
                      <td style={{ padding: "6px 0", paddingLeft: isTotal ? 0 : 16, color: isGrand ? "#059669" : isTotal ? "#111" : "#4b5563", fontWeight: isTotal ? 600 : 400 }}>
                        {label}
                      </td>
                      <td style={{ padding: "6px 0", textAlign: "right", color: isGrand ? "#059669" : isNeg ? "#dc2626" : "#111", fontWeight: isTotal ? 600 : 400, fontVariantNumeric: "tabular-nums" }}>
                        {amount}
                      </td>
                      <td style={{ padding: "6px 0", textAlign: "right", color: "#9ca3af", fontVariantNumeric: "tabular-nums" }}>
                        {pct}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
            <p style={{ color: "#9ca3af", fontSize: 10, marginTop: 32, textAlign: "center", borderTop: "1px solid #f3f4f6", paddingTop: 16 }}>
              Cifras en USD · Generado el {TODAY} · HermeX POS v2.4.1
            </p>
          </div>
        </div>

        {/* Footer buttons */}
        <div style={{ padding: "14px 20px", borderTop: "1px solid #e5e7eb", background: "#fafafa", display: "flex", justifyContent: "flex-end", gap: 10 }}>
          <button onClick={onClose} style={{ padding: "7px 16px", borderRadius: 7, border: "1px solid #e5e7eb", background: "#fff", color: "#374151", fontSize: 13, cursor: "pointer", fontFamily: "inherit" }}>
            Cerrar
          </button>
          <button
            onClick={() => { onClose(); toast.success("🖨️ Enviado a impresora"); }}
            style={{ padding: "7px 16px", borderRadius: 7, border: "none", background: "#4f46e5", color: "#fff", fontSize: 13, fontWeight: 500, cursor: "pointer", fontFamily: "inherit" }}
          >
            Imprimir
          </button>
        </div>
      </motion.div>
    </motion.div>
  );
}

// ── Section block with collapse ────────────────────────────────────────────

function SectionBlock({
  id, label, summary, collapsed, onToggle, children,
}: {
  id: string; label: string; summary?: string;
  collapsed: boolean; onToggle: () => void; children: React.ReactNode;
}) {
  return (
    <div style={{ marginBottom: 32 }}>
      <button
        onClick={onToggle}
        style={{
          display: "flex", alignItems: "center", gap: 10, width: "100%",
          background: "none", border: "none", cursor: "pointer", marginBottom: 14, padding: 0,
        }}
      >
        <span style={{ color: "#334155", fontSize: 10, fontWeight: 700, letterSpacing: "0.12em", textTransform: "uppercase", fontFamily: "inherit" }}>
          {label}
        </span>
        <div style={{ flex: 1, height: 1, background: "#1e2130" }} />
        {collapsed && summary && (
          <span style={{ color: "#475569", fontSize: 12, fontFamily: "inherit", fontVariantNumeric: "tabular-nums" }}>
            {summary}
          </span>
        )}
        <motion.div animate={{ rotate: collapsed ? 0 : 180 }} transition={{ duration: 0.2 }}>
          <ChevronDown size={13} style={{ color: "#334155" }} />
        </motion.div>
      </button>
      <AnimatePresence initial={false}>
        {!collapsed && (
          <motion.div
            initial={{ opacity: 0, height: 0, overflow: "hidden" }}
            animate={{ opacity: 1, height: "auto", overflow: "visible" }}
            exit={{ opacity: 0, height: 0, overflow: "hidden" }}
            transition={{ duration: 0.25, ease: "easeInOut" }}
          >
            {children}
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}

// ── Sidebar ────────────────────────────────────────────────────────────────

const navItems = [
  { icon: BarChart3, label: "Dashboard" },
  { icon: ShoppingCart, label: "Ventas" },
  { icon: RefreshCw, label: "Devoluciones" },
  { icon: DollarSign, label: "Estado de Resultados", active: true },
  { icon: FileText, label: "Reportes" },
];

function Sidebar() {
  return (
    <aside style={{
      width: 240, minWidth: 240, background: "#0a0c14", borderRight: "1px solid #1e2130",
      height: "100vh", position: "fixed", top: 0, left: 0, display: "flex", flexDirection: "column",
    }}>
      <div style={{ padding: "18px 18px 16px", borderBottom: "1px solid #1e2130" }}>
        <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
          <div style={{ background: "linear-gradient(135deg,#6366f1,#8b5cf6)", width: 32, height: 32, borderRadius: 9, display: "flex", alignItems: "center", justifyContent: "center" }}>
            <span style={{ color: "#fff", fontWeight: 700, fontSize: 14 }}>H</span>
          </div>
          <div>
            <p style={{ color: "#e2e8f0", fontWeight: 600, fontSize: 13.5, lineHeight: 1.2 }}>HermeX POS</p>
            <p style={{ color: "#334155", fontSize: 11 }}>Módulo Finanzas</p>
          </div>
        </div>
      </div>
      <div style={{ padding: "14px 18px 6px" }}>
        <span style={{ color: "#334155", fontSize: 10, fontWeight: 700, letterSpacing: "0.1em", textTransform: "uppercase" }}>Principal</span>
      </div>
      <nav style={{ flex: 1, padding: "0 10px", overflowY: "auto" }}>
        {navItems.map((item) => (
          <div key={item.label} style={{
            display: "flex", alignItems: "center", gap: 9, padding: "8px 10px",
            borderRadius: 7, marginBottom: 1, cursor: "pointer",
            background: item.active ? "rgba(99,102,241,0.12)" : "transparent",
            color: item.active ? "#818cf8" : "#475569", fontSize: 12.5, fontWeight: item.active ? 500 : 400,
            borderLeft: item.active ? "2px solid #6366f1" : "2px solid transparent",
          }}>
            <item.icon size={14} />
            {item.label}
          </div>
        ))}
      </nav>
      <div style={{ padding: "12px 14px", borderTop: "1px solid #1e2130", display: "flex", alignItems: "center", gap: 9 }}>
        <div style={{ width: 30, height: 30, borderRadius: "50%", background: "linear-gradient(135deg,#6366f1,#8b5cf6)", display: "flex", alignItems: "center", justifyContent: "center", color: "#fff", fontSize: 11, fontWeight: 700, flexShrink: 0 }}>
          JG
        </div>
        <div>
          <p style={{ color: "#94a3b8", fontSize: 12, fontWeight: 500 }}>José García</p>
          <p style={{ color: "#334155", fontSize: 11 }}>Administrador</p>
        </div>
      </div>
    </aside>
  );
}

// ── Main Page ──────────────────────────────────────────────────────────────

export function IncomeStatement() {
  // Filter state
  const [activePreset, setActivePreset] = useState<Preset>("Mes");
  const [fromDate, setFromDate] = useState("2026-05-01");
  const [toDate, setToDate] = useState("2026-05-27");
  const [showCustomDates, setShowCustomDates] = useState(false);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [filterKey, setFilterKey] = useState("init");
  const [isEmpty, setIsEmpty] = useState(false);

  // UI state
  const [comparisonMode, setComparisonMode] = useState(false);
  const [exportLoading, setExportLoading] = useState<"pdf" | "excel" | null>(null);
  const [showPrintModal, setShowPrintModal] = useState(false);
  const [showStickyBar, setShowStickyBar] = useState(false);
  const [scrollPct, setScrollPct] = useState(0);
  const [collapsed, setCollapsed] = useState<Record<string, boolean>>({});

  // Refs
  const kpiRef = useRef<HTMLDivElement>(null);
  const contentRef = useRef<HTMLDivElement>(null);

  // Scroll progress
  useEffect(() => {
    const el = contentRef.current;
    if (!el) return;
    const handler = () => {
      const pct = (el.scrollTop / (el.scrollHeight - el.clientHeight)) * 100;
      setScrollPct(isNaN(pct) ? 0 : pct);
    };
    el.addEventListener("scroll", handler, { passive: true });
    return () => el.removeEventListener("scroll", handler);
  }, []);

  // Sticky bar — fires when KPI section exits viewport
  useEffect(() => {
    const el = kpiRef.current;
    if (!el) return;
    const observer = new IntersectionObserver(
      ([entry]) => setShowStickyBar(!entry.isIntersecting),
      { root: contentRef.current, rootMargin: "-52px 0px 0px 0px" }
    );
    observer.observe(el);
    return () => observer.disconnect();
  }, []);

  const selectPreset = useCallback((p: Preset) => {
    setActivePreset(p);
    if (p === "Personalizado") {
      setShowCustomDates(true);
    } else {
      setShowCustomDates(false);
      const [f, t] = presetDates(p);
      setFromDate(f);
      setToDate(t);
    }
  }, []);

  const applyFilters = useCallback(async () => {
    const empty = activePreset === "Personalizado" && isFuture(fromDate, toDate);
    setIsRefreshing(true);
    await new Promise((r) => setTimeout(r, 800));
    setIsRefreshing(false);
    setIsEmpty(empty);
    setFilterKey(`${activePreset}-${fromDate}-${toDate}-${Date.now()}`);
  }, [activePreset, fromDate, toDate]);

  const handleExport = useCallback(async (type: "pdf" | "excel" | "print") => {
    if (type === "print") { setShowPrintModal(true); return; }
    setExportLoading(type);
  }, []);

  function toggleCollapse(id: string) {
    setCollapsed((prev) => ({ ...prev, [id]: !prev[id] }));
  }

  return (
    <div style={{ display: "flex", background: "#0a0c14", height: "100vh", fontFamily: "Inter, -apple-system, BlinkMacSystemFont, sans-serif" }}>
      <style>{SHIMMER_STYLE}</style>
      <Sidebar />

      {/* Content column */}
      <div style={{ marginLeft: 240, flex: 1, display: "flex", flexDirection: "column", minWidth: 0, position: "relative" }}>

        {/* Scroll progress bar */}
        <div style={{ position: "absolute", top: 0, left: 0, right: 0, height: 2, background: "#1e2130", zIndex: 30 }}>
          <motion.div
            animate={{ width: `${scrollPct}%` }}
            transition={{ ease: "linear", duration: 0.1 }}
            style={{ height: "100%", background: "linear-gradient(90deg,#6366f1,#8b5cf6)" }}
          />
        </div>

        {/* Top bar */}
        <header style={{
          height: 52, background: "#0a0c14", borderBottom: "1px solid #1e2130",
          display: "flex", alignItems: "center", padding: "0 24px", gap: 6,
          position: "sticky", top: 0, zIndex: 20, flexShrink: 0,
        }}>
          <span style={{ color: "#334155", fontSize: 12.5 }}>Finanzas</span>
          <ChevronRight size={13} style={{ color: "#252830" }} />
          <span style={{ color: "#64748b", fontSize: 12.5, fontWeight: 500 }}>Estado de Resultados</span>
          <div style={{ marginLeft: "auto", display: "flex", alignItems: "center", gap: 10 }}>
            {/* Comparison toggle */}
            <button
              onClick={() => setComparisonMode((v) => !v)}
              style={{
                display: "flex", alignItems: "center", gap: 6, padding: "5px 11px",
                borderRadius: 7, border: `1px solid ${comparisonMode ? "#6366f1" : "#1e2130"}`,
                background: comparisonMode ? "rgba(99,102,241,0.12)" : "transparent",
                color: comparisonMode ? "#818cf8" : "#475569", fontSize: 11.5,
                cursor: "pointer", fontFamily: "inherit",
              }}
            >
              <GitCompare size={13} />
              vs Período Anterior
            </button>
            <div style={{ width: 6, height: 6, borderRadius: "50%", background: "#10b981" }} />
            <span style={{ color: "#334155", fontSize: 11.5 }}>En línea</span>
          </div>
        </header>

        {/* Sticky net-profit bar */}
        <AnimatePresence>
          {showStickyBar && (
            <motion.div
              initial={{ y: -48, opacity: 0 }}
              animate={{ y: 0, opacity: 1 }}
              exit={{ y: -48, opacity: 0 }}
              transition={{ duration: 0.2, ease: "easeOut" }}
              style={{
                position: "sticky", top: 52, zIndex: 19, height: 48,
                background: "#0d0f1a", borderBottom: "1px solid #1e2130",
                display: "flex", alignItems: "center", padding: "0 28px", gap: 16,
                backdropFilter: "blur(8px)",
              }}
            >
              <DollarSign size={14} style={{ color: "#34d399" }} />
              <span style={{ color: "#475569", fontSize: 12 }}>Utilidad Neta del Período</span>
              <span style={{ color: "#34d399", fontSize: 16, fontWeight: 700, fontVariantNumeric: "tabular-nums", letterSpacing: "-0.02em" }}>
                $5,118.00
              </span>
              <span style={{ padding: "2px 8px", borderRadius: 4, background: "rgba(52,211,153,0.1)", color: "#34d399", fontSize: 11, fontWeight: 500 }}>
                +18.2%
              </span>
              {comparisonMode && (
                <span style={{ color: "#334155", fontSize: 11 }}>Ant.: $4,330.00 ↑ $788.00</span>
              )}
            </motion.div>
          )}
        </AnimatePresence>

        {/* Scrollable main area */}
        <div ref={contentRef} style={{ flex: 1, overflowY: "auto" }}>
          <main style={{ padding: "24px 28px 64px" }}>

            {/* Page header */}
            <div style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", marginBottom: 18, gap: 16, flexWrap: "wrap" }}>
              <div>
                <h1 style={{ color: "#e2e8f0", fontSize: 20, fontWeight: 600, margin: 0, letterSpacing: "-0.01em" }}>
                  Estado de Resultados
                </h1>
                <p style={{ color: "#475569", fontSize: 12.5, marginTop: 5 }}>
                  {activePreset} · {fromDate} → {toDate}
                </p>
              </div>
              <div style={{ display: "flex", alignItems: "center", gap: 8, flexShrink: 0 }}>
                {(["pdf", "excel", "print"] as const).map((type) => {
                  const cfg = {
                    pdf:   { icon: FileText, label: "PDF",      color: "#f87171", bg: "rgba(248,113,113,0.07)", border: "rgba(248,113,113,0.25)" },
                    excel: { icon: Sheet,    label: "Excel",    color: "#34d399", bg: "rgba(52,211,153,0.07)",  border: "rgba(52,211,153,0.25)" },
                    print: { icon: Printer,  label: "Imprimir", color: "#64748b", bg: "rgba(255,255,255,0.03)", border: "#1e2130" },
                  }[type];
                  return (
                    <button
                      key={type}
                      onClick={() => handleExport(type)}
                      style={{
                        display: "flex", alignItems: "center", gap: 6, padding: "7px 13px",
                        borderRadius: 7, border: `1px solid ${cfg.border}`, background: cfg.bg,
                        color: cfg.color, fontSize: 12, fontWeight: 500, cursor: "pointer", fontFamily: "inherit",
                      }}
                    >
                      <cfg.icon size={13} />
                      {cfg.label}
                    </button>
                  );
                })}
              </div>
            </div>

            {/* ── Filter bar ── */}
            <div style={{
              background: "#0f1117", border: "1px solid #1e2130", borderRadius: 10,
              padding: "12px 16px", marginBottom: 20,
            }}>
              {/* Preset buttons */}
              <div style={{ display: "flex", alignItems: "center", gap: 4, flexWrap: "wrap" }}>
                <span style={{ color: "#334155", fontSize: 11, marginRight: 6 }}>Período:</span>
                {PRESETS.map((p) => {
                  const isActive = activePreset === p;
                  return (
                    <motion.button
                      key={p}
                      onClick={() => selectPreset(p)}
                      whileTap={{ scale: 0.96 }}
                      animate={{ scale: isActive ? 1.0 : 1.0 }}
                      style={{
                        padding: "5px 12px", borderRadius: 7, border: "none", cursor: "pointer",
                        background: isActive ? "rgba(99,102,241,0.18)" : "rgba(255,255,255,0.03)",
                        color: isActive ? "#818cf8" : "#475569",
                        fontSize: 12, fontWeight: isActive ? 600 : 400, fontFamily: "inherit",
                        outline: isActive ? "1px solid rgba(99,102,241,0.35)" : "1px solid transparent",
                        transition: "all 0.15s",
                      }}
                    >
                      {p}
                    </motion.button>
                  );
                })}

                <button
                  onClick={applyFilters}
                  disabled={isRefreshing}
                  style={{
                    marginLeft: "auto", padding: "6px 16px", borderRadius: 7, border: "none",
                    background: "linear-gradient(135deg,#6366f1,#8b5cf6)", color: "#fff",
                    fontSize: 12, fontWeight: 500, cursor: isRefreshing ? "not-allowed" : "pointer",
                    fontFamily: "inherit", display: "flex", alignItems: "center", gap: 6,
                    opacity: isRefreshing ? 0.75 : 1,
                  }}
                >
                  {isRefreshing ? (
                    <>
                      <Loader2 size={12} style={{ animation: "spin 0.8s linear infinite" }} />
                      Cargando…
                    </>
                  ) : "Aplicar"}
                </button>
              </div>

              {/* Custom date inputs — slide down */}
              <AnimatePresence>
                {showCustomDates && (
                  <motion.div
                    initial={{ height: 0, opacity: 0 }}
                    animate={{ height: "auto", opacity: 1 }}
                    exit={{ height: 0, opacity: 0 }}
                    transition={{ duration: 0.2, ease: "easeInOut" }}
                    style={{ overflow: "hidden" }}
                  >
                    <div style={{ display: "flex", alignItems: "center", gap: 10, paddingTop: 12, borderTop: "1px solid #1e2130", marginTop: 12 }}>
                      <span style={{ color: "#475569", fontSize: 12 }}>Del</span>
                      <input
                        type="date"
                        value={fromDate}
                        onChange={(e) => setFromDate(e.target.value)}
                        style={{ padding: "5px 9px", borderRadius: 7, border: "1px solid #1e2130", background: "#171a26", color: "#94a3b8", fontSize: 12, fontFamily: "inherit", colorScheme: "dark", outline: "none" }}
                      />
                      <span style={{ color: "#334155", fontSize: 12 }}>al</span>
                      <input
                        type="date"
                        value={toDate}
                        onChange={(e) => setToDate(e.target.value)}
                        style={{ padding: "5px 9px", borderRadius: 7, border: "1px solid #1e2130", background: "#171a26", color: "#94a3b8", fontSize: 12, fontFamily: "inherit", colorScheme: "dark", outline: "none" }}
                      />
                    </div>
                  </motion.div>
                )}
              </AnimatePresence>
            </div>

            {/* ── KPI Cards ── */}
            <div ref={kpiRef}>
              <AnimatePresence mode="wait">
                {isRefreshing ? (
                  <motion.div
                    key="skeleton"
                    initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
                    style={{ display: "grid", gridTemplateColumns: "repeat(4,1fr)", gap: 12, marginBottom: 24 }}
                  >
                    {[0,1,2,3].map((i) => <SkeletonCard key={i} />)}
                  </motion.div>
                ) : isEmpty ? null : (
                  <motion.div
                    key={filterKey}
                    initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
                    transition={{ duration: 0.3 }}
                    style={{ display: "grid", gridTemplateColumns: "repeat(4,1fr)", gap: 12, marginBottom: 24 }}
                  >
                    <SummaryCard icon={ArrowUpRight} label="Ventas Brutas"   target={48350} prevTarget={43100} change="+12.4%" changeUp={true}  accent="#34d399" delay={0}   filterKey={filterKey} comparisonMode={comparisonMode} />
                    <SummaryCard icon={RefreshCw}    label="Devoluciones"    target={1930}  prevTarget={1990}  change="+3.1%"  changeUp={false} accent="#f87171" delay={80}  filterKey={filterKey} comparisonMode={comparisonMode} />
                    <SummaryCard icon={ShoppingCart} label="Costo de Ventas" target={32470} prevTarget={29850} change="+8.7%"  changeUp={false} accent="#f59e0b" delay={160} filterKey={filterKey} comparisonMode={comparisonMode} />
                    <SummaryCard icon={DollarSign}   label="Utilidad Neta"   target={5118}  prevTarget={4330}  change="+18.2%" changeUp={true}  accent="#6366f1" delay={240} filterKey={filterKey} comparisonMode={comparisonMode} />
                  </motion.div>
                )}
              </AnimatePresence>
            </div>

            {/* ── Empty state ── */}
            <AnimatePresence>
              {isEmpty && !isRefreshing && (
                <motion.div
                  initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0 }}
                  style={{ textAlign: "center", padding: "48px 24px", marginBottom: 32 }}
                >
                  <div style={{ fontSize: 40, marginBottom: 16 }}>📭</div>
                  <p style={{ color: "#e2e8f0", fontSize: 15, fontWeight: 600, marginBottom: 6 }}>Sin datos para este período</p>
                  <p style={{ color: "#475569", fontSize: 13, marginBottom: 20 }}>El rango de fechas seleccionado no contiene datos disponibles.</p>
                  <button
                    onClick={() => { selectPreset("Mes"); applyFilters(); }}
                    style={{ padding: "8px 20px", borderRadius: 8, border: "1px solid #6366f1", background: "rgba(99,102,241,0.1)", color: "#818cf8", fontSize: 13, cursor: "pointer", fontFamily: "inherit" }}
                  >
                    Seleccionar otro rango
                  </button>
                </motion.div>
              )}
            </AnimatePresence>

            {/* ── Desglose Contable ── */}
            <SectionBlock
              id="table" label="Desglose Contable" summary="Utilidad Neta: $5,118.00"
              collapsed={!!collapsed.table} onToggle={() => toggleCollapse("table")}
            >
              <AccountingTable filterKey={filterKey} isRefreshing={isRefreshing} />
            </SectionBlock>

            {/* ── Rentabilidad ── */}
            <SectionBlock
              id="profit" label="Análisis de Rentabilidad" summary="Margen Bruto: 28.8%"
              collapsed={!!collapsed.profit} onToggle={() => toggleCollapse("profit")}
            >
              <ProfitabilitySection />
            </SectionBlock>

            {/* ── Pagos ── */}
            <SectionBlock
              id="payments" label="Métodos de Pago y Crédito" summary="Total: $48,350.00"
              collapsed={!!collapsed.payments} onToggle={() => toggleCollapse("payments")}
            >
              <PaymentBreakdown />
            </SectionBlock>

          </main>
        </div>
      </div>

      {/* ── Overlays ── */}
      <AnimatePresence>
        {exportLoading && (
          <ExportOverlay key="export" type={exportLoading} onDone={() => setExportLoading(null)} />
        )}
      </AnimatePresence>
      <AnimatePresence>
        {showPrintModal && (
          <PrintModal key="print" fromDate={fromDate} toDate={toDate} onClose={() => setShowPrintModal(false)} />
        )}
      </AnimatePresence>
    </div>
  );
}
