import { useEffect, useRef, useState } from "react";
import { Bell, ChevronDown, ChevronRight, MapPin, Settings, LogOut, User, Check, Clock, AlertCircle } from "lucide-react";
import { AnimatePresence, motion } from "motion/react";
import type { Page } from "./Sidebar";

const breadcrumbMap: Record<Page, string[]> = {
  "dashboard": ["Dashboard", "Resumen General"],
  "productos": ["Dashboard", "Productos"],
  "clientes": ["Dashboard", "Clientes"],
  "ventas": ["Dashboard", "Ventas"],
  "estado-resultados": ["Dashboard", "Reportes", "Estado de Resultados"],
  "balance-general": ["Dashboard", "Reportes", "Balance General"],
  "ventas-por-cliente": ["Dashboard", "Reportes", "Ventas por Cliente"],
  "cuentas-por-cobrar": ["Dashboard", "Reportes", "Cuentas por Cobrar"],
  "cierre-de-caja": ["Dashboard", "Reportes", "Cierre de Caja"],
  "inventario": ["Dashboard", "Inventario"],
  "configuracion": ["Dashboard", "Configuración"],
};

const warehouses = ["Sucursal Central", "Sucursal Norte", "Todas las Sucursales"];

const notifications = [
  { icon: <AlertCircle size={14} color="#ef4444" />, title: "Cuentas vencidas", desc: "4 cuentas llevan más de 15 días vencidas", time: "Hace 2h" },
  { icon: <Clock size={14} color="#f59e0b" />, title: "Stock bajo", desc: "3 productos tienen menos de 5 unidades", time: "Hace 5h" },
  { icon: <Check size={14} color="#22c55e" />, title: "Cierre pendiente", desc: "No se ha realizado el cierre de caja de hoy", time: "Hace 1d" },
];

interface TopBarProps {
  currentPage: Page;
  onNavigate: (page: Page) => void;
  sidebarWidth: number;
}

function useClickOutside(ref: React.RefObject<HTMLElement | null>, cb: () => void) {
  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) cb();
    };
    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, [ref, cb]);
}

export function TopBar({ currentPage, onNavigate, sidebarWidth }: TopBarProps) {
  const breadcrumbs = breadcrumbMap[currentPage] ?? ["Dashboard"];
  const [warehouse, setWarehouse] = useState("Sucursal Central");
  const [showWarehouse, setShowWarehouse] = useState(false);
  const [showNotif, setShowNotif] = useState(false);
  const [showUser, setShowUser] = useState(false);

  const warehouseRef = useRef<HTMLDivElement>(null);
  const notifRef = useRef<HTMLDivElement>(null);
  const userRef = useRef<HTMLDivElement>(null);

  useClickOutside(warehouseRef, () => setShowWarehouse(false));
  useClickOutside(notifRef, () => setShowNotif(false));
  useClickOutside(userRef, () => setShowUser(false));

  const [pulse, setPulse] = useState(true);
  useEffect(() => {
    const t = setInterval(() => setPulse((v) => !v), 1000);
    return () => clearInterval(t);
  }, []);

  const dropdownStyle: React.CSSProperties = {
    position: "absolute",
    top: "calc(100% + 8px)",
    backgroundColor: "#1a1d27",
    border: "1px solid #2e3039",
    borderRadius: 12,
    boxShadow: "0 16px 48px rgba(0,0,0,0.5)",
    zIndex: 100,
    overflow: "hidden",
  };

  return (
    <header
      className="fixed top-0 right-0 flex items-center justify-between px-6 z-10"
      style={{ left: sidebarWidth, height: 64, backgroundColor: "#0f1117", borderBottom: "1px solid #2e3039", transition: "left 0.25s ease-in-out" }}
    >
      {/* Breadcrumb */}
      <div className="flex items-center gap-1.5 flex-1 min-w-0">
        {breadcrumbs.map((crumb, i) => (
          <div key={`${crumb}-${i}`} className="flex items-center gap-1.5">
            {i > 0 && <ChevronRight size={12} color="#334155" />}
            <button
              onClick={() => i === 0 ? onNavigate("dashboard") : undefined}
              style={{
                color: i === breadcrumbs.length - 1 ? "#94a3b8" : "#475569",
                fontSize: 13,
                fontWeight: i === breadcrumbs.length - 1 ? 500 : 400,
                background: "none",
                cursor: i === 0 ? "pointer" : "default",
              }}
            >
              {crumb}
            </button>
          </div>
        ))}

        {/* Real-time indicator */}
        <div className="flex items-center gap-1.5 ml-4">
          <motion.span
            animate={{ opacity: pulse ? 1 : 0.3 }}
            transition={{ duration: 0.5 }}
            className="inline-block rounded-full"
            style={{ width: 7, height: 7, backgroundColor: "#22c55e" }}
          />
          <span style={{ color: "#22c55e", fontSize: 11, fontWeight: 500 }}>En vivo</span>
        </div>
      </div>

      {/* Right controls */}
      <div className="flex items-center gap-2">
        {/* Warehouse selector */}
        <div ref={warehouseRef} style={{ position: "relative" }}>
          <button
            onClick={() => { setShowWarehouse((v) => !v); setShowNotif(false); setShowUser(false); }}
            className="flex items-center gap-2 rounded-lg px-3 transition-all"
            style={{ height: 36, backgroundColor: "#1a1d27", border: "1px solid #2e3039", color: "#94a3b8" }}
          >
            <MapPin size={13} color="#6366f1" />
            <span style={{ fontSize: 12, color: "#cbd5e1", fontWeight: 500 }}>{warehouse}</span>
            <ChevronDown size={12} color="#475569" />
          </button>
          <AnimatePresence>
            {showWarehouse && (
              <motion.div
                initial={{ opacity: 0, scale: 0.95, y: -4 }}
                animate={{ opacity: 1, scale: 1, y: 0 }}
                exit={{ opacity: 0, scale: 0.95, y: -4 }}
                transition={{ duration: 0.15 }}
                style={{ ...dropdownStyle, right: 0, width: 200 }}
              >
                {warehouses.map((w) => (
                  <button
                    key={w}
                    onClick={() => { setWarehouse(w); setShowWarehouse(false); }}
                    className="w-full flex items-center justify-between px-4 py-3 transition-colors"
                    style={{ color: w === warehouse ? "#818cf8" : "#94a3b8", fontSize: 13 }}
                    onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = "#252830")}
                    onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = "transparent")}
                  >
                    {w}
                    {w === warehouse && <Check size={13} color="#6366f1" />}
                  </button>
                ))}
              </motion.div>
            )}
          </AnimatePresence>
        </div>

        {/* Notification bell */}
        <div ref={notifRef} style={{ position: "relative" }}>
          <button
            onClick={() => { setShowNotif((v) => !v); setShowWarehouse(false); setShowUser(false); }}
            className="relative flex items-center justify-center rounded-lg transition-all"
            style={{ width: 36, height: 36, backgroundColor: "#1a1d27", border: "1px solid #2e3039" }}
          >
            <Bell size={15} color="#94a3b8" />
            <span className="absolute rounded-full" style={{ top: 7, right: 7, width: 8, height: 8, backgroundColor: "#ef4444", border: "2px solid #0f1117" }} />
          </button>
          <AnimatePresence>
            {showNotif && (
              <motion.div
                initial={{ opacity: 0, scale: 0.95, y: -4 }}
                animate={{ opacity: 1, scale: 1, y: 0 }}
                exit={{ opacity: 0, scale: 0.95, y: -4 }}
                transition={{ duration: 0.15 }}
                style={{ ...dropdownStyle, right: 0, width: 320 }}
              >
                <div className="px-4 py-3" style={{ borderBottom: "1px solid #2e3039" }}>
                  <span style={{ color: "#f1f5f9", fontSize: 13, fontWeight: 600 }}>Notificaciones</span>
                </div>
                {notifications.map((n, i) => (
                  <button
                    key={i}
                    className="w-full flex items-start gap-3 px-4 py-3 transition-colors text-left"
                    style={{ borderBottom: i < notifications.length - 1 ? "1px solid #2e3039" : "none" }}
                    onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = "#252830")}
                    onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = "transparent")}
                  >
                    <span className="flex-shrink-0 mt-0.5">{n.icon}</span>
                    <div className="flex-1 min-w-0">
                      <p style={{ color: "#e2e8f0", fontSize: 12, fontWeight: 600 }}>{n.title}</p>
                      <p style={{ color: "#64748b", fontSize: 11, marginTop: 2 }}>{n.desc}</p>
                    </div>
                    <span style={{ color: "#475569", fontSize: 10, flexShrink: 0 }}>{n.time}</span>
                  </button>
                ))}
              </motion.div>
            )}
          </AnimatePresence>
        </div>

        {/* User avatar */}
        <div ref={userRef} style={{ position: "relative" }}>
          <button
            onClick={() => { setShowUser((v) => !v); setShowWarehouse(false); setShowNotif(false); }}
            className="flex items-center justify-center rounded-full"
            style={{ width: 36, height: 36, background: "linear-gradient(135deg, #6366f1, #8b5cf6)", cursor: "pointer" }}
          >
            <span style={{ color: "#fff", fontSize: 12, fontWeight: 600 }}>JR</span>
          </button>
          <AnimatePresence>
            {showUser && (
              <motion.div
                initial={{ opacity: 0, scale: 0.95, y: -4 }}
                animate={{ opacity: 1, scale: 1, y: 0 }}
                exit={{ opacity: 0, scale: 0.95, y: -4 }}
                transition={{ duration: 0.15 }}
                style={{ ...dropdownStyle, right: 0, width: 200 }}
              >
                <div className="px-4 py-3" style={{ borderBottom: "1px solid #2e3039" }}>
                  <p style={{ color: "#f1f5f9", fontSize: 13, fontWeight: 600 }}>Juan Rodríguez</p>
                  <p style={{ color: "#475569", fontSize: 11, marginTop: 1 }}>Administrador</p>
                </div>
                <button
                  onClick={() => { onNavigate("configuracion"); setShowUser(false); }}
                  className="w-full flex items-center gap-3 px-4 py-3 transition-colors"
                  style={{ color: "#94a3b8", fontSize: 13, borderBottom: "1px solid #2e3039" }}
                  onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = "#252830")}
                  onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = "transparent")}
                >
                  <Settings size={14} /> Configuración
                </button>
                <button
                  className="w-full flex items-center gap-3 px-4 py-3 transition-colors"
                  style={{ color: "#ef4444", fontSize: 13 }}
                  onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = "#252830")}
                  onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = "transparent")}
                >
                  <LogOut size={14} /> Cerrar Sesión
                </button>
              </motion.div>
            )}
          </AnimatePresence>
        </div>
      </div>
    </header>
  );
}
