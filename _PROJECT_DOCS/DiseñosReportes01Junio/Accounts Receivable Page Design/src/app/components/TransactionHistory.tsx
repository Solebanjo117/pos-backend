import { useState } from "react";
import { motion, AnimatePresence } from "motion/react";
import {
  ArrowDownCircle,
  ArrowUpCircle,
  ChevronDown,
  Banknote,
  CreditCard,
  ArrowLeftRight,
  Smartphone,
  CheckCircle,
  Clock,
  Hash,
} from "lucide-react";

interface Transaction {
  id: string;
  date: string;
  type: "credit" | "payment";
  description: string;
  client: string;
  invoice: string;
  amount: number;
  method?: string;
  processedBy: string;
  timestamp: string;
  status: "Completado" | "Pendiente" | "Procesando";
  ref: string;
}

const txs: Transaction[] = [
  {
    id: "tx-001",
    date: "27 May 2026",
    type: "payment",
    description: "Pago recibido",
    client: "Taller Hermanos García",
    invoice: "FACT-222110",
    amount: 600.00,
    method: "Transferencia",
    processedBy: "Admin",
    timestamp: "14:32:08",
    status: "Completado",
    ref: "TRF-88421",
  },
  {
    id: "tx-002",
    date: "24 May 2026",
    type: "credit",
    description: "Crédito otorgado",
    client: "Taller Hermanos García",
    invoice: "FACT-222104",
    amount: 135.00,
    processedBy: "Cajero #2",
    timestamp: "09:15:44",
    status: "Completado",
    ref: "CRD-77304",
  },
  {
    id: "tx-003",
    date: "24 May 2026",
    type: "payment",
    description: "Pago recibido",
    client: "Taller Hermanos García",
    invoice: "FACT-222104",
    amount: 135.00,
    method: "Efectivo + Yappy",
    processedBy: "Cajero #2",
    timestamp: "16:04:22",
    status: "Completado",
    ref: "PAY-55219",
  },
  {
    id: "tx-004",
    date: "22 May 2026",
    type: "credit",
    description: "Crédito otorgado",
    client: "Taller Hermanos García",
    invoice: "FACT-222098",
    amount: 280.00,
    processedBy: "Admin",
    timestamp: "11:08:55",
    status: "Completado",
    ref: "CRD-77201",
  },
  {
    id: "tx-005",
    date: "21 May 2026",
    type: "payment",
    description: "Pago recibido",
    client: "Cliente Frecuente #12",
    invoice: "FACT-222091",
    amount: 400.00,
    method: "Transferencia",
    processedBy: "Admin",
    timestamp: "13:45:17",
    status: "Completado",
    ref: "TRF-88301",
  },
  {
    id: "tx-006",
    date: "20 May 2026",
    type: "credit",
    description: "Crédito otorgado",
    client: "Auto Repuestos JM",
    invoice: "FACT-222089",
    amount: 600.00,
    processedBy: "Cajero #1",
    timestamp: "10:20:03",
    status: "Completado",
    ref: "CRD-77198",
  },
  {
    id: "tx-007",
    date: "18 May 2026",
    type: "payment",
    description: "Pago recibido",
    client: "Distribuidora Central",
    invoice: "FACT-222076",
    amount: 1200.00,
    method: "Tarjeta",
    processedBy: "Admin",
    timestamp: "15:50:29",
    status: "Completado",
    ref: "PAY-55102",
  },
];

function groupByDate(txs: Transaction[]) {
  const map = new Map<string, Transaction[]>();
  txs.forEach((t) => {
    if (!map.has(t.date)) map.set(t.date, []);
    map.get(t.date)!.push(t);
  });
  return Array.from(map.entries()).map(([date, items]) => ({ date, items }));
}

function MethodIcon({ method }: { method?: string }) {
  if (!method) return null;
  const m = method.toLowerCase();
  const icon =
    m.includes("tarjeta") ? <CreditCard size={11} /> :
    m.includes("transfer") ? <ArrowLeftRight size={11} /> :
    m.includes("yappy") ? <Smartphone size={11} /> :
    <Banknote size={11} />;
  return (
    <span
      className="inline-flex items-center gap-1 rounded px-1.5 py-0.5"
      style={{ background: "#1a1d2e", color: "#7880a0", fontSize: 10 }}
    >
      {icon}
      {method}
    </span>
  );
}

function StatusChip({ status }: { status: Transaction["status"] }) {
  const cfg = {
    Completado: { color: "#4ade80", bg: "#22c55e0e", icon: <CheckCircle size={10} /> },
    Pendiente: { color: "#fbbf24", bg: "#f59e0b0e", icon: <Clock size={10} /> },
    Procesando: { color: "#60a5fa", bg: "#3b82f60e", icon: <Clock size={10} /> },
  }[status];
  return (
    <span
      className="inline-flex items-center gap-1 rounded px-1.5 py-0.5"
      style={{ background: cfg.bg, color: cfg.color, fontSize: 10, fontWeight: 600 }}
    >
      {cfg.icon}
      {status}
    </span>
  );
}

function TxRow({ tx, index }: { tx: Transaction; index: number }) {
  const [expanded, setExpanded] = useState(false);
  const isCredit = tx.type === "credit";
  const accentColor = isCredit ? "#f97316" : "#22c55e";

  return (
    <motion.div
      initial={{ opacity: 0, x: -10 }}
      animate={{ opacity: 1, x: 0 }}
      transition={{ duration: 0.22, delay: index * 0.05 }}
    >
      <div
        className="flex items-center gap-3 py-2.5 px-3 rounded-lg cursor-pointer group transition-colors hover:bg-white/[0.018]"
        onClick={() => setExpanded(!expanded)}
      >
        {/* Icon */}
        <div
          className="flex items-center justify-center rounded-full flex-shrink-0"
          style={{
            width: 28,
            height: 28,
            background: `${accentColor}12`,
            border: `1px solid ${accentColor}20`,
          }}
        >
          {isCredit
            ? <ArrowDownCircle size={13} style={{ color: accentColor }} />
            : <ArrowUpCircle size={13} style={{ color: accentColor }} />}
        </div>

        {/* Main info */}
        <div style={{ flex: 1, minWidth: 0 }}>
          <div className="flex items-center gap-2">
            <span style={{ color: "#dde0ee", fontSize: 12.5, fontWeight: 500 }}>
              {tx.description} — {tx.invoice}
            </span>
            <span style={{ color: "#434865", fontSize: 12 }}>·</span>
            <span style={{ color: "#7880a0", fontSize: 12 }}>{tx.client}</span>
          </div>
          <div className="flex items-center gap-2 mt-0.5">
            <span
              className="inline-flex items-center gap-1"
              style={{ color: "#323654", fontSize: 10, fontVariantNumeric: "tabular-nums" }}
            >
              <Hash size={9} />
              {tx.ref}
            </span>
            {tx.method && <MethodIcon method={tx.method} />}
            <StatusChip status={tx.status} />
          </div>
        </div>

        {/* Amount + expand */}
        <div className="flex items-center gap-3 flex-shrink-0">
          <div className="text-right">
            <div
              style={{
                color: accentColor,
                fontSize: 13,
                fontWeight: 700,
                fontVariantNumeric: "tabular-nums",
              }}
            >
              {isCredit ? "+" : "−"}${tx.amount.toFixed(2)}
            </div>
            <div style={{ color: "#323654", fontSize: 10, marginTop: 1 }}>{tx.timestamp}</div>
          </div>
          <motion.div
            animate={{ rotate: expanded ? 180 : 0 }}
            transition={{ duration: 0.2 }}
          >
            <ChevronDown size={13} style={{ color: "#323654" }} />
          </motion.div>
        </div>
      </div>

      {/* Expanded audit row */}
      <AnimatePresence>
        {expanded && (
          <motion.div
            initial={{ height: 0, opacity: 0 }}
            animate={{ height: "auto", opacity: 1 }}
            exit={{ height: 0, opacity: 0 }}
            transition={{ duration: 0.2 }}
            style={{ overflow: "hidden" }}
          >
            <div
              className="mx-3 mb-2 rounded-lg px-4 py-3"
              style={{ background: "#0e1019", border: "1px solid #1a1d2e" }}
            >
              <div style={{ color: "#434865", fontSize: 10, fontWeight: 600, textTransform: "uppercase", letterSpacing: "0.05em", marginBottom: 8 }}>
                Detalles del movimiento
              </div>
              <div className="grid grid-cols-4 gap-4">
                {[
                  ["Referencia", tx.ref],
                  ["Procesado por", tx.processedBy],
                  ["Fecha/Hora", `${tx.date} ${tx.timestamp}`],
                  ["Método", tx.method || "—"],
                  ["Factura", tx.invoice],
                  ["Cliente", tx.client],
                  ["Tipo", isCredit ? "Crédito otorgado" : "Cobro recibido"],
                  ["Estado", tx.status],
                ].map(([k, v]) => (
                  <div key={k}>
                    <div style={{ color: "#323654", fontSize: 10, marginBottom: 2, textTransform: "uppercase", letterSpacing: "0.03em" }}>{k}</div>
                    <div style={{ color: "#7880a0", fontSize: 11, fontWeight: 500, fontVariantNumeric: "tabular-nums" }}>{v}</div>
                  </div>
                ))}
              </div>
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </motion.div>
  );
}

export function TransactionHistory() {
  const groups = groupByDate(txs);
  let globalIdx = 0;

  return (
    <div className="rounded-lg" style={{ background: "#111420", border: "1px solid #1e2132" }}>
      {/* Header */}
      <div
        className="flex items-center justify-between px-4 py-3"
        style={{ borderBottom: "1px solid #1a1d2e" }}
      >
        <div>
          <div style={{ color: "#dde0ee", fontSize: 14, fontWeight: 600 }}>
            Historial de Movimientos
          </div>
          <div style={{ color: "#4a506a", fontSize: 11, marginTop: 1 }}>
            {txs.length} transacciones · Registro de auditoría
          </div>
        </div>
        <div className="flex items-center gap-4">
          <div className="flex items-center gap-1.5">
            <div className="rounded-full" style={{ width: 6, height: 6, background: "#22c55e" }} />
            <span style={{ color: "#434865", fontSize: 11 }}>
              {txs.filter((t) => t.type === "payment").length} cobros
            </span>
          </div>
          <div className="flex items-center gap-1.5">
            <div className="rounded-full" style={{ width: 6, height: 6, background: "#f97316" }} />
            <span style={{ color: "#434865", fontSize: 11 }}>
              {txs.filter((t) => t.type === "credit").length} créditos
            </span>
          </div>
        </div>
      </div>

      <div className="px-3 py-3">
        {groups.map(({ date, items }) => (
          <div key={date}>
            {/* Sticky date separator */}
            <div className="flex items-center gap-3 py-2 px-1">
              <div
                className="rounded px-2 py-0.5"
                style={{
                  background: "#0e1019",
                  color: "#434865",
                  fontSize: 10,
                  fontWeight: 600,
                  border: "1px solid #1a1d2e",
                  letterSpacing: "0.03em",
                }}
              >
                {date}
              </div>
              <div style={{ flex: 1, height: 1, background: "#1a1d2e" }} />
              <span style={{ color: "#323654", fontSize: 10 }}>
                {items.length} mov.
              </span>
            </div>

            {/* Transactions */}
            {items.map((tx) => {
              const idx = globalIdx++;
              return <TxRow key={tx.id} tx={tx} index={idx} />;
            })}
          </div>
        ))}
      </div>
    </div>
  );
}
