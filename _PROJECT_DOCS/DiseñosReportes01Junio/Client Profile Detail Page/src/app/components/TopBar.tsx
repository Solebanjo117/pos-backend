import { ChevronRight, Warehouse, Bell, Search } from "lucide-react";

export function TopBar() {
  return (
    <header
      style={{ height: 64, left: 260 }}
      className="fixed top-0 right-0 bg-[#0f1117] border-b border-[#2e3039] flex items-center justify-between px-6 z-20"
    >
      {/* Breadcrumbs */}
      <div className="flex items-center gap-1.5 text-sm">
        <span className="text-gray-500 hover:text-gray-300 cursor-pointer transition-colors">Clientes</span>
        <ChevronRight size={14} className="text-gray-600" />
        <span className="text-gray-200 font-medium">Taller Hermanos</span>
      </div>

      {/* Right side */}
      <div className="flex items-center gap-3">
        <button className="p-2 rounded-lg text-gray-400 hover:text-gray-200 hover:bg-[#1a1d27] transition-all">
          <Search size={17} />
        </button>

        {/* Warehouse selector */}
        <button className="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-[#1a1d27] border border-[#2e3039] text-sm text-gray-300 hover:border-indigo-500/50 transition-all">
          <Warehouse size={15} className="text-indigo-400" />
          <span>Almacén Principal</span>
          <ChevronRight size={13} className="text-gray-500 rotate-90" />
        </button>

        <button className="relative p-2 rounded-lg text-gray-400 hover:text-gray-200 hover:bg-[#1a1d27] transition-all">
          <Bell size={17} />
          <span className="absolute top-1.5 right-1.5 w-1.5 h-1.5 rounded-full bg-indigo-500" />
        </button>

        {/* Avatar */}
        <div className="w-8 h-8 rounded-full bg-indigo-600 flex items-center justify-center cursor-pointer">
          <span className="text-xs text-white font-semibold">AD</span>
        </div>
      </div>
    </header>
  );
}
