import { DollarSign, FileText, TrendingUp, Box, TrendingDown, ArrowRight } from "lucide-react";
import { motion } from "motion/react";
import { SparklineChart } from "./SparklineChart";
import type { Page } from "./Sidebar";

const incomeData = [28, 32, 30, 38, 35, 42, 40, 45, 43, 48];
const netData = [10, 13, 12, 15, 14, 18, 16, 20, 19, 22];

interface KPICardsProps {
  onNavigate: (page: Page) => void;
  comparisonMode: boolean;
}

const comparisonDeltas = [
  { delta: "+12.4%", positive: true, prev: "$43,010.00" },
  { delta: "+8.2%", positive: false, prev: "$11,534.00" },
  { delta: "+18.7%", positive: true, prev: "$15,767.00" },
  { delta: "+5.1%", positive: false, prev: "$28,190.00" },
];

const cards = [
  {
    title: "Ingresos Totales",
    value: "$48,350.00",
    subtitle: "142 transacciones",
    icon: <DollarSign size={18} />,
    iconColor: "#3b82f6",
    iconBg: "rgba(59,130,246,0.15)",
    borderColor: "#3b82f6",
    navigateTo: "estado-resultados" as Page,
    extra: (idx: number) => <SparklineChart data={incomeData} color="#22c55e" />,
  },
  {
    title: "Cuentas por Cobrar",
    value: "$12,480.00",
    subtitle: "23 clientes pendientes",
    icon: <FileText size={18} />,
    iconColor: "#f97316",
    iconBg: "rgba(249,115,22,0.15)",
    borderColor: "#f97316",
    navigateTo: "cuentas-por-cobrar" as Page,
    extra: (_: number) => (
      <div
        className="inline-flex items-center gap-1 rounded-full px-2 py-0.5"
        style={{ backgroundColor: "rgba(245,158,11,0.12)", border: "1px solid rgba(245,158,11,0.25)" }}
      >
        <span style={{ color: "#f59e0b", fontSize: 11, fontWeight: 600 }}>↑ 8% vs mes anterior</span>
      </div>
    ),
  },
  {
    title: "Utilidad Neta",
    value: "$18,720.00",
    subtitle: "Margen 38.7%",
    icon: <TrendingUp size={18} />,
    iconColor: "#22c55e",
    iconBg: "rgba(34,197,94,0.15)",
    borderColor: "#22c55e",
    navigateTo: "estado-resultados" as Page,
    extra: (_: number) => <SparklineChart data={netData} color="#22c55e" />,
  },
  {
    title: "Gastos / COGS",
    value: "$29,630.00",
    subtitle: "Costo de mercancía vendida",
    icon: <Box size={18} />,
    iconColor: "#ef4444",
    iconBg: "rgba(239,68,68,0.15)",
    borderColor: "#ef4444",
    navigateTo: "estado-resultados" as Page,
    extra: () => null,
  },
];

export function KPICards({ onNavigate, comparisonMode }: KPICardsProps) {
  return (
    <div className="grid gap-4" style={{ gridTemplateColumns: "repeat(4, 1fr)" }}>
      {cards.map((card, i) => (
        <motion.div
          key={card.title}
          initial={{ opacity: 0, y: 16 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.4, delay: i * 0.08, ease: "easeOut" }}
          whileHover={{ y: -2, boxShadow: "0 8px 24px rgba(0,0,0,0.35)" }}
          onClick={() => onNavigate(card.navigateTo)}
          className="rounded-xl p-5 relative overflow-hidden group"
          style={{
            backgroundColor: "#1a1d27",
            border: "1px solid #2e3039",
            borderLeft: `4px solid ${card.borderColor}`,
            cursor: "pointer",
          }}
        >
          <div className="flex items-start justify-between mb-3">
            <div>
              <p style={{ color: "#64748b", fontSize: 11, fontWeight: 500, marginBottom: 6, textTransform: "uppercase", letterSpacing: "0.05em" }}>
                {card.title}
              </p>
              <p style={{ color: "#f1f5f9", fontSize: 22, fontWeight: 700, letterSpacing: "-0.5px", lineHeight: 1 }}>
                {card.value}
              </p>
            </div>
            <div
              className="flex items-center justify-center rounded-lg flex-shrink-0"
              style={{ width: 38, height: 38, backgroundColor: card.iconBg }}
            >
              <span style={{ color: card.iconColor }}>{card.icon}</span>
            </div>
          </div>

          <p style={{ color: "#475569", fontSize: 12, marginBottom: 8 }}>{card.subtitle}</p>

          {/* Comparison mode delta */}
          {comparisonMode && (
            <motion.div
              initial={{ opacity: 0, height: 0 }}
              animate={{ opacity: 1, height: "auto" }}
              exit={{ opacity: 0, height: 0 }}
              className="flex items-center gap-2 mb-2"
            >
              {comparisonDeltas[i].positive
                ? <TrendingUp size={11} color="#22c55e" />
                : <TrendingDown size={11} color="#ef4444" />
              }
              <span style={{ color: comparisonDeltas[i].positive ? "#22c55e" : "#ef4444", fontSize: 11, fontWeight: 600 }}>
                {comparisonDeltas[i].delta}
              </span>
              <span style={{ color: "#475569", fontSize: 11 }}>vs {comparisonDeltas[i].prev}</span>
            </motion.div>
          )}

          {card.extra(i) && <div className="mt-1">{card.extra(i)}</div>}

          {/* Hover arrow */}
          <div
            className="absolute bottom-4 right-4 opacity-0 group-hover:opacity-100 transition-opacity flex items-center gap-1"
            style={{ color: card.iconColor }}
          >
            <span style={{ fontSize: 10, fontWeight: 600 }}>Ver detalle</span>
            <ArrowRight size={10} />
          </div>
        </motion.div>
      ))}
    </div>
  );
}
