import { TrendingUp, TrendingDown, CheckCircle2 } from 'lucide-react';

interface SummaryCardProps {
  label: string;
  amount: number;
  isHighlight?: boolean;
  isDifference?: boolean;
}

function SummaryCard({ label, amount, isHighlight = false, isDifference = false }: SummaryCardProps) {
  const isNegative = amount < 0;
  const isZero = amount === 0;
  
  return (
    <div className={`bg-white rounded-lg p-6 shadow-sm border ${
      isDifference && !isZero 
        ? 'border-red-500 bg-red-50' 
        : isDifference && isZero
        ? 'border-green-500 bg-green-50'
        : 'border-gray-200'
    }`}>
      <div className="flex items-start justify-between">
        <div className="flex-1">
          <p className="text-xs uppercase tracking-wider text-gray-500 font-semibold mb-2">
            {label}
          </p>
          <p className={`text-3xl font-bold tracking-tight ${
            isDifference && !isZero
              ? 'text-red-600'
              : isDifference && isZero
              ? 'text-green-600'
              : isHighlight
              ? 'text-gray-900'
              : 'text-gray-800'
          }`}>
            ${amount.toLocaleString('es-PA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
          </p>
        </div>
        {isDifference && (
          <div className="ml-2">
            {isZero ? (
              <CheckCircle2 className="w-6 h-6 text-green-600" />
            ) : isNegative ? (
              <TrendingDown className="w-6 h-6 text-red-600" />
            ) : (
              <TrendingUp className="w-6 h-6 text-red-600" />
            )}
          </div>
        )}
      </div>
    </div>
  );
}

interface ResumenGeneralProps {
  fondoInicial: number;
  ventasEfectivo: number;
  ventasTarjeta: number;
  pagosDigitales: number;
  totalDia: number;
  diferenciaFinal: number;
}

export function ResumenGeneral({
  fondoInicial,
  ventasEfectivo,
  ventasTarjeta,
  pagosDigitales,
  totalDia,
  diferenciaFinal
}: ResumenGeneralProps) {
  return (
    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
      <SummaryCard label="Fondo Inicial" amount={fondoInicial} />
      <SummaryCard label="Ventas en Efectivo" amount={ventasEfectivo} />
      <SummaryCard label="Ventas con Tarjeta" amount={ventasTarjeta} />
      <SummaryCard label="Pagos Digitales" amount={pagosDigitales} />
      <SummaryCard label="Total del Día" amount={totalDia} isHighlight />
      <SummaryCard label="Diferencia Final" amount={diferenciaFinal} isDifference />
    </div>
  );
}
