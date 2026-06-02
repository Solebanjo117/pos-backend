import { motion, AnimatePresence } from "motion/react";
import { X, FileText, User, CreditCard, Printer, Download, CheckCircle } from "lucide-react";

export interface Transaction {
  id: string;
  date: string;
  amount: string;
  type: string;
  initials: string;
  color: string;
  client?: string;
}

interface TransactionDrawerProps {
  open: boolean;
  transaction: Transaction | null;
  onClose: () => void;
}

const items = [
  { desc: "Filtro de aceite Toyota Camry", qty: 2, unit: "$12.50", total: "$25.00" },
  { desc: "Pastillas de freno delanteras", qty: 1, unit: "$65.00", total: "$65.00" },
  { desc: "Aceite motor 5W-30 (4L)", qty: 1, unit: "$32.00", total: "$32.00" },
  { desc: "Mano de obra instalación", qty: 1, unit: "$13.00", total: "$13.00" },
];

const typeColors: Record<string, { bg: string; text: string }> = {
  Crédito: { bg: "rgba(249,115,22,0.12)", text: "#f97316" },
  Efectivo: { bg: "rgba(34,197,94,0.12)", text: "#22c55e" },
  Tarjeta: { bg: "rgba(59,130,246,0.12)", text: "#3b82f6" },
  Yappy: { bg: "rgba(139,92,246,0.12)", text: "#8b5cf6" },
};

export function TransactionDrawer({ open, transaction, onClose }: TransactionDrawerProps) {
  const typeStyle = transaction ? (typeColors[transaction.type] ?? { bg: "rgba(100,116,139,0.12)", text: "#64748b" }) : null;

  return (
    <AnimatePresence>
      {open && transaction && (
        <>
          {/* Backdrop */}
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.2 }}
            onClick={onClose}
            style={{
              position: "fixed", inset: 0, backgroundColor: "rgba(0,0,0,0.5)", zIndex: 40,
            }}
          />

          {/* Drawer */}
          <motion.div
            initial={{ x: 360 }}
            animate={{ x: 0 }}
            exit={{ x: 360 }}
            transition={{ duration: 0.3, ease: "easeOut" }}
            style={{
              position: "fixed", top: 0, right: 0, bottom: 0, width: 360,
              backgroundColor: "#1a1d27", borderLeft: "1px solid #2e3039",
              zIndex: 50, display: "flex", flexDirection: "column",
            }}
          >
            {/* Header */}
            <div
              className="flex items-center justify-between px-5 py-4"
              style={{ borderBottom: "1px solid #2e3039" }}
            >
              <div className="flex items-center gap-2">
                <FileText size={16} color="#6366f1" />
                <span style={{ color: "#f1f5f9", fontSize: 15, fontWeight: 600 }}>
                  {transaction.id}
                </span>
              </div>
              <button
                onClick={onClose}
                className="flex items-center justify-center rounded-lg transition-all"
                style={{ width: 32, height: 32, backgroundColor: "#252830", border: "1px solid #2e3039" }}
              >
                <X size={14} color="#64748b" />
              </button>
            </div>

            {/* Content */}
            <div className="flex-1 overflow-y-auto px-5 py-4 flex flex-col gap-5">
              {/* Status badge */}
              <div className="flex items-center gap-2">
                <CheckCircle size={14} color="#22c55e" />
                <span style={{ color: "#22c55e", fontSize: 12, fontWeight: 600 }}>Factura completada</span>
                <span
                  className="ml-auto inline-flex items-center rounded-full px-2"
                  style={{ height: 20, backgroundColor: typeStyle?.bg, color: typeStyle?.text, fontSize: 11, fontWeight: 600 }}
                >
                  {transaction.type}
                </span>
              </div>

              {/* Client info */}
              <div
                className="rounded-xl p-4 flex items-center gap-3"
                style={{ backgroundColor: "#252830", border: "1px solid #2e3039" }}
              >
                <div
                  className="flex items-center justify-center rounded-full"
                  style={{ width: 40, height: 40, backgroundColor: transaction.color + "22", border: `1px solid ${transaction.color}44`, flexShrink: 0 }}
                >
                  <span style={{ color: transaction.color, fontSize: 13, fontWeight: 700 }}>
                    {transaction.initials}
                  </span>
                </div>
                <div>
                  <p style={{ color: "#e2e8f0", fontSize: 13, fontWeight: 600 }}>
                    {transaction.client ?? "Mecánico Rodríguez"}
                  </p>
                  <p style={{ color: "#475569", fontSize: 11 }}>Cliente frecuente · RUC 8-123-456</p>
                </div>
                <User size={14} color="#475569" style={{ marginLeft: "auto" }} />
              </div>

              {/* Meta */}
              <div className="grid gap-2" style={{ gridTemplateColumns: "1fr 1fr" }}>
                {[
                  { label: "Fecha", value: transaction.date },
                  { label: "Sucursal", value: "Sucursal Central" },
                  { label: "Vendedor", value: "Juan Rodríguez" },
                  { label: "Vencimiento", value: "22 Jun 2026" },
                ].map(({ label, value }) => (
                  <div key={label} className="rounded-lg p-3" style={{ backgroundColor: "#252830" }}>
                    <p style={{ color: "#475569", fontSize: 11, marginBottom: 3 }}>{label}</p>
                    <p style={{ color: "#cbd5e1", fontSize: 12, fontWeight: 500 }}>{value}</p>
                  </div>
                ))}
              </div>

              {/* Items */}
              <div>
                <p style={{ color: "#64748b", fontSize: 11, fontWeight: 600, textTransform: "uppercase", letterSpacing: "0.05em", marginBottom: 10 }}>
                  Artículos
                </p>
                <div className="flex flex-col gap-0" style={{ border: "1px solid #2e3039", borderRadius: 10, overflow: "hidden" }}>
                  {items.map((item, i) => (
                    <div
                      key={i}
                      className="flex items-start gap-2 px-3 py-3"
                      style={{ borderBottom: i < items.length - 1 ? "1px solid #2e3039" : "none" }}
                    >
                      <div className="flex-1 min-w-0">
                        <p style={{ color: "#cbd5e1", fontSize: 12, fontWeight: 500 }}>{item.desc}</p>
                        <p style={{ color: "#475569", fontSize: 11, marginTop: 2 }}>
                          {item.qty}× {item.unit}
                        </p>
                      </div>
                      <span style={{ color: "#f1f5f9", fontSize: 12, fontWeight: 700, flexShrink: 0 }}>
                        {item.total}
                      </span>
                    </div>
                  ))}
                </div>
              </div>

              {/* Totals */}
              <div className="rounded-xl p-4 flex flex-col gap-2" style={{ backgroundColor: "#252830" }}>
                {[
                  { label: "Subtotal", value: "$117.65" },
                  { label: "ITBMS (7%)", value: "$8.24" },
                  { label: "Descuento", value: "-$0.00" },
                ].map(({ label, value }) => (
                  <div key={label} className="flex items-center justify-between">
                    <span style={{ color: "#64748b", fontSize: 12 }}>{label}</span>
                    <span style={{ color: "#94a3b8", fontSize: 12 }}>{value}</span>
                  </div>
                ))}
                <div style={{ height: 1, backgroundColor: "#2e3039", margin: "4px 0" }} />
                <div className="flex items-center justify-between">
                  <span style={{ color: "#e2e8f0", fontSize: 14, fontWeight: 700 }}>Total</span>
                  <span style={{ color: "#f1f5f9", fontSize: 14, fontWeight: 700 }}>{transaction.amount}</span>
                </div>
              </div>
            </div>

            {/* Actions */}
            <div
              className="flex items-center gap-2 px-5 py-4"
              style={{ borderTop: "1px solid #2e3039" }}
            >
              <button
                className="flex items-center gap-2 rounded-lg px-3 flex-1 justify-center transition-all"
                style={{ height: 36, backgroundColor: "#252830", border: "1px solid #2e3039", color: "#94a3b8", fontSize: 12, fontWeight: 500 }}
              >
                <Printer size={14} />
                Imprimir
              </button>
              <button
                className="flex items-center gap-2 rounded-lg px-3 flex-1 justify-center transition-all"
                style={{ height: 36, backgroundColor: "#252830", border: "1px solid #2e3039", color: "#94a3b8", fontSize: 12, fontWeight: 500 }}
              >
                <Download size={14} />
                PDF
              </button>
              <button
                className="flex items-center gap-2 rounded-lg px-3 flex-1 justify-center transition-all"
                style={{ height: 36, background: "linear-gradient(135deg,#6366f1,#8b5cf6)", color: "#fff", fontSize: 12, fontWeight: 600 }}
              >
                <CreditCard size={14} />
                Cobrar
              </button>
            </div>
          </motion.div>
        </>
      )}
    </AnimatePresence>
  );
}
