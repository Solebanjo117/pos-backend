import { Phone, Mail, MapPin, Hash, CreditCard, Calendar, CheckCircle, UserCircle2 } from "lucide-react";

function InfoCard({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div
      className="rounded-xl p-5"
      style={{ backgroundColor: "#1a1d27", border: "1px solid #2e3039" }}
    >
      <h3 className="text-sm font-semibold text-gray-200 mb-4 pb-3" style={{ borderBottom: "1px solid #2e3039" }}>
        {title}
      </h3>
      {children}
    </div>
  );
}

function InfoRow({ icon: Icon, label, value, iconColor = "#6b7280" }: {
  icon: React.ElementType;
  label: string;
  value: React.ReactNode;
  iconColor?: string;
}) {
  return (
    <div className="flex items-start gap-3 mb-3 last:mb-0">
      <Icon size={15} style={{ color: iconColor, marginTop: 2, flexShrink: 0 }} />
      <div className="flex-1 min-w-0">
        <p className="text-xs text-gray-500 mb-0.5">{label}</p>
        <div className="text-sm text-gray-200">{value}</div>
      </div>
    </div>
  );
}

export function ClientInfoGrid() {
  const creditUsedPct = 24;

  return (
    <div className="grid grid-cols-3 gap-4">
      {/* Información General */}
      <InfoCard title="Información General">
        <InfoRow icon={Phone} label="Teléfono" value="+507 6XXX-XXXX" iconColor="#6366f1" />
        <InfoRow icon={Mail} label="Email" value="taller@email.com" iconColor="#6366f1" />
        <InfoRow icon={MapPin} label="Dirección" value="Panamá, Vía España" iconColor="#6366f1" />
        <InfoRow icon={Hash} label="RUC/Cédula" value="8-XXX-XXXX" iconColor="#6366f1" />
      </InfoCard>

      {/* Crédito */}
      <InfoCard title="Crédito">
        <InfoRow
          icon={CreditCard}
          label="Límite de Crédito"
          value="$5,000.00"
          iconColor="#8b5cf6"
        />
        <InfoRow
          icon={CreditCard}
          label="Saldo Actual"
          value={
            <span
              className="px-2 py-0.5 rounded-md text-xs font-semibold"
              style={{ backgroundColor: "#f9731620", color: "#fb923c", border: "1px solid #f9731630" }}
            >
              $1,200.00
            </span>
          }
          iconColor="#f97316"
        />
        <InfoRow
          icon={Calendar}
          label="Días de Crédito"
          value="30 días"
          iconColor="#8b5cf6"
        />
        <InfoRow
          icon={CheckCircle}
          label="Estado"
          value={
            <span
              className="px-2 py-0.5 rounded-md text-xs font-semibold"
              style={{ backgroundColor: "#22c55e20", color: "#4ade80", border: "1px solid #22c55e30" }}
            >
              Al día
            </span>
          }
          iconColor="#22c55e"
        />
        {/* Progress bar */}
        <div className="mt-3">
          <div className="flex justify-between text-xs text-gray-500 mb-1.5">
            <span>Crédito usado</span>
            <span style={{ color: "#22c55e" }}>{creditUsedPct}%</span>
          </div>
          <div className="h-1.5 rounded-full" style={{ backgroundColor: "#252830" }}>
            <div
              className="h-full rounded-full transition-all"
              style={{ width: `${creditUsedPct}%`, backgroundColor: "#22c55e" }}
            />
          </div>
          <p className="text-xs text-gray-600 mt-1">$1,200 de $5,000 utilizados</p>
        </div>
      </InfoCard>

      {/* Mecánico Asignado */}
      <InfoCard title="Mecánico Asignado">
        <div className="flex items-center gap-3 mb-3">
          <div
            className="w-10 h-10 rounded-full flex items-center justify-center shrink-0"
            style={{ backgroundColor: "#6366f120", border: "1px solid #6366f130" }}
          >
            <UserCircle2 size={20} className="text-indigo-400" />
          </div>
          <div className="flex-1">
            <p className="text-sm text-gray-200 font-medium">Mecánico Rodríguez</p>
            <p className="text-xs text-gray-500">Técnico Senior</p>
          </div>
          <button className="text-xs font-medium" style={{ color: "#6366f1" }}>
            Cambiar
          </button>
        </div>

        <div
          className="rounded-lg px-3 py-2.5 mt-2"
          style={{ backgroundColor: "#252830" }}
        >
          <div className="flex items-center gap-2 text-xs text-gray-500">
            <Calendar size={12} />
            <span>Asignado desde Mar 2025</span>
          </div>
        </div>

        <div className="mt-3 space-y-2">
          <div className="flex justify-between text-xs">
            <span className="text-gray-500">Facturas atendidas</span>
            <span className="text-gray-300">38</span>
          </div>
          <div className="flex justify-between text-xs">
            <span className="text-gray-500">Última atención</span>
            <span className="text-gray-300">22 May 2026</span>
          </div>
        </div>
      </InfoCard>
    </div>
  );
}
