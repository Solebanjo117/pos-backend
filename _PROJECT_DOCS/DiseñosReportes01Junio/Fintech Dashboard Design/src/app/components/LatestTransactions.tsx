import { motion } from "motion/react";
import { ArrowRight } from "lucide-react";
import type { Transaction } from "./TransactionDrawer";
import type { Page } from "./Sidebar";

const transactions: Transaction[] = [
  { id: "FACT #222104", date: "22 May 2026", amount: "$135.00", type: "Crédito", initials: "MR", color: "#6366f1", client: "Mecánico Rodríguez" },
  { id: "FACT #222103", date: "22 May 2026", amount: "$89.50", type: "Efectivo", initials: "TH", color: "#22c55e", client: "Taller Hermanos" },
  { id: "FACT #222102", date: "21 May 2026", amount: "$320.00", type: "Tarjeta", initials: "AP", color: "#3b82f6", client: "AutoParts Panamá" },
  { id: "FACT #222101", date: "21 May 2026", amount: "$56.75", type: "Yappy", initials: "CF", color: "#8b5cf6", client: "Cliente Frecuente #12" },
  { id: "FACT #222100", date: "20 May 2026", amount: "$210.00", type: "Crédito", initials: "SE", color: "#f97316", client: "Servicio Express" },
];

const typeColors: Record<string, { bg: string; text: string }> = {
  Crédito: { bg: "rgba(249,115,22,0.12)", text: "#f97316" },
  Efectivo: { bg: "rgba(34,197,94,0.12)", text: "#22c55e" },
  Tarjeta: { bg: "rgba(59,130,246,0.12)", text: "#3b82f6" },
  Yappy: { bg: "rgba(139,92,246,0.12)", text: "#8b5cf6" },
};

interface LatestTransactionsProps {
  onOpenDrawer: (tx: Transaction) => void;
  onNavigate: (page: Page) => void;
}

export function LatestTransactions({ onOpenDrawer, onNavigate }: LatestTransactionsProps) {
  return (
    <motion.div
      initial={{ opacity: 0, y: 20 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.5, delay: 0.55 }}
      className="rounded-xl p-5"
      style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039" }}
    >
      <div className="flex items-center justify-between mb-5">
        <div>
          <h3 style={{ color: "#f1f5f9", fontSize: 15, fontWeight: 600, marginBottom: 2 }}>
            Últimas Transacciones
          </h3>
          <p style={{ color: "#475569", fontSize: 12 }}>Actividad reciente</p>
        </div>
        <button
          onClick={() => onNavigate("ventas")}
          className="rounded-lg px-3 transition-all"
          style={{ height: 30, backgroundColor: "rgba(99,102,241,0.1)", border: "1px solid rgba(99,102,241,0.25)", color: "#818cf8", fontSize: 12, fontWeight: 500 }}
        >
          Ver todas
        </button>
      </div>

      <div>
        {transactions.map((tx, i) => {
          const typeStyle = typeColors[tx.type] ?? { bg: "rgba(100,116,139,0.12)", text: "#64748b" };
          return (
            <motion.div
              key={tx.id}
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              transition={{ duration: 0.3, delay: 0.6 + i * 0.06 }}
            >
              <div
                className="flex items-center gap-3 py-3 px-2 rounded-lg transition-all group relative"
                style={{ cursor: "pointer", marginLeft: -8, marginRight: -8 }}
                onClick={() => onOpenDrawer(tx)}
                onMouseEnter={(e) => {
                  (e.currentTarget as HTMLDivElement).style.backgroundColor = "#252830";
                  (e.currentTarget as HTMLDivElement).style.paddingLeft = "16px";
                }}
                onMouseLeave={(e) => {
                  (e.currentTarget as HTMLDivElement).style.backgroundColor = "transparent";
                  (e.currentTarget as HTMLDivElement).style.paddingLeft = "8px";
                }}
              >
                {/* Avatar */}
                <div
                  className="flex items-center justify-center rounded-full flex-shrink-0"
                  style={{ width: 36, height: 36, backgroundColor: tx.color + "22", border: `1px solid ${tx.color}44` }}
                >
                  <span style={{ color: tx.color, fontSize: 12, fontWeight: 700 }}>{tx.initials}</span>
                </div>

                {/* Info */}
                <div className="flex-1 min-w-0">
                  <p style={{ color: "#e2e8f0", fontSize: 13, fontWeight: 600 }}>{tx.id}</p>
                  <p style={{ color: "#475569", fontSize: 11, marginTop: 1 }}>{tx.date}</p>
                </div>

                {/* Amount + badge */}
                <div className="flex flex-col items-end gap-1">
                  <span style={{ color: "#f1f5f9", fontSize: 13, fontWeight: 700 }}>{tx.amount}</span>
                  <span
                    className="inline-flex items-center rounded-full px-2"
                    style={{ height: 18, backgroundColor: typeStyle.bg, color: typeStyle.text, fontSize: 10, fontWeight: 600 }}
                  >
                    {tx.type}
                  </span>
                </div>

                {/* "Ver detalle" hint */}
                <div
                  className="absolute right-2 opacity-0 group-hover:opacity-100 transition-opacity flex items-center gap-1"
                  style={{ color: "#6366f1", top: "50%", transform: "translateY(-50%)", pointerEvents: "none" }}
                >
                  <span style={{ fontSize: 10, fontWeight: 600 }}>Ver detalle</span>
                  <ArrowRight size={10} />
                </div>
              </div>
              {i < transactions.length - 1 && (
                <div style={{ height: 1, backgroundColor: "#2e3039", marginLeft: 0 }} />
              )}
            </motion.div>
          );
        })}
      </div>
    </motion.div>
  );
}
