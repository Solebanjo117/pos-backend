import {
  BarChart3,
  Car,
  ChevronRight,
  FileText,
  LayoutDashboard,
  Package,
  Settings,
  ShoppingCart,
  Users,
  Wrench,
  Zap,
} from "lucide-react";
import { useState } from "react";

interface NavItem {
  icon: React.ReactNode;
  label: string;
  href?: string;
  children?: { label: string; href: string; active?: boolean }[];
}

const navItems: NavItem[] = [
  { icon: <LayoutDashboard size={16} />, label: "Dashboard" },
  { icon: <ShoppingCart size={16} />, label: "Ventas" },
  { icon: <Package size={16} />, label: "Inventario" },
  { icon: <Car size={16} />, label: "Vehículos" },
  { icon: <Wrench size={16} />, label: "Mecánicos" },
  { icon: <Users size={16} />, label: "Clientes" },
  {
    icon: <BarChart3 size={16} />,
    label: "Reportes",
    children: [
      { label: "Resumen General", href: "#" },
      { label: "Ventas por Cliente", href: "#", active: true },
      { label: "Ventas por Mecánico", href: "#" },
      { label: "Inventario", href: "#" },
      { label: "Cuentas por Cobrar", href: "#" },
    ],
  },
  { icon: <FileText size={16} />, label: "Facturación" },
  { icon: <Settings size={16} />, label: "Configuración" },
];

export function Sidebar() {
  const [expandedItems, setExpandedItems] = useState<string[]>(["Reportes"]);

  const toggleExpand = (label: string) => {
    setExpandedItems((prev) =>
      prev.includes(label) ? prev.filter((i) => i !== label) : [...prev, label]
    );
  };

  return (
    <aside
      style={{ width: 260, background: "#111318", borderRight: "1px solid #2e3039" }}
      className="h-screen flex flex-col fixed left-0 top-0 z-30"
    >
      {/* Logo */}
      <div
        style={{ height: 64, borderBottom: "1px solid #2e3039" }}
        className="flex items-center gap-3 px-5"
      >
        <div
          style={{ background: "linear-gradient(135deg, #6366f1, #8b5cf6)" }}
          className="w-8 h-8 rounded-lg flex items-center justify-center"
        >
          <Zap size={16} className="text-white" />
        </div>
        <div>
          <div style={{ color: "#fff", fontWeight: 700, fontSize: 15, letterSpacing: "-0.02em" }}>
            HermeX POS
          </div>
          <div style={{ color: "#6b7280", fontSize: 11 }}>Sistema de Punto de Venta</div>
        </div>
      </div>

      {/* Nav */}
      <nav className="flex-1 overflow-y-auto py-4 px-3">
        {navItems.map((item) => {
          const isExpanded = expandedItems.includes(item.label);
          const hasChildren = !!item.children;

          return (
            <div key={item.label} className="mb-0.5">
              <button
                onClick={() => hasChildren && toggleExpand(item.label)}
                style={{
                  color: hasChildren && isExpanded ? "#a5b4fc" : "#9ca3af",
                  background: "transparent",
                  width: "100%",
                  textAlign: "left",
                  display: "flex",
                  alignItems: "center",
                  gap: 10,
                  padding: "7px 10px",
                  borderRadius: 7,
                  fontSize: 13.5,
                  fontWeight: 500,
                  cursor: "pointer",
                  transition: "all 0.15s",
                }}
                onMouseEnter={(e) => {
                  (e.currentTarget as HTMLButtonElement).style.background = "#1e2130";
                  (e.currentTarget as HTMLButtonElement).style.color = "#e5e7eb";
                }}
                onMouseLeave={(e) => {
                  (e.currentTarget as HTMLButtonElement).style.background = "transparent";
                  (e.currentTarget as HTMLButtonElement).style.color =
                    hasChildren && isExpanded ? "#a5b4fc" : "#9ca3af";
                }}
              >
                <span style={{ color: hasChildren && isExpanded ? "#6366f1" : "#6b7280" }}>
                  {item.icon}
                </span>
                <span className="flex-1">{item.label}</span>
                {hasChildren && (
                  <ChevronRight
                    size={14}
                    style={{
                      transform: isExpanded ? "rotate(90deg)" : "rotate(0deg)",
                      transition: "transform 0.2s",
                      color: "#6b7280",
                    }}
                  />
                )}
              </button>

              {hasChildren && isExpanded && (
                <div className="ml-6 mt-0.5 mb-1">
                  {item.children!.map((child) => (
                    <a
                      key={child.label}
                      href={child.href}
                      style={{
                        display: "flex",
                        alignItems: "center",
                        padding: "6px 10px",
                        borderRadius: 6,
                        fontSize: 13,
                        fontWeight: child.active ? 600 : 400,
                        color: child.active ? "#a5b4fc" : "#9ca3af",
                        background: child.active ? "rgba(99,102,241,0.1)" : "transparent",
                        borderLeft: child.active ? "2px solid #6366f1" : "2px solid transparent",
                        marginBottom: 1,
                        textDecoration: "none",
                        transition: "all 0.15s",
                      }}
                      onMouseEnter={(e) => {
                        if (!child.active) {
                          (e.currentTarget as HTMLAnchorElement).style.color = "#e5e7eb";
                          (e.currentTarget as HTMLAnchorElement).style.background = "#1e2130";
                        }
                      }}
                      onMouseLeave={(e) => {
                        if (!child.active) {
                          (e.currentTarget as HTMLAnchorElement).style.color = "#9ca3af";
                          (e.currentTarget as HTMLAnchorElement).style.background = "transparent";
                        }
                      }}
                    >
                      {child.label}
                    </a>
                  ))}
                </div>
              )}
            </div>
          );
        })}
      </nav>

      {/* Footer */}
      <div style={{ borderTop: "1px solid #2e3039", padding: "12px 16px" }}>
        <div className="flex items-center gap-3">
          <div
            style={{
              width: 32,
              height: 32,
              borderRadius: "50%",
              background: "linear-gradient(135deg, #6366f1, #8b5cf6)",
              display: "flex",
              alignItems: "center",
              justifyContent: "center",
              fontSize: 12,
              fontWeight: 700,
              color: "#fff",
              flexShrink: 0,
            }}
          >
            AD
          </div>
          <div className="flex-1 min-w-0">
            <div style={{ fontSize: 13, fontWeight: 600, color: "#e5e7eb" }}>Admin</div>
            <div style={{ fontSize: 11, color: "#6b7280" }}>admin@hermex.com</div>
          </div>
        </div>
      </div>
    </aside>
  );
}
