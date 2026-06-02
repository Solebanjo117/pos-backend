import { useState } from "react";
import { motion, AnimatePresence } from "motion/react";
import { Car, Plus, Banknote, CreditCard, Smartphone, ChevronRight, ExternalLink } from "lucide-react";

const TABS = ["Facturas", "Items Comprados", "Vehículos", "Pagos", "Historial"] as const;
type Tab = typeof TABS[number];

function Badge({ children, color }: { children: React.ReactNode; color: "emerald" | "amber" | "red" | "blue" }) {
  const styles = {
    emerald: { bg: "#22c55e20", text: "#4ade80", border: "#22c55e30" },
    amber: { bg: "#f59e0b20", text: "#fbbf24", border: "#f59e0b30" },
    red: { bg: "#ef444420", text: "#f87171", border: "#ef444430" },
    blue: { bg: "#3b82f620", text: "#60a5fa", border: "#3b82f630" },
  }[color];

  return (
    <span
      className="px-2 py-0.5 rounded-md text-xs font-semibold"
      style={{ backgroundColor: styles.bg, color: styles.text, border: `1px solid ${styles.border}` }}
    >
      {children}
    </span>
  );
}

const facturas = [
  { id: "FACT #222104", fecha: "22 May 2026", mecanico: "Mec. Rodríguez", items: 5, total: "$135.00", pagado: "$135.00", pendiente: "$0.00", estado: "Pagado" as const },
  { id: "FACT #221890", fecha: "15 May 2026", mecanico: "Mec. Rodríguez", items: 3, total: "$89.00", pagado: "$89.00", pendiente: "$0.00", estado: "Pagado" as const },
  { id: "FACT #221500", fecha: "02 May 2026", mecanico: "Mec. López", items: 8, total: "$340.00", pagado: "$200.00", pendiente: "$140.00", estado: "Parcial" as const },
];

function TabFacturas() {
  return (
    <div className="rounded-xl overflow-hidden" style={{ border: "1px solid #2e3039" }}>
      <table className="w-full text-sm">
        <thead>
          <tr style={{ backgroundColor: "#252830", borderBottom: "1px solid #2e3039" }}>
            {["Factura", "Fecha", "Mecánico", "Items", "Total", "Pagado", "Pendiente", "Estado"].map(h => (
              <th key={h} className="text-left px-4 py-3 text-xs text-gray-500 font-medium uppercase tracking-wider">{h}</th>
            ))}
          </tr>
        </thead>
        <tbody style={{ backgroundColor: "#1a1d27" }}>
          {facturas.map((f, i) => (
            <tr
              key={f.id}
              className="hover:bg-[#252830] transition-colors cursor-pointer group"
              style={{ borderBottom: i < facturas.length - 1 ? "1px solid #2e3039" : undefined }}
            >
              <td className="px-4 py-3">
                <div className="flex items-center gap-2">
                  <span className="text-indigo-400 font-medium">{f.id}</span>
                  <ExternalLink size={12} className="text-gray-600 opacity-0 group-hover:opacity-100 transition-opacity" />
                </div>
              </td>
              <td className="px-4 py-3 text-gray-400">{f.fecha}</td>
              <td className="px-4 py-3 text-gray-300">{f.mecanico}</td>
              <td className="px-4 py-3 text-gray-300">{f.items}</td>
              <td className="px-4 py-3 text-gray-200 font-medium">{f.total}</td>
              <td className="px-4 py-3 text-emerald-400">{f.pagado}</td>
              <td className="px-4 py-3" style={{ color: f.pendiente === "$0.00" ? "#6b7280" : "#fb923c" }}>
                {f.pendiente}
              </td>
              <td className="px-4 py-3">
                <Badge color={f.estado === "Pagado" ? "emerald" : "amber"}>{f.estado}</Badge>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

const vehiculos = [
  {
    marca: "Toyota Yaris 2008",
    vin: "1234ASDF",
    motor: "1NZ-FE 1.5L",
    ultimaCompra: "22 May 2026",
    color: "#3b82f6",
    colorBg: "#3b82f620",
  },
  {
    marca: "Honda CRV 2006",
    vin: "5678QWER",
    motor: "K24 2.4L",
    ultimaCompra: "15 May 2026",
    color: "#8b5cf6",
    colorBg: "#8b5cf620",
  },
];

function TabVehiculos() {
  return (
    <div className="grid grid-cols-2 gap-4">
      {vehiculos.map((v) => (
        <motion.div
          key={v.vin}
          whileHover={{ y: -3, boxShadow: "0 8px 24px rgba(0,0,0,0.3)" }}
          transition={{ duration: 0.15 }}
          className="rounded-xl p-5 cursor-pointer"
          style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039" }}
        >
          <div className="flex items-start gap-4">
            <div
              className="w-11 h-11 rounded-xl flex items-center justify-center shrink-0"
              style={{ backgroundColor: v.colorBg, border: `1px solid ${v.color}30` }}
            >
              <Car size={20} style={{ color: v.color }} />
            </div>
            <div className="flex-1 min-w-0">
              <h4 className="text-gray-100 font-semibold mb-1" style={{ lineHeight: 1.3 }}>{v.marca}</h4>
              <div className="space-y-1">
                <p className="text-xs text-gray-500">VIN: <span className="text-gray-400 font-mono">{v.vin}</span></p>
                <p className="text-xs text-gray-500">Motor: <span className="text-gray-400">{v.motor}</span></p>
                <p className="text-xs text-gray-500 mt-2">Última compra: <span className="text-gray-400">{v.ultimaCompra}</span></p>
              </div>
            </div>
          </div>
          <button
            className="mt-4 w-full py-2 rounded-lg text-xs font-medium text-gray-300 hover:text-white transition-all"
            style={{ backgroundColor: "#252830", border: "1px solid #2e3039" }}
          >
            Ver compatibilidades
          </button>
        </motion.div>
      ))}

      {/* Add vehicle */}
      <button
        className="rounded-xl p-5 flex flex-col items-center justify-center gap-2 transition-all hover:bg-[#1a1d27]"
        style={{
          border: "2px dashed #2e3039",
          backgroundColor: "transparent",
          minHeight: 160,
        }}
      >
        <div
          className="w-10 h-10 rounded-full flex items-center justify-center"
          style={{ backgroundColor: "#252830" }}
        >
          <Plus size={18} className="text-gray-400" />
        </div>
        <span className="text-sm text-gray-400 font-medium">Agregar Vehículo</span>
      </button>
    </div>
  );
}

const pagos = [
  {
    fecha: "24 May 2026",
    factura: "FACT #222104",
    metodos: [
      { icon: Banknote, label: "Efectivo", amount: "$100.00" },
      { icon: Smartphone, label: "Yappy", amount: "$35.00" },
    ],
    estado: "Completo" as const,
  },
  {
    fecha: "15 May 2026",
    factura: "FACT #221890",
    metodos: [
      { icon: CreditCard, label: "Tarjeta", amount: "$89.00" },
    ],
    estado: "Completo" as const,
  },
  {
    fecha: "02 May 2026",
    factura: "FACT #221500",
    metodos: [
      { icon: Banknote, label: "Efectivo", amount: "$200.00" },
    ],
    estado: "Parcial" as const,
  },
];

function TabPagos() {
  return (
    <div className="space-y-3">
      {pagos.map((p, i) => (
        <motion.div
          key={`${p.fecha}-${p.factura}`}
          initial={{ opacity: 0, x: -12 }}
          animate={{ opacity: 1, x: 0 }}
          transition={{ duration: 0.2, delay: i * 0.07 }}
          className="rounded-xl p-4 flex items-center gap-4"
          style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039" }}
        >
          {/* Date dot */}
          <div className="flex flex-col items-center shrink-0">
            <div
              className="w-2.5 h-2.5 rounded-full"
              style={{ backgroundColor: p.estado === "Completo" ? "#22c55e" : "#f59e0b" }}
            />
          </div>

          {/* Date */}
          <div className="w-28 shrink-0">
            <p className="text-xs text-gray-500 font-medium">{p.fecha}</p>
          </div>

          {/* Payment methods */}
          <div className="flex items-center gap-2 flex-1 flex-wrap">
            {p.metodos.map((m, j) => {
              const Icon = m.icon;
              return (
                <div
                  key={j}
                  className="flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs"
                  style={{ backgroundColor: "#252830", border: "1px solid #2e3039" }}
                >
                  <Icon size={13} className="text-gray-400" />
                  <span className="text-gray-400">{m.label}</span>
                  <span className="text-gray-200 font-semibold">{m.amount}</span>
                </div>
              );
            })}
          </div>

          {/* Invoice */}
          <div className="flex items-center gap-1.5 shrink-0">
            <ChevronRight size={12} className="text-gray-600" />
            <span className="text-xs text-indigo-400 font-medium">{p.factura}</span>
          </div>

          {/* Status */}
          <Badge color={p.estado === "Completo" ? "emerald" : "amber"}>{p.estado}</Badge>
        </motion.div>
      ))}
    </div>
  );
}

function TabPlaceholder({ label }: { label: string }) {
  return (
    <div
      className="rounded-xl p-12 flex flex-col items-center justify-center gap-3"
      style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039" }}
    >
      <div className="w-12 h-12 rounded-full flex items-center justify-center" style={{ backgroundColor: "#252830" }}>
        <span className="text-2xl">📋</span>
      </div>
      <p className="text-gray-400 text-sm">{label} — próximamente</p>
    </div>
  );
}

export function ClientTabs() {
  const [activeTab, setActiveTab] = useState<Tab>("Facturas");
  const [prevTab, setPrevTab] = useState<Tab | null>(null);

  function handleTab(tab: Tab) {
    setPrevTab(activeTab);
    setActiveTab(tab);
  }

  return (
    <div>
      {/* Tab bar */}
      <div className="flex items-end gap-0 mb-5" style={{ borderBottom: "1px solid #2e3039" }}>
        {TABS.map((tab) => {
          const isActive = tab === activeTab;
          return (
            <button
              key={tab}
              onClick={() => handleTab(tab)}
              className="relative px-4 py-3 text-sm transition-colors"
              style={{
                color: isActive ? "#a5b4fc" : "#6b7280",
                fontWeight: isActive ? 500 : 400,
              }}
            >
              {tab}
              {isActive && (
                <motion.div
                  layoutId="tab-underline"
                  className="absolute bottom-0 left-0 right-0 h-0.5 rounded-full"
                  style={{ backgroundColor: "#6366f1" }}
                  transition={{ duration: 0.2, ease: "easeInOut" }}
                />
              )}
            </button>
          );
        })}
      </div>

      {/* Tab content */}
      <AnimatePresence mode="wait">
        <motion.div
          key={activeTab}
          initial={{ opacity: 0, y: 6 }}
          animate={{ opacity: 1, y: 0 }}
          exit={{ opacity: 0, y: -4 }}
          transition={{ duration: 0.15 }}
        >
          {activeTab === "Facturas" && <TabFacturas />}
          {activeTab === "Vehículos" && <TabVehiculos />}
          {activeTab === "Pagos" && <TabPagos />}
          {activeTab === "Items Comprados" && <TabPlaceholder label="Items Comprados" />}
          {activeTab === "Historial" && <TabPlaceholder label="Historial de actividad" />}
        </motion.div>
      </AnimatePresence>
    </div>
  );
}
