import { useState } from "react";
import { HandCoins, Bell, ChevronRight, Warehouse, User, Download, RefreshCcw } from "lucide-react";
import { motion, AnimatePresence } from "motion/react";
import { Sidebar } from "./components/Sidebar";
import { KPICards } from "./components/KPICards";
import { AgingChart } from "./components/AgingChart";
import { ClientsTable, type Client } from "./components/ClientsTable";
import { PaymentModal } from "./components/PaymentModal";
import { TransactionHistory } from "./components/TransactionHistory";

export default function App() {
  const [selectedClient, setSelectedClient] = useState<Client | null>(null);
  const [toast, setToast] = useState<{ msg: string; color: string } | null>(null);

  function handleRegisterPayment(client: Client) {
    setSelectedClient(client);
  }

  function handleConfirmPayment(data: {
    amount: string;
    method: string;
    reference: string;
    date: string;
    note: string;
  }) {
    setSelectedClient(null);
    setToast({
      msg: `$${parseFloat(data.amount).toFixed(2)} registrado · ${data.method}`,
      color: "#22c55e",
    });
    setTimeout(() => setToast(null), 3800);
  }

  return (
    <div
      className="flex h-screen w-full overflow-hidden"
      style={{ background: "#0b0d15", fontFamily: "'Inter', system-ui, sans-serif" }}
    >
      <Sidebar />

      <div className="flex flex-col flex-1 min-w-0 overflow-hidden">
        {/* Top Bar */}
        <header
          className="flex items-center justify-between px-5 flex-shrink-0"
          style={{
            height: 52,
            background: "#0e1019",
            borderBottom: "1px solid #1a1d2e",
          }}
        >
          {/* Breadcrumb */}
          <div className="flex items-center gap-1.5" style={{ color: "#323654", fontSize: 12 }}>
            <span>Reportes</span>
            <ChevronRight size={12} />
            <span style={{ color: "#7880a0" }}>Cuentas por Cobrar</span>
          </div>

          <div className="flex items-center gap-2">
            {/* Warehouse */}
            <button
              className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-md transition-colors hover:bg-white/5"
              style={{
                background: "#111420",
                border: "1px solid #1a1d2e",
                color: "#7880a0",
                fontSize: 12,
              }}
            >
              <Warehouse size={13} />
              <span>Sucursal Principal</span>
              <ChevronRight size={10} style={{ transform: "rotate(90deg)", color: "#323654" }} />
            </button>

            {/* Export */}
            <button
              className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-md transition-colors hover:bg-white/5"
              style={{
                background: "#111420",
                border: "1px solid #1a1d2e",
                color: "#7880a0",
                fontSize: 12,
              }}
            >
              <Download size={13} />
              <span>Exportar</span>
            </button>

            {/* Refresh */}
            <button
              className="flex items-center justify-center rounded-md transition-colors hover:bg-white/5"
              style={{ width: 30, height: 30, background: "#111420", border: "1px solid #1a1d2e", color: "#434865" }}
            >
              <RefreshCcw size={13} />
            </button>

            {/* Bell */}
            <button
              className="relative flex items-center justify-center rounded-md transition-colors hover:bg-white/5"
              style={{ width: 30, height: 30, background: "#111420", border: "1px solid #1a1d2e", color: "#434865" }}
            >
              <Bell size={13} />
              <span
                className="absolute rounded-full"
                style={{ top: 5, right: 5, width: 5, height: 5, background: "#ef4444" }}
              />
            </button>

            {/* Avatar */}
            <div
              className="flex items-center justify-center rounded-full"
              style={{ width: 28, height: 28, background: "#6366f1", fontSize: 11, fontWeight: 700, color: "white" }}
            >
              <User size={13} />
            </div>
          </div>
        </header>

        {/* Main scroll */}
        <main className="flex-1 overflow-y-auto" style={{ padding: "20px 20px 32px" }}>
          <div className="flex flex-col gap-5" style={{ maxWidth: 1440 }}>

            {/* Page Header */}
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-3">
                <div
                  className="flex items-center justify-center rounded-lg"
                  style={{ width: 36, height: 36, background: "#f9731610" }}
                >
                  <HandCoins size={19} color="#f97316" />
                </div>
                <div>
                  <h1
                    style={{
                      color: "#dde0ee",
                      fontSize: 20,
                      fontWeight: 700,
                      letterSpacing: "-0.02em",
                      lineHeight: 1,
                      margin: 0,
                    }}
                  >
                    Cuentas por Cobrar
                  </h1>
                  <p style={{ color: "#434865", fontSize: 12, marginTop: 3 }}>
                    Control de créditos otorgados y saldos pendientes · Mayo 2026
                  </p>
                </div>
              </div>

              {/* Period badge */}
              <div
                className="flex items-center gap-2 px-3 py-1.5 rounded-md"
                style={{ background: "#111420", border: "1px solid #1a1d2e" }}
              >
                <div className="rounded-full" style={{ width: 6, height: 6, background: "#22c55e" }} />
                <span style={{ color: "#7880a0", fontSize: 12 }}>Datos en tiempo real</span>
              </div>
            </div>

            <KPICards />
            <AgingChart />
            <ClientsTable onRegisterPayment={handleRegisterPayment} />
            <TransactionHistory />
          </div>
        </main>
      </div>

      {/* Modal */}
      <PaymentModal
        client={selectedClient}
        onClose={() => setSelectedClient(null)}
        onConfirm={handleConfirmPayment}
      />

      {/* Toast */}
      <AnimatePresence>
        {toast && (
          <motion.div
            initial={{ opacity: 0, y: 8, scale: 0.97 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: 8, scale: 0.97 }}
            transition={{ duration: 0.18 }}
            className="fixed bottom-5 right-5 flex items-center gap-2.5 rounded-lg px-3.5 py-2.5 z-50"
            style={{
              background: "#111420",
              border: "1px solid #22c55e30",
              boxShadow: "0 4px 20px rgba(0,0,0,0.5)",
            }}
          >
            <div className="rounded-full flex items-center justify-center" style={{ width: 18, height: 18, background: "#22c55e18" }}>
              <span style={{ color: "#4ade80", fontSize: 11 }}>✓</span>
            </div>
            <span style={{ color: "#4ade80", fontSize: 12, fontWeight: 500 }}>Pago registrado —</span>
            <span style={{ color: "#7880a0", fontSize: 12 }}>{toast.msg}</span>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}
