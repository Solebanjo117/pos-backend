import { CreditCard, Smartphone, Building2, FileText } from 'lucide-react';
import { Input } from './ui/input';

interface PaymentMethod {
  id: string;
  nombre: string;
  transacciones: number;
  monto: number;
  categoria: 'tarjeta' | 'digital' | 'otro';
}

interface PagosElectronicosProps {
  metodos: PaymentMethod[];
  onMontoChange: (id: string, monto: number) => void;
  onTransaccionesChange: (id: string, transacciones: number) => void;
}

function PaymentCard({ 
  metodo, 
  onMontoChange, 
  onTransaccionesChange 
}: { 
  metodo: PaymentMethod;
  onMontoChange: (id: string, monto: number) => void;
  onTransaccionesChange: (id: string, transacciones: number) => void;
}) {
  const getIcon = () => {
    if (metodo.categoria === 'tarjeta') return <CreditCard className="w-5 h-5" />;
    if (metodo.categoria === 'digital') return <Smartphone className="w-5 h-5" />;
    if (metodo.nombre.includes('Banco') || metodo.nombre.includes('ACH')) return <Building2 className="w-5 h-5" />;
    return <FileText className="w-5 h-5" />;
  };

  return (
    <div className="bg-white rounded-lg p-4 shadow-sm border border-gray-200 hover:shadow-md transition-shadow">
      <div className="flex items-center gap-3 mb-4">
        <div className="text-gray-600">
          {getIcon()}
        </div>
        <h3 className="font-bold text-sm text-gray-800 uppercase tracking-wide">{metodo.nombre}</h3>
      </div>
      
      <div className="space-y-3">
        <div>
          <label className="text-xs text-gray-500 font-semibold uppercase tracking-wider mb-1 block">
            Transacciones
          </label>
          <Input
            type="number"
            min="0"
            value={metodo.transacciones}
            onChange={(e) => onTransaccionesChange(metodo.id, parseInt(e.target.value) || 0)}
            className="w-full text-center font-mono font-semibold"
          />
        </div>
        
        <div>
          <label className="text-xs text-gray-500 font-semibold uppercase tracking-wider mb-1 block">
            Monto Total
          </label>
          <Input
            type="number"
            min="0"
            step="0.01"
            value={metodo.monto}
            onChange={(e) => onMontoChange(metodo.id, parseFloat(e.target.value) || 0)}
            className="w-full text-right font-mono font-semibold"
          />
        </div>
        
        <div className="pt-2 border-t border-gray-200">
          <p className="text-xs text-gray-500 font-semibold uppercase tracking-wider mb-1">Total</p>
          <p className="text-lg font-bold text-gray-900 text-right font-mono">
            ${metodo.monto.toLocaleString('es-PA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
          </p>
        </div>
      </div>
    </div>
  );
}

export function PagosElectronicos({ metodos, onMontoChange, onTransaccionesChange }: PagosElectronicosProps) {
  const tarjetas = metodos.filter(m => m.categoria === 'tarjeta');
  const digitalesOtros = metodos.filter(m => m.categoria === 'digital' || m.categoria === 'otro');

  return (
    <div className="bg-white rounded-lg shadow-md border border-gray-200 overflow-hidden">
      <div className="bg-gray-800 text-white px-6 py-4 border-b-4 border-red-600">
        <h2 className="text-lg font-bold uppercase tracking-wide">Pagos Electrónicos</h2>
      </div>
      
      <div className="p-6 space-y-6">
        {/* Tarjetas */}
        <div>
          <h3 className="text-sm font-bold text-gray-700 uppercase tracking-wider mb-4 pb-2 border-b-2 border-gray-200">
            Tarjetas
          </h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            {tarjetas.map(metodo => (
              <PaymentCard 
                key={metodo.id} 
                metodo={metodo} 
                onMontoChange={onMontoChange}
                onTransaccionesChange={onTransaccionesChange}
              />
            ))}
          </div>
        </div>

        {/* Digitales y Otros */}
        <div>
          <h3 className="text-sm font-bold text-gray-700 uppercase tracking-wider mb-4 pb-2 border-b-2 border-gray-200">
            Digitales y Otros
          </h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            {digitalesOtros.map(metodo => (
              <PaymentCard 
                key={metodo.id} 
                metodo={metodo} 
                onMontoChange={onMontoChange}
                onTransaccionesChange={onTransaccionesChange}
              />
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}
