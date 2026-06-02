import { useEffect, useState } from "react";
import { motion } from "motion/react";
import { Wallet, TrendingUp, CheckCircle, AlertTriangle, TrendingDown } from "lucide-react";
import { SparkLine } from "./SparkLine";

const fmt = (n: number) =>
  new Intl.NumberFormat("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);

function useCountUp(target: number, duration = 1100) {
  const [v, setV] = useState(0);
  useEffect(() => {
    const start = Date.now();
    const tick = () => {
      const p = Math.min((Date.now() - start) / duration, 1);
      const e = 1 - Math.pow(1 - p, 3);
      setV(e * target);
      if (p < 1) requestAnimationFrame(tick);
      else setV(target);
    };
    requestAnimationFrame(tick);
  }, [target, duration]);
  return v;
}

interface KPIConfig {
  id: string;
  title: string;
  value: number;
  prevValue: number;
  subtitle: string;
  icon: React.ReactNode;
  accentColor: string;
  sparkData: number[];
  positiveUp: boolean;
  description: string;
}

const cards: KPIConfig[] = [
  {
    id: "receivable",
    title: "Total por Cobrar",
    value: 12480,
    prevValue: 11842,
    subtitle: "23 clientes activos",
    icon: <Wallet size={15} />,
    accentColor: "#f97316",
    sparkData: [10.2, 10.8, 11.1, 11.5, 11.9, 12.1, 12.3, 12.48],
    positiveUp: true,
    description: "Saldo pendiente total",
  },
  {
    id: "credits",
    title: "Créditos Otorgados",
    value: 8450,
    prevValue: 7200,
    subtitle: "15 operaciones / mes",
    icon: <TrendingUp size={15} />,
    accentColor: "#3b82f6",
    sparkData: [6.2, 7.1, 6.8, 7.5, 7.8, 8.1, 8.3, 8.45],
    positiveUp: true,
    description: "Crédito extendido este mes",
  },
  {
    id: "collections",
    title: "Cobros del Mes",
    value: 5200,
    prevValue: 4850,
    subtitle: "12 pagos procesados",
    icon: <CheckCircle size={15} />,
    accentColor: "#22c55e",
    sparkData: [3.8, 4.2, 4.5, 4.8, 4.9, 5.0, 5.1, 5.2],
    positiveUp: true,
    description: "Recaudación acumulada",
  },
  {
    id: "overdue",
    title: "Vencidos +90 días",
    value: 800,
    prevValue: 950,
    subtitle: "2 cuentas en riesgo",
    icon: <AlertTriangle size={15} />,
    accentColor: "#ef4444",
    sparkData: [1.1, 1.0, 0.98, 0.95, 0.9, 0.88, 0.84, 0.8],
    positiveUp: false,
    description: "Requiere acción inmediata",
  },
];

interface KPICardProps extends KPIConfig {
  index: number;
}

function KPICard({ title, value, prevValue, subtitle, icon, accentColor, sparkData, positiveUp, description, id, index }: KPICardProps) {
  const animated = useCountUp(value, 1100 + index * 80);
  const delta = value - prevValue;
  const pct = Math.abs((delta / prevValue) * 100).toFixed(1);
  const isPositive = positiveUp ? delta >= 0 : delta <= 0;
  const trendColor = isPositive ? "#22c55e" : "#ef4444";
  const TrendIcon = delta >= 0 ? TrendingUp : TrendingDown;

  return (
    <motion.div
      initial={{ opacity: 0, y: 12 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.3, delay: index * 0.07 }}
      className="rounded-lg p-4 flex flex-col gap-0 relative"
      style={{
        background: "#111420",
        border: "1px solid #1e2132",
        borderTop: `2px solid ${accentColor}50`,
      }}
    >
      {/* Header row */}
      <div className="flex items-start justify-between mb-3">
        <div className="flex items-center gap-2">
          <div
            className="flex items-center justify-center rounded-md"
            style={{
              width: 28,
              height: 28,
              background: `${accentColor}12`,
              color: accentColor,
            }}
          >
            {icon}
          </div>
          <span
            style={{
              color: "#7880a0",
              fontSize: 11,
              fontWeight: 600,
              letterSpacing: "0.04em",
              textTransform: "uppercase",
            }}
          >
            {title}
          </span>
        </div>
        <SparkLine data={sparkData} color={accentColor} width={72} height={26} />
      </div>

      {/* Main value */}
      <div
        style={{
          color: "#dde0ee",
          fontSize: 24,
          fontWeight: 700,
          letterSpacing: "-0.02em",
          lineHeight: 1,
          fontVariantNumeric: "tabular-nums",
          marginBottom: 8,
        }}
      >
        ${fmt(animated)}
      </div>

      {/* Trend */}
      <div className="flex items-center gap-2 mb-2">
        <div
          className="flex items-center gap-1 rounded px-1.5 py-0.5"
          style={{
            background: `${trendColor}12`,
            border: `1px solid ${trendColor}20`,
          }}
        >
          <TrendIcon size={10} style={{ color: trendColor }} />
          <span style={{ color: trendColor, fontSize: 11, fontWeight: 600 }}>{pct}%</span>
        </div>
        <span style={{ color: "#434865", fontSize: 11 }}>
          vs ${fmt(prevValue)} mes ant.
        </span>
      </div>

      {/* Footer */}
      <div className="flex items-center justify-between" style={{ borderTop: "1px solid #1a1d2e", paddingTop: 8, marginTop: 2 }}>
        <span style={{ color: "#4a506a", fontSize: 11 }}>{subtitle}</span>
        {id === "overdue" && (
          <motion.div
            animate={{ opacity: [0.4, 1, 0.4] }}
            transition={{ duration: 2.4, repeat: Infinity, ease: "easeInOut" }}
            className="flex items-center gap-1"
          >
            <div className="rounded-full" style={{ width: 5, height: 5, background: "#ef4444" }} />
            <span style={{ color: "#ef4444", fontSize: 10, fontWeight: 600 }}>URGENTE</span>
          </motion.div>
        )}
        {id !== "overdue" && (
          <span style={{ color: "#434865", fontSize: 10, fontWeight: 500 }}>{description}</span>
        )}
      </div>
    </motion.div>
  );
}

export function KPICards() {
  return (
    <div className="grid grid-cols-4 gap-3">
      {cards.map((card, i) => (
        <KPICard key={card.id} {...card} index={i} />
      ))}
    </div>
  );
}
