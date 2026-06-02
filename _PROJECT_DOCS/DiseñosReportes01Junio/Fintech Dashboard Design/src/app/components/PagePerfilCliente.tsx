import { motion } from "motion/react";
import { User, Phone, MapPin, TrendingUp, ShoppingCart, CreditCard, ArrowLeft } from "lucide-react";
import { ComposedChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from "recharts";

const monthlyPurchases = [
  { mes: "Ene", monto: 820 },
  { mes: "Feb", monto: 1240 },
  { mes: "Mar", monto: 680 },
  { mes: "Abr", monto: 1580 },
  { mes: "May", monto: 4200 },
];

const recentInvoices = [
  { id: "FACT #222098", date: "22 May 2026", amount: "$1,200.00", status: "Crédito" },
  { id: "FACT #221876", date: "15 May 2026", amount: "$430.00", status: "Efectivo" },
  { id: "FACT #221500", date: "02 May 2026", amount: "$2,570.00", status: "Tarjeta" },
  { id: "FACT #220980", date: "18 Abr 2026", amount: "$890.00", status: "Efectivo" },
  { id: "FACT #220450", date: "05 Abr 2026", amount: "$680.00", status: "Crédito" },
];

const typeColors: Record<string, { bg: string; text: string }> = {
  Crédito: { bg: "rgba(249,115,22,0.12)", text: "#f97316" },
  Efectivo: { bg: "rgba(34,197,94,0.12)", text: "#22c55e" },
  Tarjeta: { bg: "rgba(59,130,246,0.12)", text: "#3b82f6" },
};

const CustomTooltip = ({ active, payload, label }: any) => {
  if (!active || !payload?.length) return null;
  return (
    <div className="rounded-lg p-3" style={{ backgroundColor: "#252830", border: "1px solid #3e4250" }}>
      <p style={{ color: "#64748b", fontSize: 11, marginBottom: 4 }}>{label}</p>
      <p style={{ color: "#f1f5f9", fontSize: 12, fontWeight: 600 }}>${payload[0].value?.toLocaleString()}</p>
    </div>
  );
};

interface Props {
  clientName?: string;
}

export function PagePerfilCliente({ clientName = "Mecánico Rodríguez" }: Props) {
  const initials = clientName.split(" ").slice(0, 2).map((w: string) => w[0]).join("").toUpperCase();

  return (
    <motion.div
      initial={{ opacity: 0, x: 24 }}
      animate={{ opacity: 1, x: 0 }}
      transition={{ duration: 0.3, ease: "easeOut" }}
      className="flex flex-col gap-6"
      style={{ padding: 24, paddingTop: 88 }}
    >
      {/* Client hero */}
      <div className="rounded-xl p-6 flex items-center gap-5" style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039" }}>
        <div
          className="flex items-center justify-center rounded-full flex-shrink-0"
          style={{ width: 64, height: 64, background: "linear-gradient(135deg, #6366f1, #8b5cf6)" }}
        >
          <span style={{ color: "#fff", fontSize: 22, fontWeight: 700 }}>{initials}</span>
        </div>
        <div className="flex-1">
          <h1 style={{ color: "#f1f5f9", fontWeight: 700, fontSize: 20, letterSpacing: "-0.5px" }}>{clientName}</h1>
          <div className="flex items-center gap-4 mt-2">
            <span className="flex items-center gap-1" style={{ color: "#64748b", fontSize: 12 }}>
              <User size={12} /> Cliente Frecuente
            </span>
            <span className="flex items-center gap-1" style={{ color: "#64748b", fontSize: 12 }}>
              <Phone size={12} /> +507 6234-5678
            </span>
            <span className="flex items-center gap-1" style={{ color: "#64748b", fontSize: 12 }}>
              <MapPin size={12} /> Panamá, Chorrera
            </span>
          </div>
        </div>
        <div className="flex flex-col items-end gap-1">
          <span
            className="inline-flex items-center rounded-full px-3"
            style={{ height: 24, backgroundColor: "rgba(34,197,94,0.12)", color: "#22c55e", fontSize: 12, fontWeight: 600 }}
          >
            Cliente Activo
          </span>
          <span style={{ color: "#475569", fontSize: 11 }}>RUC: 8-123-456</span>
        </div>
      </div>

      {/* Stats */}
      <div className="grid gap-4" style={{ gridTemplateColumns: "repeat(3, 1fr)" }}>
        {[
          { label: "Compras Totales 2026", value: "$8,520.00", icon: <ShoppingCart size={16} />, iconColor: "#3b82f6", borderColor: "#3b82f6" },
          { label: "Facturación Mayo", value: "$4,200.00", icon: <TrendingUp size={16} />, iconColor: "#22c55e", borderColor: "#22c55e" },
          { label: "Crédito Pendiente", value: "$1,630.00", icon: <CreditCard size={16} />, iconColor: "#f97316", borderColor: "#f97316" },
        ].map((k) => (
          <div key={k.label} className="rounded-xl p-5" style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039", borderLeft: `4px solid ${k.borderColor}` }}>
            <div className="flex items-center justify-between mb-2">
              <p style={{ color: "#64748b", fontSize: 11, fontWeight: 500, textTransform: "uppercase", letterSpacing: "0.05em" }}>{k.label}</p>
              <span style={{ color: k.iconColor }}>{k.icon}</span>
            </div>
            <p style={{ color: "#f1f5f9", fontSize: 20, fontWeight: 700 }}>{k.value}</p>
          </div>
        ))}
      </div>

      <div className="grid gap-6" style={{ gridTemplateColumns: "1fr 1fr" }}>
        {/* Purchase history chart */}
        <div className="rounded-xl p-5" style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039" }}>
          <h3 style={{ color: "#f1f5f9", fontSize: 15, fontWeight: 600, marginBottom: 4 }}>Historial de Compras</h3>
          <p style={{ color: "#475569", fontSize: 12, marginBottom: 16 }}>Ene — May 2026</p>
          <ResponsiveContainer width="100%" height={200}>
            <ComposedChart data={monthlyPurchases} margin={{ top: 4, right: 4, left: -20, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="#2e3039" vertical={false} />
              <XAxis dataKey="mes" tick={{ fill: "#475569", fontSize: 11 }} axisLine={false} tickLine={false} />
              <YAxis tick={{ fill: "#475569", fontSize: 11 }} axisLine={false} tickLine={false} tickFormatter={(v) => `$${(v / 1000).toFixed(1)}k`} />
              <Tooltip content={<CustomTooltip />} cursor={{ fill: "rgba(99,102,241,0.04)" }} />
              <Bar name="Monto" dataKey="monto" fill="#6366f1" fillOpacity={0.8} radius={[4, 4, 0, 0]} />
            </ComposedChart>
          </ResponsiveContainer>
        </div>

        {/* Recent invoices */}
        <div className="rounded-xl overflow-hidden" style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039" }}>
          <div className="px-5 py-4" style={{ borderBottom: "1px solid #2e3039" }}>
            <h3 style={{ color: "#f1f5f9", fontSize: 15, fontWeight: 600 }}>Últimas Facturas</h3>
          </div>
          {recentInvoices.map((inv, i) => {
            const st = typeColors[inv.status] ?? { bg: "rgba(100,116,139,0.12)", text: "#64748b" };
            return (
              <div
                key={i}
                className="flex items-center px-5 py-3 transition-colors"
                style={{ borderBottom: i < recentInvoices.length - 1 ? "1px solid #2e3039" : "none", cursor: "pointer" }}
                onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = "#252830")}
                onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = "transparent")}
              >
                <div className="flex-1">
                  <p style={{ color: "#e2e8f0", fontSize: 12, fontWeight: 600 }}>{inv.id}</p>
                  <p style={{ color: "#475569", fontSize: 11, marginTop: 2 }}>{inv.date}</p>
                </div>
                <div className="flex flex-col items-end gap-1">
                  <span style={{ color: "#f1f5f9", fontSize: 12, fontWeight: 700 }}>{inv.amount}</span>
                  <span className="inline-flex items-center rounded-full px-2" style={{ height: 18, backgroundColor: st.bg, color: st.text, fontSize: 10, fontWeight: 600 }}>
                    {inv.status}
                  </span>
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </motion.div>
  );
}
