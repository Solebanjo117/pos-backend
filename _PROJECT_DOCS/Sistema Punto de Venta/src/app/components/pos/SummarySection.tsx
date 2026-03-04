import { User, IdCard, CheckCircle2 } from 'lucide-react';
import { useState } from 'react';

export function SummarySection() {
  const [customerCode, setCustomerCode] = useState('');
  const [customerName, setCustomerName] = useState('');
  
  const [sellerCode, setSellerCode] = useState('');
  const [sellerName, setSellerName] = useState('');

  const handleCustomerChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    // Solo números
    const value = e.target.value.replace(/\D/g, '');
    if (value.length <= 8) {
      setCustomerCode(value);
      // Mock logic: si tiene 8 dígitos, asignamos un nombre
      if (value === '00000000') {
        setCustomerName('Clientes Varios');
      } else if (value.length === 8) {
        setCustomerName('Juan Pérez');
      } else {
        setCustomerName('');
      }
    }
  };

  const handleSellerChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    // Solo números
    const value = e.target.value.replace(/\D/g, '');
    if (value.length <= 4) {
      setSellerCode(value);
      // Lógica específica del usuario
      if (value === '0000') {
        setSellerName('Henry');
      } else if (value === '0001') {
        setSellerName('Joshua');
      } else if (value === '0002') {
        setSellerName('Juan');
      } else {
        setSellerName('');
      }
    }
  };

  // Validación visual
  const isCustomerError = customerCode.length > 0 && customerCode.length !== 8;

  return (
    <div className="flex flex-col gap-4">
      {/* Customer & Seller Form */}
      <div className="space-y-4">
        
        {/* Cliente Input Group */}
        <div>
          <label className="text-xs text-gray-500 font-bold mb-1 block uppercase tracking-wide">
            Cliente
          </label>
          <div className="flex gap-2">
            {/* Código Cliente */}
            <div className="relative group w-28 shrink-0">
              <User className={`absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 transition-colors ${isCustomerError ? 'text-red-400' : 'text-gray-400 group-focus-within:text-blue-500'}`} />
              <input 
                type="text" 
                placeholder="Código"
                value={customerCode}
                onChange={handleCustomerChange}
                className={`w-full pl-9 pr-2 py-2 bg-gray-50 border rounded-lg text-sm text-gray-700 focus:outline-none focus:ring-2 transition-all shadow-sm text-center ${
                  isCustomerError 
                    ? 'border-red-300 focus:ring-red-200 focus:border-red-400' 
                    : 'border-gray-200 focus:ring-blue-500 focus:border-blue-500 hover:border-gray-300'
                }`}
              />
            </div>
            
            {/* Nombre Cliente (Read Only) */}
            <div className="relative flex-1">
              <input 
                type="text" 
                readOnly
                placeholder="Nombre del Cliente"
                value={customerName}
                className={`w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-medium focus:outline-none transition-all shadow-sm ${
                  customerName 
                    ? 'bg-blue-50 text-blue-700 border-blue-200' 
                    : 'bg-gray-100 text-gray-400'
                }`}
              />
              {customerName && (
                <CheckCircle2 className="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-blue-500 animate-in fade-in zoom-in duration-300" />
              )}
            </div>
          </div>
          <div className="mt-1 text-[10px] text-gray-400 pl-1">
            Código 8 dígitos
          </div>
        </div>
        
        {/* Vendedor Input Group */}
        <div>
          <label className="text-xs text-gray-500 font-bold mb-1 block uppercase tracking-wide">
            Vendedor
          </label>
          <div className="flex gap-2">
            {/* Código Vendedor */}
            <div className="relative group w-24 shrink-0">
              <IdCard className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 group-focus-within:text-blue-500 transition-colors" />
              <input 
                type="text" 
                placeholder="0000"
                value={sellerCode}
                onChange={handleSellerChange}
                className="w-full pl-9 pr-2 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all shadow-sm hover:border-gray-300 text-center"
              />
            </div>

            {/* Nombre Vendedor (Read Only) */}
            <div className="relative flex-1">
              <input 
                type="text" 
                readOnly
                placeholder="Vendedor"
                value={sellerName}
                className={`w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-medium focus:outline-none transition-all shadow-sm ${
                  sellerName 
                    ? 'bg-blue-50 text-blue-700 border-blue-200' 
                    : 'bg-gray-100 text-gray-400'
                }`}
              />
              {sellerName && (
                <CheckCircle2 className="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-blue-500 animate-in fade-in zoom-in duration-300" />
              )}
            </div>
          </div>
          <div className="mt-1 text-[10px] text-gray-400 pl-1">
            Código 4 dígitos
          </div>
        </div>
      </div>
      
      {/* Divider */}
      <div className="h-px bg-gray-100 my-1"></div>

      {/* Summary Card */}
      <div className="bg-blue-50/50 p-4 rounded-xl border border-blue-100 relative overflow-hidden">
        <div className="absolute top-0 right-0 w-24 h-24 bg-blue-100 rounded-bl-full -mr-8 -mt-8 opacity-40 pointer-events-none"></div>
        
        <div className="relative z-10 space-y-2">
          <div className="flex justify-between text-gray-600 text-sm">
            <span>Subtotal</span>
            <span className="font-medium text-gray-900">$424.88</span>
          </div>
          <div className="flex justify-between text-gray-600 text-sm">
            <span>IVA (16%)</span>
            <span className="font-medium text-gray-900">$67.98</span>
          </div>
          
          <div className="h-px bg-blue-200/50 my-2"></div>
          
          <div className="flex justify-between items-end">
            <span className="text-base font-bold text-gray-800 mb-0.5">TOTAL</span>
            <span className="text-3xl font-bold text-blue-600">$492.86</span>
          </div>
        </div>
      </div>
    </div>
  );
}
