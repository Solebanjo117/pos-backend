import { motion } from "motion/react";
import {
  BarChart,
  Bar,
  Cell,
  XAxis,
  YAxis,
  Tooltip,
  ResponsiveContainer,
  LabelList,
} from "recharts";
import { CreditCard } from "lucide-react";

interface PaymentMethod {
  name: string;
  amount: number;
  percentage: number;
  color: string;
  txns: number;
}

const paymentMethods: PaymentMethod[] = [
  { name: "Efectivo", amount: 18373, percentage: 38, color: "#10b981", txns: 209 },
  { name: "Tarjeta", amount: 15955, percentage: 33, color: "#3b82f6", txns: 181 },
  { name: "Transferencia", amount: 8219, percentage: 17, color: "#8b5cf6", txns: 93 },
  { name: "Crédito", amount: 4835, percentage: 10, color: "#f59e0b", txns: 39 },
  { name: "Yappy", amount: 968, percentage: 2, color: "#06b6d4", txns: 22 },
];

interface CreditRow {
  label: string;
  amount: number;
  type: "normal" | "positive" | "highlight";
}

const creditRows: CreditRow[] = [
  { label: "Créditos Otorgados en el Período", amount: 4835, type: "normal" },
  { label: "Créditos Cobrados / Recuperados", amount: 3125, type: "positive" },
  { label: "Nuevo Saldo Pendiente de Cobro", amount: 1710, type: "highlight" },
];

function fmtAmt(v: number): string {
  return "$" + v.toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

const PaymentTooltip = ({
  active,
  payload,
  label,
}: {
  active?: boolean;
  payload?: Array<{ value: number; payload: PaymentMethod }>;
  label?: string;
}) => {
  if (active && payload?.length) {
    const d = payload[0].payload;
    return (
      <div
        style={{
          background: "#171a26",
          border: "1px solid #252830",
          borderRadius: 8,
          padding: "9px 13px",
          fontSize: 12,
          fontFamily: "inherit",
        }}
      >
        <p style={{ color: "#e2e8f0", fontWeight: 500, marginBottom: 4 }}>{label}</p>
        <p style={{ color: "#94a3b8" }}>
          Monto: <span style={{ color: "#e2e8f0" }}>{fmtAmt(d.amount)}</span>
        </p>
        <p style={{ color: "#94a3b8" }}>
          Transacciones: <span style={{ color: "#e2e8f0" }}>{d.txns}</span>
        </p>
        <p style={{ color: "#94a3b8" }}>
          Participación:{" "}
          <span style={{ color: d.color, fontWeight: 500 }}>{d.percentage}%</span>
        </p>
      </div>
    );
  }
  return null;
};

export function PaymentBreakdown() {
  return (
    <div>
      <div
        style={{
          background: "#0f1117",
          border: "1px solid #1e2130",
          borderRadius: 12,
          overflow: "hidden",
        }}
      >
        {/* Header */}
        <div
          style={{
            padding: "12px 20px",
            borderBottom: "1px solid #1e2130",
            background: "#0d0f1a",
            display: "flex",
            alignItems: "center",
            gap: 10,
          }}
        >
          <CreditCard size={14} style={{ color: "#6366f1" }} />
          <span style={{ color: "#e2e8f0", fontSize: 12.5, fontWeight: 600, fontFamily: "inherit" }}>
            Desglose por Método de Pago
          </span>
          <span
            style={{
              marginLeft: "auto",
              color: "#334155",
              fontSize: 11,
              fontFamily: "inherit",
            }}
          >
            Total: {fmtAmt(48350)} · {paymentMethods.reduce((s, m) => s + m.txns, 0)} transacciones
          </span>
        </div>

        {/* Two-column body */}
        <div style={{ display: "flex", gap: 0 }}>

          {/* LEFT — Bar chart */}
          <div style={{ flex: 1, minWidth: 0, padding: "18px 16px 18px 20px", borderRight: "1px solid #1e2130" }}>
            <p
              style={{
                color: "#334155",
                fontSize: 10,
                fontWeight: 700,
                letterSpacing: "0.1em",
                textTransform: "uppercase",
                fontFamily: "inherit",
                marginBottom: 14,
              }}
            >
              Monto recaudado por canal
            </p>
            {/* Recharts BarChart — amount per method */}
            <ResponsiveContainer width="100%" height={160}>
              <BarChart
                data={paymentMethods}
                margin={{ top: 16, right: 8, bottom: 0, left: 0 }}
                barCategoryGap="30%"
              >
                <XAxis
                  dataKey="name"
                  tick={{ fill: "#6b7280", fontSize: 11, fontFamily: "inherit" }}
                  axisLine={false}
                  tickLine={false}
                />
                <YAxis hide />
                <Tooltip content={<PaymentTooltip />} cursor={{ fill: "rgba(255,255,255,0.03)" }} />
                <Bar dataKey="amount" radius={[3, 3, 0, 0]} maxBarSize={32}>
                  {paymentMethods.map((m, i) => (
                    <Cell key={i} fill={m.color} fillOpacity={0.85} />
                  ))}
                  <LabelList
                    dataKey="amount"
                    position="top"
                    formatter={(v: number) => `$${(v / 1000).toFixed(1)}k`}
                    style={{ fill: "#475569", fontSize: 10, fontFamily: "inherit" }}
                  />
                </Bar>
              </BarChart>
            </ResponsiveContainer>

            {/* Stacked proportion bar */}
            <div style={{ marginTop: 16 }}>
              <p
                style={{
                  color: "#334155",
                  fontSize: 10,
                  fontWeight: 700,
                  letterSpacing: "0.1em",
                  textTransform: "uppercase",
                  fontFamily: "inherit",
                  marginBottom: 8,
                }}
              >
                Proporción del total
              </p>
              <div
                style={{
                  display: "flex",
                  height: 7,
                  borderRadius: 4,
                  overflow: "hidden",
                  gap: 1,
                }}
              >
                {paymentMethods.map((m, i) => (
                  <motion.div
                    key={m.name}
                    initial={{ flexGrow: 0 }}
                    animate={{ flexGrow: m.percentage }}
                    transition={{ duration: 0.8, delay: 0.2 + i * 0.07, ease: "easeOut" }}
                    style={{ background: m.color, borderRadius: 2 }}
                  />
                ))}
              </div>
              <div style={{ display: "flex", gap: 14, flexWrap: "wrap", marginTop: 10 }}>
                {paymentMethods.map((m) => (
                  <div key={m.name} style={{ display: "flex", alignItems: "center", gap: 5 }}>
                    <div
                      style={{
                        width: 8,
                        height: 8,
                        borderRadius: 2,
                        background: m.color,
                        flexShrink: 0,
                      }}
                    />
                    <span style={{ color: "#6b7280", fontSize: 11, fontFamily: "inherit" }}>
                      {m.name}
                    </span>
                    <span style={{ color: "#334155", fontSize: 11, fontFamily: "inherit" }}>
                      {m.percentage}%
                    </span>
                  </div>
                ))}
              </div>
            </div>
          </div>

          {/* RIGHT — Table + credit summary */}
          <div style={{ width: 320, flexShrink: 0, display: "flex", flexDirection: "column" }}>
            {/* Method table */}
            <div style={{ padding: "18px 20px 0" }}>
              <p
                style={{
                  color: "#334155",
                  fontSize: 10,
                  fontWeight: 700,
                  letterSpacing: "0.1em",
                  textTransform: "uppercase",
                  fontFamily: "inherit",
                  marginBottom: 10,
                }}
              >
                Detalle por canal
              </p>
              <table style={{ width: "100%", borderCollapse: "collapse", fontFamily: "inherit" }}>
                <thead>
                  <tr style={{ borderBottom: "1px solid #1e2130" }}>
                    <th style={{ textAlign: "left", paddingBottom: 6, color: "#334155", fontSize: 10, fontWeight: 600, letterSpacing: "0.08em", textTransform: "uppercase" }}>Canal</th>
                    <th style={{ textAlign: "right", paddingBottom: 6, color: "#334155", fontSize: 10, fontWeight: 600, letterSpacing: "0.08em", textTransform: "uppercase" }}>Monto</th>
                    <th style={{ textAlign: "right", paddingBottom: 6, color: "#334155", fontSize: 10, fontWeight: 600, letterSpacing: "0.08em", textTransform: "uppercase" }}>Txn</th>
                  </tr>
                </thead>
                <tbody>
                  {paymentMethods.map((m, i) => (
                    <motion.tr
                      key={m.name}
                      initial={{ opacity: 0 }}
                      animate={{ opacity: 1 }}
                      transition={{ delay: 0.1 + i * 0.05 }}
                      className="hover:bg-white/[0.02] transition-colors"
                    >
                      <td style={{ padding: "7px 0" }}>
                        <div style={{ display: "flex", alignItems: "center", gap: 7 }}>
                          <div
                            style={{
                              width: 8,
                              height: 8,
                              borderRadius: 2,
                              background: m.color,
                              flexShrink: 0,
                            }}
                          />
                          <span style={{ color: "#6b7280", fontSize: 12 }}>{m.name}</span>
                        </div>
                      </td>
                      <td style={{ padding: "7px 0", textAlign: "right", color: "#94a3b8", fontSize: 12, fontVariantNumeric: "tabular-nums" }}>
                        {fmtAmt(m.amount)}
                      </td>
                      <td style={{ padding: "7px 0", textAlign: "right", color: "#475569", fontSize: 12, fontVariantNumeric: "tabular-nums" }}>
                        {m.txns}
                      </td>
                    </motion.tr>
                  ))}
                </tbody>
              </table>
            </div>

            {/* Credit summary */}
            <div
              style={{
                padding: "16px 20px 18px",
                borderTop: "1px solid #1e2130",
                marginTop: "auto",
              }}
            >
              <p
                style={{
                  color: "#334155",
                  fontSize: 10,
                  fontWeight: 700,
                  letterSpacing: "0.1em",
                  textTransform: "uppercase",
                  fontFamily: "inherit",
                  marginBottom: 10,
                }}
              >
                Resumen de Créditos
              </p>
              <div style={{ display: "flex", flexDirection: "column", gap: 5 }}>
                {creditRows.map((row) => (
                  <div
                    key={row.label}
                    style={{
                      display: "flex",
                      alignItems: "center",
                      justifyContent: "space-between",
                      padding: "7px 10px",
                      borderRadius: 7,
                      background:
                        row.type === "highlight"
                          ? "rgba(249,168,37,0.07)"
                          : "rgba(255,255,255,0.02)",
                      border:
                        row.type === "highlight"
                          ? "1px solid rgba(249,168,37,0.2)"
                          : "1px solid transparent",
                    }}
                  >
                    <span
                      style={{
                        color:
                          row.type === "highlight"
                            ? "#fbbf24"
                            : "#6b7280",
                        fontSize: 12,
                        fontFamily: "inherit",
                        fontWeight: row.type === "highlight" ? 500 : 400,
                      }}
                    >
                      {row.label}
                    </span>
                    <span
                      style={{
                        color:
                          row.type === "highlight"
                            ? "#fbbf24"
                            : row.type === "positive"
                            ? "#34d399"
                            : "#94a3b8",
                        fontSize: 12,
                        fontVariantNumeric: "tabular-nums",
                        fontWeight: row.type === "highlight" ? 600 : 500,
                        fontFamily: "inherit",
                        flexShrink: 0,
                        marginLeft: 12,
                      }}
                    >
                      {row.type === "positive" ? "+" : ""}
                      {fmtAmt(row.amount)}
                    </span>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
