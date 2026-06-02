import { motion, AnimatePresence } from "motion/react";
import { X, Banknote, CreditCard, ArrowLeftRight, Smartphone, CheckCircle2, AlertCircle } from "lucide-react";
import { useState, useEffect } from "react";

export interface Client {
  name: string;
  pending: number;
  initials: string;
  avatarColor: string;
}

interface PaymentModalProps {
  client: Client | null;
  onClose: () => void;
  onConfirm: (data: { amount: string; method: string; reference: string; date: string; note: string }) => void;
}

const methods = [
  { id: "Efectivo", label: "Efectivo", icon: <Banknote size={18} /> },
  { id: "Tarjeta", label: "Tarjeta", icon: <CreditCard size={18} /> },
  { id: "Transferencia", label: "Transfer.", icon: <ArrowLeftRight size={18} /> },
  { id: "Yappy", label: "Yappy", icon: <Smartphone size={18} /> },
];

const fmt2 = (n: number) =>
  new Intl.NumberFormat("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);

export function PaymentModal({ client, onClose, onConfirm }: PaymentModalProps) {
  const [rawAmount, setRawAmount] = useState("");
  const [method, setMethod] = useState("Efectivo");
  const [reference, setReference] = useState("");
  const [date, setDate] = useState(new Date().toISOString().split("T")[0]);
  const [note, setNote] = useState("");

  useEffect(() => {
    if (client) setRawAmount(client.pending.toFixed(2));
  }, [client]);

  const numAmount = parseFloat(rawAmount) || 0;
  const remaining = client ? client.pending - numAmount : 0;
  const isPartial = remaining > 0.005;
  const isOverpay = remaining < -0.005;
  const isExact = !isPartial && !isOverpay && numAmount > 0;

  const statusColor = isOverpay ? "#ef4444" : isPartial ? "#f59e0b" : "#22c55e";
  const statusLabel = isOverpay
    ? "Excede el saldo pendiente"
    : isPartial
    ? `Pago parcial — resta $${fmt2(Math.abs(remaining))}`
    : numAmount > 0
    ? "Pago completo"
    : "";

  const inputBase: React.CSSProperties = {
    background: "#0e1019",
    border: "1px solid #1e2132",
    borderRadius: 6,
    color: "#dde0ee",
    outline: "none",
    width: "100%",
    fontSize: 13,
    padding: "8px 10px",
  };

  const labelStyle: React.CSSProperties = {
    color: "#7880a0",
    fontSize: 11,
    fontWeight: 600,
    letterSpacing: "0.04em",
    textTransform: "uppercase" as const,
    marginBottom: 6,
    display: "block",
  };

  return (
    <AnimatePresence>
      {client && (
        <motion.div
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          transition={{ duration: 0.18 }}
          className="fixed inset-0 flex items-center justify-center z-50"
          style={{ background: "rgba(6,8,16,0.78)", backdropFilter: "blur(12px)" }}
          onClick={(e) => e.target === e.currentTarget && onClose()}
        >
          <motion.div
            initial={{ scale: 0.96, opacity: 0, y: 8 }}
            animate={{ scale: 1, opacity: 1, y: 0 }}
            exit={{ scale: 0.96, opacity: 0, y: 8 }}
            transition={{ duration: 0.2, ease: [0.16, 1, 0.3, 1] }}
            style={{
              width: "100%",
              maxWidth: 460,
              background: "#111420",
              borderRadius: 12,
              border: "1px solid #252840",
              boxShadow: "0 24px 64px rgba(0,0,0,0.6), 0 0 0 1px rgba(255,255,255,0.04)",
              overflow: "hidden",
              margin: "0 16px",
            }}
          >
            {/* Header */}
            <div
              className="flex items-center justify-between px-5 py-4"
              style={{ borderBottom: "1px solid #1a1d2e" }}
            >
              <div className="flex items-center gap-3">
                <div
                  className="flex items-center justify-center rounded-md"
                  style={{
                    width: 32,
                    height: 32,
                    background: `${client.avatarColor}18`,
                    color: client.avatarColor,
                    fontSize: 12,
                    fontWeight: 700,
                    border: `1px solid ${client.avatarColor}20`,
                  }}
                >
                  {client.initials}
                </div>
                <div>
                  <div style={{ color: "#dde0ee", fontSize: 14, fontWeight: 600 }}>Registrar Pago</div>
                  <div style={{ color: "#6366f1", fontSize: 12, marginTop: 1 }}>{client.name}</div>
                </div>
              </div>
              <button
                onClick={onClose}
                className="flex items-center justify-center rounded-md transition-colors hover:bg-white/5"
                style={{ width: 28, height: 28, color: "#434865" }}
              >
                <X size={15} />
              </button>
            </div>

            <div className="px-5 py-4 flex flex-col gap-4">
              {/* Amount input */}
              <div>
                <label style={labelStyle}>Monto a Pagar</label>
                <div
                  className="flex items-center rounded-lg overflow-hidden"
                  style={{
                    background: "#0e1019",
                    border: `1px solid ${numAmount > 0 ? (isOverpay ? "#ef444460" : isPartial ? "#f59e0b40" : "#22c55e40") : "#1e2132"}`,
                    transition: "border-color 200ms",
                  }}
                >
                  <span
                    style={{
                      color: "#434865",
                      fontSize: 20,
                      fontWeight: 300,
                      paddingLeft: 14,
                      paddingRight: 4,
                      lineHeight: 1,
                    }}
                  >
                    $
                  </span>
                  <input
                    type="number"
                    value={rawAmount}
                    onChange={(e) => setRawAmount(e.target.value)}
                    style={{
                      background: "transparent",
                      border: "none",
                      outline: "none",
                      color: "#dde0ee",
                      fontSize: 22,
                      fontWeight: 700,
                      padding: "10px 14px 10px 4px",
                      width: "100%",
                      fontVariantNumeric: "tabular-nums",
                    }}
                    placeholder="0.00"
                  />
                  {numAmount > 0 && (
                    <div className="px-3">
                      {isOverpay ? (
                        <AlertCircle size={16} style={{ color: "#f87171" }} />
                      ) : (
                        <CheckCircle2 size={16} style={{ color: isExact ? "#4ade80" : "#fbbf24" }} />
                      )}
                    </div>
                  )}
                </div>

                {/* Balance preview */}
                {numAmount > 0 && (
                  <motion.div
                    initial={{ opacity: 0, height: 0 }}
                    animate={{ opacity: 1, height: "auto" }}
                    className="mt-2 rounded-lg px-3 py-2.5 flex items-center justify-between"
                    style={{ background: `${statusColor}08`, border: `1px solid ${statusColor}20` }}
                  >
                    <div className="flex flex-col gap-0.5">
                      <div className="flex items-center gap-4">
                        <div>
                          <div style={{ color: "#434865", fontSize: 10, textTransform: "uppercase", letterSpacing: "0.04em" }}>Saldo pendiente</div>
                          <div style={{ color: "#dde0ee", fontSize: 13, fontWeight: 600, fontVariantNumeric: "tabular-nums" }}>${fmt2(client.pending)}</div>
                        </div>
                        <div style={{ color: "#1e2132", fontSize: 18 }}>→</div>
                        <div>
                          <div style={{ color: "#434865", fontSize: 10, textTransform: "uppercase", letterSpacing: "0.04em" }}>Saldo restante</div>
                          <div
                            style={{
                              color: isOverpay ? "#f87171" : isExact ? "#4ade80" : "#fbbf24",
                              fontSize: 13,
                              fontWeight: 700,
                              fontVariantNumeric: "tabular-nums",
                            }}
                          >
                            ${fmt2(Math.max(remaining, 0))}
                          </div>
                        </div>
                      </div>
                    </div>
                    <span style={{ color: statusColor, fontSize: 11, fontWeight: 600, textAlign: "right" }}>
                      {statusLabel}
                    </span>
                  </motion.div>
                )}
              </div>

              {/* Payment methods */}
              <div>
                <label style={labelStyle}>Método de Pago</label>
                <div className="grid grid-cols-4 gap-2">
                  {methods.map((m) => {
                    const active = method === m.id;
                    return (
                      <button
                        key={m.id}
                        onClick={() => setMethod(m.id)}
                        className="flex flex-col items-center gap-1.5 rounded-lg py-3 transition-all"
                        style={{
                          background: active ? "#6366f118" : "#0e1019",
                          border: active ? "1px solid #6366f150" : "1px solid #1e2132",
                          color: active ? "#818cf8" : "#434865",
                        }}
                      >
                        <span style={{ color: active ? "#6366f1" : "#434865" }}>{m.icon}</span>
                        <span style={{ fontSize: 11, fontWeight: 600 }}>{m.label}</span>
                      </button>
                    );
                  })}
                </div>
              </div>

              {/* Reference + Date (side by side) */}
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label style={labelStyle}>Referencia <span style={{ color: "#323654", textTransform: "none" }}>(opcional)</span></label>
                  <input
                    type="text"
                    value={reference}
                    onChange={(e) => setReference(e.target.value)}
                    style={inputBase}
                    placeholder="Nº transferencia, cheque…"
                  />
                </div>
                <div>
                  <label style={labelStyle}>Fecha</label>
                  <input
                    type="date"
                    value={date}
                    onChange={(e) => setDate(e.target.value)}
                    style={{ ...inputBase, colorScheme: "dark" }}
                  />
                </div>
              </div>

              {/* Note */}
              <div>
                <label style={labelStyle}>Nota <span style={{ color: "#323654", textTransform: "none" }}>(opcional)</span></label>
                <textarea
                  value={note}
                  onChange={(e) => setNote(e.target.value)}
                  rows={2}
                  style={{ ...inputBase, resize: "none", lineHeight: 1.5 }}
                  placeholder="Observaciones para el registro de auditoría…"
                />
              </div>
            </div>

            {/* Footer */}
            <div className="px-5 pb-5 flex flex-col gap-3">
              <div className="flex gap-2">
                <button
                  onClick={onClose}
                  className="flex-1 rounded-lg py-2.5 transition-colors hover:bg-white/5"
                  style={{
                    background: "transparent",
                    color: "#7880a0",
                    border: "1px solid #1e2132",
                    fontSize: 13,
                    fontWeight: 500,
                  }}
                >
                  Cancelar
                </button>
                <button
                  onClick={() => !isOverpay && numAmount > 0 && onConfirm({ amount: rawAmount, method, reference, date, note })}
                  className="flex-1 rounded-lg py-2.5 transition-opacity"
                  style={{
                    background: isOverpay || numAmount <= 0 ? "#6366f140" : "#6366f1",
                    color: isOverpay || numAmount <= 0 ? "#6366f180" : "white",
                    fontSize: 13,
                    fontWeight: 600,
                    cursor: isOverpay || numAmount <= 0 ? "not-allowed" : "pointer",
                  }}
                >
                  Confirmar Pago
                </button>
              </div>
              <div style={{ color: "#323654", fontSize: 11, textAlign: "center", lineHeight: 1.5 }}>
                Aplicado a la factura más antigua primero (método FIFO)
              </div>
            </div>
          </motion.div>
        </motion.div>
      )}
    </AnimatePresence>
  );
}
