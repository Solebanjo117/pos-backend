import { AlertCircle, Lock } from 'lucide-react';

export function CashRegisterStatus() {
  return (
    <div className="bg-blue-50 border-b border-blue-100 px-6 py-3 flex items-center justify-between">
      <div className="flex items-center gap-3 text-blue-700">
        <AlertCircle className="w-5 h-5" />
        <span className="font-medium">Caja no iniciada – Ingresa el fondo inicial para habilitar ventas.</span>
      </div>
      
      <div className="flex items-center gap-3">
        <div className="relative">
          <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500">$</span>
          <input 
            type="number" 
            defaultValue="0"
            className="w-32 pl-7 pr-3 py-2 bg-white border border-blue-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>
        <button className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center gap-2">
          <Lock className="w-4 h-4" />
          Abrir Caja
        </button>
      </div>
    </div>
  );
}
