import { Trash2, Plus, Minus } from 'lucide-react';

interface Product {
  id: number;
  name: string;
  brand: string | null;
  price: number;
  quantity: number;
  total: number;
}

const products: Product[] = [
  { id: 1, name: 'Alternador 12V 90A', brand: null, price: 300.00, quantity: 1, total: 348.00 },
  { id: 2, name: 'Alternador 12V 90A', brand: 'Brembo', price: 36.00, quantity: 1, total: 41.76 },
  { id: 3, name: 'Alternador 12V 90A', brand: 'Bosch', price: 88.88, quantity: 1, total: 103.10 },
  { id: 4, name: 'Filtro Aceite PH3593A', brand: 'Fram', price: 12.50, quantity: 2, total: 29.00 },
  { id: 5, name: 'Bujía Iridium', brand: 'NGK', price: 18.00, quantity: 4, total: 83.52 },
  { id: 6, name: 'Aceite Sintético 5W-30', brand: 'Mobil 1', price: 45.00, quantity: 1, total: 52.20 },
  { id: 7, name: 'Pastillas Frenos Del.', brand: 'Wagner', price: 65.00, quantity: 1, total: 75.40 },
  { id: 8, name: 'Líquido de Frenos DOT4', brand: 'Bosch', price: 8.50, quantity: 2, total: 19.72 },
  { id: 9, name: 'Líquido de Frenos DOT4', brand: 'Bosch', price: 8.50, quantity: 2, total: 19.72 },
  { id: 10, name: 'Líquido de Frenos DOT4', brand: 'Bosch', price: 8.50, quantity: 2, total: 19.72 },
  { id: 11, name: 'Líquido de Frenos DOT4', brand: 'Bosch', price: 8.50, quantity: 2, total: 19.72 },
  { id: 12, name: 'Líquido de Frenos DOT4', brand: 'Bosch', price: 8.50, quantity: 2, total: 19.72 },
];

export function ProductTable() {
  return (
    <div className="bg-white rounded-xl shadow-sm border border-gray-100 flex flex-col h-full overflow-hidden">
      <div className="flex-1 overflow-auto relative">
        <table className="w-full text-left border-collapse">
          <thead className="sticky top-0 z-10 bg-gray-50 shadow-sm">
            <tr className="border-b border-gray-100 text-xs uppercase text-gray-500 font-semibold tracking-wider">
              <th className="px-4 py-3 bg-gray-50">Producto</th>
              <th className="px-4 py-3 text-right bg-gray-50">Precio</th>
              <th className="px-4 py-3 text-center bg-gray-50">Cant.</th>
              <th className="px-4 py-3 text-right bg-gray-50">Total</th>
              <th className="px-2 py-3 text-center w-10 bg-gray-50"></th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-50">
            {products.map((product) => (
              <tr key={product.id} className="hover:bg-gray-50/50 transition-colors group">
                <td className="px-4 py-3">
                  <div className="flex flex-col">
                    <span className="font-medium text-gray-900 text-sm">{product.name}</span>
                    {product.brand && (
                      <span className="text-xs text-gray-500">{product.brand}</span>
                    )}
                  </div>
                </td>
                <td className="px-4 py-3 text-right font-medium text-gray-600 text-sm">
                  ${product.price.toFixed(2)}
                </td>
                <td className="px-4 py-3">
                  <div className="flex items-center justify-center gap-2">
                    <button className="p-0.5 rounded-md hover:bg-gray-200 text-gray-400 hover:text-gray-600 transition-colors">
                      <Minus className="w-3.5 h-3.5" />
                    </button>
                    <span className="font-semibold text-gray-900 w-6 text-center text-sm">{product.quantity}</span>
                    <button className="p-0.5 rounded-md hover:bg-gray-200 text-gray-400 hover:text-gray-600 transition-colors">
                      <Plus className="w-3.5 h-3.5" />
                    </button>
                  </div>
                </td>
                <td className="px-4 py-3 text-right font-bold text-gray-900 bg-blue-50/30 text-sm">
                  ${product.total.toFixed(2)}
                </td>
                <td className="px-2 py-3 text-center">
                  <button className="p-1.5 text-gray-300 hover:text-red-500 hover:bg-red-50 rounded-lg transition-all opacity-0 group-hover:opacity-100">
                    <Trash2 className="w-3.5 h-3.5" />
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      
      <div className="bg-gray-50 px-4 py-2 border-t border-gray-100 text-xs text-gray-400 text-center uppercase tracking-widest shrink-0">
        12 productos en lista
      </div>
    </div>
  );
}
