import React from "react";
import { motion } from "motion/react";
import { FileText, CheckCircle2, Clock, AlertCircle } from "lucide-react";
import { fmt } from "./utils";

type CreditStatus = "paid" | "partial" | "pending" | "overdue";

interface CreditRow {
  auditRef: string;
  invoice: string;
  client: string;
  dueDate: string;
  total: number;
  paidToday: number;
  balance: number;
  paymentMethod: string;
  status: CreditStatus;
}

const ROWS: CreditRow[] = [
  {
    auditRef: "AUD-2026-0841",
    invoice: "FACT #222104",
    client: "Taller Hermanos",
    dueDate: "26/05/2026",
    total: 135.0,
    paidToday: 135.0,
    balance: 0.0,
    paymentMethod: "Cash $100 + Yappy $35",
    status: "paid",
  },
  {
    auditRef: "AUD-2026-0842",
    invoice: "FACT #222443",
    client: "Cliente Frecuente #12",
    dueDate: "02/06/2026",
    total: 55.64,
    paidToday: 5.64,
    balance: 50.0,
    paymentMethod: "Cash",
    status: "partial",
  },
];

const STATUS_MAP: Record<
  CreditStatus,
  { label: string; color: string; bg: string; border: string; Icon: React.ElementType }
> = {
  paid: {
    label: "Saldado",
    color: "#4ade80",
    bg: "rgba(5,46,22,0.5)",
    border: "#15803d",
    Icon: CheckCircle2,
  },
  partial: {
    label: "Parcial",
    color: "#fbbf24",
    bg: "rgba(78,38,4,0.5)",
    border: "#b45309",
    Icon: Clock,
  },
  pending: {
    label: "Pendiente",
    color: "#fb923c",
    bg: "rgba(67,20,7,0.5)",
    border: "#9a3412",
    Icon: Clock,
  },
  overdue: {
    label: "Vencido",
    color: "#f87171",
    bg: "rgba(69,10,10,0.5)",
    border: "#991b1b",
    Icon: AlertCircle,
  },
};

function StatusChip({ status }: { status: CreditStatus }) {
  const s = STATUS_MAP[status];
  const { Icon } = s;
  return (
    <span
      className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md"
      style={{
        background: s.bg,
        border: `1px solid ${s.border}`,
        color: s.color,
        fontSize: 10,
        fontWeight: 600,
        letterSpacing: "0.04em",
        whiteSpace: "nowrap",
      }}
    >
      <Icon size={9} />
      {s.label}
    </span>
  );
}

export function CreditTable() {
  const totalCredit = ROWS.reduce((s, r) => s + r.total, 0);
  const totalPaid = ROWS.reduce((s, r) => s + r.paidToday, 0);
  const totalBalance = ROWS.reduce((s, r) => s + r.balance, 0);

  return (
    <motion.div
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.38, delay: 0.15 }}
      className="rounded-xl border border-[#2e3039] overflow-hidden"
      style={{ background: "#1a1d27" }}
    >
      {/* Header */}
      <div className="flex items-center justify-between px-5 py-3.5 border-b border-[#2e3039]">
        <div className="flex items-center gap-2.5">
          <div
            className="w-7 h-7 rounded-lg flex items-center justify-center"
            style={{ background: "rgba(249,115,22,0.1)" }}
          >
            <FileText size={14} style={{ color: "#f97316" }} />
          </div>
          <span
            style={{
              color: "#e2e8f0",
              fontWeight: 600,
              fontSize: 12,
              letterSpacing: "0.05em",
            }}
          >
            DETALLE DE CRÉDITOS DEL DÍA
          </span>
        </div>
        <div
          style={{
            color: "#6b7280",
            fontSize: 11,
          }}
        >
          {ROWS.length} operaciones
        </div>
      </div>

      {/* Table */}
      <div className="overflow-x-auto">
        <table className="w-full">
          {/* Table head */}
          <thead>
            <tr style={{ borderBottom: "1px solid #2e3039" }}>
              {[
                { label: "Ref. Auditoría", align: "left" as const, pl: 20 },
                { label: "Factura", align: "left" as const },
                { label: "Cliente", align: "left" as const },
                { label: "Vence", align: "left" as const },
                { label: "Monto", align: "right" as const },
                { label: "Pagado Hoy", align: "right" as const },
                { label: "Método", align: "left" as const },
                { label: "Saldo", align: "right" as const },
                { label: "Estado", align: "center" as const, pr: 20 },
              ].map((col) => (
                <th
                  key={col.label}
                  className="py-2.5 whitespace-nowrap"
                  style={{
                    textAlign: col.align,
                    color: "#4b5563",
                    fontSize: 9.5,
                    fontWeight: 600,
                    letterSpacing: "0.08em",
                    textTransform: "uppercase",
                    paddingLeft: col.pl ?? 12,
                    paddingRight: col.pr ?? 12,
                  }}
                >
                  {col.label}
                </th>
              ))}
            </tr>
          </thead>

          {/* Table body */}
          <tbody>
            {ROWS.map((row, i) => (
              <motion.tr
                key={row.auditRef}
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                transition={{ duration: 0.25, delay: 0.2 + i * 0.07 }}
                style={{
                  borderBottom: i < ROWS.length - 1 ? "1px solid #252830" : undefined,
                }}
                onMouseEnter={(e) => (e.currentTarget.style.background = "rgba(37,40,48,0.5)")}
                onMouseLeave={(e) => (e.currentTarget.style.background = "transparent")}
              >
                {/* Audit ref */}
                <td
                  className="py-3 pl-5 pr-3"
                  style={{ color: "#4b5563", fontSize: 10, fontVariantNumeric: "tabular-nums", whiteSpace: "nowrap" }}
                >
                  {row.auditRef}
                </td>

                {/* Invoice */}
                <td
                  className="py-3 pr-3"
                  style={{ color: "#818cf8", fontSize: 12, fontWeight: 500, whiteSpace: "nowrap" }}
                >
                  {row.invoice}
                </td>

                {/* Client */}
                <td
                  className="py-3 pr-3"
                  style={{ color: "#d1d5db", fontSize: 12, whiteSpace: "nowrap" }}
                >
                  {row.client}
                </td>

                {/* Due date */}
                <td
                  className="py-3 pr-3"
                  style={{ color: "#6b7280", fontSize: 11, whiteSpace: "nowrap" }}
                >
                  {row.dueDate}
                </td>

                {/* Total */}
                <td
                  className="py-3 pr-3 text-right"
                  style={{
                    color: "#d1d5db",
                    fontSize: 12,
                    fontVariantNumeric: "tabular-nums",
                    fontWeight: 500,
                    whiteSpace: "nowrap",
                  }}
                >
                  ${fmt(row.total)}
                </td>

                {/* Paid today */}
                <td
                  className="py-3 pr-3 text-right"
                  style={{
                    color: "#22c55e",
                    fontSize: 12,
                    fontVariantNumeric: "tabular-nums",
                    fontWeight: 600,
                    whiteSpace: "nowrap",
                  }}
                >
                  ${fmt(row.paidToday)}
                </td>

                {/* Payment method */}
                <td
                  className="py-3 pr-3"
                  style={{ color: "#9ca3af", fontSize: 11, whiteSpace: "nowrap" }}
                >
                  {row.paymentMethod}
                </td>

                {/* Balance */}
                <td
                  className="py-3 pr-3 text-right"
                  style={{ whiteSpace: "nowrap" }}
                >
                  <span
                    style={{
                      color: row.balance === 0 ? "#22c55e" : "#f97316",
                      fontSize: 12,
                      fontVariantNumeric: "tabular-nums",
                      fontWeight: 700,
                    }}
                  >
                    ${fmt(row.balance)}
                  </span>
                </td>

                {/* Status */}
                <td className="py-3 pr-5 text-center">
                  <StatusChip status={row.status} />
                </td>
              </motion.tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Summary strip */}
      <motion.div
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        transition={{ delay: 0.38 }}
        className="flex items-center gap-0 border-t border-[#2e3039]"
        style={{ background: "rgba(37,40,48,0.4)" }}
      >
        {[
          {
            label: "Total Crédito Otorgado",
            value: `$${fmt(totalCredit)}`,
            color: "#d1d5db",
          },
          {
            label: "Cobrado Hoy",
            value: `$${fmt(totalPaid)}`,
            color: "#22c55e",
          },
          {
            label: "Saldo Pendiente",
            value: `$${fmt(totalBalance)}`,
            color: totalBalance > 0 ? "#f97316" : "#22c55e",
          },
        ].map((item, i) => (
          <div
            key={item.label}
            className="flex-1 px-5 py-3 flex items-center justify-between"
            style={{
              borderRight: i < 2 ? "1px solid #2e3039" : undefined,
            }}
          >
            <span style={{ color: "#6b7280", fontSize: 11 }}>{item.label}</span>
            <span
              style={{
                color: item.color,
                fontSize: 13,
                fontWeight: 700,
                fontVariantNumeric: "tabular-nums",
              }}
            >
              {item.value}
            </span>
          </div>
        ))}
      </motion.div>
    </motion.div>
  );
}
