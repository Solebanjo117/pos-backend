import { motion } from "motion/react";
import { Scale } from "lucide-react";

const assets = [
  { label: "Efectivo en Caja", value: "$8,420.00", group: "Activos Corrientes" },
  { label: "Cuentas por Cobrar", value: "$12,480.00", group: "Activos Corrientes" },
  { label: "Inventario", value: "$34,200.00", group: "Activos Corrientes" },
  { label: "Equipos y Herramientas", value: "$18,500.00", group: "Activos Fijos" },
  { label: "Mobiliario", value: "$4,200.00", group: "Activos Fijos" },
];

const liabilities = [
  { label: "Cuentas por Pagar", value: "$9,800.00", group: "Pasivos Corrientes" },
  { label: "Préstamo Bancario", value: "$15,000.00", group: "Pasivos a Largo Plazo" },
  { label: "Capital Social", value: "$34,000.00", group: "Patrimonio" },
  { label: "Utilidades Retenidas", value: "$19,000.00", group: "Patrimonio" },
];

function Section({ title, rows, color }: { title: string; rows: typeof assets; color: string }) {
  const groups = [...new Set(rows.map((r) => r.group))];
  return (
    <div className="rounded-xl overflow-hidden" style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039" }}>
      <div className="px-5 py-4" style={{ borderBottom: "1px solid #2e3039", borderLeft: `4px solid ${color}` }}>
        <h3 style={{ color: "#f1f5f9", fontSize: 15, fontWeight: 600 }}>{title}</h3>
      </div>
      {groups.map((group) => (
        <div key={group}>
          <div className="px-5 py-2" style={{ backgroundColor: "#252830" }}>
            <span style={{ color: "#475569", fontSize: 11, fontWeight: 600, textTransform: "uppercase", letterSpacing: "0.05em" }}>{group}</span>
          </div>
          {rows.filter((r) => r.group === group).map((row, i, arr) => (
            <div
              key={row.label}
              className="flex items-center justify-between px-5 py-3"
              style={{ borderBottom: i < arr.length - 1 || groups.indexOf(group) < groups.length - 1 ? "1px solid #2e3039" : "none" }}
            >
              <span style={{ color: "#94a3b8", fontSize: 12 }}>{row.label}</span>
              <span style={{ color: "#f1f5f9", fontSize: 12, fontWeight: 600 }}>{row.value}</span>
            </div>
          ))}
        </div>
      ))}
      <div
        className="flex items-center justify-between px-5 py-3"
        style={{ borderTop: "1px solid #2e3039", backgroundColor: "rgba(99,102,241,0.04)" }}
      >
        <span style={{ color: "#e2e8f0", fontSize: 13, fontWeight: 700 }}>Total {title}</span>
        <span style={{ color, fontSize: 13, fontWeight: 700 }}>
          ${rows.reduce((acc, r) => acc + parseFloat(r.value.replace(/[$,]/g, "")), 0).toLocaleString("en-US", { minimumFractionDigits: 2 })}
        </span>
      </div>
    </div>
  );
}

export function PageBalanceGeneral() {
  return (
    <motion.div
      initial={{ opacity: 0, x: 24 }}
      animate={{ opacity: 1, x: 0 }}
      transition={{ duration: 0.3, ease: "easeOut" }}
      className="flex flex-col gap-6"
      style={{ padding: 24, paddingTop: 88 }}
    >
      <div className="flex items-center gap-3">
        <div className="flex items-center justify-center rounded-xl" style={{ width: 44, height: 44, background: "rgba(99,102,241,0.12)", border: "1px solid rgba(99,102,241,0.2)" }}>
          <Scale size={22} color="#6366f1" />
        </div>
        <div>
          <h1 style={{ color: "#f1f5f9", fontWeight: 700, letterSpacing: "-0.5px", lineHeight: 1.2 }}>Balance General</h1>
          <p style={{ color: "#475569", fontSize: 13, marginTop: 3 }}>Al 26 de Mayo de 2026</p>
        </div>
      </div>

      <div className="grid gap-6" style={{ gridTemplateColumns: "1fr 1fr" }}>
        <Section title="Activos" rows={assets} color="#3b82f6" />
        <Section title="Pasivos y Patrimonio" rows={liabilities} color="#8b5cf6" />
      </div>

      <div className="rounded-xl p-5 flex items-center justify-between" style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039", borderLeft: "4px solid #22c55e" }}>
        <span style={{ color: "#e2e8f0", fontSize: 15, fontWeight: 700 }}>Patrimonio Neto</span>
        <span style={{ color: "#22c55e", fontSize: 18, fontWeight: 700 }}>$77,800.00</span>
      </div>
    </motion.div>
  );
}
