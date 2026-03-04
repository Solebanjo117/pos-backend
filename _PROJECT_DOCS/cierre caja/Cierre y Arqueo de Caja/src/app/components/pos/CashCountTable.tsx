import { Fragment } from "react";
import { formatCurrency } from "../../utils/format";
import clsx from "clsx";

export interface CashRowConfig {
  id: string; // base id, e.g., '100'
  label: string; // Display label e.g., '$100'
  unitValue: number; // value of single unit
  packValue: number; // value of a pack (bundle/roll)
  packLabel: string; // header for pack column e.g. "Fajo (100)"
  isCoin?: boolean;
}

export const CASH_ROWS: CashRowConfig[] = [
  // Bills
  { id: '100', label: '$100', unitValue: 100, packValue: 10000, packLabel: 'Fajo' },
  { id: '50', label: '$50', unitValue: 50, packValue: 5000, packLabel: 'Fajo' },
  { id: '20', label: '$20', unitValue: 20, packValue: 2000, packLabel: 'Fajo' },
  { id: '10', label: '$10', unitValue: 10, packValue: 1000, packLabel: 'Fajo' },
  { id: '5', label: '$5', unitValue: 5, packValue: 500, packLabel: 'Fajo' },
  { id: '1', label: '$1', unitValue: 1, packValue: 100, packLabel: 'Fajo' },
  // Coins
  { id: '1.00', label: '$1.00', unitValue: 1.00, packValue: 25.00, packLabel: 'Rollo', isCoin: true },
  { id: '0.50', label: '$0.50', unitValue: 0.50, packValue: 10.00, packLabel: 'Rollo', isCoin: true },
  { id: '0.25', label: '$0.25', unitValue: 0.25, packValue: 10.00, packLabel: 'Rollo', isCoin: true },
  { id: '0.10', label: '$0.10', unitValue: 0.10, packValue: 5.00, packLabel: 'Rollo', isCoin: true },
  { id: '0.05', label: '$0.05', unitValue: 0.05, packValue: 2.00, packLabel: 'Rollo', isCoin: true },
  { id: '0.01', label: '$0.01', unitValue: 0.01, packValue: 0.50, packLabel: 'Rollo', isCoin: true },
];

// Helper to calculate total for a row
export const calculateRowTotal = (counts: Record<string, number>, row: CashRowConfig) => {
  const unitKey = `unit_${row.id}`;
  const packKey = `pack_${row.id}`;
  const unitCount = counts[unitKey] || 0;
  const packCount = counts[packKey] || 0;
  return (unitCount * row.unitValue) + (packCount * row.packValue);
};

// Flattened list for total calculation for the whole table
export const calculateTotalCash = (counts: Record<string, number>) => {
  return CASH_ROWS.reduce((acc, row) => acc + calculateRowTotal(counts, row), 0);
};

interface CashCountTableProps {
  counts: Record<string, number>;
  onCountChange: (id: string, value: number) => void;
}

export function CashCountTable({ counts, onCountChange }: CashCountTableProps) {
  const total = calculateTotalCash(counts);

  return (
    <div className="bg-white rounded-lg shadow-sm border border-gray-200 h-full flex flex-col overflow-hidden">
      {/* Header */}
      <div className="bg-gray-50 border-b border-gray-200 px-3 py-2 flex justify-between items-center flex-none">
        <h3 className="text-xs font-bold text-gray-700 uppercase tracking-wide">
          Efectivo
        </h3>
        <span className="text-xs font-bold text-green-700 bg-green-50 px-2 py-0.5 rounded border border-green-100">
          {formatCurrency(total)}
        </span>
      </div>
      
      <div className="overflow-y-auto flex-1 p-0 scrollbar-thin scrollbar-thumb-gray-200">
        <table className="w-full text-xs border-collapse table-fixed">
          <thead className="sticky top-0 z-10 bg-white shadow-sm ring-1 ring-gray-100">
            <tr className="text-gray-500 text-[10px] uppercase tracking-wider">
              <th className="text-left py-2 pl-3 font-semibold w-20">Denom.</th>
              <th className="text-center py-2 font-semibold w-16">x1</th>
              <th className="text-center py-2 font-semibold w-16">xPack</th>
              <th className="text-right py-2 pr-3 font-semibold">Total</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {CASH_ROWS.map((row) => {
              const unitKey = `unit_${row.id}`;
              const packKey = `pack_${row.id}`;
              const unitCount = counts[unitKey] || 0;
              const packCount = counts[packKey] || 0;
              const rowTotal = (unitCount * row.unitValue) + (packCount * row.packValue);
              const isZero = rowTotal === 0;

              return (
                <tr key={row.id} className="hover:bg-blue-50/30 transition-colors group">
                  <td className="py-1 pl-3 text-gray-700 font-bold font-mono text-[11px]">
                    {row.label}
                  </td>
                  
                  {/* Unit Input */}
                  <td className="py-1 px-1 text-center">
                    <input
                      type="number"
                      min="0"
                      step="1"
                      value={unitCount === 0 ? '' : unitCount}
                      onChange={(e) => {
                        const val = parseFloat(e.target.value);
                        onCountChange(unitKey, isNaN(val) ? 0 : val);
                      }}
                      className={clsx(
                        "w-full text-center border rounded px-1 py-0.5 text-xs focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 transition-all font-mono",
                        unitCount > 0 ? "border-blue-300 bg-blue-50 text-blue-900 font-bold" : "border-gray-200 text-gray-400 placeholder-gray-200"
                      )}
                      placeholder="-"
                    />
                  </td>

                  {/* Pack Input */}
                  <td className="py-1 px-1 text-center">
                    <input
                      type="number"
                      min="0"
                      step="1"
                      value={packCount === 0 ? '' : packCount}
                      onChange={(e) => {
                        const val = parseFloat(e.target.value);
                        onCountChange(packKey, isNaN(val) ? 0 : val);
                      }}
                      className={clsx(
                        "w-full text-center border rounded px-1 py-0.5 text-xs focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 transition-all font-mono",
                        packCount > 0 ? "border-indigo-300 bg-indigo-50 text-indigo-900 font-bold" : "border-gray-200 text-gray-400 placeholder-gray-200"
                      )}
                      placeholder={row.packLabel === 'Rollo' ? 'R' : 'F'}
                    />
                  </td>

                  {/* Row Total */}
                  <td className={clsx(
                    "py-1 pr-3 text-right font-mono font-medium transition-colors text-[11px]",
                    isZero ? "text-gray-300" : "text-gray-900 font-bold"
                  )}>
                    {formatCurrency(rowTotal)}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
