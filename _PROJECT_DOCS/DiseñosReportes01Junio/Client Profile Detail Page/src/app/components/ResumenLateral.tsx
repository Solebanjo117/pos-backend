import { DollarSign, FileText, TrendingUp, CalendarDays } from "lucide-react";

const topItems = [
  { label: "Filtros de aceite", units: 24, max: 24 },
  { label: "Pastillas de freno", units: 12, max: 24 },
  { label: "Bujías", units: 8, max: 24 },
];

function StatRow({ icon: Icon, label, value, iconColor }: {
  icon: React.ElementType;
  label: string;
  value: string;
  iconColor: string;
}) {
  return (
    <div className="flex items-center justify-between py-3" style={{ borderBottom: "1px solid #252830" }}>
      <div className="flex items-center gap-2.5">
        <div
          className="w-7 h-7 rounded-lg flex items-center justify-center"
          style={{ backgroundColor: `${iconColor}15` }}
        >
          <Icon size={14} style={{ color: iconColor }} />
        </div>
        <span className="text-sm text-gray-400">{label}</span>
      </div>
      <span className="text-sm text-gray-100 font-semibold">{value}</span>
    </div>
  );
}

export function ResumenLateral() {
  return (
    <div
      className="rounded-xl p-5 sticky top-24"
      style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039", width: 300 }}
    >
      <h3 className="text-sm font-semibold text-gray-200 mb-1" style={{ lineHeight: 1.4 }}>
        Resumen del Cliente
      </h3>
      <p className="text-xs text-gray-500 mb-4">Taller Hermanos · CLI-00431</p>

      {/* Stats */}
      <div style={{ borderTop: "1px solid #252830" }}>
        <StatRow icon={DollarSign} label="Total Comprado" value="$12,450.00" iconColor="#22c55e" />
        <StatRow icon={FileText} label="Total Facturas" value="48" iconColor="#6366f1" />
        <StatRow icon={TrendingUp} label="Ticket Promedio" value="$259.38" iconColor="#8b5cf6" />
        <StatRow icon={CalendarDays} label="Última Compra" value="22 May 2026" iconColor="#3b82f6" />
      </div>

      {/* Top items */}
      <div className="mt-5">
        <p className="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">
          Items Más Comprados
        </p>
        <div className="space-y-3">
          {topItems.map(({ label, units, max }) => (
            <div key={label}>
              <div className="flex justify-between text-xs mb-1.5">
                <span className="text-gray-300">{label}</span>
                <span className="text-gray-500">{units} uds.</span>
              </div>
              <div className="h-1.5 rounded-full" style={{ backgroundColor: "#252830" }}>
                <div
                  className="h-full rounded-full"
                  style={{
                    width: `${(units / max) * 100}%`,
                    background: "linear-gradient(90deg, #6366f1, #8b5cf6)",
                  }}
                />
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* CTA */}
      <button
        className="mt-5 w-full py-2.5 rounded-lg text-sm font-medium text-gray-300 hover:text-white transition-all"
        style={{ backgroundColor: "#252830", border: "1px solid #2e3039" }}
      >
        Ver análisis completo
      </button>
    </div>
  );
}
