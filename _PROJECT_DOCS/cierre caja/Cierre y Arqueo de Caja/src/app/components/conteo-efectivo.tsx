import { Input } from './ui/input';

interface DenominacionRow {
  label: string;
  valor: number;
  cantidad: number;
  tipo: 'billete' | 'moneda' | 'otros';
}

interface ConteoEfectivoProps {
  denominaciones: DenominacionRow[];
  onCantidadChange: (index: number, cantidad: number) => void;
}

export function ConteoEfectivo({ denominaciones, onCantidadChange }: ConteoEfectivoProps) {
  const billetes = denominaciones.filter(d => d.tipo === 'billete');
  const monedas = denominaciones.filter(d => d.tipo === 'moneda');
  const otros = denominaciones.filter(d => d.tipo === 'otros');

  const totalContado = denominaciones.reduce((sum, d) => sum + (d.valor * d.cantidad), 0);

  const renderRows = (items: DenominacionRow[], startIndex: number) => (
    items.map((item, idx) => {
      const globalIndex = startIndex + idx;
      const total = item.valor * item.cantidad;
      return (
        <tr key={globalIndex} className="border-b border-gray-100 hover:bg-gray-50 transition-colors">
          <td className="py-3 px-4 text-sm font-semibold text-gray-700">
            {item.label}
          </td>
          <td className="py-3 px-4">
            <Input
              type="number"
              min="0"
              value={item.cantidad}
              onChange={(e) => onCantidadChange(globalIndex, parseInt(e.target.value) || 0)}
              className="w-24 text-center font-mono font-semibold"
            />
          </td>
          <td className="py-3 px-4 text-right text-sm font-bold text-gray-900 font-mono">
            ${total.toLocaleString('es-PA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
          </td>
        </tr>
      );
    })
  );

  return (
    <div className="bg-white rounded-lg shadow-md border border-gray-200 overflow-hidden">
      <div className="bg-gray-800 text-white px-6 py-4 border-b-4 border-red-600">
        <h2 className="text-lg font-bold uppercase tracking-wide">Conteo de Efectivo</h2>
      </div>
      
      <div className="overflow-x-auto">
        <table className="w-full">
          <thead>
            <tr className="bg-gray-100 border-b-2 border-gray-300">
              <th className="py-3 px-4 text-left text-xs font-bold text-gray-600 uppercase tracking-wider">
                Denominación
              </th>
              <th className="py-3 px-4 text-center text-xs font-bold text-gray-600 uppercase tracking-wider">
                Cantidad
              </th>
              <th className="py-3 px-4 text-right text-xs font-bold text-gray-600 uppercase tracking-wider">
                Total
              </th>
            </tr>
          </thead>
          <tbody>
            {/* Billetes */}
            <tr className="bg-gray-50">
              <td colSpan={3} className="py-2 px-4 text-xs font-bold text-gray-700 uppercase tracking-wider">
                Billetes
              </td>
            </tr>
            {renderRows(billetes, 0)}

            {/* Monedas */}
            <tr className="bg-gray-50">
              <td colSpan={3} className="py-2 px-4 text-xs font-bold text-gray-700 uppercase tracking-wider">
                Monedas
              </td>
            </tr>
            {renderRows(monedas, billetes.length)}

            {/* Otros */}
            <tr className="bg-gray-50">
              <td colSpan={3} className="py-2 px-4 text-xs font-bold text-gray-700 uppercase tracking-wider">
                Otros
              </td>
            </tr>
            {renderRows(otros, billetes.length + monedas.length)}

            {/* Total */}
            <tr className="bg-gray-800 text-white">
              <td colSpan={2} className="py-4 px-4 text-sm font-bold uppercase tracking-wide">
                Total Efectivo Contado
              </td>
              <td className="py-4 px-4 text-right text-xl font-bold font-mono">
                ${totalContado.toLocaleString('es-PA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  );
}
