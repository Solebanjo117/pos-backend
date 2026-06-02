import { useState } from "react";
import { motion, AnimatePresence } from "motion/react";
import { BarChart3, ChevronRight, ChevronsUpDown, ArrowUp, ArrowDown } from "lucide-react";
import {
  LineChart, Line, Tooltip as RTooltip, ResponsiveContainer,
} from "recharts";

// ── Types ──────────────────────────────────────────────────────────────────

type RowType = "category" | "item" | "subtotal" | "separator" | "grand-total";

interface SubRow {
  label: string;
  amount: number;
  percentage: number;
}

interface PLRow {
  id: string;
  type: RowType;
  label: string;
  amount?: number;
  percentage?: number;
  expandable?: boolean;
  subRows?: SubRow[];
  hasChart?: boolean;
}

// ── Data ───────────────────────────────────────────────────────────────────

const plData: PLRow[] = [
  { id: "h1", type: "category", label: "INGRESOS" },
  {
    id: "r1", type: "item", label: "Ventas Brutas", amount: 48350, percentage: 100.0,
    expandable: true,
    subRows: [
      { label: "Canal Presencial", amount: 32640, percentage: 67.5 },
      { label: "E-commerce / Online", amount: 9670, percentage: 20.0 },
      { label: "Ventas Mayoreo", amount: 4350, percentage: 9.0 },
      { label: "Consignación", amount: 1690, percentage: 3.5 },
    ],
  },
  {
    id: "r2", type: "item", label: "(-) Devoluciones sobre Ventas", amount: -1250, percentage: -2.6,
    expandable: true,
    subRows: [
      { label: "Defecto de Fábrica", amount: -680, percentage: -1.4 },
      { label: "Error en Pedido", amount: -370, percentage: -0.8 },
      { label: "Cambio de Opinión", amount: -200, percentage: -0.4 },
    ],
  },
  { id: "r3", type: "item", label: "(-) Descuentos Comerciales", amount: -680, percentage: -1.4 },
  { id: "s1", type: "separator" },
  { id: "t1", type: "subtotal", label: "Ventas Netas", amount: 46420, percentage: 96.0 },

  { id: "h2", type: "category", label: "COSTO DE VENTAS" },
  {
    id: "r4", type: "item", label: "(-) Costo de Mercancía Vendida", amount: -29630, percentage: -61.3,
    expandable: true,
    subRows: [
      { label: "Categoría: Electrónicos", amount: -14815, percentage: -30.6 },
      { label: "Categoría: Ropa y Acces.", amount: -8889, percentage: -18.4 },
      { label: "Categoría: Hogar", amount: -3556, percentage: -7.4 },
      { label: "Categoría: Alimentos", amount: -2370, percentage: -4.9 },
    ],
  },
  { id: "r5", type: "item", label: "(-) Mano de Obra Directa", amount: -2840, percentage: -5.9 },
  { id: "s2", type: "separator" },
  { id: "t2", type: "subtotal", label: "Utilidad Bruta", amount: 13950, percentage: 28.8 },

  { id: "h3", type: "category", label: "GASTOS OPERATIVOS" },
  { id: "r6", type: "item", label: "(-) Gastos de Administración", amount: -2840, percentage: -5.9 },
  { id: "r7", type: "item", label: "(-) Gastos de Ventas y Marketing", amount: -1920, percentage: -4.0 },
  { id: "r8", type: "item", label: "(-) Depreciación y Amortización", amount: -480, percentage: -1.0 },
  { id: "s3", type: "separator" },
  { id: "t3", type: "subtotal", label: "Utilidad Operativa (EBIT)", amount: 8710, percentage: 18.0 },

  { id: "h4", type: "category", label: "RESULTADO FINANCIERO" },
  { id: "r9", type: "item", label: "Ingresos Financieros", amount: 380, percentage: 0.8 },
  { id: "r10", type: "item", label: "(-) Gastos Financieros e Intereses", amount: -560, percentage: -1.2 },
  { id: "s4", type: "separator" },
  { id: "t4", type: "subtotal", label: "Utilidad Antes de Impuestos", amount: 8530, percentage: 17.6 },

  { id: "h5", type: "category", label: "IMPUESTOS Y CONTRIBUCIONES" },
  { id: "r11", type: "item", label: "(-) ISR — Impuesto Sobre la Renta (30%)", amount: -2559, percentage: -5.3 },
  { id: "r12", type: "item", label: "(-) PTU — Participación de Trabajadores", amount: -853, percentage: -1.8 },
  { id: "s5", type: "separator" },

  { id: "h6", type: "category", label: "RESULTADO DEL EJERCICIO" },
  {
    id: "t5", type: "grand-total", label: "UTILIDAD NETA DEL PERÍODO", amount: 5118, percentage: 10.6,
    expandable: true, hasChart: true,
    subRows: [
      { label: "Margen Bruto", amount: 13950, percentage: 28.8 },
      { label: "Margen Operativo (EBIT)", amount: 8710, percentage: 18.0 },
      { label: "Margen Antes de Imptos.", amount: 8530, percentage: 17.6 },
    ],
  },
];

const trendData = [
  { m: "Ene", v: 3200 },
  { m: "Feb", v: 3850 },
  { m: "Mar", v: 3100 },
  { m: "Abr", v: 4400 },
  { m: "May", v: 5118 },
];

// ── Helpers ────────────────────────────────────────────────────────────────

function fmtAmt(v: number): string {
  const abs = Math.abs(v);
  const s = abs.toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return v < 0 ? `-$${s}` : `$${s}`;
}

function fmtPct(v: number): string {
  return `${v > 0 ? "+" : ""}${v.toFixed(1)}%`;
}

// ── Skeleton row ───────────────────────────────────────────────────────────

function SkeletonRow({ wide = false }: { wide?: boolean }) {
  return (
    <tr>
      <td style={{ padding: "9px 20px" }}>
        <div className="shimmer" style={{ height: 11, borderRadius: 4, width: wide ? "55%" : "70%" }} />
      </td>
      <td style={{ padding: "9px 8px" }}>
        <div className="shimmer" style={{ height: 11, borderRadius: 4, width: 90, marginLeft: "auto" }} />
      </td>
      <td style={{ padding: "9px 20px" }}>
        <div className="shimmer" style={{ height: 11, borderRadius: 4, width: 50, marginLeft: "auto" }} />
      </td>
    </tr>
  );
}

// ── Mini trend chart ──────────────────────────────────────────────────────

const TrendTooltip = ({ active, payload }: { active?: boolean; payload?: Array<{ payload: { m: string; v: number } }> }) => {
  if (active && payload?.length) {
    const d = payload[0].payload;
    return (
      <div style={{ background: "#1e2130", border: "1px solid #252830", borderRadius: 6, padding: "5px 9px", fontSize: 11, fontFamily: "inherit" }}>
        <span style={{ color: "#94a3b8" }}>{d.m}: </span>
        <span style={{ color: "#34d399", fontWeight: 500 }}>${(d.v / 1000).toFixed(1)}k</span>
      </div>
    );
  }
  return null;
};

function MiniTrendChart() {
  return (
    <tr style={{ background: "rgba(52,211,153,0.03)" }}>
      <td colSpan={3} style={{ padding: "12px 40px 16px" }}>
        <p style={{ color: "#475569", fontSize: 10.5, fontWeight: 600, letterSpacing: "0.08em", textTransform: "uppercase", marginBottom: 8, fontFamily: "inherit" }}>
          Tendencia de Utilidad Neta — Últimos 5 meses
        </p>
        <ResponsiveContainer width="100%" height={72}>
          <LineChart data={trendData} margin={{ top: 4, right: 8, bottom: 0, left: 0 }}>
            <RTooltip content={<TrendTooltip />} />
            <Line
              type="monotone" dataKey="v" stroke="#34d399" strokeWidth={2}
              dot={{ fill: "#34d399", r: 3, strokeWidth: 0 }}
              activeDot={{ r: 4, fill: "#34d399" }}
              animationDuration={800}
            />
          </LineChart>
        </ResponsiveContainer>
        <div style={{ display: "flex", justifyContent: "space-between", marginTop: 4 }}>
          {trendData.map((d) => (
            <span key={d.m} style={{ color: "#334155", fontSize: 10, fontFamily: "inherit" }}>{d.m}</span>
          ))}
        </div>
      </td>
    </tr>
  );
}

// ── Sub-rows ──────────────────────────────────────────────────────────────

function SubRowEl({ sub, index }: { sub: SubRow; index: number }) {
  const isNeg = sub.amount < 0;
  return (
    <motion.tr
      initial={{ opacity: 0, x: -8 }}
      animate={{ opacity: 1, x: 0 }}
      transition={{ delay: index * 0.05, duration: 0.18, ease: "easeOut" }}
      style={{ background: "rgba(255,255,255,0.01)" }}
      className="hover:bg-white/[0.02] transition-colors"
    >
      <td style={{ paddingLeft: 60, paddingTop: 7, paddingBottom: 7, paddingRight: 12 }}>
        <div style={{ display: "flex", alignItems: "center", gap: 7 }}>
          <div style={{ width: 1, height: 14, background: "#1e2130", flexShrink: 0 }} />
          <span style={{ color: "#4b5563", fontSize: 11.5, fontFamily: "inherit" }}>{sub.label}</span>
        </div>
      </td>
      <td style={{ textAlign: "right", padding: "7px 8px", color: isNeg ? "#f87171" : "#64748b", fontSize: 11.5, fontVariantNumeric: "tabular-nums", fontFamily: "inherit" }}>
        {fmtAmt(sub.amount)}
      </td>
      <td style={{ textAlign: "right", padding: "7px 20px", color: "#2d3748", fontSize: 11, fontVariantNumeric: "tabular-nums", fontFamily: "inherit" }}>
        {fmtPct(sub.percentage)}
      </td>
    </motion.tr>
  );
}

// ── Data row ──────────────────────────────────────────────────────────────

function DataRow({
  row, index, expanded, onToggle,
}: {
  row: PLRow; index: number; expanded: boolean; onToggle: () => void;
}) {
  const isNeg = (row.amount ?? 0) < 0;
  const isGrand = row.type === "grand-total";
  const isTotal = row.type === "subtotal" || isGrand;

  const amtColor = isGrand ? "#34d399" : isNeg ? "#f87171" : isTotal ? "#e2e8f0" : "#94a3b8";
  const pctColor = isGrand ? "#34d399" : isNeg ? "#f87171" : isTotal ? "#64748b" : "#374151";

  return (
    <motion.tr
      initial={{ opacity: 0, x: -5 }}
      animate={{ opacity: 1, x: 0 }}
      transition={{ duration: 0.22, delay: index * 0.016, ease: "easeOut" }}
      style={{ background: isGrand ? "rgba(52,211,153,0.055)" : "transparent", cursor: row.expandable ? "pointer" : "default" }}
      className="hover:bg-white/[0.02] transition-colors duration-75 group"
      onClick={row.expandable ? onToggle : undefined}
    >
      {/* Concepto */}
      <td style={{
        paddingLeft: isTotal ? 20 : 44,
        paddingTop: isGrand ? 14 : isTotal ? 10 : 8,
        paddingBottom: isGrand ? 14 : isTotal ? 10 : 8,
        paddingRight: 12,
        color: isGrand ? "#34d399" : isTotal ? "#e2e8f0" : "#6b7280",
        fontSize: isGrand ? 13 : isTotal ? 12.5 : 12,
        fontWeight: isGrand ? 600 : isTotal ? 500 : 400,
        borderTop: isTotal ? "1px solid #252830" : "none",
        lineHeight: 1.45, fontFamily: "inherit",
      }}>
        <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
          <span>{row.label}</span>
          {row.expandable && (
            <motion.div
              animate={{ rotate: expanded ? 90 : 0 }}
              transition={{ duration: 0.18 }}
              style={{ opacity: 0.5, flexShrink: 0 }}
              className="group-hover:opacity-100 transition-opacity"
            >
              <ChevronRight size={12} style={{ color: isGrand ? "#34d399" : "#475569" }} />
            </motion.div>
          )}
        </div>
      </td>

      {/* Monto */}
      <td style={{
        textAlign: "right",
        paddingTop: isGrand ? 14 : isTotal ? 10 : 8,
        paddingBottom: isGrand ? 14 : isTotal ? 10 : 8,
        paddingLeft: 8, paddingRight: 8,
        color: amtColor,
        fontSize: isGrand ? 13.5 : isTotal ? 12.5 : 12,
        fontWeight: isGrand ? 600 : isTotal ? 500 : 400,
        fontVariantNumeric: "tabular-nums", whiteSpace: "nowrap", letterSpacing: "-0.01em",
        borderTop: isTotal ? "1px solid #252830" : "none", fontFamily: "inherit",
      }}>
        {row.amount !== undefined ? fmtAmt(row.amount) : ""}
      </td>

      {/* % */}
      <td style={{
        textAlign: "right",
        paddingTop: isGrand ? 14 : isTotal ? 10 : 8,
        paddingBottom: isGrand ? 14 : isTotal ? 10 : 8,
        paddingLeft: 8, paddingRight: 20,
        color: pctColor,
        fontSize: 11.5, fontVariantNumeric: "tabular-nums", whiteSpace: "nowrap",
        borderTop: isTotal ? "1px solid #252830" : "none", fontFamily: "inherit",
      }}>
        {row.percentage !== undefined ? fmtPct(row.percentage) : ""}
      </td>
    </motion.tr>
  );
}

// ── Table ──────────────────────────────────────────────────────────────────

export function AccountingTable({ filterKey, isRefreshing }: { filterKey: string; isRefreshing?: boolean }) {
  const [expandedRows, setExpandedRows] = useState<Set<string>>(new Set());
  const [sortAsc, setSortAsc] = useState<boolean | null>(null);

  function toggleRow(id: string) {
    setExpandedRows((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  }

  let animIdx = 0;

  return (
    <div style={{ background: "#0f1117", border: "1px solid #1e2130", borderRadius: 12, overflow: "hidden" }}>
      {/* Card header */}
      <div style={{ padding: "13px 20px", borderBottom: "1px solid #1e2130", display: "flex", alignItems: "center", gap: 10, background: "#0d0f1a" }}>
        <BarChart3 size={14} style={{ color: "#6366f1" }} />
        <span style={{ color: "#e2e8f0", fontSize: 12.5, fontWeight: 600, fontFamily: "inherit" }}>
          Desglose Contable
        </span>
        <span style={{ marginLeft: "auto", padding: "2px 10px", borderRadius: 20, background: "#171a26", color: "#475569", fontSize: 10.5, border: "1px solid #1e2130", fontFamily: "inherit" }}>
          USD · Período Actual
        </span>
      </div>

      <div style={{ overflowX: "auto" }}>
        <table style={{ width: "100%", borderCollapse: "collapse", minWidth: 480, fontFamily: "inherit" }}>
          <colgroup>
            <col style={{ width: "auto" }} />
            <col style={{ width: 160 }} />
            <col style={{ width: 90 }} />
          </colgroup>
          <thead>
            <tr style={{ borderBottom: "1px solid #1e2130" }}>
              <th
                onClick={() => setSortAsc((v) => v === null ? true : v ? false : null)}
                style={{
                  textAlign: "left", padding: "8px 20px", color: "#334155", fontSize: 10,
                  fontWeight: 700, letterSpacing: "0.1em", textTransform: "uppercase",
                  background: "#0d0f1a", fontFamily: "inherit", cursor: "pointer",
                  userSelect: "none",
                }}
              >
                <div style={{ display: "flex", alignItems: "center", gap: 5 }}>
                  <span>Concepto</span>
                  <motion.div animate={{ rotate: sortAsc === true ? 0 : sortAsc === false ? 180 : 0 }}>
                    {sortAsc === null
                      ? <ChevronsUpDown size={11} style={{ color: "#1e2130" }} />
                      : sortAsc
                      ? <ArrowUp size={11} style={{ color: "#6366f1" }} />
                      : <ArrowDown size={11} style={{ color: "#6366f1" }} />
                    }
                  </motion.div>
                </div>
              </th>
              <th style={{ textAlign: "right", padding: "8px 8px", color: "#334155", fontSize: 10, fontWeight: 700, letterSpacing: "0.1em", textTransform: "uppercase", background: "#0d0f1a", fontFamily: "inherit" }}>
                Monto
              </th>
              <th style={{ textAlign: "right", padding: "8px 20px", color: "#334155", fontSize: 10, fontWeight: 700, letterSpacing: "0.1em", textTransform: "uppercase", background: "#0d0f1a", whiteSpace: "nowrap", fontFamily: "inherit" }}>
                % Ingreso
              </th>
            </tr>
          </thead>

          <AnimatePresence mode="wait">
            {isRefreshing ? (
              <motion.tbody key="skeleton" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
                {Array.from({ length: 8 }).map((_, i) => <SkeletonRow key={i} wide={i % 3 === 0} />)}
              </motion.tbody>
            ) : (
              <motion.tbody key={filterKey} initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} transition={{ duration: 0.18 }}>
                {plData.map((row) => {
                  if (row.type === "separator") {
                    return (
                      <tr key={row.id} aria-hidden>
                        <td colSpan={3} style={{ padding: "3px 0" }}>
                          <div style={{ height: 1, background: "#252830" }} />
                        </td>
                      </tr>
                    );
                  }
                  if (row.type === "category") {
                    return (
                      <tr key={row.id} style={{ background: "#171a26" }}>
                        <td colSpan={3} style={{ padding: "9px 20px 8px", borderTop: "1px solid #252830", borderBottom: "1px solid #252830", color: "#475569", fontSize: 10, fontWeight: 700, letterSpacing: "0.12em", textTransform: "uppercase", fontFamily: "inherit" }}>
                          {row.label}
                        </td>
                      </tr>
                    );
                  }

                  const idx = animIdx++;
                  const isExpanded = expandedRows.has(row.id);

                  return (
                    <>
                      <DataRow key={row.id} row={row} index={idx} expanded={isExpanded} onToggle={() => toggleRow(row.id)} />
                      {isExpanded && row.hasChart && <MiniTrendChart key={`${row.id}-chart`} />}
                      {isExpanded && row.subRows?.map((sub, i) => (
                        <SubRowEl key={`${row.id}-s${i}`} sub={sub} index={i} />
                      ))}
                    </>
                  );
                })}
              </motion.tbody>
            )}
          </AnimatePresence>
        </table>
      </div>

      {/* Footer */}
      <div style={{ padding: "10px 20px", borderTop: "1px solid #1e2130", display: "flex", alignItems: "center", gap: 16, background: "#0d0f1a" }}>
        <span style={{ color: "#334155", fontSize: 11, fontFamily: "inherit" }}>Cifras en USD · Haz clic en una fila para expandir detalles</span>
        <div style={{ marginLeft: "auto", display: "flex", alignItems: "center", gap: 6 }}>
          <div style={{ width: 6, height: 6, borderRadius: "50%", background: "#34d399" }} />
          <span style={{ color: "#34d399", fontSize: 11, fontWeight: 500, fontFamily: "inherit" }}>Margen Neto: 10.6%</span>
        </div>
      </div>
    </div>
  );
}
