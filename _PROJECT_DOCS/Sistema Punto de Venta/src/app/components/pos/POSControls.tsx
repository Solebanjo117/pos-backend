import { Search } from 'lucide-react';

export function POSControls() {
  return (
    <div className="bg-white p-4 rounded-xl shadow-sm border border-gray-100">
      <div className="flex flex-col md:flex-row items-end gap-3">
        {/* Search Bar */}
        <div className="relative flex-1 w-full">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
          <input 
            type="text" 
            placeholder="SKU, nombre o código de barras… (Enter para buscar)"
            className="w-full pl-10 pr-3 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm text-gray-700 placeholder:text-gray-400 transition-shadow h-10"
          />
        </div>
        
        {/* Dropdowns */}
        <div className="flex gap-2 w-full md:w-auto">
          <select className="flex-1 px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-xs text-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 cursor-pointer h-10">
            <option>Todas las Categorías</option>
            <option>Motor</option>
            <option>Suspensión</option>
            <option>Frenos</option>
          </select>
          <select className="flex-1 px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-xs text-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 cursor-pointer h-10">
            <option>Todas las Marcas</option>
            <option>Bosch</option>
            <option>Brembo</option>
            <option>Denso</option>
          </select>
        </div>

        {/* Quantity Input */}
        <div className="flex items-end gap-2 shrink-0">
          <div className="w-16">
            <input 
              type="number" 
              defaultValue="1"
              className="w-full px-2 py-2 bg-gray-50 border border-gray-200 rounded-lg text-center font-medium focus:outline-none focus:ring-2 focus:ring-blue-500 h-10 text-sm"
              placeholder="Qty"
            />
          </div>
          <button className="h-10 px-4 bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium rounded-lg transition-colors border border-gray-200 text-sm">
            Buscar
          </button>
        </div>
      </div>
    </div>
  );
}
