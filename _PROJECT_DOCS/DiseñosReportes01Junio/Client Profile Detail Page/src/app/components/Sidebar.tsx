import {
  LayoutDashboard,
  Users,
  ShoppingCart,
  Package,
  Wrench,
  FileText,
  CreditCard,
  BarChart2,
  Settings,
  ChevronRight,
} from "lucide-react";

const navItems = [
  { icon: LayoutDashboard, label: "Dashboard", active: false },
  { icon: ShoppingCart, label: "Ventas", active: false },
  { icon: Users, label: "Clientes", active: true },
  { icon: Package, label: "Inventario", active: false },
  { icon: Wrench, label: "Mecánicos", active: false },
  { icon: FileText, label: "Facturas", active: false },
  { icon: CreditCard, label: "Pagos", active: false },
  { icon: BarChart2, label: "Reportes", active: false },
];

export function Sidebar() {
  return (
    <aside
      style={{ width: 260, minWidth: 260, backgroundColor: "#111318" }}
      className="h-screen flex flex-col border-r border-[#2e3039] fixed left-0 top-0 z-30"
    >
      {/* Logo */}
      <div className="h-16 flex items-center px-5 border-b border-[#2e3039]">
        <div className="flex items-center gap-2.5">
          <div className="w-8 h-8 rounded-lg bg-indigo-600 flex items-center justify-center">
            <Wrench size={16} className="text-white" />
          </div>
          <span className="text-white font-semibold tracking-tight">HermeX POS</span>
        </div>
      </div>

      {/* Nav */}
      <nav className="flex-1 py-4 px-3 overflow-y-auto">
        <p className="text-[11px] text-gray-500 uppercase tracking-wider px-3 mb-2 font-medium">Principal</p>
        {navItems.map(({ icon: Icon, label, active }) => (
          <button
            key={label}
            className={`w-full flex items-center justify-between px-3 py-2.5 rounded-lg mb-0.5 text-sm transition-all group ${
              active
                ? "bg-indigo-600/10 text-indigo-400 border-l-2 border-indigo-500 rounded-l-none"
                : "text-gray-400 hover:text-gray-200 hover:bg-[#1a1d27]"
            }`}
          >
            <div className="flex items-center gap-3">
              <Icon size={17} className={active ? "text-indigo-400" : "text-gray-500 group-hover:text-gray-300"} />
              <span className={active ? "font-medium text-indigo-300" : ""}>{label}</span>
            </div>
            {active && <ChevronRight size={14} className="text-indigo-400" />}
          </button>
        ))}

        <div className="mt-4 pt-4 border-t border-[#2e3039]">
          <p className="text-[11px] text-gray-500 uppercase tracking-wider px-3 mb-2 font-medium">Sistema</p>
          <button className="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-400 hover:text-gray-200 hover:bg-[#1a1d27] transition-all">
            <Settings size={17} className="text-gray-500" />
            Configuración
          </button>
        </div>
      </nav>

      {/* User */}
      <div className="px-4 py-4 border-t border-[#2e3039]">
        <div className="flex items-center gap-3">
          <div className="w-8 h-8 rounded-full bg-violet-600/20 border border-violet-500/30 flex items-center justify-center">
            <span className="text-xs text-violet-300 font-semibold">AD</span>
          </div>
          <div className="flex-1 min-w-0">
            <p className="text-sm text-gray-200 font-medium truncate">Admin</p>
            <p className="text-xs text-gray-500 truncate">admin@hermex.com</p>
          </div>
        </div>
      </div>
    </aside>
  );
}
