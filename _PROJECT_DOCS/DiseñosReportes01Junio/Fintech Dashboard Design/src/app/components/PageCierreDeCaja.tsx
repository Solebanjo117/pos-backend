import { motion } from "motion/react";
import { Calculator, CheckCircle, DollarSign, TrendingUp } from "lucide-react";

const paymentBreakdown = [
  { method: "Efectivo", icon: "💵", amount: "$21,757.50", count: 64, color: "#22c55e" },
  { method: "Tarjeta (Débito/Crédito)", icon: "💳", amount: "$12,087.50", count: 36, color: "#3b82f6" },
  { method: "Yappy / Transferencia", icon: "📲", amount: "$8,703.00", count: 26, color: "#8b5cf6" },
  { method: "Crédito al Cliente", icon: "📋", amount: "$5,802.00", count: 16, color: "#f97316" },
];

const denominations = [
  { bill: "$100", qty: 28, total: "$2,800" },
  { bill: "$50", qty: 15, total: "$750" },
  { bill: "$20", qty: 32, total: "$640" },
  { bill: "$10", qty: 18, total: "$180" },
  { bill: "$5", qty: 24, total: "$120" },
  { bill: "$1", qty: 67, total: "$67" },
  { bill: "Monedas", qty: null, total: "$200.50" },
];

export function PageCierreDeCaja() {
  return (
    <motion.div
      initial={{ opacity: 0, x: 24 }}
      animate={{ opacity: 1, x: 0 }}
      transition={{ duration: 0.3, ease: "easeOut" }}
      className="flex flex-col gap-6"
      style={{ padding: 24, paddingTop: 88 }}
    >
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <div className="flex items-center justify-center rounded-xl" style={{ width: 44, height: 44, background: "rgba(245,158,11,0.12)", border: "1px solid rgba(245,158,11,0.2)" }}>
            <Calculator size={22} color="#f59e0b" />
          </div>
          <div>
            <h1 style={{ color: "#f1f5f9", fontWeight: 700, letterSpacing: "-0.5px", lineHeight: 1.2 }}>Cierre de Caja</h1>
            <p style={{ color: "#475569", fontSize: 13, marginTop: 3 }}>26 de Mayo de 2026 · Sucursal Central</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <CheckCircle size={16} color="#22c55e" />
          <span style={{ color: "#22c55e", fontSize: 13, fontWeight: 600 }}>Caja abierta · 08:00 AM</span>
        </div>
      </div>

      {/* Summary KPIs */}
      <div className="grid gap-4" style={{ gridTemplateColumns: "repeat(4, 1fr)" }}>
        {[
          { label: "Fondo Inicial", value: "$500.00", borderColor: "#475569", icon: <DollarSign size={16} /> },
          { label: "Ventas del Día", value: "$48,350.00", borderColor: "#3b82f6", icon: <TrendingUp size={16} /> },
          { label: "Efectivo en Caja", value: "$21,757.50", borderColor: "#22c55e", icon: <DollarSign size={16} /> },
          { label: "Diferencia", value: "$+0.00", borderColor: "#22c55e", icon: <CheckCircle size={16} /> },
        ].map((k) => (
          <div key={k.label} className="rounded-xl p-5" style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039", borderLeft: `4px solid ${k.borderColor}` }}>
            <div className="flex items-center justify-between mb-2">
              <p style={{ color: "#64748b", fontSize: 11, fontWeight: 500, textTransform: "uppercase", letterSpacing: "0.05em" }}>{k.label}</p>
              <span style={{ color: k.borderColor }}>{k.icon}</span>
            </div>
            <p style={{ color: "#f1f5f9", fontSize: 20, fontWeight: 700 }}>{k.value}</p>
          </div>
        ))}
      </div>

      <div className="grid gap-6" style={{ gridTemplateColumns: "1fr 1fr" }}>
        {/* Payment breakdown */}
        <div className="rounded-xl overflow-hidden" style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039" }}>
          <div className="px-5 py-4" style={{ borderBottom: "1px solid #2e3039" }}>
            <h3 style={{ color: "#f1f5f9", fontSize: 15, fontWeight: 600 }}>Desglose por Método de Pago</h3>
          </div>
          {paymentBreakdown.map((p, i) => (
            <div key={p.method} className="px-5 py-4 flex items-center gap-3" style={{ borderBottom: i < paymentBreakdown.length - 1 ? "1px solid #2e3039" : "none" }}>
              <span style={{ fontSize: 20 }}>{p.icon}</span>
              <div className="flex-1">
                <div className="flex items-center justify-between mb-1.5">
                  <span style={{ color: "#cbd5e1", fontSize: 12, fontWeight: 500 }}>{p.method}</span>
                  <span style={{ color: "#f1f5f9", fontSize: 12, fontWeight: 700 }}>{p.amount}</span>
                </div>
                <div className="flex items-center gap-2">
                  <div className="flex-1 rounded-full overflow-hidden" style={{ height: 5, backgroundColor: "#252830" }}>
                    <div className="h-full rounded-full" style={{ width: `${(p.count / 142) * 100}%`, backgroundColor: p.color }} />
                  </div>
                  <span style={{ color: "#475569", fontSize: 11 }}>{p.count} trans.</span>
                </div>
              </div>
            </div>
          ))}
        </div>

        {/* Cash denomination */}
        <div className="rounded-xl overflow-hidden" style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039" }}>
          <div className="px-5 py-4" style={{ borderBottom: "1px solid #2e3039" }}>
            <h3 style={{ color: "#f1f5f9", fontSize: 15, fontWeight: 600 }}>Conteo de Efectivo</h3>
          </div>
          <div className="grid px-5 py-2" style={{ gridTemplateColumns: "1fr 1fr 1fr", borderBottom: "1px solid #2e3039", backgroundColor: "#252830" }}>
            {["Denominación", "Cantidad", "Total"].map((h) => (
              <span key={h} style={{ color: "#475569", fontSize: 11, fontWeight: 600, textTransform: "uppercase", letterSpacing: "0.05em" }}>{h}</span>
            ))}
          </div>
          {denominations.map((d, i) => (
            <div key={d.bill} className="grid px-5 py-3" style={{ gridTemplateColumns: "1fr 1fr 1fr", borderBottom: i < denominations.length - 1 ? "1px solid #2e3039" : "none" }}>
              <span style={{ color: "#94a3b8", fontSize: 12 }}>{d.bill}</span>
              <span style={{ color: "#64748b", fontSize: 12 }}>{d.qty ?? "—"}</span>
              <span style={{ color: "#f1f5f9", fontSize: 12, fontWeight: 600 }}>{d.total}</span>
            </div>
          ))}
          <div className="flex items-center justify-between px-5 py-3" style={{ borderTop: "1px solid #2e3039", backgroundColor: "rgba(34,197,94,0.04)" }}>
            <span style={{ color: "#e2e8f0", fontSize: 13, fontWeight: 700 }}>Total Efectivo</span>
            <span style={{ color: "#22c55e", fontSize: 13, fontWeight: 700 }}>$4,757.50</span>
          </div>
        </div>
      </div>

      {/* Close button */}
      <div className="flex justify-end gap-3">
        <button className="rounded-lg px-5" style={{ height: 40, backgroundColor: "#252830", border: "1px solid #2e3039", color: "#94a3b8", fontSize: 13, fontWeight: 500 }}>
          Imprimir Reporte
        </button>
        <button className="rounded-lg px-5" style={{ height: 40, background: "linear-gradient(135deg, #6366f1, #8b5cf6)", color: "#fff", fontSize: 13, fontWeight: 600 }}>
          Cerrar Caja
        </button>
      </div>
    </motion.div>
  );
}
