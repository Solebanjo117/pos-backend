import {
  LayoutDashboard,
  ShoppingCart,
  Package,
  Users,
  BarChart3,
  ChevronRight,
  HandCoins,
  CreditCard,
  FileText,
  TrendingUp,
  Settings,
  Store,
} from "lucide-react";
import { useState } from "react";

interface NavItem {
  icon: React.ReactNode;
  label: string;
  active?: boolean;
  children?: { label: string; active?: boolean }[];
}

const navItems: NavItem[] = [
  { icon: <LayoutDashboard size={18} />, label: "Dashboard" },
  { icon: <ShoppingCart size={18} />, label: "Ventas" },
  { icon: <Package size={18} />, label: "Inventario" },
  { icon: <Users size={18} />, label: "Clientes" },
  {
    icon: <BarChart3 size={18} />,
    label: "Reportes",
    active: true,
    children: [
      { label: "Resumen General" },
      { label: "Cuentas por Cobrar", active: true },
      { label: "Cuentas por Pagar" },
      { label: "Flujo de Caja" },
    ],
  },
  { icon: <CreditCard size={18} />, label: "Pagos" },
  { icon: <FileText size={18} />, label: "Facturas" },
  { icon: <TrendingUp size={18} />, label: "Análisis" },
];

export function Sidebar() {
  const [expandedReportes, setExpandedReportes] = useState(true);

  return (
    <aside
      style={{ width: 260, background: "#111318", borderRight: "1px solid #2e3039" }}
      className="flex flex-col h-full flex-shrink-0"
    >
      {/* Logo */}
      <div className="flex items-center gap-3 px-5 py-5" style={{ borderBottom: "1px solid #2e3039" }}>
        <div
          className="flex items-center justify-center rounded-lg"
          style={{ width: 32, height: 32, background: "#6366f1" }}
        >
          <Store size={18} color="white" />
        </div>
        <div>
          <div style={{ color: "#f1f5f9", fontWeight: 700, fontSize: 16, lineHeight: 1 }}>HermeX</div>
          <div style={{ color: "#64748b", fontSize: 11, marginTop: 2 }}>Point of Sale</div>
        </div>
      </div>

      {/* Nav */}
      <nav className="flex-1 px-3 py-4 overflow-y-auto">
        {navItems.map((item) => (
          <div key={item.label} className="mb-1">
            <button
              onClick={() => item.label === "Reportes" && setExpandedReportes(!expandedReportes)}
              className="w-full flex items-center gap-3 px-3 py-2 rounded-lg transition-colors group"
              style={{
                background: item.active && !item.children ? "rgba(99,102,241,0.1)" : "transparent",
                color: item.active ? "#818cf8" : "#94a3b8",
              }}
            >
              <span style={{ color: item.active ? "#6366f1" : "#64748b" }}>{item.icon}</span>
              <span style={{ fontSize: 13.5, fontWeight: 500, flex: 1, textAlign: "left" }}>{item.label}</span>
              {item.children && (
                <ChevronRight
                  size={14}
                  style={{
                    color: "#64748b",
                    transform: expandedReportes ? "rotate(90deg)" : "rotate(0deg)",
                    transition: "transform 200ms",
                  }}
                />
              )}
            </button>

            {item.children && expandedReportes && (
              <div className="ml-4 mt-1 pl-3" style={{ borderLeft: "1px solid #2e3039" }}>
                {item.children.map((child) => (
                  <button
                    key={child.label}
                    className="w-full flex items-center gap-2 px-3 py-1.5 rounded-lg mb-0.5 text-left transition-colors"
                    style={{
                      background: child.active ? "rgba(99,102,241,0.1)" : "transparent",
                      color: child.active ? "#818cf8" : "#64748b",
                      borderLeft: child.active ? "2px solid #6366f1" : "2px solid transparent",
                      fontSize: 13,
                      fontWeight: child.active ? 600 : 400,
                    }}
                  >
                    {child.label}
                  </button>
                ))}
              </div>
            )}
          </div>
        ))}
      </nav>

      {/* Bottom */}
      <div className="px-3 py-4" style={{ borderTop: "1px solid #2e3039" }}>
        <button
          className="w-full flex items-center gap-3 px-3 py-2 rounded-lg"
          style={{ color: "#64748b" }}
        >
          <Settings size={18} />
          <span style={{ fontSize: 13.5, fontWeight: 500 }}>Configuración</span>
        </button>
      </div>
    </aside>
  );
}
