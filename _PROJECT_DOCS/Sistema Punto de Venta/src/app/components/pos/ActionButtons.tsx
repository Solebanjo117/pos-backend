import { Trash2, Save, Lock, Banknote, CreditCard, ArrowLeftRight } from 'lucide-react';

export function ActionButtons() {
  return (
    <div className="flex flex-col gap-3 flex-1 justify-end">
      {/* Top Actions */}
      <div className="grid grid-cols-2 gap-2">
        <button className="px-3 py-2 bg-gray-100 hover:bg-gray-200 text-gray-600 rounded-lg text-xs font-medium transition-colors flex items-center justify-center gap-2 border border-gray-200">
          <Trash2 className="w-3.5 h-3.5" />
          Limpiar
        </button>
        <button className="px-3 py-2 bg-gray-100 hover:bg-gray-200 text-gray-600 rounded-lg text-xs font-medium transition-colors flex items-center justify-center gap-2 border border-gray-200">
          <Save className="w-3.5 h-3.5" />
          Guardar
        </button>
      </div>

      {/* Warning Button */}
      <button disabled className="w-full px-3 py-2 bg-orange-50 text-orange-400 border border-orange-200 rounded-lg text-xs font-medium cursor-not-allowed flex items-center justify-center gap-2 select-none opacity-80">
        <Lock className="w-3.5 h-3.5" />
        Abre caja
      </button>

      {/* Payment Buttons - Vertical for compact side panel */}
      <div className="space-y-2 mt-2">
        <button className="w-full h-12 flex items-center justify-center gap-3 bg-green-500 hover:bg-green-600 text-white rounded-xl shadow-sm hover:shadow-md transition-all active:scale-[0.98] group relative overflow-hidden">
          <div className="absolute inset-0 bg-white/10 opacity-0 group-hover:opacity-100 transition-opacity"></div>
          <Banknote className="w-5 h-5 group-hover:scale-110 transition-transform" />
          <span className="text-base font-bold tracking-wide">Efectivo</span>
        </button>
        
        <div className="grid grid-cols-2 gap-2">
          <button className="h-12 flex items-center justify-center gap-2 bg-blue-500 hover:bg-blue-600 text-white rounded-xl shadow-sm hover:shadow-md transition-all active:scale-[0.98] group relative overflow-hidden">
            <div className="absolute inset-0 bg-white/10 opacity-0 group-hover:opacity-100 transition-opacity"></div>
            <CreditCard className="w-5 h-5 group-hover:scale-110 transition-transform" />
            <span className="text-sm font-bold tracking-wide">Tarjeta</span>
          </button>
          
          <button className="h-12 flex items-center justify-center gap-2 bg-purple-500 hover:bg-purple-600 text-white rounded-xl shadow-sm hover:shadow-md transition-all active:scale-[0.98] group relative overflow-hidden">
            <div className="absolute inset-0 bg-white/10 opacity-0 group-hover:opacity-100 transition-opacity"></div>
            <ArrowLeftRight className="w-5 h-5 group-hover:scale-110 transition-transform" />
            <span className="text-sm font-bold tracking-wide">Transf.</span>
          </button>
        </div>
      </div>
    </div>
  );
}
