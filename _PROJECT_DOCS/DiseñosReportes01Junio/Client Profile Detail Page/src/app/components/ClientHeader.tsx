import { Pencil, Plus, Wrench, Building2 } from "lucide-react";

export function ClientHeader() {
  return (
    <div
      className="rounded-xl p-6 flex items-center gap-6"
      style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039" }}
    >
      {/* Avatar */}
      <div
        className="w-16 h-16 rounded-full bg-indigo-600 flex items-center justify-center shrink-0"
      >
        <span className="text-white font-bold" style={{ fontSize: 22 }}>TH</span>
      </div>

      {/* Info */}
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-3 mb-1">
          <h1 className="text-white" style={{ fontSize: 22, fontWeight: 700, lineHeight: 1.3 }}>
            Taller Hermanos
          </h1>
          <span
            className="px-2.5 py-0.5 rounded-full text-xs font-medium"
            style={{ backgroundColor: "#8b5cf620", color: "#a78bfa", border: "1px solid #8b5cf630" }}
          >
            Taller Mecánico
          </span>
        </div>
        <div className="flex items-center gap-4">
          <span className="text-sm text-gray-400">Cliente desde Ene 2025</span>
          <span className="flex items-center gap-1.5 text-sm text-gray-500">
            <Building2 size={13} />
            ID: CLI-00431
          </span>
        </div>
      </div>

      {/* Actions */}
      <div className="flex items-center gap-2 shrink-0">
        <button
          className="flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-gray-300 hover:text-white transition-all"
          style={{ backgroundColor: "#252830", border: "1px solid #2e3039" }}
        >
          <Pencil size={15} />
          Editar
        </button>
        <button
          className="flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-gray-300 hover:text-white transition-all"
          style={{ backgroundColor: "#252830", border: "1px solid #2e3039" }}
        >
          <Wrench size={15} />
          Asignar Mecánico
        </button>
        <button
          className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm text-white font-medium transition-all hover:bg-indigo-500"
          style={{ backgroundColor: "#6366f1" }}
        >
          <Plus size={15} />
          Nueva Venta
        </button>
      </div>
    </div>
  );
}
