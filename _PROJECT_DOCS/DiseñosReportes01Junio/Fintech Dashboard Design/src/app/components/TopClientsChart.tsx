import { motion } from "motion/react";
import type { Page } from "./Sidebar";

const clients = [
  { name: "Mecánico Rodríguez", amount: 4200 },
  { name: "Taller Hermanos", amount: 3800 },
  { name: "AutoParts Panamá", amount: 2950 },
  { name: "Cliente Frecuente #12", amount: 2100 },
  { name: "Servicio Express", amount: 1800 },
];

const max = clients[0].amount;

function getInitials(name: string) {
  return name.split(" ").slice(0, 2).map((w) => w[0]).join("").toUpperCase();
}

const avatarColors = ["#6366f1", "#8b5cf6", "#3b82f6", "#22c55e", "#f97316"];

interface TopClientsChartProps {
  onNavigate: (page: Page) => void;
}

export function TopClientsChart({ onNavigate }: TopClientsChartProps) {
  return (
    <motion.div
      initial={{ opacity: 0, y: 20 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.5, delay: 0.5 }}
      className="rounded-xl p-5"
      style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039" }}
    >
      <div className="flex items-center justify-between mb-5">
        <div>
          <h3 style={{ color: "#f1f5f9", fontSize: 15, fontWeight: 600, marginBottom: 2 }}>
            Top 5 Clientes por Facturación
          </h3>
          <p style={{ color: "#475569", fontSize: 12 }}>01 May — 26 May 2026</p>
        </div>
        <button
          onClick={() => onNavigate("ventas-por-cliente")}
          className="rounded-lg px-3 transition-all"
          style={{ height: 30, backgroundColor: "rgba(99,102,241,0.1)", border: "1px solid rgba(99,102,241,0.25)", color: "#818cf8", fontSize: 12, fontWeight: 500 }}
        >
          Ver ranking
        </button>
      </div>

      <div className="flex flex-col gap-3">
        {clients.map((client, i) => (
          <motion.button
            key={client.name}
            initial={{ opacity: 0, x: -16 }}
            animate={{ opacity: 1, x: 0 }}
            transition={{ duration: 0.35, delay: 0.5 + i * 0.07 }}
            onClick={() => onNavigate("perfil-cliente" as Page)}
            className="flex items-center gap-3 w-full rounded-lg p-2 transition-all text-left"
            style={{ cursor: "pointer" }}
            onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = "#252830")}
            onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = "transparent")}
          >
            {/* Avatar */}
            <div
              className="flex items-center justify-center rounded-full flex-shrink-0"
              style={{ width: 32, height: 32, backgroundColor: avatarColors[i] + "22", border: `1px solid ${avatarColors[i]}44` }}
            >
              <span style={{ color: avatarColors[i], fontSize: 11, fontWeight: 700 }}>
                {getInitials(client.name)}
              </span>
            </div>

            {/* Bar + label */}
            <div className="flex-1 min-w-0">
              <div className="flex items-center justify-between mb-1">
                <span style={{ color: "#cbd5e1", fontSize: 12, fontWeight: 500, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis", maxWidth: "65%" }}>
                  {client.name}
                </span>
                <span style={{ color: "#f1f5f9", fontSize: 12, fontWeight: 700, flexShrink: 0 }}>
                  ${client.amount.toLocaleString()}
                </span>
              </div>
              <div className="rounded-full overflow-hidden" style={{ height: 6, backgroundColor: "#252830" }}>
                <motion.div
                  className="h-full rounded-full"
                  initial={{ width: 0 }}
                  animate={{ width: `${(client.amount / max) * 100}%` }}
                  transition={{ duration: 0.7, delay: 0.6 + i * 0.07, ease: "easeOut" }}
                  style={{ background: "linear-gradient(90deg, #6366f1, #8b5cf6)" }}
                />
              </div>
            </div>
          </motion.button>
        ))}
      </div>
    </motion.div>
  );
}
