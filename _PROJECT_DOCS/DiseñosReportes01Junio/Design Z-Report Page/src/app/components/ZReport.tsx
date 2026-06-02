import { motion } from "motion/react";
import { Receipt, Printer, RefreshCw, ChevronRight } from "lucide-react";
import { CashDrawer } from "./zreport/CashDrawer";
import { DailyPnL } from "./zreport/DailyPnL";
import { PaymentMethods } from "./zreport/PaymentMethods";
import { CreditTable } from "./zreport/CreditTable";
import { PrintPreview } from "./zreport/PrintPreview";

function SectionDivider({ label, delay = 0 }: { label: string; delay?: number }) {
  return (
    <motion.div
      initial={{ opacity: 0 }}
      animate={{ opacity: 1 }}
      transition={{ delay }}
      className="flex items-center gap-3"
    >
      <span
        style={{
          color: "#4b5563",
          fontSize: 9.5,
          fontWeight: 600,
          letterSpacing: "0.1em",
          textTransform: "uppercase",
          whiteSpace: "nowrap",
        }}
      >
        {label}
      </span>
      <div className="flex-1 h-px" style={{ background: "#252830" }} />
    </motion.div>
  );
}

export function ZReport() {
  const handlePrint = () => window.print();

  return (
    <div className="flex-1 flex flex-col overflow-hidden" style={{ background: "#0f1117" }}>
      {/* ─ Top bar ─ */}
      <div
        className="flex items-center justify-between px-6 border-b border-[#1e2130] flex-shrink-0"
        style={{ height: 56, background: "#0f1117" }}
      >
        <div className="flex items-center gap-1.5" style={{ color: "#6b7280", fontSize: 12 }}>
          <span>Reportes</span>
          <ChevronRight size={12} />
          <span style={{ color: "#d1d5db" }}>Cierre de Caja</span>
          <ChevronRight size={12} />
          <span style={{ color: "#9ca3af" }}>Z-Report</span>
        </div>
        <div className="flex items-center gap-3">
          <div
            style={{
              color: "#9ca3af",
              fontSize: 11,
              background: "#1a1d27",
              border: "1px solid #2e3039",
              padding: "4px 10px",
              borderRadius: 6,
            }}
          >
            Sucursal Central
          </div>
          <div
            className="w-7 h-7 rounded-full flex items-center justify-center"
            style={{ background: "#6366f1", color: "#fff", fontSize: 11, fontWeight: 700 }}
          >
            JM
          </div>
        </div>
      </div>

      {/* ─ Scroll area ─ */}
      <div className="flex-1 overflow-y-auto">
        <div className="px-6 py-5 space-y-5 max-w-7xl mx-auto">
          {/* ── PAGE HEADER ── */}
          <motion.div
            initial={{ opacity: 0, y: -8 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.32 }}
            className="flex items-start justify-between"
          >
            <div>
              <div className="flex items-center gap-2.5 mb-1">
                <Receipt size={20} style={{ color: "#6366f1" }} />
                <h1
                  style={{
                    color: "#f1f5f9",
                    fontWeight: 700,
                    fontSize: 22,
                    letterSpacing: "-0.01em",
                    lineHeight: 1,
                  }}
                >
                  Cierre de Caja — Z-Report
                </h1>
              </div>
              <p style={{ color: "#6b7280", fontSize: 12, marginLeft: 29 }}>
                Arqueo de efectivo &middot; Estado de resultados &middot; Conciliación de pagos
                <span
                  className="ml-3 px-2 py-0.5 rounded"
                  style={{
                    background: "rgba(34,197,94,0.1)",
                    color: "#22c55e",
                    fontSize: 10,
                    fontWeight: 600,
                    border: "1px solid rgba(34,197,94,0.2)",
                  }}
                >
                  Caja Cuadra
                </span>
              </p>
            </div>
            <button
              onClick={handlePrint}
              className="flex items-center gap-2 px-4 py-2 rounded-lg transition-colors"
              style={{
                background: "#1a1d27",
                border: "1px solid #2e3039",
                color: "#d1d5db",
                fontSize: 12,
                fontWeight: 500,
                flexShrink: 0,
              }}
              onMouseEnter={(e) => (e.currentTarget.style.background = "#252830")}
              onMouseLeave={(e) => (e.currentTarget.style.background = "#1a1d27")}
            >
              <Printer size={14} />
              Imprimir Z-Report
            </button>
          </motion.div>

          {/* ── FILTERS ── */}
          <motion.div
            initial={{ opacity: 0, y: 6 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.3, delay: 0.04 }}
            className="flex flex-wrap items-end gap-3 px-5 py-4 rounded-xl border border-[#2e3039]"
            style={{ background: "#1a1d27" }}
          >
            {[
              { label: "Fecha del Cierre", type: "date", default: "2026-05-26" },
            ].map((f) => (
              <div key={f.label} className="flex flex-col gap-1">
                <label style={{ color: "#6b7280", fontSize: 10, fontWeight: 600, letterSpacing: "0.07em", textTransform: "uppercase" }}>
                  {f.label}
                </label>
                <input
                  type={f.type}
                  defaultValue={f.default}
                  className="rounded-lg outline-none transition-colors"
                  style={{
                    background: "#252830",
                    border: "1px solid #2e3039",
                    color: "#d1d5db",
                    fontSize: 12,
                    padding: "6px 10px",
                  }}
                />
              </div>
            ))}
            {[
              { label: "Sucursal", options: ["Central", "Norte", "Sur"] },
              { label: "Cajero / Usuario", options: ["Juan Martínez", "María González", "Carlos Pérez"] },
            ].map((f) => (
              <div key={f.label} className="flex flex-col gap-1">
                <label style={{ color: "#6b7280", fontSize: 10, fontWeight: 600, letterSpacing: "0.07em", textTransform: "uppercase" }}>
                  {f.label}
                </label>
                <select
                  className="rounded-lg outline-none transition-colors appearance-none"
                  style={{
                    background: "#252830",
                    border: "1px solid #2e3039",
                    color: "#d1d5db",
                    fontSize: 12,
                    padding: "6px 28px 6px 10px",
                  }}
                >
                  {f.options.map((o) => (
                    <option key={o}>{o}</option>
                  ))}
                </select>
              </div>
            ))}
            <button
              className="flex items-center gap-1.5 px-4 py-2 rounded-lg transition-colors"
              style={{
                background: "#6366f1",
                color: "#fff",
                fontSize: 12,
                fontWeight: 600,
                border: "none",
                cursor: "pointer",
              }}
              onMouseEnter={(e) => (e.currentTarget.style.background = "#4f46e5")}
              onMouseLeave={(e) => (e.currentTarget.style.background = "#6366f1")}
            >
              <RefreshCw size={13} />
              Cargar
            </button>

            {/* Status pill */}
            <div className="ml-auto flex items-center gap-2">
              <div
                className="h-1.5 w-1.5 rounded-full"
                style={{ background: "#22c55e" }}
              />
              <span style={{ color: "#6b7280", fontSize: 11 }}>
                Datos al 26/05/2026 · 22:34
              </span>
            </div>
          </motion.div>

          {/* ── ARQUEO DE EFECTIVO (HERO) ── */}
          <SectionDivider label="Arqueo de Efectivo" delay={0.06} />
          <CashDrawer />

          {/* ── ESTADO DE RESULTADOS ── */}
          <SectionDivider label="Estado de Resultados del Día" delay={0.08} />
          <DailyPnL />

          {/* ── MÉTODOS DE PAGO ── */}
          <SectionDivider label="Desglose por Método de Pago" delay={0.1} />
          <PaymentMethods />

          {/* ── CRÉDITOS ── */}
          <SectionDivider label="Detalle de Créditos del Día" delay={0.12} />
          <CreditTable />

          {/* ── IMPRESIÓN ── */}
          <SectionDivider label="Vista Previa de Impresión" delay={0.14} />
          <PrintPreview onPrint={handlePrint} />

          {/* Bottom padding */}
          <div className="h-6" />
        </div>
      </div>
    </div>
  );
}
