import { useState } from "react";
import {
  LayoutDashboard, Package, Users, ShoppingCart, BarChart2,
  ChevronDown, ChevronRight, Warehouse, Settings,
  FileText, Scale, UserCheck, CreditCard, Calculator,
  Zap, PanelLeftClose, PanelLeftOpen,
} from "lucide-react";
import { motion, AnimatePresence } from "motion/react";

export type Page =
  | "dashboard"
  | "productos"
  | "clientes"
  | "ventas"
  | "estado-resultados"
  | "balance-general"
  | "ventas-por-cliente"
  | "cuentas-por-cobrar"
  | "cierre-de-caja"
  | "inventario"
  | "configuracion";

interface SidebarProps {
  currentPage: Page;
  onNavigate: (page: Page) => void;
  collapsed: boolean;
  onToggleCollapse: () => void;
}

interface NavItem {
  icon: React.ReactNode;
  label: string;
  page?: Page;
  submenu?: { label: string; icon: React.ReactNode; page: Page }[];
}

const navItems: NavItem[] = [
  { icon: <LayoutDashboard size={18} />, label: "Dashboard", page: "dashboard" },
  { icon: <Package size={18} />, label: "Productos", page: "productos" },
  { icon: <Users size={18} />, label: "Clientes", page: "clientes" },
  { icon: <ShoppingCart size={18} />, label: "Ventas", page: "ventas" },
  {
    icon: <BarChart2 size={18} />,
    label: "Reportes",
    submenu: [
      { label: "Estado de Resultados", icon: <FileText size={14} />, page: "estado-resultados" },
      { label: "Balance General", icon: <Scale size={14} />, page: "balance-general" },
      { label: "Ventas por Cliente", icon: <UserCheck size={14} />, page: "ventas-por-cliente" },
      { label: "Cuentas por Cobrar", icon: <CreditCard size={14} />, page: "cuentas-por-cobrar" },
      { label: "Cierre de Caja", icon: <Calculator size={14} />, page: "cierre-de-caja" },
    ],
  },
  { icon: <Warehouse size={18} />, label: "Inventario", page: "inventario" },
  { icon: <Settings size={18} />, label: "Configuración", page: "configuracion" },
];

const reportPages: Page[] = ["estado-resultados", "balance-general", "ventas-por-cliente", "cuentas-por-cobrar", "cierre-de-caja"];

export function Sidebar({ currentPage, onNavigate, collapsed, onToggleCollapse }: SidebarProps) {
  const isReportPage = reportPages.includes(currentPage);
  const [reportesOpen, setReportesOpen] = useState(isReportPage);

  const isActive = (item: NavItem) => {
    if (item.page) return currentPage === item.page;
    if (item.submenu) return item.submenu.some((s) => s.page === currentPage);
    return false;
  };

  return (
    <motion.aside
      animate={{ width: collapsed ? 64 : 260 }}
      transition={{ duration: 0.25, ease: "easeInOut" }}
      className="fixed left-0 top-0 h-full flex flex-col z-20 overflow-hidden"
      style={{ backgroundColor: "#111318", borderRight: "1px solid #2e3039" }}
    >
      {/* Logo */}
      <div
        className="flex items-center px-4 flex-shrink-0"
        style={{ height: 64, borderBottom: "1px solid #2e3039", gap: collapsed ? 0 : 12 }}
      >
        <div
          className="flex items-center justify-center rounded-lg flex-shrink-0"
          style={{ width: 34, height: 34, background: "linear-gradient(135deg, #6366f1, #8b5cf6)" }}
        >
          <Zap size={18} color="#fff" fill="#fff" />
        </div>
        <AnimatePresence>
          {!collapsed && (
            <motion.div
              initial={{ opacity: 0, width: 0 }}
              animate={{ opacity: 1, width: "auto" }}
              exit={{ opacity: 0, width: 0 }}
              transition={{ duration: 0.2 }}
              style={{ overflow: "hidden", whiteSpace: "nowrap" }}
            >
              <span style={{ color: "#fff", fontWeight: 700, fontSize: 18, letterSpacing: "-0.3px" }}>HermeX</span>
              <span style={{ color: "#6366f1", fontWeight: 700, fontSize: 18 }}> POS</span>
            </motion.div>
          )}
        </AnimatePresence>
        <button
          onClick={onToggleCollapse}
          className="ml-auto flex-shrink-0 flex items-center justify-center rounded-lg transition-all"
          style={{ width: 28, height: 28, color: "#475569" }}
          title={collapsed ? "Expandir" : "Colapsar"}
        >
          {collapsed ? <PanelLeftOpen size={15} /> : <PanelLeftClose size={15} />}
        </button>
      </div>

      {/* Nav */}
      <nav className="flex-1 py-3 overflow-y-auto overflow-x-hidden">
        {navItems.map((item) => {
          const active = isActive(item);
          const hasSubmenu = !!item.submenu;
          const isReportes = hasSubmenu && item.label === "Reportes";
          const isOpen = isReportes && reportesOpen && !collapsed;

          return (
            <div key={item.label}>
              <button
                onClick={() => {
                  if (isReportes) {
                    if (collapsed) return;
                    setReportesOpen((v) => !v);
                  } else if (item.page) {
                    onNavigate(item.page);
                  }
                }}
                title={collapsed ? item.label : undefined}
                className="w-full flex items-center transition-all duration-150 relative"
                style={{
                  height: 38,
                  marginBottom: 2,
                  paddingLeft: collapsed ? 0 : active ? 14 : 16,
                  paddingRight: collapsed ? 0 : 12,
                  justifyContent: collapsed ? "center" : "flex-start",
                  gap: collapsed ? 0 : 10,
                  backgroundColor: active ? "rgba(99,102,241,0.12)" : "transparent",
                  color: active ? "#6366f1" : "#94a3b8",
                  borderLeft: !collapsed && active ? "2px solid #6366f1" : "2px solid transparent",
                  width: "calc(100% - 8px)",
                  marginLeft: 4,
                  borderRadius: 8,
                }}
              >
                <span style={{ color: active ? "#6366f1" : "#64748b", flexShrink: 0 }}>{item.icon}</span>
                <AnimatePresence>
                  {!collapsed && (
                    <motion.span
                      initial={{ opacity: 0 }}
                      animate={{ opacity: 1 }}
                      exit={{ opacity: 0 }}
                      transition={{ duration: 0.15 }}
                      style={{ fontSize: 13, fontWeight: active ? 600 : 400, whiteSpace: "nowrap", flex: 1, textAlign: "left" }}
                    >
                      {item.label}
                    </motion.span>
                  )}
                </AnimatePresence>
                {!collapsed && hasSubmenu && (
                  <span style={{ color: "#475569", flexShrink: 0 }}>
                    {isOpen ? <ChevronDown size={13} /> : <ChevronRight size={13} />}
                  </span>
                )}
              </button>

              {/* Submenu */}
              {hasSubmenu && !collapsed && (
                <AnimatePresence initial={false}>
                  {isOpen && (
                    <motion.div
                      initial={{ height: 0, opacity: 0 }}
                      animate={{ height: "auto", opacity: 1 }}
                      exit={{ height: 0, opacity: 0 }}
                      transition={{ duration: 0.2, ease: "easeOut" }}
                      style={{ overflow: "hidden" }}
                    >
                      <div className="pl-10 pr-2 pb-1">
                        {item.submenu!.map((sub) => {
                          const subActive = currentPage === sub.page;
                          return (
                            <button
                              key={sub.label}
                              onClick={() => onNavigate(sub.page)}
                              className="w-full flex items-center gap-2 rounded-md transition-all duration-150"
                              style={{
                                height: 32,
                                paddingLeft: 10,
                                paddingRight: 8,
                                marginBottom: 1,
                                backgroundColor: subActive ? "rgba(99,102,241,0.1)" : "transparent",
                                color: subActive ? "#818cf8" : "#64748b",
                              }}
                              onMouseEnter={(e) => {
                                if (!subActive) {
                                  (e.currentTarget as HTMLButtonElement).style.color = "#94a3b8";
                                  (e.currentTarget as HTMLButtonElement).style.backgroundColor = "rgba(99,102,241,0.06)";
                                }
                              }}
                              onMouseLeave={(e) => {
                                if (!subActive) {
                                  (e.currentTarget as HTMLButtonElement).style.color = "#64748b";
                                  (e.currentTarget as HTMLButtonElement).style.backgroundColor = "transparent";
                                }
                              }}
                            >
                              <span>{sub.icon}</span>
                              <span style={{ fontSize: 12 }}>{sub.label}</span>
                            </button>
                          );
                        })}
                      </div>
                    </motion.div>
                  )}
                </AnimatePresence>
              )}
            </div>
          );
        })}
      </nav>

      {/* User */}
      <div
        className="flex items-center flex-shrink-0"
        style={{
          borderTop: "1px solid #2e3039",
          padding: collapsed ? "12px 0" : "12px 16px",
          justifyContent: collapsed ? "center" : "flex-start",
          gap: 10,
        }}
      >
        <div
          className="flex items-center justify-center rounded-full flex-shrink-0 cursor-pointer"
          style={{ width: 34, height: 34, background: "linear-gradient(135deg, #6366f1, #8b5cf6)" }}
          title="Juan Rodríguez"
        >
          <span style={{ color: "#fff", fontSize: 12, fontWeight: 600 }}>JR</span>
        </div>
        <AnimatePresence>
          {!collapsed && (
            <motion.div
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              exit={{ opacity: 0 }}
              transition={{ duration: 0.15 }}
              className="flex-1 min-w-0"
            >
              <div style={{ color: "#e2e8f0", fontSize: 12, fontWeight: 600, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>
                Juan Rodríguez
              </div>
              <div
                className="inline-flex items-center px-2 rounded-full mt-0.5"
                style={{ backgroundColor: "rgba(99,102,241,0.15)", height: 16 }}
              >
                <span style={{ color: "#818cf8", fontSize: 10, fontWeight: 500 }}>Administrador</span>
              </div>
            </motion.div>
          )}
        </AnimatePresence>
      </div>
    </motion.aside>
  );
}
