import { formatCurrency } from "../../utils/format";
import clsx from "clsx";

interface CashSummaryProps {
  initialFund: number;
  cashSales: number;
  electronicPayments: number;
  totalDay: number;
  difference: number;
}

export function CashSummary({
  initialFund,
  cashSales,
  electronicPayments,
  totalDay,
  difference,
}: CashSummaryProps) {
  return (
    <div className="bg-white p-4 rounded-lg shadow-sm border border-gray-100">
      <h2 className="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">
        Resumen Superior
      </h2>
      <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
        <SummaryItem label="Fondo Inicial" value={initialFund} />
        <SummaryItem label="Ventas en Efectivo" value={cashSales} />
        <SummaryItem label="Pagos Electrónicos" value={electronicPayments} />
        <SummaryItem label="Total del Día" value={totalDay} highlight />
        <SummaryItem
          label="Diferencia"
          value={difference}
          isDifference
        />
      </div>
    </div>
  );
}

function SummaryItem({
  label,
  value,
  highlight = false,
  isDifference = false,
}: {
  label: string;
  value: number;
  highlight?: boolean;
  isDifference?: boolean;
}) {
  const isNegative = value < 0;
  const isPositive = value > 0;
  
  let valueClass = "text-xl font-bold text-gray-900";
  
  if (highlight) {
    valueClass = "text-xl font-bold text-blue-600";
  }
  
  if (isDifference) {
    if (Math.abs(value) < 0.01) {
      valueClass = "text-xl font-bold text-green-600";
    } else {
      valueClass = "text-xl font-bold text-red-600";
    }
  }

  return (
    <div className="flex flex-col">
      <span className="text-xs text-gray-500 mb-1">{label}</span>
      <span className={valueClass}>{formatCurrency(value)}</span>
    </div>
  );
}
