import { motion } from "motion/react";
import { CreditCard, AlertCircle, Clock, CheckCircle } from "lucide-react";

const receivables = [
  { client: "Mecánico Rodríguez", initials: "MR", color: "#6366f1", invoice: "FACT #222098", amount: "$1,200.00", due: "05 Jun 2026", days: 10, status: "Pendiente" },
  { client: "Taller Hermanos", initials: "TH", color: "#22c55e", invoice: "FACT #222089", amount: "$3,800.00", due: "01 Jun 2026", days: 6, status: "Pendiente" },
  { client: "AutoParts Panamá", initials: "AP", color: "#3b82f6", invoice: "FACT #221990", amount: "$2,950.00", due: "28 May 2026", days: 2, status: "Por vencer" },
  { client: "Cliente Frecuente #12", initials: "CF", color: "#8b5cf6", invoice: "FACT #221876", amount: "$430.00", due: "22 May 2026", days: -5, status: "Vencida" },
  { client: "Servicio Express", initials: "SE", color: "#f97316", invoice: "FACT #221810", amount: "$900.00", due: "15 May 2026", days: -12, status: "Vencida" },
  { client: "Garage del Norte", initials: "GN", color: "#ef4444", invoice: "FACT #221755", amount: "$1,200.00", due: "10 May 2026", days: -17, status: "Vencida" },
  { client: "Transport Logistics", initials: "TL", color: "#f59e0b", invoice: "FACT #221700", amount: "$2,000.00", due: "05 May 2026", days: -22, status: "Vencida" },
];

const statusStyle: Record<string, { bg: string; text: string; icon: React.ReactNode }> = {
  Pendiente: { bg: "rgba(59,130,246,0.12)", text: "#3b82f6", icon: <Clock size={11} /> },
  "Por vencer": { bg: "rgba(245,158,11,0.12)", text: "#f59e0b", icon: <AlertCircle size={11} /> },
  Vencida: { bg: "rgba(239,68,68,0.12)", text: "#ef4444", icon: <AlertCircle size={11} /> },
};

export function PageCuentasPorCobrar() {
  const total = "$12,480.00";
  const vencidas = "$4,530.00";
  const porVencer = "$2,950.00";
  const alDia = "$5,000.00";

  return (
    <motion.div
      initial={{ opacity: 0, x: 24 }}
      animate={{ opacity: 1, x: 0 }}
      transition={{ duration: 0.3, ease: "easeOut" }}
      className="flex flex-col gap-6"
      style={{ padding: 24, paddingTop: 88 }}
    >
      <div className="flex items-center gap-3">
        <div className="flex items-center justify-center rounded-xl" style={{ width: 44, height: 44, background: "rgba(249,115,22,0.12)", border: "1px solid rgba(249,115,22,0.2)" }}>
          <CreditCard size={22} color="#f97316" />
        </div>
        <div>
          <h1 style={{ color: "#f1f5f9", fontWeight: 700, letterSpacing: "-0.5px", lineHeight: 1.2 }}>Cuentas por Cobrar</h1>
          <p style={{ color: "#475569", fontSize: 13, marginTop: 3 }}>23 clientes con saldo pendiente</p>
        </div>
      </div>

      {/* Summary cards */}
      <div className="grid gap-4" style={{ gridTemplateColumns: "repeat(4, 1fr)" }}>
        {[
          { label: "Total Pendiente", value: total, borderColor: "#f97316", icon: <CreditCard size={16} />, iconColor: "#f97316" },
          { label: "Vencidas", value: vencidas, borderColor: "#ef4444", icon: <AlertCircle size={16} />, iconColor: "#ef4444" },
          { label: "Por Vencer", value: porVencer, borderColor: "#f59e0b", icon: <Clock size={16} />, iconColor: "#f59e0b" },
          { label: "Al Día", value: alDia, borderColor: "#22c55e", icon: <CheckCircle size={16} />, iconColor: "#22c55e" },
        ].map((k) => (
          <motion.div
            key={k.label}
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.35 }}
            className="rounded-xl p-5"
            style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039", borderLeft: `4px solid ${k.borderColor}` }}
          >
            <div className="flex items-center justify-between mb-2">
              <p style={{ color: "#64748b", fontSize: 11, fontWeight: 500, textTransform: "uppercase", letterSpacing: "0.05em" }}>{k.label}</p>
              <span style={{ color: k.iconColor }}>{k.icon}</span>
            </div>
            <p style={{ color: "#f1f5f9", fontSize: 20, fontWeight: 700, letterSpacing: "-0.5px" }}>{k.value}</p>
          </motion.div>
        ))}
      </div>

      {/* Table */}
      <div className="rounded-xl overflow-hidden" style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039" }}>
        <div className="px-5 py-4 flex items-center justify-between" style={{ borderBottom: "1px solid #2e3039" }}>
          <h3 style={{ color: "#f1f5f9", fontSize: 15, fontWeight: 600 }}>Detalle de Cobros</h3>
          <button
            className="rounded-lg px-3"
            style={{ height: 30, backgroundColor: "rgba(99,102,241,0.1)", border: "1px solid rgba(99,102,241,0.25)", color: "#818cf8", fontSize: 12, fontWeight: 500 }}
          >
            Exportar
          </button>
        </div>

        {/* Table header */}
        <div
          className="grid px-5 py-2"
          style={{ gridTemplateColumns: "2fr 1.5fr 1fr 1fr 1fr", borderBottom: "1px solid #2e3039", backgroundColor: "#252830" }}
        >
          {["Cliente", "Factura", "Monto", "Vencimiento", "Estado"].map((h) => (
            <span key={h} style={{ color: "#475569", fontSize: 11, fontWeight: 600, textTransform: "uppercase", letterSpacing: "0.05em" }}>{h}</span>
          ))}
        </div>

        {receivables.map((row, i) => {
          const st = statusStyle[row.status];
          return (
            <div
              key={i}
              className="grid items-center px-5 py-3 transition-colors"
              style={{
                gridTemplateColumns: "2fr 1.5fr 1fr 1fr 1fr",
                borderBottom: i < receivables.length - 1 ? "1px solid #2e3039" : "none",
                cursor: "pointer",
              }}
              onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = "#252830")}
              onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = "transparent")}
            >
              <div className="flex items-center gap-2">
                <div className="flex items-center justify-center rounded-full" style={{ width: 28, height: 28, backgroundColor: row.color + "22" }}>
                  <span style={{ color: row.color, fontSize: 10, fontWeight: 700 }}>{row.initials}</span>
                </div>
                <span style={{ color: "#cbd5e1", fontSize: 12, fontWeight: 500 }}>{row.client}</span>
              </div>
              <span style={{ color: "#64748b", fontSize: 12 }}>{row.invoice}</span>
              <span style={{ color: "#f1f5f9", fontSize: 12, fontWeight: 700 }}>{row.amount}</span>
              <span style={{ color: row.days < 0 ? "#ef4444" : "#94a3b8", fontSize: 12 }}>{row.due}</span>
              <span
                className="inline-flex items-center gap-1 rounded-full px-2"
                style={{ height: 20, backgroundColor: st.bg, color: st.text, fontSize: 10, fontWeight: 600, width: "fit-content" }}
              >
                {st.icon}
                {row.status}
              </span>
            </div>
          );
        })}
      </div>
    </motion.div>
  );
}
