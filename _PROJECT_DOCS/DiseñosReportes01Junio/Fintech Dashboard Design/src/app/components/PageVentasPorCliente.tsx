import { motion } from "motion/react";
import { UserCheck } from "lucide-react";

const salesData = [
  { rank: 1, client: "Mecánico Rodríguez", initials: "MR", color: "#6366f1", facturas: 12, total: "$4,200.00", pct: 8.7 },
  { rank: 2, client: "Taller Hermanos", initials: "TH", color: "#22c55e", facturas: 9, total: "$3,800.00", pct: 7.9 },
  { rank: 3, client: "AutoParts Panamá", initials: "AP", color: "#3b82f6", facturas: 7, total: "$2,950.00", pct: 6.1 },
  { rank: 4, client: "Cliente Frecuente #12", initials: "CF", color: "#8b5cf6", facturas: 6, total: "$2,100.00", pct: 4.3 },
  { rank: 5, client: "Servicio Express", initials: "SE", color: "#f97316", facturas: 5, total: "$1,800.00", pct: 3.7 },
  { rank: 6, client: "Garage del Norte", initials: "GN", color: "#ef4444", facturas: 4, total: "$1,540.00", pct: 3.2 },
  { rank: 7, client: "Transport Logistics", initials: "TL", color: "#f59e0b", facturas: 3, total: "$1,200.00", pct: 2.5 },
  { rank: 8, client: "Auto Center VIP", initials: "AV", color: "#14b8a6", facturas: 3, total: "$980.00", pct: 2.0 },
];

const max = 4200;

export function PageVentasPorCliente() {
  return (
    <motion.div
      initial={{ opacity: 0, x: 24 }}
      animate={{ opacity: 1, x: 0 }}
      transition={{ duration: 0.3, ease: "easeOut" }}
      className="flex flex-col gap-6"
      style={{ padding: 24, paddingTop: 88 }}
    >
      <div className="flex items-center gap-3">
        <div className="flex items-center justify-center rounded-xl" style={{ width: 44, height: 44, background: "rgba(34,197,94,0.12)", border: "1px solid rgba(34,197,94,0.2)" }}>
          <UserCheck size={22} color="#22c55e" />
        </div>
        <div>
          <h1 style={{ color: "#f1f5f9", fontWeight: 700, letterSpacing: "-0.5px", lineHeight: 1.2 }}>Ventas por Cliente</h1>
          <p style={{ color: "#475569", fontSize: 13, marginTop: 3 }}>01 May 2026 — 26 May 2026 · 142 transacciones</p>
        </div>
      </div>

      <div className="rounded-xl overflow-hidden" style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039" }}>
        <div className="px-5 py-4 flex items-center justify-between" style={{ borderBottom: "1px solid #2e3039" }}>
          <h3 style={{ color: "#f1f5f9", fontSize: 15, fontWeight: 600 }}>Ranking de Clientes</h3>
          <button className="rounded-lg px-3" style={{ height: 30, backgroundColor: "rgba(99,102,241,0.1)", border: "1px solid rgba(99,102,241,0.25)", color: "#818cf8", fontSize: 12, fontWeight: 500 }}>
            Exportar
          </button>
        </div>

        <div className="grid px-5 py-2" style={{ gridTemplateColumns: "40px 2fr 3fr 80px 100px", borderBottom: "1px solid #2e3039", backgroundColor: "#252830" }}>
          {["#", "Cliente", "Facturación", "Facturas", "% Total"].map((h) => (
            <span key={h} style={{ color: "#475569", fontSize: 11, fontWeight: 600, textTransform: "uppercase", letterSpacing: "0.05em" }}>{h}</span>
          ))}
        </div>

        {salesData.map((row, i) => (
          <div
            key={row.rank}
            className="grid items-center px-5 py-4 transition-colors"
            style={{ gridTemplateColumns: "40px 2fr 3fr 80px 100px", borderBottom: i < salesData.length - 1 ? "1px solid #2e3039" : "none", cursor: "pointer" }}
            onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = "#252830")}
            onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = "transparent")}
          >
            <span style={{ color: "#475569", fontSize: 13, fontWeight: 700 }}>#{row.rank}</span>
            <div className="flex items-center gap-2">
              <div className="flex items-center justify-center rounded-full" style={{ width: 30, height: 30, backgroundColor: row.color + "22" }}>
                <span style={{ color: row.color, fontSize: 10, fontWeight: 700 }}>{row.initials}</span>
              </div>
              <span style={{ color: "#cbd5e1", fontSize: 12, fontWeight: 500 }}>{row.client}</span>
            </div>
            <div className="flex items-center gap-3 pr-6">
              <div className="flex-1 rounded-full overflow-hidden" style={{ height: 6, backgroundColor: "#252830" }}>
                <div className="h-full rounded-full" style={{ width: `${(parseFloat(row.total.replace(/[$,]/g, "")) / max) * 100}%`, background: "linear-gradient(90deg, #6366f1, #8b5cf6)" }} />
              </div>
              <span style={{ color: "#f1f5f9", fontSize: 12, fontWeight: 700, minWidth: 72, textAlign: "right" }}>{row.total}</span>
            </div>
            <span style={{ color: "#64748b", fontSize: 12 }}>{row.facturas} facts.</span>
            <span style={{ color: "#94a3b8", fontSize: 12 }}>{row.pct}%</span>
          </div>
        ))}
      </div>
    </motion.div>
  );
}
