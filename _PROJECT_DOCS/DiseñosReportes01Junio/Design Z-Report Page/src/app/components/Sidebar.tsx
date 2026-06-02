import {
  LayoutDashboard,
  ShoppingCart,
  Package,
  Users,
  BarChart3,
  FileText,
  Settings,
  ChevronDown,
  ChevronRight,
  Receipt,
  TrendingUp,
  ClipboardList,
} from "lucide-react";
import { useState } from "react";

interface NavItem {
  icon: React.ReactNode;
  label: string;
  active?: boolean;
  children?: { label: string; active?: boolean }[];
}

const navItems: NavItem[] = [
  { icon: <LayoutDashboard size={16} />, label: "Dashboard" },
  { icon: <ShoppingCart size={16} />, label: "Punto de Venta" },
  { icon: <Package size={16} />, label: "Inventario" },
  { icon: <Users size={16} />, label: "Clientes" },
  {
    icon: <BarChart3 size={16} />,
    label: "Reportes",
    active: true,
    children: [
      { label: "Ventas del Día" },
      { label: "Cierre de Caja", active: true },
      { label: "Inventario" },
      { label: "Clientes" },
    ],
  },
  { icon: <ClipboardList size={16} />, label: "Facturación" },
  { icon: <Settings size={16} />, label: "Configuración" },
];

export function Sidebar() {
  const [expandedItems, setExpandedItems] = useState<string[]>(["Reportes"]);

  const toggleExpand = (label: string) => {
    setExpandedItems((prev) =>
      prev.includes(label) ? prev.filter((l) => l !== label) : [...prev, label]
    );
  };

  return (
    <aside
      style={{ width: 260, background: "#111318" }}
      className="h-screen flex flex-col flex-shrink-0 border-r border-[#2e3039]"
    >
      {/* Logo */}
      <div className="flex items-center gap-3 px-5 py-5 border-b border-[#2e3039]">
        <div className="w-8 h-8 rounded-lg bg-indigo-600 flex items-center justify-center">
          <Receipt size={16} className="text-white" />
        </div>
        <div>
          <div className="text-white text-sm" style={{ fontWeight: 700 }}>
            HermeX POS
          </div>
          <div className="text-[#717182] text-xs">v2.4.1</div>
        </div>
      </div>

      {/* Nav */}
      <nav className="flex-1 overflow-y-auto py-3 px-3">
        {navItems.map((item) => {
          const isExpanded = expandedItems.includes(item.label);
          const hasChildren = !!item.children;

          return (
            <div key={item.label}>
              <button
                onClick={() => hasChildren && toggleExpand(item.label)}
                className={`w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors mb-0.5 ${
                  item.active && !hasChildren
                    ? "bg-indigo-600/10 text-indigo-400 border-l-2 border-indigo-500"
                    : item.active && hasChildren
                    ? "text-white"
                    : "text-[#717182] hover:text-white hover:bg-[#1a1d27]"
                }`}
              >
                <span
                  className={
                    item.active ? "text-indigo-400" : "text-[#717182]"
                  }
                >
                  {item.icon}
                </span>
                <span className="flex-1 text-left">{item.label}</span>
                {hasChildren &&
                  (isExpanded ? (
                    <ChevronDown size={14} className="text-[#717182]" />
                  ) : (
                    <ChevronRight size={14} className="text-[#717182]" />
                  ))}
              </button>

              {hasChildren && isExpanded && (
                <div className="ml-6 mb-1">
                  {item.children!.map((child) => (
                    <button
                      key={child.label}
                      className={`w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm transition-colors mb-0.5 ${
                        child.active
                          ? "bg-indigo-600/10 text-indigo-400 border-l-2 border-indigo-500"
                          : "text-[#717182] hover:text-white hover:bg-[#1a1d27]"
                      }`}
                    >
                      {child.label}
                    </button>
                  ))}
                </div>
              )}
            </div>
          );
        })}
      </nav>

      {/* User */}
      <div className="p-4 border-t border-[#2e3039]">
        <div className="flex items-center gap-3">
          <div className="w-8 h-8 rounded-full bg-indigo-600 flex items-center justify-center text-white text-xs" style={{ fontWeight: 700 }}>
            JM
          </div>
          <div className="flex-1 min-w-0">
            <div className="text-white text-sm truncate" style={{ fontWeight: 500 }}>
              Juan Martínez
            </div>
            <div className="text-[#717182] text-xs">Administrador</div>
          </div>
        </div>
      </div>
    </aside>
  );
}
